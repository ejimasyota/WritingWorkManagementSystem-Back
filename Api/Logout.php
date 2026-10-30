<?php
/**
 * Logout.php
 * ログアウトAPI
 *
 * サーバーサイドのPHPセッションを破棄し、認証セッションクッキーを削除する。
 * 明示的なログアウト操作・セッションタイムアウト後のクリーンアップから呼ばれる。
 */

require_once __DIR__ . '/../Common/Logger.php';
require_once __DIR__ . '/../Common/Response.php';
require_once __DIR__ . '/../Common/Csrf.php';

// セッションCookieのセキュリティ設定
session_set_cookie_params([
    'httponly'  => true,
    'secure'   => ($_SERVER['HTTPS'] ?? '') === 'on',
    'samesite' => 'Strict',
]);

// セッション開始
session_start();

// POSTメソッドのみ許可
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::Error('不正なリクエストです', 405);
}

$FunctionName = 'ログアウト';
$UserId = $_SESSION['UserId'] ?? '';

try {
    Logger::Info($FunctionName, 'ログアウト処理開始', $UserId);

    // セッション情報をクリア
    $_SESSION = [];

    // セッションクッキーを削除
    if (ini_get('session.use_cookies')) {
        $Params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $Params['path'],
            $Params['domain'],
            $Params['secure'],
            $Params['httponly']
        );
    }

    // セッション破棄
    session_destroy();

    Logger::Info($FunctionName, 'ログアウト処理終了', $UserId);

    Response::Success(null, 'ログアウトしました');

} catch (Exception $E) {
    Logger::Error($FunctionName, 'ログアウト処理エラー', $UserId, $E->getMessage());
    Response::Error('ログアウト処理中にエラーが発生しました', 500);
}
