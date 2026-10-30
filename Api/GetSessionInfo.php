<?php
/**
 * GetSessionInfo.php
 * セッション情報取得API
 *
 * サーバーサイドセッションから認証済みユーザーの情報を返す。
 * フロントエンドが画面ロード時に呼び出し、管理者状態などを取得する。
 */

require_once __DIR__ . '/../Common/Database.php';
require_once __DIR__ . '/../Common/Logger.php';
require_once __DIR__ . '/../Common/Response.php';
require_once __DIR__ . '/../Common/Auth.php';

// GETメソッドのみ許可
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    Response::Error('不正なリクエストです', 405);
}

Response::Success([
    'UserId' => $AuthUserId,
    'IsAdmin' => $IsAuthAdmin
]);
