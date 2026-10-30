<?php
/**
 * DismissSystemNotification.php
 * システム通知削除API（ユーザ単位）
 *
 * 通知パネルの各システム通知にある「削除」ボタン押下時に呼び出す。
 * マスタは削除せず、SystemNotificationDismiss に (notificationId, userId) を INSERT する。
 * 既に削除済みなら NOOP。
 */

require_once __DIR__ . '/../Common/Database.php';
require_once __DIR__ . '/../Common/Logger.php';
require_once __DIR__ . '/../Common/Response.php';
require_once __DIR__ . '/../Common/Auth.php';

// POSTメソッドのみ許可
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::Error('不正なリクエストです', 405);
}

$FunctionName = '通知';
$UserId = $AuthUserId;

try {
    $Input = Response::GetJsonInput();
    $NotificationId = $Input['NotificationId'] ?? null;

    if (empty($NotificationId)) {
        Response::Error('通知IDが指定されていません');
    }

    $Pdo = Database::GetConnection();

    // 存在チェック（削除済み or 存在しない通知への挿入を防止）
    $CheckStmt = $Pdo->prepare('SELECT 1 FROM SystemNotification WHERE notificationId = :notificationId');
    $CheckStmt->execute([':notificationId' => $NotificationId]);
    if (!$CheckStmt->fetch()) {
        Response::Error('対象の通知が見つかりません', 404);
    }

    // ON CONFLICT DO NOTHING で二重押下も安全
    $Sql = "
        INSERT INTO SystemNotificationDismiss (notificationId, userId)
        VALUES (:notificationId, :userId)
        ON CONFLICT (notificationId, userId) DO NOTHING
    ";
    $Stmt = $Pdo->prepare($Sql);
    $Stmt->execute([
        ':notificationId' => $NotificationId,
        ':userId'         => $UserId,
    ]);

    Response::Success(null, '通知を削除しました');

} catch (Exception $E) {
    Logger::Error($FunctionName, 'システム通知削除エラー', $UserId, $E->getMessage());
    Response::Error('通知の削除に失敗しました', 500);
}
