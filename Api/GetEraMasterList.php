<?php
/**
 * GetEraMasterList.php
 * 元号マスタ一覧取得API（管理者用）
 *
 * 管理者が管理する全体共通の実元号マスタの一覧を返す。
 * 管理画面で新規追加・編集・削除の操作対象一覧として使用する。
 */

require_once __DIR__ . '/../Common/Database.php';
require_once __DIR__ . '/../Common/Logger.php';
require_once __DIR__ . '/../Common/Response.php';
require_once __DIR__ . '/../Common/Auth.php';

// GETメソッドのみ許可
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    Response::Error('不正なリクエストです', 405);
}

// 管理者権限チェック（セッション + AdminInfo 実表照合）
if (!$IsAuthAdmin) {
    Response::Error('管理者権限が必要です', 403);
}

$FunctionName = '元号マスタ一覧';
$UserId = $AuthUserId;

try {
    Logger::Info($FunctionName, '取得処理開始', $UserId);

    $Pdo = Database::GetConnection();

    $Stmt = $Pdo->prepare("
        SELECT
            eraMasterId AS \"EraMasterId\",
            eraName     AS \"EraName\",
            startYear   AS \"StartYear\",
            endYear     AS \"EndYear\",
            registDate  AS \"RegistDate\",
            updateDate  AS \"UpdateDate\",
            registUser  AS \"RegistUser\",
            updateUser  AS \"UpdateUser\"
        FROM EraMasterInfo
        ORDER BY eraMasterId DESC
    ");
    $Stmt->execute();
    $Rows = $Stmt->fetchAll();

    Logger::Info($FunctionName, '取得処理終了', $UserId);

    Response::Success($Rows);

} catch (Exception $E) {
    Logger::Error($FunctionName, '取得処理エラー', $UserId, $E->getMessage());
    Response::Error('元号マスタ一覧の取得に失敗しました', 500);
}
