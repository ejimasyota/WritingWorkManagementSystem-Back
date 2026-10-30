<?php
/**
 * GetWorkInfo.php
 * 作品情報取得API
 */

require_once __DIR__ . '/../Common/Database.php';
require_once __DIR__ . '/../Common/Logger.php';
require_once __DIR__ . '/../Common/Response.php';
require_once __DIR__ . '/../Common/Auth.php';

// GETメソッドのみ許可
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    Response::Error('不正なリクエストです', 405);
}

$FunctionName = '作品情報取得';
$WorkId = $AuthWorkId ?? '';

try {
    Logger::Info($FunctionName, '取得処理開始');

    if (empty($WorkId)) {
        Response::Error('作品IDが指定されていません');
    }

    $Db = Database::GetConnection();

    // 作品情報を取得
    $Stmt = $Db->prepare('
        SELECT
            W.workId AS "WorkId",
            W.workTitle AS "WorkTitle",
            W.genre AS "Genre",
            W.projectLockFlg AS "ProjectLockFlg",
            W.description AS "Description",
            W.registDate AS "RegistDate",
            W.updateDate AS "UpdateDate",
            W.registUser AS "RegistUser",
            W.updateUser AS "UpdateUser",
            S.settingDate AS "SettingDate",
            S.motif AS "Motif",
            S.theme AS "Theme"
        FROM WorkInfo W
        LEFT JOIN SettingInfo S ON W.workId = S.workId
        WHERE W.workId = :workId
        ORDER BY W.registDate DESC
    ');

    $Stmt->execute([':workId' => $WorkId]);
    $Work = $Stmt->fetch();

    if (!$Work) {
        Response::Error('作品が見つかりません');
    }

    Logger::Info($FunctionName, '取得処理終了');

    Response::Success($Work);

} catch (Exception $E) {
    Logger::Error($FunctionName, '取得処理エラー', '', $E->getMessage());
    Response::Error('作品情報の取得中にエラーが発生しました', 500);
}
