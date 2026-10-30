<?php
/**
 * SaveCalendar.php
 * 元号登録・更新API
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

$FunctionName = '元号設定';
$UserId = '';

try {
    /** リクエストデータを取得 */
    $Input = Response::GetJsonInput();
    $CaleId = $Input['CaleId'] ?? null;
    $WorkId = $AuthWorkId ?? '';
    $EraName = $Input['EraName'] ?? '';
    $StartYear = $Input['StartYear'] ?? null;
    $EndYear = $Input['EndYear'] ?? null;
    $Description = $Input['Description'] ?? null;
    $UserId = $AuthUserId;
    $UpdateDate = $Input['UpdateDate'] ?? null;

    $Operation = empty($CaleId) ? '登録' : '更新';
    // 更新履歴の詳細メッセージ（登録/更新分岐でそれぞれ生成）
    $Detail = null;
    Logger::Info($FunctionName, $Operation . '処理開始', $UserId);

    /** 入力チェック */
    if (empty($WorkId)) {
        Response::Error('作品IDが指定されていません');
    }

    if (empty($EraName)) {
        Response::Error('元号名を入力してください');
    }

    /** DB接続 */
    $Pdo = Database::GetConnection();
    Database::BeginTransaction();

    /** 空文字をNULLに変換 */
    $CaleId = !empty($CaleId) ? $CaleId : null;
    $StartYear = !empty($StartYear) ? $StartYear : null;
    $EndYear = !empty($EndYear) ? $EndYear : null;
    $Description = !empty($Description) ? $Description : null;

    if (empty($CaleId)) {
        /** 新規登録 */
        $Sql = "
            INSERT INTO CalendarInfo (
                workId, eraName, startYear, endYear, description,
                registDate, updateDate, registUser, updateUser
            ) VALUES (
                :workId, :eraName, :startYear, :endYear, :description,
                CURRENT_TIMESTAMP(3), CURRENT_TIMESTAMP(3), :registUser, :updateUser
            )
            RETURNING caleId, updateDate
        ";

        $Stmt = $Pdo->prepare($Sql);
        $Stmt->execute([
            ':workId' => $WorkId,
            ':eraName' => $EraName,
            ':startYear' => $StartYear,
            ':endYear' => $EndYear,
            ':description' => $Description,
            ':registUser' => $UserId,
            ':updateUser' => $UserId
        ]);

        $Result = $Stmt->fetch();
        $NewCaleId = $Result['caleid'];
        $NewUpdateDate = $Result['updatedate'];
        // 更新履歴の詳細メッセージを生成（登録）
        $Detail = HistoryDetail::Register($EraName);

    } else {
        /** 更新 */
        /** 差分履歴用：更新前の表示値を取得（キーはPDO既定の小文字） */
        $OldRowStmt = $Pdo->prepare("
            SELECT eraName, startYear, endYear, description
            FROM CalendarInfo
            WHERE caleId = :caleId AND workId = :workId
        ");
        $OldRowStmt->bindValue(':caleId', $CaleId, PDO::PARAM_STR);
        $OldRowStmt->bindValue(':workId', $WorkId, PDO::PARAM_STR);
        $OldRowStmt->execute();
        $OldRow = $OldRowStmt->fetch() ?: [];

        $Sql = "
            UPDATE CalendarInfo SET
                eraName = :eraName,
                startYear = :startYear,
                endYear = :endYear,
                description = :description,
                updateDate = CURRENT_TIMESTAMP(3),
                updateUser = :updateUser
            WHERE caleId = :caleId AND workId = :workId AND updateDate = :updateDate
            RETURNING updateDate
        ";

        $Stmt = $Pdo->prepare($Sql);
        $Stmt->execute([
            ':caleId' => $CaleId,
            ':workId' => $WorkId,
            ':eraName' => $EraName,
            ':startYear' => $StartYear,
            ':endYear' => $EndYear,
            ':description' => $Description,
            ':updateUser' => $UserId,
            ':updateDate' => $UpdateDate
        ]);
        $UpdateResult = $Stmt->fetch();

        if (!$UpdateResult) {
            Database::Rollback();
            Response::ConflictError();
        }

        $NewCaleId = $CaleId;
        $NewUpdateDate = $UpdateResult['updatedate'];

        /** 更新履歴の詳細メッセージを生成（更新・列単位差分） */
        // 差分対象の列 → 和名
        $Labels = [
            'eraName'     => '元号名',
            'startYear'   => '開始年',
            'endYear'     => '終了年',
            'description' => '説明',
        ];
        // 更新前の表示値
        $OldDisplay = [
            'eraName'     => $OldRow['eraname']     ?? null,
            'startYear'   => $OldRow['startyear']   ?? null,
            'endYear'     => $OldRow['endyear']     ?? null,
            'description' => $OldRow['description'] ?? null,
        ];
        // 更新後の表示値
        $NewDisplay = [
            'eraName'     => $EraName,
            'startYear'   => $StartYear,
            'endYear'     => $EndYear,
            'description' => $Description,
        ];
        $Detail = HistoryDetail::Update($EraName, $OldDisplay, $NewDisplay, $Labels);
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
        'CaleId' => $NewCaleId,
        'UpdateDate' => $NewUpdateDate,
        'Message' => '元号を' . $Operation . 'しました'
    ]);

} catch (Exception $E) {
    Database::Rollback();
    Logger::Error($FunctionName, '保存処理エラー', $UserId, $E->getMessage());
    Response::Error('元号の保存に失敗しました');
}
