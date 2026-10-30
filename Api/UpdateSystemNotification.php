<?php
/**
 * UpdateSystemNotification.php
 * システム通知編集API（管理者用）
 *
 * 既存のシステム通知の タイトル / 本文 / 期限 を編集する。
 * 楽観ロック（updateDate 一致）採用。
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

$FunctionName = 'システム通知編集';
$UserId = $AuthUserId;

try {
    $Input = Response::GetJsonInput();
    $NotificationId = $Input['NotificationId'] ?? null;
    $Title      = trim($Input['Title']      ?? '');
    $Message    = trim($Input['Message']    ?? '');
    $ExpireDate = $Input['ExpireDate'] ?? null;
    $ExpireDate = !empty($ExpireDate) ? $ExpireDate : null;
    $UpdateDate = $Input['UpdateDate'] ?? null;

    // "YYYY年MM月DD日" 形式（DatePicker 出力）を DB 取り込み可能な ISO 形式に変換
    if ($ExpireDate !== null && preg_match('/^(\d{4})年(\d{1,2})月(\d{1,2})日$/', $ExpireDate, $M)) {
        $ExpireDate = sprintf('%04d-%02d-%02d 23:59:59.999', (int)$M[1], (int)$M[2], (int)$M[3]);
    }

    // 有効期限バリデーション：システム日付以前（当日含む）は不可（防御的重複チェック）
    if ($ExpireDate !== null) {
        $ExpireTs = strtotime($ExpireDate);
        $TodayEndTs = strtotime(date('Y-m-d 23:59:59'));
        if ($ExpireTs === false || $ExpireTs <= $TodayEndTs) {
            Response::Error('有効期限は明日以降の日付を指定してください');
        }
    }

    Logger::Info($FunctionName, '編集処理開始', $UserId);

    if (empty($NotificationId)) {
        Response::Error('通知IDが指定されていません');
    }
    if ($Title === '') {
        Response::Error('タイトルを入力してください');
    }
    if ($Message === '') {
        Response::Error('本文を入力してください');
    }
    if (mb_strlen($Title) > 200) {
        Response::Error('タイトルは200文字以下で入力してください');
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

    $Sql = "
        UPDATE SystemNotification SET
            title       = :title,
            message     = :message,
            expireDate  = :expireDate,
            updateDate  = current_timestamp(3),
            updateUser  = :updateUser
        WHERE notificationId = :notificationId
          AND updateDate = :updateDate
        RETURNING updateDate
    ";
    $Stmt = $Pdo->prepare($Sql);
    $Stmt->execute([
        ':title'          => $Title,
        ':message'        => $Message,
        ':expireDate'     => $ExpireDate,
        ':updateUser'     => $UserId,
        ':notificationId' => $NotificationId,
        ':updateDate'     => $UpdateDate,
    ]);
    $Row = $Stmt->fetch();
    if (!$Row) {
        Database::Rollback();
        Response::ConflictError();
    }

    Database::Commit();

    Logger::Info($FunctionName, '編集処理終了', $UserId);

    Response::Success([
        'UpdateDate' => $Row['updatedate'],
        'Message'    => 'システム通知を更新しました',
    ]);

} catch (Exception $E) {
    Database::Rollback();
    Logger::Error($FunctionName, '編集処理エラー', $UserId, $E->getMessage());
    Response::Error('システム通知の更新に失敗しました', 500);
}
