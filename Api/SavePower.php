<?php
/**
 * SavePower.php
 * 能力登録・更新API
 */

require_once __DIR__ . '/../Common/Database.php';
require_once __DIR__ . '/../Common/Logger.php';
require_once __DIR__ . '/../Common/Response.php';
require_once __DIR__ . '/../Common/Auth.php';
// 更新履歴 詳細メッセージ生成
require_once __DIR__ . '/../Common/HistoryDetail.php';
// 表形式で保持する項目（発動条件・代償・副作用）のユーティリティ
require_once __DIR__ . '/../Common/EntryTable.php';

// POSTメソッドのみ許可
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::Error('不正なリクエストです', 405);
}

$FunctionName = '能力設定';
$UserId = '';

try {
    /** リクエストデータを取得 */
    $Input = Response::GetJsonInput();
    $PowerId = $Input['PowerId'] ?? null;
    $WorkId = $AuthWorkId ?? '';
    $PowerName = $Input['PowerName'] ?? '';
    $Description = $Input['Description'] ?? null;
    $Conditions = $Input['Conditions'] ?? null;
    $Risk = $Input['Risk'] ?? null;
    $UserId = $AuthUserId;
    $UpdateDate = $Input['UpdateDate'] ?? null;

    $Operation = empty($PowerId) ? '登録' : '更新';
    // 更新履歴の詳細メッセージ（登録/更新分岐でそれぞれ生成）
    $Detail = null;
    Logger::Info($FunctionName, $Operation . '処理開始', $UserId);

    /** 入力チェック */
    if (empty($WorkId)) {
        Response::Error('作品IDが指定されていません');
    }

    if (empty($PowerName)) {
        Response::Error('能力名を入力してください');
    }

    /** DB接続 */
    $Pdo = Database::GetConnection();
    Database::BeginTransaction();

    /** 空文字をNULLに変換 */
    $PowerId = !empty($PowerId) ? $PowerId : null;
    $Description = !empty($Description) ? $Description : null;

    /**
     * 表形式で保持する項目のJSON文字列を正規化
     * 移行前のフリーテキストが送られる可能性があるため、
     * EntryTable 側で1行の表へ変換する（行が無い場合はNULL）
     */
    $Conditions = EntryTable::NormalizeJson('Power', 'Conditions', $Conditions);
    $Risk = EntryTable::NormalizeJson('Power', 'Risk', $Risk);

    if (empty($PowerId)) {
        /** 新規登録 */
        $Sql = "
            INSERT INTO PowerInfo (
                workId, powerName, description, conditions, risk,
                registDate, updateDate, registUser, updateUser
            ) VALUES (
                :workId, :powerName, :description, :conditions, :risk,
                CURRENT_TIMESTAMP(3), CURRENT_TIMESTAMP(3), :registUser, :updateUser
            )
            RETURNING powerId, updateDate
        ";

        $Stmt = $Pdo->prepare($Sql);
        $Stmt->execute([
            ':workId' => $WorkId,
            ':powerName' => $PowerName,
            ':description' => $Description,
            ':conditions' => $Conditions,
            ':risk' => $Risk,
            ':registUser' => $UserId,
            ':updateUser' => $UserId
        ]);

        $Result = $Stmt->fetch();
        $NewPowerId = $Result['powerid'];
        $NewUpdateDate = $Result['updatedate'];

        // 更新履歴の詳細メッセージを生成（登録）
        $Detail = HistoryDetail::Register($PowerName);

    } else {
        /** 更新前の表示値を取得（列単位差分用） */
        $OldRowStmt = $Pdo->prepare("
            SELECT powerName, description, conditions, risk
            FROM PowerInfo
            WHERE powerId = :powerId AND workId = :workId
        ");
        $OldRowStmt->bindValue(':powerId', $PowerId, PDO::PARAM_STR);
        $OldRowStmt->bindValue(':workId',  $WorkId,  PDO::PARAM_STR);
        $OldRowStmt->execute();
        $OldRow = $OldRowStmt->fetch() ?: [];

        /** 更新 */
        $Sql = "
            UPDATE PowerInfo SET
                powerName = :powerName,
                description = :description,
                conditions = :conditions,
                risk = :risk,
                updateDate = CURRENT_TIMESTAMP(3),
                updateUser = :updateUser
            WHERE powerId = :powerId AND workId = :workId AND updateDate = :updateDate
            RETURNING updateDate
        ";

        $Stmt = $Pdo->prepare($Sql);
        $Stmt->execute([
            ':powerId' => $PowerId,
            ':workId' => $WorkId,
            ':powerName' => $PowerName,
            ':description' => $Description,
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

        $NewPowerId = $PowerId;
        $NewUpdateDate = $UpdateResult['updatedate'];

        /** 更新履歴の詳細メッセージを生成（更新・列単位差分。FKなし） */
        $Labels = [
            'powerName'   => '能力名',
            'description' => '説明',
            'conditions'  => '発動条件',
            'risk'        => '代償・副作用',
        ];
        // 表形式の項目はJSONのままでは差分が読めないため、表示値へ整形して比較する
        $OldDisplay = [
            'powerName'   => $OldRow['powername']   ?? null,
            'description' => $OldRow['description']  ?? null,
            'conditions'  => EntryTable::FormatForHistory('Power', 'Conditions', $OldRow['conditions'] ?? null),
            'risk'        => EntryTable::FormatForHistory('Power', 'Risk',       $OldRow['risk']       ?? null),
        ];
        $NewDisplay = [
            'powerName'   => $PowerName,
            'description' => $Description,
            'conditions'  => EntryTable::FormatForHistory('Power', 'Conditions', $Conditions),
            'risk'        => EntryTable::FormatForHistory('Power', 'Risk',       $Risk),
        ];
        $Detail = HistoryDetail::Update($PowerName, $OldDisplay, $NewDisplay, $Labels);
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
        'PowerId' => $NewPowerId,
        'UpdateDate' => $NewUpdateDate,
        'Message' => '能力を' . $Operation . 'しました'
    ]);

} catch (Exception $E) {
    Database::Rollback();
    Logger::Error($FunctionName, '保存処理エラー', $UserId, $E->getMessage());
    Response::Error('能力の保存に失敗しました');
}
