<?php
/**
 * GetTagList.php
 * タグ一覧取得API
 */

require_once __DIR__ . '/../Common/Database.php';
require_once __DIR__ . '/../Common/Logger.php';
require_once __DIR__ . '/../Common/Response.php';
require_once __DIR__ . '/../Common/Auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    Response::Error('不正なリクエストです', 405);
}

$FunctionName = 'タグマスタ';
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

    /** タグ一覧を取得 */
    $Sql = "
        SELECT
            t.tagId AS \"TagId\",
            t.workId AS \"WorkId\",
            t.tagName AS \"TagName\",
            t.description AS \"Description\",
            t.tagColor AS \"TagColor\",
            t.registDate AS \"RegistDate\",
            t.updateDate AS \"UpdateDate\",
            t.registUser AS \"RegistUser\",
            t.updateUser AS \"UpdateUser\"
        FROM TagInfo t
        WHERE t.workId = :workId
        ORDER BY t.tagName ASC
    ";

    $Stmt = $Pdo->prepare($Sql);
    $Stmt->execute([':workId' => $WorkId]);
    $TagList = $Stmt->fetchAll();

    Logger::Info($FunctionName, '一覧取得処理終了', $UserId);

    Response::Success([
        'TagList' => $TagList,
        'TotalCount' => count($TagList)
    ]);

} catch (Exception $E) {
    Logger::Error($FunctionName, '一覧取得処理エラー', $UserId, $E->getMessage());
    Response::Error('タグ一覧の取得に失敗しました');
}
