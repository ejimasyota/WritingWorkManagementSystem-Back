<?php
/**
 * DeleteWbsTask.php
 * WBSタスク削除API
 *
 * 削除は作成者・管理者のみ実行できる（参加メンバーは削除不可）。
 * 親タスクを削除した場合、配下の子タスクは外部キーの CASCADE により併せて削除される。
 */

require_once __DIR__ . '/../Common/Database.php';
require_once __DIR__ . '/../Common/Logger.php';
require_once __DIR__ . '/../Common/Response.php';
require_once __DIR__ . '/../Common/Auth.php';
require_once __DIR__ . '/../Common/HistoryDetail.php';

// POSTメソッドのみ許可
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::Error('不正なリクエストです', 405);
}

$FunctionName = 'WBS';
$UserId = '';

try {
    /** リクエストデータを取得 */
    $Input = Response::GetJsonInput();
    $TaskId = $Input['TaskId'] ?? null;
    $WorkId = $AuthWorkId ?? '';
    $UserId = $AuthUserId;
    $UpdateDate = $Input['UpdateDate'] ?? null;

    Logger::Info($FunctionName, '削除処理開始', $UserId);

    /** 入力チェック */
    if (empty($TaskId)) {
        Response::Error('タスクIDが指定されていません');
    }

    if (empty($WorkId)) {
        Response::Error('作品IDが指定されていません');
    }

    /** DB接続 */
    $Pdo = Database::GetConnection();

    /* ==========================================================================
     * 権限判定（作成者・管理者のみ削除可能）
     * ========================================================================== */
    $PermStmt = $Pdo->prepare("
        SELECT isCreator FROM CreaterList
        WHERE workId = :workId AND userId = :userId
    ");
    $PermStmt->execute([':workId' => $WorkId, ':userId' => $UserId]);
    $PermRow = $PermStmt->fetch();
    $IsCreator = $PermRow ? (bool)$PermRow['iscreator'] : false;

    $AdminStmt = $Pdo->prepare("SELECT userId FROM AdminInfo WHERE userId = :userId");
    $AdminStmt->execute([':userId' => $UserId]);
    $IsAdmin = $AdminStmt->fetch() ? true : false;

    if (!$IsCreator && !$IsAdmin) {
        Response::Error('タスクを削除する権限がありません', 403);
    }

    Database::BeginTransaction();

    /** 削除対象のタスクが存在するか確認 */
    $CheckSql = "SELECT taskId, taskName FROM WbsTaskInfo WHERE taskId = :taskId AND workId = :workId";
    $CheckStmt = $Pdo->prepare($CheckSql);
    $CheckStmt->execute([':taskId' => $TaskId, ':workId' => $WorkId]);
    $TaskInfo = $CheckStmt->fetch();

    if (!$TaskInfo) {
        Database::Rollback();
        Response::Error('削除対象のタスクが見つかりません');
    }

    /** 配下の子タスク件数を取得（メッセージで併せて通知する） */
    $ChildCountStmt = $Pdo->prepare("
        SELECT COUNT(*) AS cnt FROM WbsTaskInfo
        WHERE parentTaskId = :taskId AND workId = :workId
    ");
    $ChildCountStmt->execute([':taskId' => $TaskId, ':workId' => $WorkId]);
    $ChildCount = (int)$ChildCountStmt->fetch()['cnt'];

    // 更新履歴の詳細メッセージを生成（削除。タスク名を名称として使用）
    $Detail = HistoryDetail::Delete($TaskInfo['taskname'] ?? null);

    /** タスクを削除（子タスクは CASCADE で削除される） */
    $Sql = "DELETE FROM WbsTaskInfo WHERE taskId = :taskId AND workId = :workId AND updateDate = :updateDate";
    $Stmt = $Pdo->prepare($Sql);
    $Stmt->execute([
        ':taskId' => $TaskId,
        ':workId' => $WorkId,
        ':updateDate' => $UpdateDate
    ]);
    if ($Stmt->rowCount() === 0) {
        Database::Rollback();
        Response::ConflictError();
    }

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
        'Message' => $ChildCount > 0
            ? 'タスクと配下の子タスク' . $ChildCount . '件を削除しました'
            : 'タスクを削除しました'
    ]);

} catch (Exception $E) {
    Database::Rollback();
    Logger::Error($FunctionName, '削除処理エラー', $UserId, $E->getMessage());
    Response::Error('タスクの削除に失敗しました');
}
