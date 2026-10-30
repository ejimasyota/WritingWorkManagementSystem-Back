<?php
/**
 * GetCurrentWork.php
 * サーバーサイドセッションに保存されている現在の作品IDを返すAPI
 *
 * 用途: 作品管理画面のページ初期化時、フロントがメモリ上に CurrentWorkId を持つために呼び出す。
 *       未設定なら null を返却 → フロントは作品一覧画面へ戻す。
 */

require_once __DIR__ . '/../Common/Response.php';
require_once __DIR__ . '/../Common/Auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    Response::Error('不正なリクエストです', 405);
}

Response::Success([
    'WorkId' => $AuthWorkId
]);
