<?php
/**
 * DeleteUpdateHistory.php
 * 更新履歴全削除API
 * ※管理者または作成者のみ実行可能
 */

require_once __DIR__ . '/../Common/Database.php';
require_once __DIR__ . '/../Common/Logger.php';
require_once __DIR__ . '/../Common/Response.php';
require_once __DIR__ . '/../Common/Auth.php';

// POSTメソッドのみ許可
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::Error('不正なリクエストです', 405);
}

$FunctionName = '更新履歴';
$UserId = '';

try {
    /** リクエストデータを取得 */
    $Input = Response::GetJsonInput();
    $WorkId = $AuthWorkId ?? '';
    $UserId = $AuthUserId;

    Logger::Info($FunctionName, '全削除処理開始', $UserId);

    /** 入力チェック */
    if (empty($WorkId)) {
        Response::Error('作品IDが指定されていません');
    }

    /** DB接続 */
    $Pdo = Database::GetConnection();

    /** 権限チェック：作成者(isCreator=TRUE) または 管理者のみ実行可能 */
    if (!$IsAuthAdmin) {
        $PermStmt = $Pdo->prepare('
            SELECT isCreator FROM CreaterList
            WHERE workId = :workId AND userId = :userId AND isCreator = TRUE
        ');
        $PermStmt->execute([':workId' => $WorkId, ':userId' => $UserId]);
        if (!$PermStmt->fetch()) {
            Response::Error('この操作を実行する権限がありません', 403);
        }
    }

    Database::BeginTransaction();

    /** 更新履歴を全削除 */
    $Sql = "DELETE FROM UpdateHistory WHERE workId = :workId";
    $Stmt = $Pdo->prepare($Sql);
    $Stmt->execute([':workId' => $WorkId]);

    Database::Commit();

    Logger::Info($FunctionName, '全削除処理終了', $UserId);

    Response::Success([
        'Message' => '更新履歴を削除しました'
    ]);

} catch (Exception $E) {
    Database::Rollback();
    Logger::Error($FunctionName, '全削除処理エラー', $UserId, $E->getMessage());
    Response::Error('更新履歴の削除に失敗しました');
}
