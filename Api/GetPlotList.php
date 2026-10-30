<?php
/**
 * GetPlotList.php
 * プロット一覧取得API
 */

require_once __DIR__ . '/../Common/Database.php';
require_once __DIR__ . '/../Common/Logger.php';
require_once __DIR__ . '/../Common/Response.php';
require_once __DIR__ . '/../Common/Auth.php';
// テンプレートマスタ参照（「テンプレートから作成」ボタンの表示可否の判定）
require_once __DIR__ . '/../Common/TemplateMaster.php';

// GETメソッドのみ許可
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    Response::Error('不正なリクエストです', 405);
}

$FunctionName = 'プロット作成';
$UserId = '';

try {
    $WorkId = $AuthWorkId ?? '';
    $PlotId = $_GET['PlotId'] ?? null;
    $UserId = $AuthUserId;

    Logger::Info($FunctionName, '一覧取得処理開始', $UserId);

    if (empty($WorkId)) {
        Response::Error('作品IDが指定されていません');
    }

    $Pdo = Database::GetConnection();

    $Sql = "
        SELECT
            p.plotId AS \"PlotId\",
            p.plotName AS \"PlotName\",
            COALESCE(p.productionType, 0) AS \"ProductionType\",
            p.workId AS \"WorkId\",
            p.registDate AS \"RegistDate\",
            p.updateDate AS \"UpdateDate\",
            p.registUser AS \"RegistUser\",
            p.updateUser AS \"UpdateUser\",
            COALESCE(r.rowCount, 0) AS \"RowCount\"
        FROM PlotInfo p
        LEFT JOIN (
            SELECT plotId, COUNT(*) AS rowCount
            FROM PlotRows
            GROUP BY plotId
        ) r ON r.plotId = p.plotId
        WHERE p.workId = :workId
        ORDER BY p.updateDate DESC, p.plotId DESC
    ";
    $Stmt = $Pdo->prepare($Sql);
    $Stmt->execute([':workId' => $WorkId]);
    $PlotList = $Stmt->fetchAll();

    $SelectedPlot = null;
    $PlotRowList = [];
    if (!empty($PlotId)) {
        $SelectedPlotStmt = $Pdo->prepare("
            SELECT
                plotId AS \"PlotId\",
                plotName AS \"PlotName\",
                COALESCE(productionType, 0) AS \"ProductionType\",
                workId AS \"WorkId\",
                registDate AS \"RegistDate\",
                updateDate AS \"UpdateDate\",
                registUser AS \"RegistUser\",
                updateUser AS \"UpdateUser\"
            FROM PlotInfo
            WHERE plotId = :plotId AND workId = :workId
        ");
        $SelectedPlotStmt->execute([
            ':plotId' => $PlotId,
            ':workId' => $WorkId
        ]);
        $SelectedPlot = $SelectedPlotStmt->fetch();

        if (!$SelectedPlot) {
            Response::Error('指定されたプロットが見つかりません');
        }

        $RowStmt = $Pdo->prepare("
            SELECT
                plotRowId AS \"PlotRowId\",
                plotId AS \"PlotId\",
                indexNum AS \"IndexNum\",
                structureType AS \"StructureType\",
                content AS \"Content\",
                registDate AS \"RegistDate\",
                updateDate AS \"UpdateDate\",
                registUser AS \"RegistUser\",
                updateUser AS \"UpdateUser\"
            FROM PlotRows
            WHERE plotId = :plotId
            ORDER BY structureType ASC, indexNum ASC, plotRowId ASC
        ");
        $RowStmt->execute([':plotId' => $PlotId]);
        $PlotRowList = $RowStmt->fetchAll();
    }

    Logger::Info($FunctionName, '一覧取得処理終了', $UserId);

    Response::Success([
        'PlotList' => $PlotList,
        'SelectedPlot' => $SelectedPlot,
        'PlotRowList' => $PlotRowList,
        /* 作品のジャンルに対応するテンプレートが無い場合、
         * プロット作成ダイアログの「テンプレートから作成」は表示しない */
        'HasTemplate' => TemplateMaster::Exists($Pdo, $WorkId, TemplateMaster::DIVISION_PLOT),
        'TotalCount' => count($PlotList)
    ]);

} catch (Exception $E) {
    Logger::Error($FunctionName, '一覧取得処理エラー', $UserId, $E->getMessage());
    Response::Error('プロット一覧の取得に失敗しました');
}

