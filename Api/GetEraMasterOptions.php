<?php
/**
 * GetEraMasterOptions.php
 * 元号マスタ選択肢取得API（元号設定画面「実元号を使用」プルダウン用）
 *
 * 認証済みユーザーが利用可能（管理者限定ではない）。
 * 実元号の名称・開始年・終了年を返す。選択時にフォームへコピーして登録するため、
 * 外部キーは持たない（マスタ更新は既存の作品別元号設定に波及しない）。
 */

require_once __DIR__ . '/../Common/Database.php';
require_once __DIR__ . '/../Common/Logger.php';
require_once __DIR__ . '/../Common/Response.php';
require_once __DIR__ . '/../Common/Auth.php';

// GETメソッドのみ許可
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    Response::Error('不正なリクエストです', 405);
}

$FunctionName = '元号マスタ選択肢';
$UserId = $AuthUserId;

try {
    $Pdo = Database::GetConnection();

    $Stmt = $Pdo->prepare("
        SELECT
            eraMasterId AS \"EraMasterId\",
            eraName     AS \"EraName\",
            startYear   AS \"StartYear\",
            endYear     AS \"EndYear\"
        FROM EraMasterInfo
        ORDER BY startYear, eraMasterId
    ");
    $Stmt->execute();

    Response::Success($Stmt->fetchAll());

} catch (Exception $E) {
    Logger::Error($FunctionName, '取得処理エラー', $UserId, $E->getMessage());
    Response::Error('実元号の取得に失敗しました', 500);
}
