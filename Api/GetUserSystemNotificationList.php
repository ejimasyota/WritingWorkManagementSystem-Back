<?php
/**
 * GetUserSystemNotificationList.php
 * システム通知一覧取得API（一般ユーザー用）
 *
 * ユーザーが通知ダイアログで削除（＝既読化）した通知の一覧。
 * SystemNotificationDismiss を基準に、対応する SystemNotification を結合して返す。
 * （既読済み通知の閲覧用。状態列は持たない）
 */

require_once __DIR__ . '/../Common/Database.php';
require_once __DIR__ . '/../Common/Logger.php';
require_once __DIR__ . '/../Common/Response.php';
require_once __DIR__ . '/../Common/Auth.php';

// GETメソッドのみ許可
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    Response::Error('不正なリクエストです', 405);
}

$FunctionName = '通知';
$UserId = $AuthUserId;

try {
    $Pdo = Database::GetConnection();

    $Stmt = $Pdo->prepare("
        SELECT
            n.notificationId AS \"NotificationId\",
            n.title          AS \"Title\",
            n.message        AS \"Message\",
            n.registDate     AS \"RegistDate\"
        FROM SystemNotificationDismiss d
        JOIN SystemNotification n ON n.notificationId = d.notificationId
        WHERE d.userId = :userId
        ORDER BY n.registDate DESC
    ");
    $Stmt->execute([':userId' => $UserId]);
    $Rows = $Stmt->fetchAll();

    Response::Success($Rows);

} catch (Exception $E) {
    Logger::Error($FunctionName, 'ユーザ用一覧取得エラー', $UserId, $E->getMessage());
    Response::Error('システム通知一覧の取得に失敗しました', 500);
}
