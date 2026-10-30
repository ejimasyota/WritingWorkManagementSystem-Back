<?php
/**
 * UpdateConversationArchive.php
 * AI会話アーカイブフラグ更新API
 */

require_once __DIR__ . '/../Common/Database.php';
require_once __DIR__ . '/../Common/Logger.php';
require_once __DIR__ . '/../Common/Response.php';
require_once __DIR__ . '/../Common/Auth.php';

// POSTメソッドのみ許可
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::Error('不正なリクエストです', 405);
}

$FunctionName = 'AI相談アーカイブ';
$UserId = '';

try {
    /** リクエストデータを取得 */
    $Input = Response::GetJsonInput();
    $ThreadId = $Input['ThreadId'] ?? '';
    $WorkId = $AuthWorkId ?? '';
    $ArchiveFlg = $Input['ArchiveFlg'] ?? false;
    $UpdateDate = $Input['UpdateDate'] ?? null;
    $UserId = $AuthUserId;

    $Operation = $ArchiveFlg ? 'アーカイブ' : 'アーカイブ解除';
    Logger::Info($FunctionName, $Operation . '処理開始', $UserId);

    /** 入力チェック */
    if (empty($ThreadId)) {
        Response::Error('スレッドIDが指定されていません');
    }

    if (empty($WorkId)) {
        Response::Error('作品IDが指定されていません');
    }

    /** DB接続 */
    $Pdo = Database::GetConnection();
    Database::BeginTransaction();

    /** スレッドの存在確認 */
    $CheckSql = "SELECT COUNT(*) as cnt FROM ConversationInfo WHERE threadId = :threadId AND workId = :workId";
    $CheckStmt = $Pdo->prepare($CheckSql);
    $CheckStmt->execute([':threadId' => $ThreadId, ':workId' => $WorkId]);
    $CheckResult = $CheckStmt->fetch();

    if ($CheckResult['cnt'] == 0) {
        Database::Rollback();
        Response::Error('指定されたスレッドが見つかりません');
    }

    /** アーカイブフラグを更新 */
    $Sql = "
        UPDATE ConversationInfo SET
            archiveFlg = :archiveFlg,
            updateDate = CURRENT_TIMESTAMP(3),
            updateUser = :updateUser
        WHERE threadId = :threadId AND workId = :workId
          AND updateDate = :updateDate
    ";

    $Stmt = $Pdo->prepare($Sql);
    $Stmt->execute([
        ':archiveFlg' => $ArchiveFlg ? 'true' : 'false',
        ':updateUser' => $UserId,
        ':threadId' => $ThreadId,
        ':workId' => $WorkId,
        ':updateDate' => $UpdateDate
    ]);

    if ($Stmt->rowCount() === 0) {
        Database::Rollback();
        Response::ConflictError();
    }

    /** 更新後のUpdateDateを取得 */
    $NewDateSql = "SELECT MAX(updateDate) AS \"UpdateDate\" FROM ConversationInfo WHERE threadId = :threadId AND workId = :workId";
    $NewDateStmt = $Pdo->prepare($NewDateSql);
    $NewDateStmt->execute([':threadId' => $ThreadId, ':workId' => $WorkId]);
    $NewDateRow = $NewDateStmt->fetch();
    $NewUpdateDate = $NewDateRow['UpdateDate'] ?? null;

    Database::Commit();

    Logger::Info($FunctionName, $Operation . '処理終了', $UserId);

    Response::Success([
        'Message' => '会話を' . $Operation . 'しました',
        'UpdateDate' => $NewUpdateDate
    ]);

} catch (Exception $E) {
    Database::Rollback();
    Logger::Error($FunctionName, 'アーカイブ処理エラー', $UserId, $E->getMessage());
    Response::Error('アーカイブ処理に失敗しました');
}
