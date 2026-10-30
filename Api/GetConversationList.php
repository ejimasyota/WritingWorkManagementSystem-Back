<?php
/**
 * GetConversationList.php
 * AI会話履歴一覧取得API
 */

require_once __DIR__ . '/../Common/Database.php';
require_once __DIR__ . '/../Common/Logger.php';
require_once __DIR__ . '/../Common/Response.php';
require_once __DIR__ . '/../Common/Auth.php';

// GETメソッドのみ許可
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    Response::Error('不正なリクエストです', 405);
}

$FunctionName = 'AI相談履歴取得';
$WorkId = $AuthWorkId ?? '';
$ThreadId = $_GET['ThreadId'] ?? '';
$IncludeArchived = $_GET['IncludeArchived'] ?? 'false';

try {
    Logger::Info($FunctionName, '取得処理開始');

    if (empty($WorkId)) {
        Response::Error('作品IDが指定されていません');
    }

    $Db = Database::GetConnection();

    // スレッドIDが指定されている場合は、そのスレッドの全会話を取得
    if (!empty($ThreadId)) {
        $Sql = "
            SELECT
                conversationId AS \"ConvId\",
                threadId AS \"ThreadId\",
                workId AS \"WorkId\",
                postMessage AS \"UserMessage\",
                responseMessage AS \"AiResponse\",
                useWorkContext AS \"UseWorkContext\",
                archiveFlg AS \"ArchiveFlg\",
                registDate AS \"RegistDate\",
                updateDate AS \"UpdateDate\",
                registUser AS \"RegistUser\",
                updateUser AS \"UpdateUser\"
            FROM ConversationInfo
            WHERE workId = :workId AND threadId = :threadId
            ORDER BY registDate ASC
        ";
        $Stmt = $Db->prepare($Sql);
        $Stmt->execute([':workId' => $WorkId, ':threadId' => $ThreadId]);
        $Conversations = $Stmt->fetchAll();

        Response::Success([
            'Conversations' => $Conversations
        ]);
    }

    // スレッド一覧を取得（各スレッドの最初のメッセージを取得）
    $ArchiveCondition = $IncludeArchived === 'true' ? '' : 'AND c.archiveFlg = false';

    $Sql = "
        SELECT DISTINCT ON (c.threadId)
            c.conversationId AS \"ConvId\",
            c.threadId AS \"ThreadId\",
            c.workId AS \"WorkId\",
            c.postMessage AS \"UserMessage\",
            c.responseMessage AS \"AiResponse\",
            c.archiveFlg AS \"ArchiveFlg\",
            c.registDate AS \"RegistDate\",
            c.registUser AS \"RegistUser\",
            (
                SELECT COUNT(*)
                FROM ConversationInfo c2
                WHERE c2.threadId = c.threadId AND c2.workId = c.workId
            ) AS \"MessageCount\",
            (
                SELECT MAX(c3.updateDate)
                FROM ConversationInfo c3
                WHERE c3.threadId = c.threadId AND c3.workId = c.workId
            ) AS \"UpdateDate\"
        FROM ConversationInfo c
        WHERE c.workId = :workId
        $ArchiveCondition
        ORDER BY c.threadId, c.registDate ASC
    ";

    $Stmt = $Db->prepare($Sql);
    $Stmt->execute([':workId' => $WorkId]);
    $ThreadList = $Stmt->fetchAll();

    // スレッドを最新の更新日時でソート
    $SortSql = "
        SELECT threadId, MAX(registDate) as latestDate
        FROM ConversationInfo
        WHERE workId = :workId
        GROUP BY threadId
        ORDER BY latestDate DESC
    ";
    $SortStmt = $Db->prepare($SortSql);
    $SortStmt->execute([':workId' => $WorkId]);
    $SortOrder = $SortStmt->fetchAll();

    // ソート順のマップを作成
    $OrderMap = [];
    foreach ($SortOrder as $Index => $Row) {
        $OrderMap[$Row['threadid']] = $Index;
    }

    // スレッドリストをソート
    usort($ThreadList, function($a, $b) use ($OrderMap) {
        $OrderA = $OrderMap[$a['ThreadId']] ?? PHP_INT_MAX;
        $OrderB = $OrderMap[$b['ThreadId']] ?? PHP_INT_MAX;
        return $OrderA - $OrderB;
    });

    Logger::Info($FunctionName, '取得処理終了');

    Response::Success([
        'ThreadList' => $ThreadList
    ]);

} catch (Exception $E) {
    Logger::Error($FunctionName, '取得処理エラー', '', $E->getMessage());
    Response::Error('会話履歴の取得中にエラーが発生しました', 500);
}
