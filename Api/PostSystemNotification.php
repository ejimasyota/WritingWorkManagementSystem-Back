<?php
/**
 * PostSystemNotification.php
 * システム通知投稿API（管理者用）
 *
 * 管理者が全ユーザ向けのシステム通知を新規投稿する。
 * SystemNotification に 1 行 INSERT するのみで、各ユーザーへの fan-out は行わない。
 * ユーザー側の未読扱いは Get 系 API 側で
 * 「Dismiss していない × notificationBaseDate 以降 × 期限内」で導出する。
 */

require_once __DIR__ . '/../Common/Database.php';
require_once __DIR__ . '/../Common/Logger.php';
require_once __DIR__ . '/../Common/Response.php';
require_once __DIR__ . '/../Common/Auth.php';

// POSTメソッドのみ許可
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::Error('不正なリクエストです', 405);
}

// 管理者権限チェック（セッション + AdminInfo 実表照合）
if (!$IsAuthAdmin) {
    Response::Error('管理者権限が必要です', 403);
}

$FunctionName = 'システム通知投稿';
$UserId = $AuthUserId;

try {
    $Input = Response::GetJsonInput();
    $Title      = trim($Input['Title']      ?? '');
    $Message    = trim($Input['Message']    ?? '');
    $ExpireDate = $Input['ExpireDate'] ?? null;
    $ExpireDate = !empty($ExpireDate) ? $ExpireDate : null;

    // "YYYY年MM月DD日" 形式（DatePicker 出力）を DB 取り込み可能な ISO 形式に変換
    // 有効期限は日単位で「その日の終わり」として扱う
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

    Logger::Info($FunctionName, '投稿処理開始', $UserId);

    if ($Title === '') {
        Response::Error('タイトルを入力してください');
    }
    if ($Message === '') {
        Response::Error('本文を入力してください');
    }
    if (mb_strlen($Title) > 200) {
        Response::Error('タイトルは200文字以下で入力してください');
    }

    $Pdo = Database::GetConnection();

    // 二重防御：AdminInfo 実表照合
    $AdminStmt = $Pdo->prepare('SELECT userId FROM AdminInfo WHERE userId = :userId');
    $AdminStmt->execute([':userId' => $UserId]);
    if (!$AdminStmt->fetch()) {
        Response::Error('管理者権限が必要です', 403);
    }

    Database::BeginTransaction();

    $Sql = "
        INSERT INTO SystemNotification (
            title, message, expireDate, registUser, updateUser
        ) VALUES (
            :title, :message, :expireDate, :registUser, :updateUser
        )
        RETURNING notificationId, registDate, updateDate
    ";
    $Stmt = $Pdo->prepare($Sql);
    $Stmt->execute([
        ':title'      => $Title,
        ':message'    => $Message,
        ':expireDate' => $ExpireDate,
        ':registUser' => $UserId,
        ':updateUser' => $UserId,
    ]);
    $Row = $Stmt->fetch();

    Database::Commit();

    Logger::Info($FunctionName, '投稿処理終了', $UserId);

    Response::Success([
        'NotificationId' => (int)$Row['notificationid'],
        'RegistDate'     => $Row['registdate'],
        'UpdateDate'     => $Row['updatedate'],
        'Message'        => 'システム通知を投稿しました',
    ]);

} catch (Exception $E) {
    Database::Rollback();
    Logger::Error($FunctionName, '投稿処理エラー', $UserId, $E->getMessage());
    Response::Error('システム通知の投稿に失敗しました', 500);
}
