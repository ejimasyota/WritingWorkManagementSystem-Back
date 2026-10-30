<?php
/**
 * ChangePassword.php
 * パスワード変更API
 */

require_once __DIR__ . '/../Common/Database.php';
require_once __DIR__ . '/../Common/Logger.php';
require_once __DIR__ . '/../Common/Response.php';
require_once __DIR__ . '/../Common/PasswordPolicy.php';
require_once __DIR__ . '/../Common/Auth.php';

// POSTメソッドのみ許可
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::Error('不正なリクエストです', 405);
}

$FunctionName = 'パスワード変更';
$Input = Response::GetJsonInput();
$UserId = $AuthUserId;
$CurrentPassword = $Input['CurrentPassword'] ?? '';
$NewPassword = $Input['NewPassword'] ?? '';

try {
    Logger::Info($FunctionName, '変更処理開始', $UserId);

    if (empty($UserId) || empty($CurrentPassword) || empty($NewPassword)) {
        Response::Error('ユーザーIDとパスワードを入力してください');
    }

    // 新パスワード強度チェック
    $PolicyError = PasswordPolicy::Validate($NewPassword);
    if ($PolicyError !== null) {
        Response::Error($PolicyError);
    }

    $Db = Database::GetConnection();

    $Stmt = $Db->prepare('
        SELECT passwordHash
        FROM UserInfo
        WHERE userId = :userId
    ');
    $Stmt->execute([':userId' => $UserId]);
    $User = $Stmt->fetch();

    if (!$User) {
        Logger::Info($FunctionName, '変更失敗：ユーザーが存在しません', $UserId);
        Response::Error('入力されたユーザーIDが存在しません');
    }

    if (!password_verify($CurrentPassword, $User['passwordhash'])) {
        Logger::Info($FunctionName, '変更失敗：現在のパスワード不一致', $UserId);
        Response::Error('現在のパスワードが正しくありません');
    }

    if (password_verify($NewPassword, $User['passwordhash'])) {
        Logger::Info($FunctionName, '変更失敗：同一パスワード指定', $UserId);
        Response::Error('パスワードを変更してください');
    }

    $PasswordHash = password_hash($NewPassword, PASSWORD_DEFAULT);

    $UpdateStmt = $Db->prepare('
        UPDATE UserInfo
        SET passwordHash = :passwordHash,
            updateDate = current_timestamp(3),
            updateUser = :updateUser
        WHERE userId = :userId
    ');

    $UpdateStmt->execute([
        ':passwordHash' => $PasswordHash,
        ':updateUser' => $UserId,
        ':userId' => $UserId
    ]);

    // 盗まれたセッションによる継続アクセスを防ぐため、セッションIDを再生成
    session_regenerate_id(true);

    Logger::Info($FunctionName, '変更処理終了', $UserId);

    Response::Success(null, 'パスワードを変更しました');

} catch (Exception $E) {
    Logger::Error($FunctionName, '変更処理エラー', $UserId, $E->getMessage());
    Response::Error('パスワード変更中にエラーが発生しました', 500);
}

