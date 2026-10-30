<?php
/**
 * SaveClue.php
 * 伏線メモ登録・更新API
 */

require_once __DIR__ . '/../Common/Database.php';
require_once __DIR__ . '/../Common/Logger.php';
require_once __DIR__ . '/../Common/Response.php';
require_once __DIR__ . '/../Common/Auth.php';
require_once __DIR__ . '/../Common/HistoryDetail.php';

// POSTメソッドのみ許可
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::Error('不正なリクエストです', 405);
}

$FunctionName = '伏線メモ';
$UserId = '';

try {
    /** リクエストデータを取得 */
    $Input = Response::GetJsonInput();
    $ClueId = $Input['ClueId'] ?? null;
    $WorkId = $AuthWorkId ?? '';
    $Title = $Input['Title'] ?? '';
    $Content = $Input['Content'] ?? null;
    $RecoveryStatus = $Input['RecoveryStatus'] ?? null;
    $UserId = $AuthUserId;
    $UpdateDate = $Input['UpdateDate'] ?? null;

    $Operation = empty($ClueId) ? '登録' : '更新';
    // 更新履歴の詳細メッセージ（登録/更新分岐でそれぞれ生成）
    $Detail = null;
    Logger::Info($FunctionName, $Operation . '処理開始', $UserId);

    /** 入力チェック */
    if (empty($WorkId)) {
        Response::Error('作品IDが指定されていません');
    }

    if (empty($Title)) {
        Response::Error('タイトルを入力してください');
    }

    /** DB接続 */
    $Pdo = Database::GetConnection();
    Database::BeginTransaction();

    /** 空文字をNULLに変換 */
    $ClueId = !empty($ClueId) ? $ClueId : null;
    $Content = !empty($Content) ? $Content : null;
    $RecoveryStatus = !empty($RecoveryStatus) ? $RecoveryStatus : null;

    if (empty($ClueId)) {
        /** 新規登録 */
        $Sql = "
            INSERT INTO ClueInfo (
                workId, title, content, recoveryStatus,
                registDate, updateDate, registUser, updateUser
            ) VALUES (
                :workId, :title, :content, :recoveryStatus,
                CURRENT_TIMESTAMP(3), CURRENT_TIMESTAMP(3), :registUser, :updateUser
            )
            RETURNING clueId, updateDate
        ";

        $Stmt = $Pdo->prepare($Sql);
        $Stmt->execute([
            ':workId' => $WorkId,
            ':title' => $Title,
            ':content' => $Content,
            ':recoveryStatus' => $RecoveryStatus,
            ':registUser' => $UserId,
            ':updateUser' => $UserId
        ]);

        $Result = $Stmt->fetch();
        $NewClueId = $Result['clueid'];
        $NewUpdateDate = $Result['updatedate'];

        // 更新履歴の詳細メッセージを生成（登録）
        $Detail = HistoryDetail::Register($Title);

    } else {
        /** 更新 */
        // 差分履歴用：更新前の表示値を取得（FK列なし）
        $OldRowStmt = $Pdo->prepare("
            SELECT title, content, recoveryStatus
            FROM ClueInfo
            WHERE clueId = :clueId AND workId = :workId
        ");
        $OldRowStmt->bindValue(':clueId', $ClueId, PDO::PARAM_STR);
        $OldRowStmt->bindValue(':workId', $WorkId, PDO::PARAM_STR);
        $OldRowStmt->execute();
        $OldRow = $OldRowStmt->fetch() ?: [];

        $Sql = "
            UPDATE ClueInfo SET
                title = :title,
                content = :content,
                recoveryStatus = :recoveryStatus,
                updateDate = CURRENT_TIMESTAMP(3),
                updateUser = :updateUser
            WHERE clueId = :clueId AND workId = :workId AND updateDate = :updateDate
            RETURNING updateDate
        ";

        $Stmt = $Pdo->prepare($Sql);
        $Stmt->execute([
            ':clueId' => $ClueId,
            ':workId' => $WorkId,
            ':title' => $Title,
            ':content' => $Content,
            ':recoveryStatus' => $RecoveryStatus,
            ':updateUser' => $UserId,
            ':updateDate' => $UpdateDate
        ]);
        $UpdateResult = $Stmt->fetch();

        if (!$UpdateResult) {
            Database::Rollback();
            Response::ConflictError();
        }

        $NewClueId = $ClueId;
        $NewUpdateDate = $UpdateResult['updatedate'];

        // 更新履歴の詳細メッセージを生成（更新・列単位差分。FK列なし）
        $Labels = [
            'title'          => 'タイトル',
            'content'        => '内容',
            'recoveryStatus' => '回収方法',
        ];
        $OldDisplay = [
            'title'          => $OldRow['title']          ?? null,
            'content'        => $OldRow['content']        ?? null,
            'recoveryStatus' => $OldRow['recoverystatus'] ?? null,
        ];
        $NewDisplay = [
            'title'          => $Title,
            'content'        => $Content,
            'recoveryStatus' => $RecoveryStatus,
        ];
        $Detail = HistoryDetail::Update($Title, $OldDisplay, $NewDisplay, $Labels);
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
        'ClueId' => $NewClueId,
        'UpdateDate' => $NewUpdateDate,
        'Message' => '伏線メモを' . $Operation . 'しました'
    ]);

} catch (Exception $E) {
    Database::Rollback();
    Logger::Error($FunctionName, '保存処理エラー', $UserId, $E->getMessage());
    Response::Error('伏線メモの保存に失敗しました');
}
