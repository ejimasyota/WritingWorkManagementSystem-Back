<?php
/**
 * GetUserInfo.php
 * ユーザー情報取得API
 */

require_once __DIR__ . '/../Common/Database.php';
require_once __DIR__ . '/../Common/Logger.php';
require_once __DIR__ . '/../Common/Response.php';
require_once __DIR__ . '/../Common/Auth.php';

// GETメソッドのみ許可
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    Response::Error('不正なリクエストです', 405);
}

$FunctionName = 'ユーザー情報取得';
$UserId = $AuthUserId;

try {
    Logger::Info($FunctionName, '取得処理開始', $UserId);

    if (empty($UserId)) {
        Response::Error('ユーザーIDを指定してください');
    }

    $Db = Database::GetConnection();

    $Stmt = $Db->prepare('
        SELECT
            userId AS "UserId",
            registDate AS "RegistDate"
        FROM UserInfo
        WHERE userId = :userId
    ');

    $Stmt->execute([':userId' => $UserId]);
    $UserInfo = $Stmt->fetch();

    if (!$UserInfo) {
        Logger::Info($FunctionName, '取得失敗：ユーザーが存在しません', $UserId);
        Response::Error('入力されたユーザーIDは存在しません');
    }

    Logger::Info($FunctionName, '取得処理終了', $UserId);

    Response::Success($UserInfo);

} catch (Exception $E) {
    Logger::Error($FunctionName, '取得処理エラー', $UserId, $E->getMessage());
    Response::Error('ユーザー情報の取得中にエラーが発生しました', 500);
}
