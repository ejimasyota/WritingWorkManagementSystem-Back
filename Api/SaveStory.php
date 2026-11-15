<?php
/**
 * SaveStory.php
 * ストーリー登録・更新API
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

$FunctionName = 'ストーリー作成';
$UserId = '';

try {
    $Input = Response::GetJsonInput();
    $Mode = $Input['Mode'] ?? 'row';
    if ($Mode === 'bundle') {
        $UserId = $AuthUserId;
        Logger::Info('ストーリー作成', '一括保存処理開始', (string)$UserId);
        require_once __DIR__ . '/../Common/NarrativeBundle.php';
        $Pdo = Database::GetConnection();
        try {
            WorkDocumentAccess::Check($Pdo, (string)$AuthWorkId, (string)$AuthUserId, $IsAuthAdmin, true);
            $Pdo->beginTransaction();
            $Saved = NarrativeBundle::Save($Pdo, 'Story', (string)$AuthWorkId, (string)$AuthUserId, $Input);
            $Pdo->commit();
        } catch (Throwable $Error) {
            if ($Pdo->inTransaction()) $Pdo->rollBack();
            if ($Error instanceof InvalidArgumentException || in_array($Error->getCode(), [403,404,409], true)) Response::Error($Error->getMessage(), $Error->getCode() ?: 400);
            throw $Error;
        }
        Logger::Info('ストーリー作成', '一括保存処理終了', (string)$UserId);
        Response::Success($Saved);
    }
    $ActionLine = !empty($Input['ActionLine']);
    if ($ActionLine) {
        foreach (['Narrator','NarratorCharaId','Speaker','CharaId','SerifId','Feeling'] as $Key) $Input[$Key] = null;
        $Input['Monologue'] = false;
    }
    $StoryId = $Input['StoryId'] ?? null;
    $StoryRowId = $Input['StoryRowId'] ?? null;
    $StoryName = trim($Input['StoryName'] ?? '');
    $ProductionType = $Input['ProductionType'] ?? 0;
    $PlotId = $Input['PlotId'] ?? null;
    $WorkId = $AuthWorkId ?? '';
    $Narrator = $Input['Narrator'] ?? null;
    $NarratorCharaId = $Input['NarratorCharaId'] ?? null;
    $Monologue = !empty($Input['Monologue']) ? true : false;
    $Content = $Input['Content'] ?? '';
    $IndexNum = isset($Input['IndexNum']) && $Input['IndexNum'] !== '' ? (int)$Input['IndexNum'] : null;
    $UserId = $AuthUserId;
    $UpdateDate = $Input['UpdateDate'] ?? null;

    $Operation = $Mode === 'info'
        ? (empty($StoryId) ? '登録' : '更新')
        : (empty($StoryRowId) ? '登録' : '更新');
    // 更新履歴の詳細メッセージ（登録/更新分岐でそれぞれ生成）
    $Detail = null;
    Logger::Info($FunctionName, $Operation . '処理開始', $UserId);

    if (empty($WorkId)) {
        Response::Error('作品IDが指定されていません');
    }

    $Pdo = Database::GetConnection();
    Database::BeginTransaction();

    if ($Mode === 'info') {
        if ($StoryName === '') {
            Response::Error('ストーリー名を入力してください');
        }

        $ProductionType = empty($ProductionType) ? null : (int)$ProductionType;
        $PlotId = empty($PlotId) ? null : $PlotId;

        if (!empty($PlotId)) {
            $PlotInfoStmt = $Pdo->prepare("
                SELECT COALESCE(productionType, 0) AS productionType
                FROM PlotInfo
                WHERE plotId = :plotId AND workId = :workId
            ");
            $PlotInfoStmt->execute([
                ':plotId' => $PlotId,
                ':workId' => $WorkId
            ]);
            $PlotInfo = $PlotInfoStmt->fetch();
            if (!$PlotInfo) {
                Response::Error('選択されたプロットが見つかりません');
            }
            if ((int)$PlotInfo['productiontype'] !== (int)($ProductionType ?? 0)) {
                Response::Error('選択したプロットと制作区分が一致していません');
            }
        }

        if (empty($StoryId)) {
            $Sql = "
                INSERT INTO StoryInfo (
                    storyName, productionType, plotId, workId,
                    registDate, updateDate, registUser, updateUser
                ) VALUES (
                    :storyName, :productionType, :plotId, :workId,
                    CURRENT_TIMESTAMP(3), CURRENT_TIMESTAMP(3), :registUser, :updateUser
                )
                RETURNING storyId, updateDate
            ";
            $Stmt = $Pdo->prepare($Sql);
            $Stmt->execute([
                ':storyName' => $StoryName,
                ':productionType' => $ProductionType,
                ':plotId' => $PlotId,
                ':workId' => $WorkId,
                ':registUser' => $UserId,
                ':updateUser' => $UserId
            ]);
            $Result = $Stmt->fetch();
            $NewStoryId = $Result['storyid'];
            $NewUpdateDate = $Result['updatedate'];
            // 更新履歴の詳細メッセージを生成（登録）
            $Detail = HistoryDetail::Register($StoryName);

            /* テンプレートから作成
             *   作成時点で行として複写するため、以後テンプレートマスタが
             *   更新・削除されても作成済みのストーリーは影響を受けない。
             *   ストーリーの登録と同一トランザクションで行い、
             *   行だけが登録されない中途半端な状態を作らない。 */
            if (!empty($Input['ApplyTemplate'])) {
                $AppliedRowCount = TemplateMaster::ApplyStoryRows($Pdo, $WorkId, $NewStoryId, $UserId);
                if ($AppliedRowCount === 0) {
                    Database::Rollback();
                    Response::Error('作品のジャンルに対応するテンプレートが登録されていません');
                }
            }
        } else {
            // 差分履歴用：更新前の表示値を取得（FKのプロットは名称へ解決）
            $OldRowStmt = $Pdo->prepare("
                SELECT s.storyName, s.plotId, s.productionType, p.plotName
                FROM StoryInfo s
                LEFT JOIN PlotInfo p ON s.plotId = p.plotId
                WHERE s.storyId = :storyId AND s.workId = :workId
            ");
            $OldRowStmt->execute([':storyId' => $StoryId, ':workId' => $WorkId]);
            $OldRow = $OldRowStmt->fetch() ?: [];

            $Sql = "
                UPDATE StoryInfo SET
                    storyName = :storyName,
                    productionType = :productionType,
                    plotId = :plotId,
                    updateDate = CURRENT_TIMESTAMP(3),
                    updateUser = :updateUser
                WHERE storyId = :storyId AND workId = :workId AND updateDate = :updateDate
                RETURNING updateDate
            ";
            $Stmt = $Pdo->prepare($Sql);
            $Stmt->execute([
                ':storyId' => $StoryId,
                ':storyName' => $StoryName,
                ':productionType' => $ProductionType,
                ':plotId' => $PlotId,
                ':workId' => $WorkId,
                ':updateUser' => $UserId,
                ':updateDate' => $UpdateDate
            ]);
            $UpdateResult = $Stmt->fetch();

            if (!$UpdateResult) {
                Database::Rollback();
                Response::ConflictError();
            }

            $NewStoryId = $StoryId;
            $NewUpdateDate = $UpdateResult['updatedate'];

            // 更新履歴の詳細メッセージを生成（更新・列単位差分）
            // FK名称解決ヘルパ（テーブル・列名は固定リテラルのみ）
            $ResolveName = function ($Table, $IdCol, $NameCol, $Id) use ($Pdo, $WorkId) {
                if ($Id === null || $Id === '') {
                    return null;
                }
                $S = $Pdo->prepare("SELECT {$NameCol} AS nm FROM {$Table} WHERE {$IdCol} = :id AND workId = :workId");
                $S->bindValue(':id', $Id);
                $S->bindValue(':workId', $WorkId, PDO::PARAM_STR);
                $S->execute();
                $R = $S->fetch();
                return $R ? $R['nm'] : null;
            };
            // 新IDが旧IDと同じならクエリせず旧名称を再利用する
            $NewFkName = function ($Table, $IdCol, $NameCol, $NewId, $OldId, $OldName) use ($ResolveName) {
                if ((string) ($NewId ?? '') === (string) ($OldId ?? '')) {
                    return $OldName;
                }
                return $ResolveName($Table, $IdCol, $NameCol, $NewId);
            };

            // 差分対象の列 → 和名（制作区分は区分コードのため対象外）
            $Labels = [
                'storyName'      => 'ストーリー名',
                'productionType' => '制作区分',
                'plotId'         => 'プロット',
            ];
            $OldDisplay = [
                'storyName'      => $OldRow['storyname'] ?? null,
                'productionType' => CodeLabel::Get('ProductionType', $OldRow['productiontype'] ?? null),
                'plotId'         => $OldRow['plotname']  ?? null,
            ];
            $NewDisplay = [
                'storyName'      => $StoryName,
                'productionType' => CodeLabel::Get('ProductionType', $ProductionType),
                'plotId'         => $NewFkName('PlotInfo', 'plotId', 'plotName', $PlotId, $OldRow['plotid'] ?? null, $OldRow['plotname'] ?? null),
            ];
            $Detail = HistoryDetail::Update($StoryName, $OldDisplay, $NewDisplay, $Labels);
        }
        $NewStoryRowId = null;
    } else {
        if (empty($StoryId)) {
            Response::Error('ストーリーIDが指定されていません');
        }

        // 対象ストーリーが現在の作品に属することを検証（他作品への越境登録・改ざんを防止）
        $OwnStmt = $Pdo->prepare("SELECT 1 FROM StoryInfo WHERE storyId = :storyId AND workId = :workId");
        $OwnStmt->execute([':storyId' => $StoryId, ':workId' => $WorkId]);
        if (!$OwnStmt->fetch()) {
            Database::Rollback();
            Response::Error('対象のストーリーが見つかりません', 403);
        }

        if (trim($Content) === '') {
            Response::Error('内容を入力してください');
        }

        $Narrator = trim((string)$Narrator);
        $Narrator = $Narrator !== '' ? $Narrator : null;
        $NarratorCharaId = !empty($NarratorCharaId) ? $NarratorCharaId : null;
        if (!empty($NarratorCharaId)) {
            $Narrator = null;
        }

        $Content = trim($Content);
        if (!empty($Narrator) || !empty($NarratorCharaId)) {
            $Content = preg_replace('/^[「(（]/u', '', $Content);
            $Content = preg_replace('/[」)）]$/u', '', $Content);
            if ($Monologue) {
                $Content = '(' . $Content . ')';
            } else {
                $Content = '「' . $Content . '」';
            }
        }

        if (empty($StoryRowId)) {
            // 並び順の同時実行競合を防ぐため、storyIdでアドバイザリロックを取得
            $LockStmt = $Pdo->prepare("SELECT pg_advisory_xact_lock(1001, hashtext(:storyId))");
            $LockStmt->execute([':storyId' => (string)$StoryId]);

            $MaxIndexSql = "SELECT COALESCE(MAX(indexNum), 0) AS currentMax FROM StoryRows WHERE storyId = :storyId";
            $MaxIndexStmt = $Pdo->prepare($MaxIndexSql);
            $MaxIndexStmt->execute([':storyId' => $StoryId]);
            $MaxIndexResult = $MaxIndexStmt->fetch();
            $CurrentMaxIndex = (int)$MaxIndexResult['currentmax'];

            if ($IndexNum === null) {
                $InsertIndexNum = $CurrentMaxIndex + 1;
            } else {
                if ($IndexNum < 1 || $IndexNum > $CurrentMaxIndex + 1) {
                    Response::Error('存在しないインデックスが入力されました');
                }
                $InsertIndexNum = $IndexNum;
            }

            if ($InsertIndexNum <= $CurrentMaxIndex) {
                $ShiftTempStmt = $Pdo->prepare("
                    UPDATE StoryRows SET
                        indexNum = -1 * (indexNum + 1),
                        updateDate = CURRENT_TIMESTAMP(3),
                        updateUser = :updateUser
                    WHERE storyId = :storyId
                      AND indexNum >= :insertIndexNum
                ");
                $ShiftTempStmt->execute([
                    ':storyId' => $StoryId,
                    ':insertIndexNum' => $InsertIndexNum,
                    ':updateUser' => $UserId
                ]);

                $ShiftApplyStmt = $Pdo->prepare("
                    UPDATE StoryRows SET
                        indexNum = -1 * indexNum,
                        updateDate = CURRENT_TIMESTAMP(3),
                        updateUser = :updateUser
                    WHERE storyId = :storyId
                      AND indexNum < 0
                ");
                $ShiftApplyStmt->execute([
                    ':storyId' => $StoryId,
                    ':updateUser' => $UserId
                ]);
            }

            $Sql = "
                INSERT INTO StoryRows (
                    storyId, indexNum, narrator, narratorCharaId, monologue, actionLine, content,
                    registDate, updateDate, registUser, updateUser
                ) VALUES (
                    :storyId, :indexNum, :narrator, :narratorCharaId, :monologue, :actionLine, :content,
                    CURRENT_TIMESTAMP(3), CURRENT_TIMESTAMP(3), :registUser, :updateUser
                )
                RETURNING storyRowId, updateDate
            ";
            $Stmt = $Pdo->prepare($Sql);
            $Stmt->execute([
                ':storyId' => $StoryId,
                ':indexNum' => $InsertIndexNum,
                ':narrator' => $Narrator,
                ':narratorCharaId' => $NarratorCharaId,
                ':monologue' => $Monologue ? 't' : 'f',
                ':actionLine' => $ActionLine ? 't' : 'f',
                ':content' => $Content,
                ':registUser' => $UserId,
                ':updateUser' => $UserId
            ]);
            $Result = $Stmt->fetch();
            $NewStoryRowId = $Result['storyrowid'];
            $NewUpdateDate = $Result['updatedate'];
            // 更新履歴の詳細メッセージを生成（登録。ストーリー行は本文を名称として扱う）
            $Detail = HistoryDetail::Register($Content);
        } else {
            // 楽観ロックチェック：本UPDATEの前段に並び順入替が走るとupdateDateが先に書き換わるため、
            // 入替前の時点でupdateDate一致を判定する。
            $CurrentRowStmt = $Pdo->prepare("
                SELECT sr.storyRowId, sr.indexNum, sr.narrator, sr.narratorCharaId, sr.monologue, sr.actionLine, sr.content,
                       c.charaName AS narratorCharaName
                FROM StoryRows sr
                LEFT JOIN CharacterInfo c ON sr.narratorCharaId = c.charaId
                WHERE sr.storyRowId = :storyRowId AND sr.storyId = :storyId
                  AND sr.updateDate = :updateDate
            ");
            $CurrentRowStmt->execute([
                ':storyRowId' => $StoryRowId,
                ':storyId' => $StoryId,
                ':updateDate' => $UpdateDate
            ]);
            $CurrentRow = $CurrentRowStmt->fetch();
            if (!$CurrentRow) {
                $ExistsStmt = $Pdo->prepare("SELECT 1 FROM StoryRows WHERE storyRowId = :storyRowId AND storyId = :storyId");
                $ExistsStmt->execute([
                    ':storyRowId' => $StoryRowId,
                    ':storyId' => $StoryId
                ]);
                if (!$ExistsStmt->fetch()) {
                    Response::Error('対象のストーリー行が見つかりません');
                }
                Database::Rollback();
                Response::ConflictError();
            }

            $CountStmt = $Pdo->prepare("SELECT COUNT(*) AS rowCount FROM StoryRows WHERE storyId = :storyId");
            $CountStmt->execute([':storyId' => $StoryId]);
            $RowCount = (int)$CountStmt->fetch()['rowcount'];

            $CurrentIndexNum = (int)$CurrentRow['indexnum'];
            $TargetIndexNum = $IndexNum ?? $CurrentIndexNum;
            if ($TargetIndexNum < 1 || $TargetIndexNum > $RowCount) {
                Response::Error('存在しないインデックスが入力されました');
            }

            if ($TargetIndexNum !== $CurrentIndexNum) {
                $TempStmt = $Pdo->prepare("
                    UPDATE StoryRows SET
                        indexNum = 0,
                        updateDate = CURRENT_TIMESTAMP(3),
                        updateUser = :updateUser
                    WHERE storyRowId = :storyRowId AND storyId = :storyId
                ");
                $TempStmt->execute([
                    ':storyRowId' => $StoryRowId,
                    ':storyId' => $StoryId,
                    ':updateUser' => $UserId
                ]);

                if ($TargetIndexNum < $CurrentIndexNum) {
                    $ShiftTempStmt = $Pdo->prepare("
                        UPDATE StoryRows SET
                            indexNum = -1 * (indexNum + 1),
                            updateDate = CURRENT_TIMESTAMP(3),
                            updateUser = :updateUser
                        WHERE storyId = :storyId
                          AND indexNum >= :targetIndexNum
                          AND indexNum < :currentIndexNum
                    ");
                } else {
                    $ShiftTempStmt = $Pdo->prepare("
                        UPDATE StoryRows SET
                            indexNum = -1 * (indexNum - 1),
                            updateDate = CURRENT_TIMESTAMP(3),
                            updateUser = :updateUser
                        WHERE storyId = :storyId
                          AND indexNum > :currentIndexNum
                          AND indexNum <= :targetIndexNum
                    ");
                }
                $ShiftTempStmt->execute([
                    ':storyId' => $StoryId,
                    ':targetIndexNum' => $TargetIndexNum,
                    ':currentIndexNum' => $CurrentIndexNum,
                    ':updateUser' => $UserId
                ]);

                $ShiftApplyStmt = $Pdo->prepare("
                    UPDATE StoryRows SET
                        indexNum = -1 * indexNum,
                        updateDate = CURRENT_TIMESTAMP(3),
                        updateUser = :updateUser
                    WHERE storyId = :storyId
                      AND indexNum < 0
                ");
                $ShiftApplyStmt->execute([
                    ':storyId' => $StoryId,
                    ':updateUser' => $UserId
                ]);
            }

            // 楽観ロックは冒頭で確認済みのため、本UPDATEではupdateDate判定を行わない
            $Sql = "
                UPDATE StoryRows SET
                    indexNum = :indexNum,
                    narrator = :narrator,
                    narratorCharaId = :narratorCharaId,
                    monologue = :monologue,
                    actionLine = :actionLine,
                    content = :content,
                    updateDate = CURRENT_TIMESTAMP(3),
                    updateUser = :updateUser
                WHERE storyRowId = :storyRowId AND storyId = :storyId
                RETURNING updateDate
            ";
            $Stmt = $Pdo->prepare($Sql);
            $Stmt->execute([
                ':storyRowId' => $StoryRowId,
                ':storyId' => $StoryId,
                ':indexNum' => $TargetIndexNum,
                ':narrator' => $Narrator,
                ':narratorCharaId' => $NarratorCharaId,
                ':monologue' => $Monologue ? 't' : 'f',
                ':actionLine' => $ActionLine ? 't' : 'f',
                ':content' => $Content,
                ':updateUser' => $UserId
            ]);

            $UpdateResult = $Stmt->fetch();
            $NewStoryRowId = $StoryRowId;
            $NewUpdateDate = $UpdateResult['updatedate'];

            // 更新履歴の詳細メッセージを生成（更新・列単位差分。並び順・独白フラグは対象外）
            $Labels = [
                'narratorCharaId' => '語り手（キャラ）',
                'narrator'        => '語り手',
                'monologue'       => 'モノローグ',
                'actionLine' => '動作描写行',
                'content'         => '内容',
            ];
            $OldDisplay = [
                'narratorCharaId' => $CurrentRow['narratorcharaname'] ?? null,
                'narrator'        => $CurrentRow['narrator']          ?? null,
                'monologue'       => CodeLabel::Bool($CurrentRow['monologue'] ?? null),
                'actionLine' => CodeLabel::Bool($CurrentRow['actionline'] ?? null),
                'content'         => $CurrentRow['content']           ?? null,
            ];
            // narratorCharaId の新名称解決（変更が無ければ旧名称を再利用、null時null）
            $NewNarratorCharaName = null;
            if ($NarratorCharaId !== null) {
                if ((string) ($NarratorCharaId ?? '') === (string) ($CurrentRow['narratorcharaid'] ?? '')) {
                    $NewNarratorCharaName = $CurrentRow['narratorcharaname'] ?? null;
                } else {
                    $NmStmt = $Pdo->prepare("SELECT charaName AS nm FROM CharacterInfo WHERE charaId = :id AND workId = :workId");
                    $NmStmt->bindValue(':id', $NarratorCharaId, PDO::PARAM_STR);
                    $NmStmt->bindValue(':workId', $WorkId, PDO::PARAM_STR);
                    $NmStmt->execute();
                    $NmRow = $NmStmt->fetch();
                    $NewNarratorCharaName = $NmRow ? $NmRow['nm'] : null;
                }
            }
            $NewDisplay = [
                'narratorCharaId' => $NewNarratorCharaName,
                'narrator'        => $Narrator,
                'monologue'       => CodeLabel::Bool($Monologue),
                'actionLine' => CodeLabel::Bool($ActionLine),
                'content'         => $Content,
            ];
            $Detail = HistoryDetail::Update($Content, $OldDisplay, $NewDisplay, $Labels);
        }

        $NewStoryId = $StoryId;
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
        'StoryId' => $NewStoryId,
        'StoryRowId' => $NewStoryRowId,
        'UpdateDate' => $NewUpdateDate,
        'Message' => 'ストーリーを' . $Operation . 'しました'
    ]);

} catch (Exception $E) {
    Database::Rollback();
    Logger::Error($FunctionName, '保存処理エラー', $UserId, $E->getMessage());
    Response::Error('ストーリーの保存に失敗しました');
}

