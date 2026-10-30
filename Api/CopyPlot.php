<?php
/**
 * CopyPlot.php
 * プロットコピーAPI
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
$Operation = 'コピー';

try {
    $Input = Response::GetJsonInput();
    $WorkId = $AuthWorkId ?? '';
    $PlotId = $Input['PlotId'] ?? null;
    $UpdateDate = $Input['UpdateDate'] ?? null;
    $UserId = $AuthUserId;

    Logger::Info($FunctionName, $Operation . '処理開始', $UserId);

    if (empty($WorkId)) {
        Response::Error('作品IDが指定されていません');
    }
    if (empty($PlotId)) {
        Response::Error('プロットIDが指定されていません');
    }

    $Pdo = Database::GetConnection();
    Database::BeginTransaction();

    $SourceInfoStmt = $Pdo->prepare("
        SELECT plotId, plotName, productionType, updateDate
        FROM PlotInfo
        WHERE plotId = :plotId AND workId = :workId
    ");
    $SourceInfoStmt->execute([
        ':plotId' => $PlotId,
        ':workId' => $WorkId
    ]);
    $SourceInfo = $SourceInfoStmt->fetch();
    if (!$SourceInfo) {
        Database::Rollback();
        Response::Error('対象のプロットが見つかりません');
    }
    if ($UpdateDate !== null && $UpdateDate !== '' && (string)$SourceInfo['updatedate'] !== (string)$UpdateDate) {
        Database::Rollback();
        Response::ConflictError();
    }

    $CopySuffix = '＿コピー';
    $MaxNameLength = 100;
    $SourceName = (string)$SourceInfo['plotname'];
    $SuffixLength = mb_strlen($CopySuffix);
    if (mb_strlen($SourceName) + $SuffixLength > $MaxNameLength) {
        $SourceName = mb_substr($SourceName, 0, $MaxNameLength - $SuffixLength);
    }
    $NewPlotName = $SourceName . $CopySuffix;

    $InsertInfoStmt = $Pdo->prepare("
        INSERT INTO PlotInfo (
            plotName, productionType, workId,
            registDate, updateDate, registUser, updateUser
        ) VALUES (
            :plotName, :productionType, :workId,
            CURRENT_TIMESTAMP(3), CURRENT_TIMESTAMP(3), :registUser, :updateUser
        )
        RETURNING plotId, updateDate
    ");
    $InsertInfoStmt->execute([
        ':plotName' => $NewPlotName,
        ':productionType' => $SourceInfo['productiontype'],
        ':workId' => $WorkId,
        ':registUser' => $UserId,
        ':updateUser' => $UserId
    ]);
    $NewInfo = $InsertInfoStmt->fetch();
    $NewPlotId = $NewInfo['plotid'];
    $NewUpdateDate = $NewInfo['updatedate'];

    $SourceRowsStmt = $Pdo->prepare("
        SELECT indexNum, structureType, content
        FROM PlotRows
        WHERE plotId = :plotId
        ORDER BY indexNum ASC
    ");
    $SourceRowsStmt->execute([':plotId' => $PlotId]);
    $SourceRows = $SourceRowsStmt->fetchAll();

    if (!empty($SourceRows)) {
        $InsertRowStmt = $Pdo->prepare("
            INSERT INTO PlotRows (
                plotId, indexNum, structureType, content,
                registDate, updateDate, registUser, updateUser
            ) VALUES (
                :plotId, :indexNum, :structureType, :content,
                CURRENT_TIMESTAMP(3), CURRENT_TIMESTAMP(3), :registUser, :updateUser
            )
        ");
        foreach ($SourceRows as $Row) {
            $InsertRowStmt->execute([
                ':plotId' => $NewPlotId,
                ':indexNum' => (int)$Row['indexnum'],
                ':structureType' => $Row['structuretype'] !== null ? (int)$Row['structuretype'] : null,
                ':content' => $Row['content'],
                ':registUser' => $UserId,
                ':updateUser' => $UserId
            ]);
        }
    }

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

    $HistoryStmt = $Pdo->prepare("
        INSERT INTO UpdateHistory (
            workId, functionName, operation, registDate, updateDate, registUser, updateUser
        ) VALUES (
            :workId, :functionName, :operation, CURRENT_TIMESTAMP(3), CURRENT_TIMESTAMP(3), :registUser, :updateUser
        )
    ");
    $HistoryStmt->execute([
        ':workId' => $WorkId,
        ':functionName' => $FunctionName,
        ':operation' => $Operation,
        ':registUser' => $UserId,
        ':updateUser' => $UserId
    ]);

    Database::Commit();

    Logger::Info($FunctionName, $Operation . '処理終了', $UserId);

    Response::Success([
        'PlotId' => $NewPlotId,
        'PlotName' => $NewPlotName,
        'UpdateDate' => $NewUpdateDate,
        'Message' => 'プロットを' . $Operation . 'しました'
    ]);

} catch (Exception $E) {
    Database::Rollback();
    Logger::Error($FunctionName, 'コピー処理エラー', $UserId, $E->getMessage());
    Response::Error('プロットのコピーに失敗しました');
}
