<?php
/**
 * SavePlot.php
 * プロット登録・更新API
 */

require_once __DIR__ . '/../Common/Database.php';
require_once __DIR__ . '/../Common/Logger.php';
require_once __DIR__ . '/../Common/Response.php';
require_once __DIR__ . '/../Common/Auth.php';
// 更新履歴 詳細メッセージ生成
require_once __DIR__ . '/../Common/HistoryDetail.php';
// コード区分値 → 名称変換
require_once __DIR__ . '/../Common/CodeLabel.php';
// テンプレートマスタ参照（テンプレートから作成）
require_once __DIR__ . '/../Common/TemplateMaster.php';

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
    $PlotName = trim($Input['PlotName'] ?? '');
    $ProductionType = $Input['ProductionType'] ?? 0;
    $WorkId = $AuthWorkId ?? '';
    $StructureType = $Input['StructureType'] ?? 0;
    $Content = $Input['Content'] ?? '';
    $IndexNum = isset($Input['IndexNum']) && $Input['IndexNum'] !== '' ? (int)$Input['IndexNum'] : null;
    $UserId = $AuthUserId;
    $UpdateDate = $Input['UpdateDate'] ?? null;

    $Operation = $Mode === 'info'
        ? (empty($PlotId) ? '登録' : '更新')
        : (empty($PlotRowId) ? '登録' : '更新');
    // 更新履歴の詳細メッセージ（登録/更新分岐でそれぞれ生成）
    $Detail = null;
    Logger::Info($FunctionName, $Operation . '処理開始', $UserId);

    if (empty($WorkId)) {
        Response::Error('作品IDが指定されていません');
    }

    $Pdo = Database::GetConnection();
    Database::BeginTransaction();

    if ($Mode === 'info') {
        if ($PlotName === '') {
            Response::Error('プロット名を入力してください');
        }

        $ProductionType = empty($ProductionType) ? null : (int)$ProductionType;

        if (empty($PlotId)) {
            $Sql = "
                INSERT INTO PlotInfo (
                    plotName, productionType, workId,
                    registDate, updateDate, registUser, updateUser
                ) VALUES (
                    :plotName, :productionType, :workId,
                    CURRENT_TIMESTAMP(3), CURRENT_TIMESTAMP(3), :registUser, :updateUser
                )
                RETURNING plotId, updateDate
            ";
            $Stmt = $Pdo->prepare($Sql);
            $Stmt->execute([
                ':plotName' => $PlotName,
                ':productionType' => $ProductionType,
                ':workId' => $WorkId,
                ':registUser' => $UserId,
                ':updateUser' => $UserId
            ]);
            $Result = $Stmt->fetch();
            $NewPlotId = $Result['plotid'];
            $NewUpdateDate = $Result['updatedate'];
            // 更新履歴の詳細メッセージを生成（登録）
            $Detail = HistoryDetail::Register($PlotName);

            /* テンプレートから作成
             *   作成時点で行として複写するため、以後テンプレートマスタが
             *   更新・削除されても作成済みのプロットは影響を受けない。
             *   プロットの登録と同一トランザクションで行い、
             *   行だけが登録されない中途半端な状態を作らない。 */
            if (!empty($Input['ApplyTemplate'])) {
                $AppliedRowCount = TemplateMaster::ApplyPlotRows($Pdo, $WorkId, $NewPlotId, $UserId);
                if ($AppliedRowCount === 0) {
                    Database::Rollback();
                    Response::Error('作品のジャンルに対応するテンプレートが登録されていません');
                }
            }
        } else {
            // 差分履歴用：更新前のプロット名を取得
            $OldRowStmt = $Pdo->prepare("SELECT plotName FROM PlotInfo WHERE plotId = :plotId AND workId = :workId");
            $OldRowStmt->execute([':plotId' => $PlotId, ':workId' => $WorkId]);
            $OldRow = $OldRowStmt->fetch() ?: [];

            $Sql = "
                UPDATE PlotInfo SET
                    plotName = :plotName,
                    productionType = :productionType,
                    updateDate = CURRENT_TIMESTAMP(3),
                    updateUser = :updateUser
                WHERE plotId = :plotId AND workId = :workId AND updateDate = :updateDate
                RETURNING updateDate
            ";
            $Stmt = $Pdo->prepare($Sql);
            $Stmt->execute([
                ':plotId' => $PlotId,
                ':plotName' => $PlotName,
                ':productionType' => $ProductionType,
                ':workId' => $WorkId,
                ':updateUser' => $UserId,
                ':updateDate' => $UpdateDate
            ]);

            $UpdateResult = $Stmt->fetch();
            if (!$UpdateResult) {
                Database::Rollback();
                Response::ConflictError();
            }

            $NewPlotId = $PlotId;
            $NewUpdateDate = $UpdateResult['updatedate'];

            // 更新履歴の詳細メッセージを生成（更新・列単位差分。制作区分は区分コードのため対象外）
            $Labels = ['plotName' => 'プロット名'];
            $OldDisplay = ['plotName' => $OldRow['plotname'] ?? null];
            $NewDisplay = ['plotName' => $PlotName];
            $Detail = HistoryDetail::Update($PlotName, $OldDisplay, $NewDisplay, $Labels);
        }
        $NewPlotRowId = null;
    } else {
        if (empty($PlotId)) {
            Response::Error('プロットIDが指定されていません');
        }

        // 対象プロットが現在の作品に属することを検証（他作品への越境登録・改ざんを防止）
        $OwnStmt = $Pdo->prepare("SELECT 1 FROM PlotInfo WHERE plotId = :plotId AND workId = :workId");
        $OwnStmt->execute([':plotId' => $PlotId, ':workId' => $WorkId]);
        if (!$OwnStmt->fetch()) {
            Database::Rollback();
            Response::Error('対象のプロットが見つかりません', 403);
        }

        if (!isset($StructureType) || $StructureType === '' || (int)$StructureType === 0) {
            Response::Error('起承転結を選択してください');
        }
        if (trim($Content) === '') {
            Response::Error('内容を入力してください');
        }

        $Content = trim($Content);
        $StructureType = (int)$StructureType;

        if (empty($PlotRowId)) {
            // 並び順の同時実行競合を防ぐため、plotIdでアドバイザリロックを取得
            $LockStmt = $Pdo->prepare("SELECT pg_advisory_xact_lock(1002, hashtext(:plotId))");
            $LockStmt->execute([':plotId' => (string)$PlotId]);

            $AllRowsStmt = $Pdo->prepare("
                SELECT plotRowId, structureType
                FROM PlotRows
                WHERE plotId = :plotId
                ORDER BY structureType ASC, indexNum ASC, plotRowId ASC
            ");
            $AllRowsStmt->execute([':plotId' => $PlotId]);
            $AllRows = $AllRowsStmt->fetchAll();

            $GroupedRows = [];
            foreach ($AllRows as $Row) {
                $RowStructureType = (int)$Row['structuretype'];
                if (!isset($GroupedRows[$RowStructureType])) {
                    $GroupedRows[$RowStructureType] = [];
                }
                $GroupedRows[$RowStructureType][] = [
                    'PlotRowId' => $Row['plotrowid'],
                    'StructureType' => $RowStructureType
                ];
            }

            if (!isset($GroupedRows[$StructureType])) {
                $GroupedRows[$StructureType] = [];
            }
            ksort($GroupedRows);

            $GroupSize = count($GroupedRows[$StructureType]);
            $TargetLocalIndex = $IndexNum ?? ($GroupSize + 1);
            $MaxLocalIndex = $GroupSize + 1;
            if ($TargetLocalIndex < 1 || $TargetLocalIndex > $MaxLocalIndex) {
                Response::Error('存在しないインデックスが入力されました');
            }

            $TempInitialIndex = -1 * (count($AllRows) + 1);

            $Sql = "
                INSERT INTO PlotRows (
                    plotId, indexNum, structureType, content,
                    registDate, updateDate, registUser, updateUser
                ) VALUES (
                    :plotId, :indexNum, :structureType, :content,
                    CURRENT_TIMESTAMP(3), CURRENT_TIMESTAMP(3), :registUser, :updateUser
                )
                RETURNING plotRowId, updateDate
            ";
            $Stmt = $Pdo->prepare($Sql);
            $Stmt->execute([
                ':plotId' => $PlotId,
                ':indexNum' => $TempInitialIndex,
                ':structureType' => $StructureType,
                ':content' => $Content,
                ':registUser' => $UserId,
                ':updateUser' => $UserId
            ]);
            $Result = $Stmt->fetch();
            $NewPlotRowId = $Result['plotrowid'];

            array_splice($GroupedRows[$StructureType], $TargetLocalIndex - 1, 0, [[
                'PlotRowId' => $NewPlotRowId,
                'StructureType' => $StructureType
            ]]);

            $FinalOrder = [];
            foreach ($GroupedRows as $Rows) {
                foreach ($Rows as $Row) {
                    $FinalOrder[] = $Row;
                }
            }

            $TempStmt = $Pdo->prepare("
                UPDATE PlotRows SET
                    indexNum = :tempIndexNum,
                    updateDate = CURRENT_TIMESTAMP(3),
                    updateUser = :updateUser
                WHERE plotRowId = :plotRowId AND plotId = :plotId
            ");
            foreach ($FinalOrder as $Index => $Row) {
                $TempStmt->execute([
                    ':tempIndexNum' => -1 * ($Index + 1),
                    ':plotRowId' => $Row['PlotRowId'],
                    ':plotId' => $PlotId,
                    ':updateUser' => $UserId
                ]);
            }

            $OrderUpdateStmt = $Pdo->prepare("
                UPDATE PlotRows SET
                    indexNum = :indexNum,
                    updateDate = CURRENT_TIMESTAMP(3),
                    updateUser = :updateUser
                WHERE plotRowId = :plotRowId AND plotId = :plotId
            ");
            foreach ($FinalOrder as $Index => $Row) {
                $OrderUpdateStmt->execute([
                    ':indexNum' => $Index + 1,
                    ':plotRowId' => $Row['PlotRowId'],
                    ':plotId' => $PlotId,
                    ':updateUser' => $UserId
                ]);
            }

            $RefreshStmt = $Pdo->prepare("SELECT updateDate FROM PlotRows WHERE plotRowId = :plotRowId AND plotId = :plotId");
            $RefreshStmt->execute([
                ':plotRowId' => $NewPlotRowId,
                ':plotId' => $PlotId
            ]);
            $RefreshResult = $RefreshStmt->fetch();
            $NewUpdateDate = $RefreshResult ? $RefreshResult['updatedate'] : null;
            // 更新履歴の詳細メッセージを生成（登録。プロット行は本文を名称として扱う）
            $Detail = HistoryDetail::Register($Content);
        } else {
            $CurrentRowStmt = $Pdo->prepare("
                SELECT plotRowId, structureType, indexNum, content
                FROM PlotRows
                WHERE plotRowId = :plotRowId AND plotId = :plotId
            ");
            $CurrentRowStmt->execute([
                ':plotRowId' => $PlotRowId,
                ':plotId' => $PlotId
            ]);
            $CurrentRow = $CurrentRowStmt->fetch();
            if (!$CurrentRow) {
                Response::Error('対象のプロット行が見つかりません');
            }

            $Sql = "
                UPDATE PlotRows SET
                    structureType = :structureType,
                    content = :content,
                    updateDate = CURRENT_TIMESTAMP(3),
                    updateUser = :updateUser
                WHERE plotRowId = :plotRowId AND plotId = :plotId AND updateDate = :updateDate
                RETURNING updateDate
            ";
            $Stmt = $Pdo->prepare($Sql);
            $Stmt->execute([
                ':plotRowId' => $PlotRowId,
                ':plotId' => $PlotId,
                ':structureType' => $StructureType,
                ':content' => $Content,
                ':updateUser' => $UserId,
                ':updateDate' => $UpdateDate
            ]);

            $UpdateResult = $Stmt->fetch();
            if (!$UpdateResult) {
                Database::Rollback();
                Response::ConflictError();
            }

            $NewPlotRowId = $PlotRowId;
            $NewUpdateDate = $UpdateResult['updatedate'];

            // 更新履歴の詳細メッセージを生成（更新・列単位差分。並び順は対象外）
            $Labels = [
                'structureType' => '起承転結',
                'content'       => '内容',
            ];
            $OldDisplay = [
                'structureType' => CodeLabel::Get('PlotStructure', $CurrentRow['structuretype'] ?? null),
                'content'       => $CurrentRow['content'] ?? null,
            ];
            $NewDisplay = [
                'structureType' => CodeLabel::Get('PlotStructure', $StructureType),
                'content'       => $Content,
            ];
            $Detail = HistoryDetail::Update($Content, $OldDisplay, $NewDisplay, $Labels);

            $AllRowsStmt = $Pdo->prepare("
                SELECT plotRowId, structureType
                FROM PlotRows
                WHERE plotId = :plotId
                ORDER BY structureType ASC, indexNum ASC, plotRowId ASC
            ");
            $AllRowsStmt->execute([':plotId' => $PlotId]);
            $AllRows = $AllRowsStmt->fetchAll();

            $GroupedRows = [];
            foreach ($AllRows as $Row) {
                if ((string)$Row['plotrowid'] === (string)$PlotRowId) {
                    continue;
                }
                $RowStructureType = (int)$Row['structuretype'];
                if (!isset($GroupedRows[$RowStructureType])) {
                    $GroupedRows[$RowStructureType] = [];
                }
                $GroupedRows[$RowStructureType][] = [
                    'PlotRowId' => $Row['plotrowid'],
                    'StructureType' => $RowStructureType
                ];
            }

            if (!isset($GroupedRows[$StructureType])) {
                $GroupedRows[$StructureType] = [];
            }
            ksort($GroupedRows);

            $TargetLocalIndex = $IndexNum ?? (count($GroupedRows[$StructureType]) + 1);
            $MaxLocalIndex = count($GroupedRows[$StructureType]) + 1;
            if ($TargetLocalIndex < 1 || $TargetLocalIndex > $MaxLocalIndex) {
                Response::Error('存在しないインデックスが入力されました');
            }

            array_splice($GroupedRows[$StructureType], $TargetLocalIndex - 1, 0, [[
                'PlotRowId' => $PlotRowId,
                'StructureType' => $StructureType
            ]]);
            ksort($GroupedRows);

            $FinalOrder = [];
            foreach ($GroupedRows as $Rows) {
                foreach ($Rows as $Row) {
                    $FinalOrder[] = $Row;
                }
            }

            if (count($FinalOrder) !== count($AllRows)) {
                Response::Error('プロット行の並び順構築に失敗しました');
            }

            $TempStmt = $Pdo->prepare("
                UPDATE PlotRows SET
                    indexNum = :tempIndexNum,
                    updateDate = CURRENT_TIMESTAMP(3),
                    updateUser = :updateUser
                WHERE plotRowId = :plotRowId AND plotId = :plotId
            ");
            foreach ($FinalOrder as $Index => $Row) {
                $TempStmt->execute([
                    ':tempIndexNum' => -1 * ($Index + 1),
                    ':plotRowId' => $Row['PlotRowId'],
                    ':plotId' => $PlotId,
                    ':updateUser' => $UserId
                ]);
            }

            $OrderUpdateStmt = $Pdo->prepare("
                UPDATE PlotRows SET
                    indexNum = :indexNum,
                    updateDate = CURRENT_TIMESTAMP(3),
                    updateUser = :updateUser
                WHERE plotRowId = :plotRowId AND plotId = :plotId
            ");
            foreach ($FinalOrder as $Index => $Row) {
                $OrderUpdateStmt->execute([
                    ':indexNum' => $Index + 1,
                    ':plotRowId' => $Row['PlotRowId'],
                    ':plotId' => $PlotId,
                    ':updateUser' => $UserId
                ]);
            }

            // 並び順入替で対象行のupdateDateも書き換わるため、最新値を再取得してフロントに返す
            $RefreshStmt = $Pdo->prepare("SELECT updateDate FROM PlotRows WHERE plotRowId = :plotRowId AND plotId = :plotId");
            $RefreshStmt->execute([
                ':plotRowId' => $PlotRowId,
                ':plotId' => $PlotId
            ]);
            $RefreshResult = $RefreshStmt->fetch();
            if ($RefreshResult) {
                $NewUpdateDate = $RefreshResult['updatedate'];
            }
        }
        $NewPlotId = $PlotId;
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
    /* 変更が無い更新は履歴に残す情報が無いため登録しない */
    if (HistoryDetail::HasChanges($Detail)) {
        $HistoryStmt->execute([
            ':workId' => $WorkId,
            ':functionName' => $FunctionName,
            ':operation' => $Operation,
            ':detail' => $Detail,
            ':registUser' => $UserId,
            ':updateUser' => $UserId
        ]);
    }

    Database::Commit();

    Logger::Info($FunctionName, $Operation . '処理終了', $UserId);

    Response::Success([
        'PlotId' => $NewPlotId,
        'PlotRowId' => $NewPlotRowId,
        'UpdateDate' => $NewUpdateDate,
        'Message' => 'プロットを' . $Operation . 'しました'
    ]);

} catch (Exception $E) {
    Database::Rollback();
    Logger::Error($FunctionName, '保存処理エラー', $UserId, $E->getMessage());
    Response::Error('プロットの保存に失敗しました');
}
