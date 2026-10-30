<?php
/**
 * DeleteSystemNotification.php
 * システム通知削除API（管理者用）
 *
 * SystemNotification のマスタ行を削除する。
 * SystemNotificationDismiss はFK CASCADE で自動整理される。
 */

require_once __DIR__ . '/../Common/Database.php';
require_once __DIR__ . '/../Common/Logger.php';
require_once __DIR__ . '/../Common/Response.php';
require_once __DIR__ . '/../Common/Auth.php';

// POSTメソッドのみ許可
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::Error('不正なリクエストです', 405);
}

if (!$IsAuthAdmin) {
    Response::Error('管理者権限が必要です', 403);
}

$FunctionName = 'システム通知削除';
$UserId = $AuthUserId;

try {
    $Input = Response::GetJsonInput();
    $NotificationId = $Input['NotificationId'] ?? null;
    $UpdateDate = $Input['UpdateDate'] ?? null;

    Logger::Info($FunctionName, '削除処理開始', $UserId);

    if (empty($NotificationId)) {
        Response::Error('通知IDが指定されていません');
    }
    if (empty($UpdateDate)) {
        Response::Error('更新年月日が指定されていません');
    }

    $Pdo = Database::GetConnection();

    $AdminStmt = $Pdo->prepare('SELECT userId FROM AdminInfo WHERE userId = :userId');
    $AdminStmt->execute([':userId' => $UserId]);
    if (!$AdminStmt->fetch()) {
        Response::Error('管理者権限が必要です', 403);
    }

    Database::BeginTransaction();

    $Sql = "DELETE FROM SystemNotification WHERE notificationId = :notificationId AND updateDate = :updateDate";
    $Stmt = $Pdo->prepare($Sql);
    $Stmt->execute([
        ':notificationId' => $NotificationId,
        ':updateDate'     => $UpdateDate,
    ]);
    if ($Stmt->rowCount() === 0) {
        Database::Rollback();
        Response::ConflictError();
    }

    Database::Commit();

    Logger::Info($FunctionName, '削除処理終了', $UserId);

    Response::Success(['Message' => 'システム通知を削除しました']);

} catch (Exception $E) {
    Database::Rollback();
    Logger::Error($FunctionName, '削除処理エラー', $UserId, $E->getMessage());
    Response::Error('システム通知の削除に失敗しました', 500);
}
