<?php
/**
 * GetImageList.php
 * 作品内の投稿画像一覧取得API
 *
 * 各設定画面（キャラ/組織/チーム/舞台/建物/武器/アイテム/作品メモ）で投稿された画像
 * （imgPath が設定されているレコード）を横断的に取得する。作品スコープ（$AuthWorkId）。
 */

require_once __DIR__ . '/../Common/Database.php';
require_once __DIR__ . '/../Common/Logger.php';
require_once __DIR__ . '/../Common/Response.php';
require_once __DIR__ . '/../Common/Auth.php';

// GETメソッドのみ許可
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    Response::Error('不正なリクエストです', 405);
}

$FunctionName = '画像一覧';
$UserId = $AuthUserId;
$WorkId = $AuthWorkId ?? '';

try {
    if (empty($WorkId)) {
        Response::Error('作品IDが指定されていません');
    }

    $Pdo = Database::GetConnection();

    // 画像横断ビュー v_workimage（imgPath 保有8テーブルを集約）を参照。作品スコープで絞り込む。
    $Sql = "
        SELECT screen AS \"Screen\", name AS \"Name\", imgPath AS \"ImgPath\"
        FROM v_workimage
        WHERE workId = :workId
        ORDER BY \"Screen\", \"Name\"
    ";
    $Stmt = $Pdo->prepare($Sql);
    $Stmt->execute([
        ':workId' => $WorkId,
    ]);

    Response::Success($Stmt->fetchAll());

} catch (Exception $E) {
    Logger::Error($FunctionName, '取得処理エラー', $UserId, $E->getMessage());
    Response::Error('画像一覧の取得に失敗しました', 500);
}
