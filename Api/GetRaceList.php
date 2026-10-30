<?php
/**
 * GetRaceList.php
 * 種族一覧取得API
 */

require_once __DIR__ . '/../Common/Database.php';
require_once __DIR__ . '/../Common/Logger.php';
require_once __DIR__ . '/../Common/Response.php';
require_once __DIR__ . '/../Common/Auth.php';

// GETメソッドのみ許可
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    Response::Error('不正なリクエストです', 405);
}

$FunctionName = '種族設定';
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

    /** 種族一覧を取得 */
    $Sql = "
        SELECT
            raceId AS \"RaceId\",
            workId AS \"WorkId\",
            raceName AS \"RaceName\",
            description AS \"Description\",
            physicalFeature AS \"PhysicalFeature\",
            registDate AS \"RegistDate\",
            updateDate AS \"UpdateDate\",
            registUser AS \"RegistUser\",
            updateUser AS \"UpdateUser\"
        FROM RaceInfo
        WHERE workId = :workId
        ORDER BY raceName ASC
    ";

    $Stmt = $Pdo->prepare($Sql);
    $Stmt->execute([':workId' => $WorkId]);
    $RaceList = $Stmt->fetchAll();

    Logger::Info($FunctionName, '一覧取得処理終了', $UserId);

    Response::Success([
        'RaceList' => $RaceList,
        'TotalCount' => count($RaceList)
    ]);

} catch (Exception $E) {
    Logger::Error($FunctionName, '一覧取得処理エラー', $UserId, $E->getMessage());
    Response::Error('種族一覧の取得に失敗しました');
}
