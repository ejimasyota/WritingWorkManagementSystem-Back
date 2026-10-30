<?php
/**
 * DeleteWbsInfo.php
 * WBS削除API
 *
 * 削除は作成者・管理者のみ実行できる。
 * WBSを削除した場合、配下のタスク（およびその子タスク・担当者の紐づけ）は
 * 外部キーの CASCADE により併せて削除される。
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
    $WbsId = $Input['WbsId'] ?? null;
    $WorkId = $AuthWorkId ?? '';
    $UserId = $AuthUserId;
    $UpdateDate = $Input['UpdateDate'] ?? null;

    Logger::Info($FunctionName, 'WBS削除処理開始', $UserId);

    /** 入力チェック */
    if (empty($WbsId)) {
        Response::Error('WBS IDが指定されていません');
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
        Response::Error('WBSを削除する権限がありません', 403);
    }

    Database::BeginTransaction();

    /** 削除対象のWBSが存在するか確認 */
    $CheckStmt = $Pdo->prepare("
        SELECT wbsId, wbsName FROM WbsInfo
        WHERE wbsId = :wbsId AND workId = :workId
    ");
    $CheckStmt->execute([':wbsId' => $WbsId, ':workId' => $WorkId]);
    $WbsRow = $CheckStmt->fetch();

    if (!$WbsRow) {
        Database::Rollback();
        Response::Error('削除対象のWBSが見つかりません');
    }

    /** 配下のタスク件数を取得（メッセージで併せて通知する） */
    $TaskCountStmt = $Pdo->prepare("
        SELECT COUNT(*) AS cnt FROM WbsTaskInfo
        WHERE wbsId = :wbsId AND workId = :workId
    ");
    $TaskCountStmt->execute([':wbsId' => $WbsId, ':workId' => $WorkId]);
    $TaskCount = (int)$TaskCountStmt->fetch()['cnt'];

    // 更新履歴の詳細メッセージを生成（削除。WBS名を名称として使用）
    $Detail = HistoryDetail::Delete($WbsRow['wbsname'] ?? null);

    /** WBSを削除（配下のタスクは CASCADE で削除される） */
    $Stmt = $Pdo->prepare("
        DELETE FROM WbsInfo
        WHERE wbsId = :wbsId AND workId = :workId AND updateDate = :updateDate
    ");
    $Stmt->execute([
        ':wbsId' => $WbsId,
        ':workId' => $WorkId,
        ':updateDate' => $UpdateDate
    ]);
    if ($Stmt->rowCount() === 0) {
        Database::Rollback();
        Response::ConflictError();
    }

    /** 作品情報の更新年月日を更新 */
    $UpdateWorkStmt = $Pdo->prepare("
        UPDATE WorkInfo SET
            updateDate = CURRENT_TIMESTAMP(3),
            updateUser = :updateUser
        WHERE workId = :workId
    ");
    $UpdateWorkStmt->execute([
        ':workId' => $WorkId,
        ':updateUser' => $UserId
    ]);

    /** 更新履歴を登録 */
    $HistoryStmt = $Pdo->prepare("
        INSERT INTO UpdateHistory (
            workId, functionName, operation, detail, registDate, updateDate, registUser, updateUser
        ) VALUES (
            :workId, :functionName, :operation, :detail, CURRENT_TIMESTAMP(3), CURRENT_TIMESTAMP(3), :registUser, :updateUser
        )
    ");
    $HistoryStmt->execute([
        ':workId' => $WorkId,
        ':functionName' => $FunctionName,
        ':operation' => '削除',
        ':detail' => $Detail,
        ':registUser' => $UserId,
        ':updateUser' => $UserId
    ]);

    Database::Commit();

    Logger::Info($FunctionName, 'WBS削除処理終了', $UserId);

    Response::Success([
        'Message' => $TaskCount > 0
            ? 'WBSと配下のタスク' . $TaskCount . '件を削除しました'
            : 'WBSを削除しました'
    ]);

} catch (Exception $E) {
    Database::Rollback();
    Logger::Error($FunctionName, 'WBS削除処理エラー', $UserId, $E->getMessage());
    Response::Error('WBSの削除に失敗しました');
}
