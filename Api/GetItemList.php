<?php
/**
 * GetItemList.php
 * アイテム一覧取得API
 */

require_once __DIR__ . '/../Common/Database.php';
require_once __DIR__ . '/../Common/Logger.php';
require_once __DIR__ . '/../Common/Response.php';
require_once __DIR__ . '/../Common/Auth.php';

// GETメソッドのみ許可
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    Response::Error('不正なリクエストです', 405);
}

$FunctionName = 'アイテム設定';
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

    /** アイテム一覧を取得 */
    $Sql = "
        SELECT
            i.itemId AS \"ItemId\",
            i.workId AS \"WorkId\",
            i.itemName AS \"ItemName\",
            i.description AS \"Description\",
            i.imgPath AS \"ImgPath\",
            i.imgList AS \"ImgList\",
            i.registDate AS \"RegistDate\",
            i.updateDate AS \"UpdateDate\",
            i.registUser AS \"RegistUser\",
            i.updateUser AS \"UpdateUser\"
        FROM ItemInfo i
        WHERE i.workId = :workId
        ORDER BY i.itemName ASC
    ";

    $Stmt = $Pdo->prepare($Sql);
    $Stmt->execute([':workId' => $WorkId]);
    $ItemList = $Stmt->fetchAll();

    Logger::Info($FunctionName, '一覧取得処理終了', $UserId);

    Response::Success([
        'ItemList' => $ItemList,
        'TotalCount' => count($ItemList)
    ]);

} catch (Exception $E) {
    Logger::Error($FunctionName, '一覧取得処理エラー', $UserId, $E->getMessage());
    Response::Error('アイテム一覧の取得に失敗しました');
}
