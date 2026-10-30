<?php
/**
 * DeletePlot.php
 * プロット削除API
 */

require_once __DIR__ . '/../Common/Database.php';
require_once __DIR__ . '/../Common/Logger.php';
require_once __DIR__ . '/../Common/Response.php';
require_once __DIR__ . '/../Common/Auth.php';
// 更新履歴 詳細メッセージ生成
require_once __DIR__ . '/../Common/HistoryDetail.php';

// POSTメソッドのみ許可
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::Error('不正なリクエストです', 405);
}

$FunctionName = 'プロット作成';
$UserId = '';

try {
    $Input = Response::GetJsonInput();
    $Mode = $Input['Mode'] ?? 'row';
    $PlotId = $Input['PlotId'] ?? null;
    $PlotRowId = $Input['PlotRowId'] ?? null;
    $WorkId = $AuthWorkId ?? '';
    $UserId = $AuthUserId;
    $UpdateDate = $Input['UpdateDate'] ?? null;
    // 更新履歴の詳細メッセージ（削除対象の名称から生成）
    $Detail = null;

    Logger::Info($FunctionName, '削除処理開始', $UserId);

    if (empty($WorkId)) {
        Response::Error('作品IDが指定されていません');
    }

    $Pdo = Database::GetConnection();
    Database::BeginTransaction();

    if ($Mode === 'info') {
        if (empty($PlotId)) {
            Response::Error('プロットIDが指定されていません');
        }

        // 削除詳細メッセージ用にプロット名を取得
        $NameStmt = $Pdo->prepare("SELECT plotName FROM PlotInfo WHERE plotId = :plotId AND workId = :workId");
        $NameStmt->execute([':plotId' => $PlotId, ':workId' => $WorkId]);
        $NameRow = $NameStmt->fetch();
        $Detail = HistoryDetail::Delete($NameRow['plotname'] ?? null);

        $Sql = "DELETE FROM PlotInfo WHERE plotId = :plotId AND workId = :workId AND updateDate = :updateDate";
        $Stmt = $Pdo->prepare($Sql);
        $Stmt->execute([
            ':plotId' => $PlotId,
            ':workId' => $WorkId,
            ':updateDate' => $UpdateDate
        ]);
        if ($Stmt->rowCount() === 0) {
            Database::Rollback();
            Response::ConflictError();
        }
    } else {
        if (empty($PlotId)) {
            Response::Error('プロットIDが指定されていません');
        }

        // 対象プロットが現在の作品に属することを検証（他作品への越境削除を防止）
        $OwnStmt = $Pdo->prepare("SELECT plotName FROM PlotInfo WHERE plotId = :plotId AND workId = :workId");
        $OwnStmt->execute([':plotId' => $PlotId, ':workId' => $WorkId]);
        $OwnRow = $OwnStmt->fetch();
        if (!$OwnRow) {
            Database::Rollback();
            Response::Error('対象のプロットが見つかりません', 403);
        }

        if (empty($PlotRowId)) {
            // 全行削除：親プロット名を削除詳細の名称とする
            $Detail = HistoryDetail::Delete($OwnRow['plotname'] ?? null);
            $Sql = "DELETE FROM PlotRows WHERE plotId = :plotId";
            $Stmt = $Pdo->prepare($Sql);
            $Stmt->execute([
                ':plotId' => $PlotId
            ]);
        } else {
            $GetIndexSql = "SELECT indexNum, content FROM PlotRows WHERE plotRowId = :plotRowId AND plotId = :plotId";
            $GetIndexStmt = $Pdo->prepare($GetIndexSql);
            $GetIndexStmt->execute([
                ':plotRowId' => $PlotRowId,
                ':plotId' => $PlotId
            ]);
            $PlotRowInfo = $GetIndexStmt->fetch();

            if (!$PlotRowInfo) {
                Database::Rollback();
                Response::Error('削除対象のプロット行が見つかりません');
            }

            $DeletedIndexNum = $PlotRowInfo['indexnum'];
            // 単一行削除：本文を削除詳細の名称とする
            $Detail = HistoryDetail::Delete($PlotRowInfo['content'] ?? null);

            $Sql = "DELETE FROM PlotRows WHERE plotRowId = :plotRowId AND plotId = :plotId";
            $Stmt = $Pdo->prepare($Sql);
            $Stmt->execute([
                ':plotRowId' => $PlotRowId,
                ':plotId' => $PlotId
            ]);

            $TempReorderSql = "
                UPDATE PlotRows SET
                    indexNum = -(indexNum - 1)
                WHERE plotId = :plotId AND indexNum > :deletedIndex
            ";
            $TempReorderStmt = $Pdo->prepare($TempReorderSql);
            $TempReorderStmt->execute([
                ':plotId' => $PlotId,
                ':deletedIndex' => $DeletedIndexNum
            ]);

            $ReorderSql = "
                UPDATE PlotRows SET
                    indexNum = -indexNum,
                    updateDate = CURRENT_TIMESTAMP(3),
                    updateUser = :updateUser
                WHERE plotId = :plotId AND indexNum < 0
            ";
            $ReorderStmt = $Pdo->prepare($ReorderSql);
            $ReorderStmt->execute([
                ':plotId' => $PlotId,
                ':updateUser' => $UserId
            ]);
        }
    }

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
        'Message' => $Mode === 'info' ? 'プロットを削除しました' : (empty($PlotRowId) ? 'プロット行を全削除しました' : 'プロット行を削除しました')
    ]);

} catch (Exception $E) {
    Database::Rollback();
    Logger::Error($FunctionName, '削除処理エラー', $UserId, $E->getMessage());
    Response::Error('プロットの削除に失敗しました');
}


