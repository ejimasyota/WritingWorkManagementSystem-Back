<?php
/**
 * GetBuildList.php
 * 建物一覧取得API
 */

require_once __DIR__ . '/../Common/Database.php';
require_once __DIR__ . '/../Common/Logger.php';
require_once __DIR__ . '/../Common/Response.php';
require_once __DIR__ . '/../Common/Auth.php';

// GETメソッドのみ許可
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    Response::Error('不正なリクエストです', 405);
}

$FunctionName = '建物設定';
$UserId = '';

try {
    /** リクエストデータを取得 */
    $WorkId = $AuthWorkId ?? '';
    $UserId = $AuthUserId;

    Logger::Info($FunctionName, '一覧取得処理開始', $UserId);

    /** 入力チェック */
    if (empty($WorkId)) {
        Response::Error('作品IDが指定されていません');
    }

    /** DB接続 */
    $Pdo = Database::GetConnection();

    /** 建物一覧を取得 */
    $Sql = "
        SELECT
            buildId AS \"BuildId\",
            workId AS \"WorkId\",
            buildName AS \"BuildName\",
            buildDate AS \"BuildDate\",
            height AS \"Height\",
            area AS \"Area\",
            description AS \"Description\",
            imgPath AS \"ImgPath\",
            imgList AS \"ImgList\",
            registDate AS \"RegistDate\",
            updateDate AS \"UpdateDate\",
            registUser AS \"RegistUser\",
            updateUser AS \"UpdateUser\"
        FROM BuildInfo
        WHERE workId = :workId
        ORDER BY buildName ASC
    ";

    $Stmt = $Pdo->prepare($Sql);
    $Stmt->execute([':workId' => $WorkId]);
    $BuildList = $Stmt->fetchAll();

    Logger::Info($FunctionName, '一覧取得処理終了', $UserId);

    Response::Success([
        'BuildList' => $BuildList,
        'TotalCount' => count($BuildList)
    ]);

} catch (Exception $E) {
    Logger::Error($FunctionName, '一覧取得処理エラー', $UserId, $E->getMessage());
    Response::Error('建物一覧の取得に失敗しました');
}
