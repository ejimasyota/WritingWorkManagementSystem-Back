<?php
/**
 * UpdateStoryOrder.php
 * ストーリー順序更新API（ドラッグ&ドロップ対応）
 */

require_once __DIR__ . '/../Common/Database.php';
require_once __DIR__ . '/../Common/Logger.php';
require_once __DIR__ . '/../Common/Response.php';
require_once __DIR__ . '/../Common/Auth.php';

// POSTメソッドのみ許可
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::Error('不正なリクエストです', 405);
}

$FunctionName = 'ストーリー作成';
$UserId = '';

try {
    $Input = Response::GetJsonInput();
    $WorkId = $AuthWorkId ?? '';
    $StoryId = $Input['StoryId'] ?? null;
    $OrderList = $Input['OrderList'] ?? [];
    $UpdateDate = $Input['UpdateDate'] ?? null;
    $UserId = $AuthUserId;

    Logger::Info($FunctionName, '順序更新処理開始', $UserId);

    if (empty($WorkId)) {
        Response::Error('作品IDが指定されていません');
    }
    if (empty($StoryId)) {
        Response::Error('ストーリーIDが指定されていません');
    }
    if (empty($OrderList) || !is_array($OrderList)) {
        Response::Error('順序情報が指定されていません');
    }

    $Pdo = Database::GetConnection();
    Database::BeginTransaction();

    // 並び順の同時実行競合を防ぐため、storyIdでアドバイザリロックを取得
    $LockStmt = $Pdo->prepare("SELECT pg_advisory_xact_lock(1001, hashtext(:storyId))");
    $LockStmt->execute([':storyId' => (string)$StoryId]);

    // OrderList が対象ストーリーの全行を網羅しているか検証
    $CountStmt = $Pdo->prepare("SELECT COUNT(*) AS cnt FROM StoryRows WHERE storyId = :storyId");
    $CountStmt->execute([':storyId' => $StoryId]);
    $CountResult = $CountStmt->fetch();
    if ((int)$CountResult['cnt'] !== count($OrderList)) {
        Database::Rollback();
        Response::Error('順序情報が不完全です。画面をリフレッシュしてください', 409);
    }

    // OrderList 内の IndexNum の重複・欠番を検出
    $Indexes = array_map(fn($o) => (int)$o['IndexNum'], $OrderList);
    if (count(array_unique($Indexes)) !== count($Indexes)) {
        Database::Rollback();
        Response::Error('順序情報に重複があります');
    }

    $TempUpdateSql = "
        UPDATE StoryRows SET
            indexNum = :tempIndexNum,
            updateDate = CURRENT_TIMESTAMP(3),
            updateUser = :updateUser
        WHERE storyRowId = :storyRowId AND storyId = :storyId
    ";
    $TempUpdateStmt = $Pdo->prepare($TempUpdateSql);
    foreach ($OrderList as $Order) {
        $TempUpdateStmt->execute([
            ':storyRowId' => $Order['StoryRowId'],
            ':storyId' => $StoryId,
            ':tempIndexNum' => -1 * $Order['IndexNum'],
            ':updateUser' => $UserId
        ]);
    }

    $UpdateSql = "
        UPDATE StoryRows SET
            indexNum = :indexNum,
            updateDate = CURRENT_TIMESTAMP(3),
            updateUser = :updateUser
        WHERE storyRowId = :storyRowId AND storyId = :storyId
    ";
    $UpdateStmt = $Pdo->prepare($UpdateSql);
    foreach ($OrderList as $Order) {
        $UpdateStmt->execute([
            ':storyRowId' => $Order['StoryRowId'],
            ':storyId' => $StoryId,
            ':indexNum' => $Order['IndexNum'],
            ':updateUser' => $UserId
        ]);
    }

    $UpdateStorySql = "
        UPDATE StoryInfo SET
            updateDate = CURRENT_TIMESTAMP(3),
            updateUser = :updateUser
        WHERE storyId = :storyId AND workId = :workId
          AND updateDate = :updateDate
        RETURNING updateDate
    ";
    $UpdateStoryStmt = $Pdo->prepare($UpdateStorySql);
    $UpdateStoryStmt->execute([
        ':storyId' => $StoryId,
        ':workId' => $WorkId,
        ':updateUser' => $UserId,
        ':updateDate' => $UpdateDate
    ]);
    $UpdateStoryResult = $UpdateStoryStmt->fetch();

    if (!$UpdateStoryResult) {
        Database::Rollback();
        Response::ConflictError();
    }

    $NewUpdateDate = $UpdateStoryResult['updatedate'];

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
            workId, functionName, operation, registDate, updateDate, registUser, updateUser
        ) VALUES (
            :workId, :functionName, :operation, CURRENT_TIMESTAMP(3), CURRENT_TIMESTAMP(3), :registUser, :updateUser
        )
    ";
    $HistoryStmt = $Pdo->prepare($HistorySql);
    $HistoryStmt->execute([
        ':workId' => $WorkId,
        ':functionName' => $FunctionName,
        ':operation' => '順序更新',
        ':registUser' => $UserId,
        ':updateUser' => $UserId
    ]);

    Database::Commit();

    Logger::Info($FunctionName, '順序更新処理終了', $UserId);

    Response::Success([
        'Message' => 'ストーリーの順序を更新しました',
        'UpdateDate' => $NewUpdateDate
    ]);

} catch (Exception $E) {
    Database::Rollback();
    Logger::Error($FunctionName, '順序更新処理エラー', $UserId, $E->getMessage());
    Response::Error('ストーリーの順序更新に失敗しました');
}
