<?php
/**
 * DeleteChapter.php
 * チャプター削除API
 */

require_once __DIR__ . '/../Common/Database.php';
require_once __DIR__ . '/../Common/Logger.php';
require_once __DIR__ . '/../Common/Response.php';
require_once __DIR__ . '/../Common/Auth.php';
// 更新履歴 詳細メッセージ生成
require_once __DIR__ . '/../Common/HistoryDetail.php';
// チャプターの並び順 正規化
require_once __DIR__ . '/../Common/ChapterOrder.php';

/**
 * チャプターを削除する
 */
function DeleteChapter(): void
{
    global $AuthUserId;
    global $AuthWorkId;
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        // 1. エラーを返す
        Response::Error('不正なリクエストです', 405);
    }
    $FunctionName = 'チャプター設定';
    $UserId = '';

    try {
        /** リクエストデータを取得 */
        $Input = Response::GetJsonInput();
        $ChapterId = $Input['ChapterId'] ?? null;
        $WorkId = $AuthWorkId ?? '';
        $UserId = $AuthUserId;
        $UpdateDate = $Input['UpdateDate'] ?? null;

        Logger::Info($FunctionName, '削除処理開始', $UserId);

        /** 入力チェック */
        if (empty($ChapterId)) {
            Response::Error('チャプターIDが指定されていません');
            return;
        }

        if (empty($WorkId)) {
            Response::Error('作品IDが指定されていません');
            return;
        }

        /** DB接続 */
        $Pdo = Database::GetConnection();
        Database::BeginTransaction();

        /** 削除詳細メッセージ用にチャプター名を取得 */
        $NameStmt = $Pdo->prepare("SELECT chapterName FROM ChapterInfo WHERE chapterId = :chapterId AND workId = :workId");
        $NameStmt->execute([':chapterId' => $ChapterId, ':workId' => $WorkId]);
        $NameRow = $NameStmt->fetch();
        $Detail = HistoryDetail::Delete($NameRow['chaptername'] ?? null);

        /** チャプターを削除 */
        $Sql = "DELETE FROM ChapterInfo WHERE chapterId = :chapterId AND workId = :workId AND updateDate = :updateDate";
        $Stmt = $Pdo->prepare($Sql);
        $Stmt->execute([
            ':chapterId' => $ChapterId,
            ':workId' => $WorkId,
            ':updateDate' => $UpdateDate
        ]);
        if ($Stmt->rowCount() === 0) {
            Database::Rollback();
            Response::ConflictError();
            return;
        }

        /** 並び順を正規化（削除により空いた連番を詰める） */
        ChapterOrder::Normalize($Pdo, $WorkId);

        /** 作品情報の更新年月日を更新 */
        $UpdateWorkSql = "
            UPDATE WorkInfo SET
                updateDate = CURRENT_TIMESTAMP(3),
                updateUser = :updateUser
            WHERE workId = :workId
        ";
        $UpdateWorkStmt = $Pdo->prepare($UpdateWorkSql);
        $UpdateWorkStmt->execute([
            ':workId' => $WorkId,
            ':updateUser' => $UserId
        ]);

        /** 更新履歴を登録 */
        $HistorySql = "
            INSERT INTO UpdateHistory (
                workId, functionName, operation, detail, registDate, updateDate, registUser, updateUser
            ) VALUES (
                :workId, :functionName, :operation, :detail, CURRENT_TIMESTAMP(3), CURRENT_TIMESTAMP(3), :registUser, :updateUser
            )
        ";
        $HistoryStmt = $Pdo->prepare($HistorySql);
        $HistoryStmt->execute([
            ':workId' => $WorkId,
            ':functionName' => $FunctionName,
            ':operation' => '削除',
            ':detail' => $Detail,
            ':registUser' => $UserId,
            ':updateUser' => $UserId
        ]);

        Database::Commit();

        Logger::Info($FunctionName, '削除処理終了', $UserId);

        Response::Success([
            'Message' => 'チャプターを削除しました'
        ]);

    } catch (Exception $E) {
        Database::Rollback();
        Logger::Error($FunctionName, '削除処理エラー', $UserId, $E->getMessage());
        Response::Error('チャプターの削除に失敗しました');
    }
}

/** API実行 */
DeleteChapter();
