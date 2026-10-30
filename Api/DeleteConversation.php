<?php
/**
 * DeleteConversation.php
 * AI会話削除API
 */

require_once __DIR__ . '/../Common/Database.php';
require_once __DIR__ . '/../Common/Logger.php';
require_once __DIR__ . '/../Common/Response.php';
require_once __DIR__ . '/../Common/Auth.php';

// POSTメソッドのみ許可
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::Error('不正なリクエストです', 405);
}

$FunctionName = 'AI相談削除';
$UserId = '';

try {
    /** リクエストデータを取得 */
    $Input = Response::GetJsonInput();
    $ThreadId = $Input['ThreadId'] ?? '';
    $WorkId = $AuthWorkId ?? '';
    $UserId = $AuthUserId;
    $UpdateDate = $Input['UpdateDate'] ?? null;

    Logger::Info($FunctionName, '削除処理開始', $UserId);

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
        Response::Error('削除対象の会話が見つかりません');
    }

    /** スレッドの全会話を削除 */
    $Sql = "DELETE FROM ConversationInfo WHERE threadId = :threadId AND workId = :workId AND updateDate = :updateDate";
    $Stmt = $Pdo->prepare($Sql);
    $Stmt->execute([
        ':threadId' => $ThreadId,
        ':workId' => $WorkId,
        ':updateDate' => $UpdateDate
    ]);
    if ($Stmt->rowCount() === 0) {
        Database::Rollback();
        Response::ConflictError();
    }

    Database::Commit();

    Logger::Info($FunctionName, '削除処理終了', $UserId);

    Response::Success([
        'Message' => '会話を削除しました'
    ]);

} catch (Exception $E) {
    Database::Rollback();
    Logger::Error($FunctionName, '削除処理エラー', $UserId, $E->getMessage());
    Response::Error('会話の削除に失敗しました');
}
