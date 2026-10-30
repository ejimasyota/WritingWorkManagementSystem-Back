<?php
/**
 * MarkWorkAsRead.php
 * 作品既読化API
 *
 * ユーザーが作品の更新履歴画面を表示した時に呼び出され、
 * WorkReadState.lastReadDate を現在時刻で UPSERT する。
 * これ以降、UpdateHistory 由来の作品更新通知は 0 件になる。
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
    $WorkId = $Input['WorkId'] ?? ($AuthWorkId ?? '');

    if (empty($WorkId)) {
        Response::Error('作品IDが指定されていません');
    }

    $Pdo = Database::GetConnection();

    // 参加権限チェック（他人の作品を勝手に既読化しないための保険）
    $PermStmt = $Pdo->prepare('SELECT 1 FROM CreaterList WHERE workId = :workId AND userId = :userId');
    $PermStmt->execute([':workId' => $WorkId, ':userId' => $UserId]);
    if (!$PermStmt->fetch() && !$IsAuthAdmin) {
        Response::Error('この作品にアクセスする権限がありません', 403);
    }

    // UPSERT で lastReadDate を現在時刻に更新
    $Sql = "
        INSERT INTO WorkReadState (userId, workId, lastReadDate)
        VALUES (:userId, :workId, current_timestamp(3))
        ON CONFLICT (userId, workId)
        DO UPDATE SET lastReadDate = current_timestamp(3)
    ";
    $Stmt = $Pdo->prepare($Sql);
    $Stmt->execute([':userId' => $UserId, ':workId' => $WorkId]);

    Response::Success(null);

} catch (Exception $E) {
    Logger::Error($FunctionName, '既読化エラー', $UserId, $E->getMessage());
    Response::Error('既読化に失敗しました', 500);
}
