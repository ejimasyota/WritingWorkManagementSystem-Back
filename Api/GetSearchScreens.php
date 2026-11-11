<?php
/** 検索対象の画面名だけを返す。SQL用のテーブル・列定義はサーバー内で保持する。 */
require_once __DIR__ . '/../Common/Auth.php';
require_once __DIR__ . '/../Common/Response.php';
require_once __DIR__ . '/../Common/Logger.php';
require_once __DIR__ . '/../Common/SearchScreens.php';
if ($_SERVER['REQUEST_METHOD'] !== 'GET') Response::Error('不正なリクエストです', 405);
try {
    $Config = SearchScreens::Load();
    Response::Success(array_map(static fn(array $Screen): array => [
        'MenuId' => $Screen['MenuId'], 'ScreenName' => $Screen['ScreenName']
    ], $Config['Screens']));
} catch (Exception $E) {
    Logger::Error('キーワード検索', '画面定義取得エラー', $AuthUserId, $E->getMessage());
    Response::Error('画面定義の読込に失敗しました', 500);
}
