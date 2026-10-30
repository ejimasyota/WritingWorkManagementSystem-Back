<?php
/**
 * SaveRace.php
 * 種族登録・更新API
 */

require_once __DIR__ . '/../Common/Database.php';
require_once __DIR__ . '/../Common/Logger.php';
require_once __DIR__ . '/../Common/Response.php';
require_once __DIR__ . '/../Common/Auth.php';
// 更新履歴 詳細メッセージ生成
require_once __DIR__ . '/../Common/HistoryDetail.php';
// 表形式で保持する項目（身体特徴）のユーティリティ
require_once __DIR__ . '/../Common/EntryTable.php';

// POSTメソッドのみ許可
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::Error('不正なリクエストです', 405);
}

$FunctionName = '種族設定';
$UserId = '';

try {
    /** リクエストデータを取得 */
    $Input = Response::GetJsonInput();
    $RaceId = $Input['RaceId'] ?? null;
    $WorkId = $AuthWorkId ?? '';
    $RaceName = $Input['RaceName'] ?? '';
    $Description = $Input['Description'] ?? null;
    $PhysicalFeature = $Input['PhysicalFeature'] ?? null;
    $UserId = $AuthUserId;
    $UpdateDate = $Input['UpdateDate'] ?? null;

    $Operation = empty($RaceId) ? '登録' : '更新';
    // 更新履歴の詳細メッセージ（登録/更新分岐でそれぞれ生成）
    $Detail = null;
    Logger::Info($FunctionName, $Operation . '処理開始', $UserId);

    /** 入力チェック */
    if (empty($WorkId)) {
        Response::Error('作品IDが指定されていません');
    }

    if (empty($RaceName)) {
        Response::Error('種族名を入力してください');
    }

    /** DB接続 */
    $Pdo = Database::GetConnection();
    Database::BeginTransaction();

    /** 空文字をNULLに変換 */
    $RaceId = !empty($RaceId) ? $RaceId : null;
    $Description = !empty($Description) ? $Description : null;
    /**
     * 表形式で保持する項目のJSON文字列を正規化
     * 移行前のフリーテキストが送られる可能性があるため、
     * EntryTable 側で1行の表へ変換する（行が無い場合はNULL）
     */
    $PhysicalFeature = EntryTable::NormalizeJson('Race', 'PhysicalFeature', $PhysicalFeature);

    if (empty($RaceId)) {
        /** 新規登録 */
        $Sql = "
            INSERT INTO RaceInfo (
                workId, raceName, description, physicalFeature,
                registDate, updateDate, registUser, updateUser
            ) VALUES (
                :workId, :raceName, :description, :physicalFeature,
                CURRENT_TIMESTAMP(3), CURRENT_TIMESTAMP(3), :registUser, :updateUser
            )
            RETURNING raceId, updateDate
        ";

        $Stmt = $Pdo->prepare($Sql);
        $Stmt->execute([
            ':workId' => $WorkId,
            ':raceName' => $RaceName,
            ':description' => $Description,
            ':physicalFeature' => $PhysicalFeature,
            ':registUser' => $UserId,
            ':updateUser' => $UserId
        ]);

        $Result = $Stmt->fetch();
        $NewRaceId = $Result['raceid'];
        $NewUpdateDate = $Result['updatedate'];

        // 更新履歴の詳細メッセージを生成（登録）
        $Detail = HistoryDetail::Register($RaceName);

    } else {
        /** 更新 */
        /* 差分履歴用：更新前の表示値を取得（FK無し。取得キーはPDO既定の小文字） */
        $OldRowStmt = $Pdo->prepare("
            SELECT raceName, description, physicalFeature
            FROM RaceInfo
            WHERE raceId = :raceId AND workId = :workId
        ");
        $OldRowStmt->bindValue(':raceId', $RaceId, PDO::PARAM_STR);
        $OldRowStmt->bindValue(':workId', $WorkId, PDO::PARAM_STR);
        $OldRowStmt->execute();
        $OldRow = $OldRowStmt->fetch() ?: [];

        $Sql = "
            UPDATE RaceInfo SET
                raceName = :raceName,
                description = :description,
                physicalFeature = :physicalFeature,
                updateDate = CURRENT_TIMESTAMP(3),
                updateUser = :updateUser
            WHERE raceId = :raceId AND workId = :workId AND updateDate = :updateDate
            RETURNING updateDate
        ";

        $Stmt = $Pdo->prepare($Sql);
        $Stmt->execute([
            ':raceId' => $RaceId,
            ':workId' => $WorkId,
            ':raceName' => $RaceName,
            ':description' => $Description,
            ':physicalFeature' => $PhysicalFeature,
            ':updateUser' => $UserId,
            ':updateDate' => $UpdateDate
        ]);

        $UpdateResult = $Stmt->fetch();
        if (!$UpdateResult) {
            Database::Rollback();
            Response::ConflictError();
        }

        $NewRaceId = $RaceId;
        $NewUpdateDate = $UpdateResult['updatedate'];

        // 更新履歴の詳細メッセージを生成（更新・列単位差分）
        $Labels = [
            'raceName'        => '種族名',
            'description'     => '説明',
            'physicalFeature' => '身体特徴',
        ];
        $OldDisplay = [
            'raceName'        => $OldRow['racename']        ?? null,
            'description'     => $OldRow['description']     ?? null,
            // 表形式の項目はJSONのままでは差分が読めないため、表示値へ整形して比較する
            'physicalFeature' => EntryTable::FormatForHistory('Race', 'PhysicalFeature', $OldRow['physicalfeature'] ?? null),
        ];
        $NewDisplay = [
            'raceName'        => $RaceName,
            'description'     => $Description,
            'physicalFeature' => EntryTable::FormatForHistory('Race', 'PhysicalFeature', $PhysicalFeature),
        ];
        $Detail = HistoryDetail::Update($RaceName, $OldDisplay, $NewDisplay, $Labels);
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
        'RaceId' => $NewRaceId,
        'UpdateDate' => $NewUpdateDate,
        'Message' => '種族を' . $Operation . 'しました'
    ]);

} catch (Exception $E) {
    Database::Rollback();
    Logger::Error($FunctionName, '保存処理エラー', $UserId, $E->getMessage());
    Response::Error('種族の保存に失敗しました');
}
