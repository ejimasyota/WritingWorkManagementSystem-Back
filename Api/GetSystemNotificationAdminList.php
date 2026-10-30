<?php
/**
 * GetSystemNotificationAdminList.php
 * システム通知一覧取得API（管理者用）
 *
 * 管理者向けに、有効/期限切れを含む全てのシステム通知一覧を返す。
 * 管理画面で新規追加・編集・削除の操作対象一覧として使用する。
 */

require_once __DIR__ . '/../Common/Database.php';
require_once __DIR__ . '/../Common/Logger.php';
require_once __DIR__ . '/../Common/Response.php';
require_once __DIR__ . '/../Common/Auth.php';

// GETメソッドのみ許可
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    Response::Error('不正なリクエストです', 405);
}

if (!$IsAuthAdmin) {
    Response::Error('管理者権限が必要です', 403);
}

$FunctionName = 'システム通知一覧';
$UserId = $AuthUserId;

try {
    Logger::Info($FunctionName, '取得処理開始', $UserId);

    $Pdo = Database::GetConnection();

    $Stmt = $Pdo->prepare("
        SELECT
            n.notificationId AS \"NotificationId\",
            n.title          AS \"Title\",
            n.message        AS \"Message\",
            n.expireDate     AS \"ExpireDate\",
            n.registDate     AS \"RegistDate\",
            n.updateDate     AS \"UpdateDate\",
            n.registUser     AS \"RegistUser\",
            n.updateUser     AS \"UpdateUser\",
            (n.expireDate IS NOT NULL AND n.expireDate <= current_timestamp(3)) AS \"IsExpired\"
        FROM SystemNotification n
        ORDER BY n.registDate DESC
    ");
    $Stmt->execute();
    $Rows = $Stmt->fetchAll();

    Logger::Info($FunctionName, '取得処理終了', $UserId);

    Response::Success($Rows);

} catch (Exception $E) {
    Logger::Error($FunctionName, '取得処理エラー', $UserId, $E->getMessage());
    Response::Error('システム通知一覧の取得に失敗しました', 500);
}
