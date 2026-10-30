<?php
/**
 * SaveMove.php
 * 技登録・更新API
 */

require_once __DIR__ . '/../Common/Database.php';
require_once __DIR__ . '/../Common/Logger.php';
require_once __DIR__ . '/../Common/Response.php';
require_once __DIR__ . '/../Common/Auth.php';
// 更新履歴 詳細メッセージ生成
require_once __DIR__ . '/../Common/HistoryDetail.php';
// キャラクターの能力・技（JSON保持）の操作
require_once __DIR__ . '/../Common/CharacterSkill.php';
// 表形式で保持する項目（発動条件・代償・副作用）のユーティリティ
require_once __DIR__ . '/../Common/EntryTable.php';

// POSTメソッドのみ許可
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::Error('不正なリクエストです', 405);
}

$FunctionName = '技設定';
$UserId = '';

try {
    /** リクエストデータを取得 */
    $Input = Response::GetJsonInput();
    $MoveId = $Input['MoveId'] ?? null;
    $WorkId = $AuthWorkId ?? '';
    $MoveName = $Input['MoveName'] ?? '';
    $Description = $Input['Description'] ?? null;
    $PowerId = $Input['PowerId'] ?? null;
    $Conditions = $Input['Conditions'] ?? null;
    $Risk = $Input['Risk'] ?? null;
    $UserId = $AuthUserId;
    $UpdateDate = $Input['UpdateDate'] ?? null;

    $Operation = empty($MoveId) ? '登録' : '更新';
    // 更新履歴の詳細メッセージ（登録/更新分岐でそれぞれ生成）
    $Detail = null;
    Logger::Info($FunctionName, $Operation . '処理開始', $UserId);

    /** 入力チェック */
    if (empty($WorkId)) {
        Response::Error('作品IDが指定されていません');
    }

    if (empty($MoveName)) {
        Response::Error('技名を入力してください');
    }

    /** DB接続 */
    $Pdo = Database::GetConnection();
    Database::BeginTransaction();

    /** 空文字をNULLに変換 */
    $MoveId = !empty($MoveId) ? $MoveId : null;
    $Description = !empty($Description) ? $Description : null;
    $PowerId = !empty($PowerId) ? $PowerId : null;

    /**
     * 表形式で保持する項目のJSON文字列を正規化
     * 必須列が空の行は保存対象から除外し、行が無い場合はNULLとする
     */
    $Conditions = EntryTable::NormalizeJson('Move', 'Conditions', $Conditions);
    $Risk = EntryTable::NormalizeJson('Move', 'Risk', $Risk);

    if (empty($MoveId)) {
        /** 新規登録 */
        $Sql = "
            INSERT INTO MoveInfo (
                workId, moveName, description, powerId, conditions, risk,
                registDate, updateDate, registUser, updateUser
            ) VALUES (
                :workId, :moveName, :description, :powerId, :conditions, :risk,
                CURRENT_TIMESTAMP(3), CURRENT_TIMESTAMP(3), :registUser, :updateUser
            )
            RETURNING moveId, updateDate
        ";

        $Stmt = $Pdo->prepare($Sql);
        $Stmt->execute([
            ':workId' => $WorkId,
            ':moveName' => $MoveName,
            ':description' => $Description,
            ':powerId' => $PowerId,
            ':conditions' => $Conditions,
            ':risk' => $Risk,
            ':registUser' => $UserId,
            ':updateUser' => $UserId
        ]);

        $Result = $Stmt->fetch();
        $NewMoveId = $Result['moveid'];
        $NewUpdateDate = $Result['updatedate'];

        // 更新履歴の詳細メッセージを生成（登録）
        $Detail = HistoryDetail::Register($MoveName);

    } else {
        /** 更新前の表示値を取得（列単位差分用。FK能力は名称へ解決するためJOIN） */
        $OldRowStmt = $Pdo->prepare("
            SELECT m.moveName, m.description, m.powerId, m.conditions, m.risk, p.powerName
            FROM MoveInfo m
            LEFT JOIN PowerInfo p ON m.powerId = p.powerId
            WHERE m.moveId = :moveId AND m.workId = :workId
        ");
        $OldRowStmt->bindValue(':moveId', $MoveId, PDO::PARAM_STR);
        $OldRowStmt->bindValue(':workId', $WorkId, PDO::PARAM_STR);
        $OldRowStmt->execute();
        $OldRow = $OldRowStmt->fetch() ?: [];

        /** 更新 */
        $Sql = "
            UPDATE MoveInfo SET
                moveName = :moveName,
                description = :description,
                powerId = :powerId,
                conditions = :conditions,
                risk = :risk,
                updateDate = CURRENT_TIMESTAMP(3),
                updateUser = :updateUser
            WHERE moveId = :moveId AND workId = :workId AND updateDate = :updateDate
            RETURNING updateDate
        ";

        $Stmt = $Pdo->prepare($Sql);
        $Stmt->execute([
            ':moveId' => $MoveId,
            ':workId' => $WorkId,
            ':moveName' => $MoveName,
            ':description' => $Description,
            ':powerId' => $PowerId,
            ':conditions' => $Conditions,
            ':risk' => $Risk,
            ':updateUser' => $UserId,
            ':updateDate' => $UpdateDate
        ]);

        $UpdateResult = $Stmt->fetch();
        if (!$UpdateResult) {
            Database::Rollback();
            Response::ConflictError();
        }

        $NewMoveId = $MoveId;
        $NewUpdateDate = $UpdateResult['updatedate'];

        /** 関連能力を変更した場合は、キャラクターの技一覧の整合を取り直す
         *  能力・技はJSON保持のため外部キーで整合を担保できず、
         *  変更後の能力を保持していないキャラクターに当該技が残ると
         *  キャラ設定画面の保存時に整合性エラーとなるため除去する */
        $OldPowerId = $OldRow['powerid'] ?? null;
        if ((string) ($PowerId ?? '') !== (string) ($OldPowerId ?? '')) {
            CharacterSkill::RemoveMoveFromCharactersWithoutPower($Pdo, $WorkId, $MoveId, $PowerId);
        }

        /** 更新履歴の詳細メッセージを生成（更新・列単位差分。powerIdはFK→能力名を解決） */
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

        $Labels = [
            'moveName'    => '技名',
            'description' => '説明',
            'powerId'     => '能力',
            'conditions'  => '発動条件',
            'risk'        => '代償・副作用',
        ];
        // 表形式の項目はJSONのままでは差分が読めないため、表示値へ整形して比較する
        $OldDisplay = [
            'moveName'    => $OldRow['movename']    ?? null,
            'description' => $OldRow['description']  ?? null,
            'powerId'     => $OldRow['powername']    ?? null,
            'conditions'  => EntryTable::FormatForHistory('Move', 'Conditions', $OldRow['conditions'] ?? null),
            'risk'        => EntryTable::FormatForHistory('Move', 'Risk',       $OldRow['risk']       ?? null),
        ];
        $NewDisplay = [
            'moveName'    => $MoveName,
            'description' => $Description,
            'powerId'     => $NewFkName('PowerInfo', 'powerId', 'powerName', $PowerId, $OldRow['powerid'] ?? null, $OldRow['powername'] ?? null),
            'conditions'  => EntryTable::FormatForHistory('Move', 'Conditions', $Conditions),
            'risk'        => EntryTable::FormatForHistory('Move', 'Risk',       $Risk),
        ];
        $Detail = HistoryDetail::Update($MoveName, $OldDisplay, $NewDisplay, $Labels);
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

    /** 更新履歴を登録（変更が無い更新は履歴に残す情報が無いため登録しない） */
    $HistorySql = "
        INSERT INTO UpdateHistory (
            workId, functionName, operation, detail, registDate, updateDate, registUser, updateUser
        ) VALUES (
            :workId, :functionName, :operation, :detail, CURRENT_TIMESTAMP(3), CURRENT_TIMESTAMP(3), :registUser, :updateUser
        )
    ";
    $HistoryStmt = $Pdo->prepare($HistorySql);
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
        'MoveId' => $NewMoveId,
        'UpdateDate' => $NewUpdateDate,
        'Message' => '技を' . $Operation . 'しました'
    ]);

} catch (Exception $E) {
    Database::Rollback();
    Logger::Error($FunctionName, '保存処理エラー', $UserId, $E->getMessage());
    Response::Error('技の保存に失敗しました');
}
