<?php
/**
 * Registration.php
 * 会員登録API
 */

require_once __DIR__ . '/../Common/Database.php';
require_once __DIR__ . '/../Common/Logger.php';
require_once __DIR__ . '/../Common/Response.php';
require_once __DIR__ . '/../Common/Csrf.php';
require_once __DIR__ . '/../Common/PasswordPolicy.php';

// POSTメソッドのみ許可
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::Error('不正なリクエストです', 405);
}

$FunctionName = '会員登録';
$Input = Response::GetJsonInput();
$UserId = $Input['UserId'] ?? '';
$Password = $Input['Password'] ?? '';

try {
    Logger::Info($FunctionName, '登録処理開始', $UserId);

    // 入力チェック
    if (empty($UserId) || empty($Password)) {
        Response::Error('ユーザーIDとパスワードを入力してください');
    }

    // ユーザーIDの書式チェック
    //   画面側（Registration.html の maxlength と Common.js の ValidateUserId）と同条件だが、
    //   クライアント検証はリクエスト直送で回避できるため API 側でも判定する。
    //   ユーザーIDは一覧画面に表示される値のため、書式を限定しておく。
    if (preg_match('/\A[A-Za-z0-9_-]{8,12}\z/', $UserId) !== 1) {
        Response::Error('ユーザーIDは半角英数字・ハイフン・アンダースコアの8文字以上12文字以内で入力してください');
    }
    if (preg_match('/[0-9]/', $UserId) !== 1) {
        Response::Error('ユーザーIDには数字を1文字以上含めてください');
    }

    // パスワード強度チェック
    $PolicyError = PasswordPolicy::Validate($Password);
    if ($PolicyError !== null) {
        Response::Error($PolicyError);
    }

    $Db = Database::GetConnection();
    Database::BeginTransaction();

    // ユーザーID重複チェック
    $CheckStmt = $Db->prepare('SELECT userId FROM UserInfo WHERE userId = :userId');
    $CheckStmt->execute([':userId' => $UserId]);

    if ($CheckStmt->fetch()) {
        Database::Rollback();
        Logger::Info($FunctionName, '登録失敗：ユーザーID重複', $UserId);
        Response::Error('入力されたユーザーIDは既に使用されています');
    }

    // パスワードをハッシュ化
    $PasswordHash = password_hash($Password, PASSWORD_DEFAULT);

    // ユーザー登録
    $InsertStmt = $Db->prepare('
        INSERT INTO UserInfo (userId, passwordHash, accountLockFlg, registUser, updateUser)
        VALUES (:userId, :passwordHash, FALSE, :registUser, :updateUser)
    ');

    $InsertStmt->execute([
        ':userId' => $UserId,
        ':passwordHash' => $PasswordHash,
        ':registUser' => $UserId,
        ':updateUser' => $UserId
    ]);

    Database::Commit();

    Logger::Info($FunctionName, '登録処理終了', $UserId);

    Response::Success(null, '会員登録が完了しました');

} catch (Exception $E) {
    Database::Rollback();
    Logger::Error($FunctionName, '登録処理エラー', $UserId, $E->getMessage());
    Response::Error('登録処理中にエラーが発生しました', 500);
}
