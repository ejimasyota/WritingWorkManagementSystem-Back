<?php
/**
 * GetReviewList.php
 * 作品レビュー一覧取得API
 */

require_once __DIR__ . '/../Common/Database.php';
require_once __DIR__ . '/../Common/Logger.php';
require_once __DIR__ . '/../Common/Response.php';
require_once __DIR__ . '/../Common/Auth.php';

/**
 * 作品レビュー一覧を取得する
 */
function GetReviewList(): void
{
    global $AuthUserId;
    global $AuthWorkId;
    // POSTメソッドのみ許可
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        Response::Error('不正なリクエストです', 405);
        return;
    }
    $FunctionName = '作品レビュー';
    $UserId = '';

    try {
        /** リクエストデータを取得 */
        $Input = Response::GetJsonInput();
        $WorkId = $AuthWorkId ?? '';
        $UserId = $AuthUserId;

        Logger::Info($FunctionName, '一覧取得処理開始', $UserId);

        /** 入力チェック */
        if (empty($WorkId)) {
            Response::Error('作品IDが指定されていません');
            return;
        }

        /** DB接続 */
        $Pdo = Database::GetConnection();

        /** 作品レビュー一覧を取得 */
        $Sql = "
            SELECT
                reviewId AS \"ReviewId\",
                workId AS \"WorkId\",
                reviewerName AS \"ReviewerName\",
                reviewContent AS \"ReviewContent\",
                evaluation AS \"Evaluation\",
                registDate AS \"RegistDate\",
                updateDate AS \"UpdateDate\",
                registUser AS \"RegistUser\",
                updateUser AS \"UpdateUser\"
            FROM ReviewInfo
            WHERE workId = :workId
            ORDER BY registDate DESC, reviewId DESC
        ";

        $Stmt = $Pdo->prepare($Sql);
        $Stmt->execute([':workId' => $WorkId]);
        $ReviewList = $Stmt->fetchAll();

        Logger::Info($FunctionName, '一覧取得処理終了', $UserId);

        Response::Success([
            'ReviewList' => $ReviewList,
            'TotalCount' => count($ReviewList)
        ]);

    } catch (Exception $E) {
        Logger::Error($FunctionName, '一覧取得処理エラー', $UserId, $E->getMessage());
        Response::Error('作品レビュー一覧の取得に失敗しました');
    }
}

/** API実行 */
GetReviewList();
