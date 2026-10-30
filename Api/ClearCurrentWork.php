<?php
/**
 * ClearCurrentWork.php
 * サーバーサイドセッションから現在の作品IDをクリアするAPI
 *
 * 用途: 作品管理画面から作品一覧画面に戻る際に呼び出す。
 *       ログアウト時は Logout.php 内で同様の処理を行うため、本APIは不要。
 */

require_once __DIR__ . '/../Common/Response.php';
require_once __DIR__ . '/../Common/Auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::Error('不正なリクエストです', 405);
}

$_SESSION['CurrentWorkId'] = null;

Response::Success(null);
