<?php
/**
 * Csrf.php
 * CSRF対策共通処理
 *
 * 状態変更系(POST)リクエストで Origin / Referer ヘッダを検証し、
 * 同一オリジンからのリクエストのみ許可する。
 * SameSite=Strict と併用することで、他サイトからのクロスオリジンPOSTを拒否する。
 *
 * 認証を要する API では Auth.php が本ファイルを読み込むため、
 * Auth.php を読み込む API では明示的に require する必要はない。
 * Auth.php を読み込まない Login.php / Registration.php / Logout.php では
 * 直接 require_once して利用する。
 */

require_once __DIR__ . '/Response.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $Origin  = $_SERVER['HTTP_ORIGIN']  ?? '';
    $Referer = $_SERVER['HTTP_REFERER'] ?? '';
    $Host    = $_SERVER['HTTP_HOST']    ?? '';
    $Scheme  = (($_SERVER['HTTPS'] ?? '') === 'on') ? 'https' : 'http';
    $ExpectedOrigin = $Scheme . '://' . $Host;

    $IsSameOrigin = false;
    if ($Origin !== '') {
        // Origin ヘッダがある場合は厳密比較
        $IsSameOrigin = ($Origin === $ExpectedOrigin);
    } elseif ($Referer !== '') {
        // Origin が無い場合は Referer のホスト部で判定
        $RefererHost = parse_url($Referer, PHP_URL_HOST);
        $IsSameOrigin = ($RefererHost !== null && $RefererHost === $Host);
    }

    if (!$IsSameOrigin) {
        Response::Error('不正なリクエスト元です', 403);
    }
}
