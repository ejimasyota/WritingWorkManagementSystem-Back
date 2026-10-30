<?php
/**
 * UpdatePlotOrder.php
 * プロット順序更新API（ドラッグ&ドロップ対応）
 */

require_once __DIR__ . '/../Common/Database.php';
require_once __DIR__ . '/../Common/Logger.php';
require_once __DIR__ . '/../Common/Response.php';
require_once __DIR__ . '/../Common/Auth.php';

// POSTメソッドのみ許可
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::Error('不正なリクエストです', 405);
}

$FunctionName = 'プロット作成';
$UserId = '';

try {
    $Input = Response::GetJsonInput();
    $WorkId = $AuthWorkId ?? '';
    $PlotId = $Input['PlotId'] ?? null;
    $OrderList = $Input['OrderList'] ?? [];
    $UpdateDate = $Input['UpdateDate'] ?? null;
    $UserId = $AuthUserId;

    Logger::Info($FunctionName, '順序更新処理開始', $UserId);

    if (empty($WorkId)) {
        Response::Error('作品IDが指定されていません');
    }
    if (empty($PlotId)) {
        Response::Error('プロットIDが指定されていません');
    }
    if (empty($OrderList) || !is_array($OrderList)) {
        Response::Error('順序情報が指定されていません');
    }

    $Pdo = Database::GetConnection();
    Database::BeginTransaction();

    // 並び順の同時実行競合を防ぐため、plotIdでアドバイザリロックを取得
    $LockStmt = $Pdo->prepare("SELECT pg_advisory_xact_lock(1002, hashtext(:plotId))");
    $LockStmt->execute([':plotId' => (string)$PlotId]);

    // OrderList が対象プロットの全行を網羅しているか検証
    $CountStmt = $Pdo->prepare("SELECT COUNT(*) AS cnt FROM PlotRows WHERE plotId = :plotId");
    $CountStmt->execute([':plotId' => $PlotId]);
    $CountResult = $CountStmt->fetch();
    if ((int)$CountResult['cnt'] !== count($OrderList)) {
        Database::Rollback();
        Response::Error('順序情報が不完全です。画面をリフレッシュしてください', 409);
    }

    $TempUpdateSql = "
        UPDATE PlotRows SET
            indexNum = :tempIndexNum,
            updateDate = CURRENT_TIMESTAMP(3),
            updateUser = :updateUser
        WHERE plotRowId = :plotRowId AND plotId = :plotId
    ";
    $TempUpdateStmt = $Pdo->prepare($TempUpdateSql);
    foreach ($OrderList as $Order) {
        $TempUpdateStmt->execute([
            ':plotRowId' => $Order['PlotRowId'],
            ':plotId' => $PlotId,
            ':tempIndexNum' => -1 * $Order['IndexNum'],
            ':updateUser' => $UserId
        ]);
    }

    $UpdateSql = "
        UPDATE PlotRows SET
            indexNum = :indexNum,
            structureType = :structureType,
            updateDate = CURRENT_TIMESTAMP(3),
            updateUser = :updateUser
        WHERE plotRowId = :plotRowId AND plotId = :plotId
    ";
    $UpdateStmt = $Pdo->prepare($UpdateSql);
    foreach ($OrderList as $Order) {
        $UpdateStmt->execute([
            ':plotRowId' => $Order['PlotRowId'],
            ':plotId' => $PlotId,
            ':indexNum' => $Order['IndexNum'],
            ':structureType' => $Order['StructureType'],
            ':updateUser' => $UserId
        ]);
    }

    $UpdatePlotSql = "
        UPDATE PlotInfo SET
            updateDate = CURRENT_TIMESTAMP(3),
            updateUser = :updateUser
        WHERE plotId = :plotId AND workId = :workId
          AND updateDate = :updateDate
        RETURNING updateDate
    ";
    $UpdatePlotStmt = $Pdo->prepare($UpdatePlotSql);
    $UpdatePlotStmt->execute([
        ':plotId' => $PlotId,
        ':workId' => $WorkId,
        ':updateUser' => $UserId,
        ':updateDate' => $UpdateDate
    ]);
    $UpdatePlotResult = $UpdatePlotStmt->fetch();

    if (!$UpdatePlotResult) {
        Database::Rollback();
        Response::ConflictError();
    }

    $NewUpdateDate = $UpdatePlotResult['updatedate'];

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

    $HistorySql = "
        INSERT INTO UpdateHistory (
            workId, functionName, operation, registDate, updateDate, registUser, updateUser
        ) VALUES (
            :workId, :functionName, :operation, CURRENT_TIMESTAMP(3), CURRENT_TIMESTAMP(3), :registUser, :updateUser
        )
    ";
    $HistoryStmt = $Pdo->prepare($HistorySql);
    $HistoryStmt->execute([
        ':workId' => $WorkId,
        ':functionName' => $FunctionName,
        ':operation' => '順序更新',
        ':registUser' => $UserId,
        ':updateUser' => $UserId
    ]);

    Database::Commit();

    Logger::Info($FunctionName, '順序更新処理終了', $UserId);

    Response::Success([
        'Message' => 'プロットの順序を更新しました',
        'UpdateDate' => $NewUpdateDate
    ]);

} catch (Exception $E) {
    Database::Rollback();
    Logger::Error($FunctionName, '順序更新処理エラー', $UserId, $E->getMessage());
    Response::Error('プロットの順序更新に失敗しました');
}
