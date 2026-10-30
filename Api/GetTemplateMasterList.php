<?php
/**
 * GetTemplateMasterList.php
 * テンプレートマスタ一覧取得API（管理者用）
 *
 * 管理者が管理する全体共通のテンプレートマスタの一覧を返す。
 * ジャンル × 区分（ストーリー / プロット）で一意のため、
 * 同一ジャンルでもストーリー用・プロット用をそれぞれ登録できる。
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

$FunctionName = 'テンプレートマスタ一覧';
$UserId = $AuthUserId;

try {
    Logger::Info($FunctionName, '取得処理開始', $UserId);

    $Pdo = Database::GetConnection();

    $Stmt = $Pdo->prepare("
        SELECT
            templateId   AS \"TemplateId\",
            genre        AS \"Genre\",
            divisionType AS \"DivisionType\",
            templateText AS \"TemplateText\",
            registDate   AS \"RegistDate\",
            updateDate   AS \"UpdateDate\",
            registUser   AS \"RegistUser\",
            updateUser   AS \"UpdateUser\"
        FROM TemplateMasterInfo
        ORDER BY divisionType ASC, genre ASC
    ");
    $Stmt->execute();
    $Rows = $Stmt->fetchAll();

    Logger::Info($FunctionName, '取得処理終了', $UserId);

    Response::Success($Rows);

} catch (Exception $E) {
    Logger::Error($FunctionName, '取得処理エラー', $UserId, $E->getMessage());
    Response::Error('テンプレートマスタ一覧の取得に失敗しました', 500);
}
