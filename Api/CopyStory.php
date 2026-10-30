<?php
/**
 * CopyStory.php
 * ストーリーコピーAPI
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
$Operation = 'コピー';

try {
    $Input = Response::GetJsonInput();
    $WorkId = $AuthWorkId ?? '';
    $StoryId = $Input['StoryId'] ?? null;
    $UpdateDate = $Input['UpdateDate'] ?? null;
    $UserId = $AuthUserId;

    Logger::Info($FunctionName, $Operation . '処理開始', $UserId);

    if (empty($WorkId)) {
        Response::Error('作品IDが指定されていません');
    }
    if (empty($StoryId)) {
        Response::Error('ストーリーIDが指定されていません');
    }

    $Pdo = Database::GetConnection();
    Database::BeginTransaction();

    $SourceInfoStmt = $Pdo->prepare("
        SELECT storyId, storyName, productionType, plotId, updateDate
        FROM StoryInfo
        WHERE storyId = :storyId AND workId = :workId
    ");
    $SourceInfoStmt->execute([
        ':storyId' => $StoryId,
        ':workId' => $WorkId
    ]);
    $SourceInfo = $SourceInfoStmt->fetch();
    if (!$SourceInfo) {
        Database::Rollback();
        Response::Error('対象のストーリーが見つかりません');
    }
    if ($UpdateDate !== null && $UpdateDate !== '' && (string)$SourceInfo['updatedate'] !== (string)$UpdateDate) {
        Database::Rollback();
        Response::ConflictError();
    }

    $CopySuffix = '＿コピー';
    $MaxNameLength = 100;
    $SourceName = (string)$SourceInfo['storyname'];
    $SuffixLength = mb_strlen($CopySuffix);
    if (mb_strlen($SourceName) + $SuffixLength > $MaxNameLength) {
        $SourceName = mb_substr($SourceName, 0, $MaxNameLength - $SuffixLength);
    }
    $NewStoryName = $SourceName . $CopySuffix;

    $InsertInfoStmt = $Pdo->prepare("
        INSERT INTO StoryInfo (
            storyName, productionType, plotId, workId,
            registDate, updateDate, registUser, updateUser
        ) VALUES (
            :storyName, :productionType, :plotId, :workId,
            CURRENT_TIMESTAMP(3), CURRENT_TIMESTAMP(3), :registUser, :updateUser
        )
        RETURNING storyId, updateDate
    ");
    $InsertInfoStmt->execute([
        ':storyName' => $NewStoryName,
        ':productionType' => $SourceInfo['productiontype'],
        ':plotId' => $SourceInfo['plotid'],
        ':workId' => $WorkId,
        ':registUser' => $UserId,
        ':updateUser' => $UserId
    ]);
    $NewInfo = $InsertInfoStmt->fetch();
    $NewStoryId = $NewInfo['storyid'];
    $NewUpdateDate = $NewInfo['updatedate'];

    $SourceRowsStmt = $Pdo->prepare("
        SELECT indexNum, narrator, narratorCharaId, monologue, content
        FROM StoryRows
        WHERE storyId = :storyId
        ORDER BY indexNum ASC
    ");
    $SourceRowsStmt->execute([':storyId' => $StoryId]);
    $SourceRows = $SourceRowsStmt->fetchAll();

    if (!empty($SourceRows)) {
        $InsertRowStmt = $Pdo->prepare("
            INSERT INTO StoryRows (
                storyId, indexNum, narrator, narratorCharaId, monologue, content,
                registDate, updateDate, registUser, updateUser
            ) VALUES (
                :storyId, :indexNum, :narrator, :narratorCharaId, :monologue, :content,
                CURRENT_TIMESTAMP(3), CURRENT_TIMESTAMP(3), :registUser, :updateUser
            )
        ");
        foreach ($SourceRows as $Row) {
            // narratorCharaId が入っていれば narrator は null に強制（SaveStory と同じ整合性ルール）
            $NarratorCharaId = !empty($Row['narratorcharaid']) ? $Row['narratorcharaid'] : null;
            $Narrator = $NarratorCharaId !== null ? null : $Row['narrator'];
            $InsertRowStmt->execute([
                ':storyId' => $NewStoryId,
                ':indexNum' => (int)$Row['indexnum'],
                ':narrator' => $Narrator,
                ':narratorCharaId' => $NarratorCharaId,
                ':monologue' => !empty($Row['monologue']) ? 't' : 'f',
                ':content' => $Row['content'],
                ':registUser' => $UserId,
                ':updateUser' => $UserId
            ]);
        }
    }

    $UpdateWorkStmt = $Pdo->prepare("
        UPDATE WorkInfo SET
            updateDate = CURRENT_TIMESTAMP(3),
            updateUser = :updateUser
        WHERE workId = :workId
    ");
    $UpdateWorkStmt->execute([
        ':workId' => $WorkId,
        ':updateUser' => $UserId
    ]);

    $HistoryStmt = $Pdo->prepare("
        INSERT INTO UpdateHistory (
            workId, functionName, operation, registDate, updateDate, registUser, updateUser
        ) VALUES (
            :workId, :functionName, :operation, CURRENT_TIMESTAMP(3), CURRENT_TIMESTAMP(3), :registUser, :updateUser
        )
    ");
    $HistoryStmt->execute([
        ':workId' => $WorkId,
        ':functionName' => $FunctionName,
        ':operation' => $Operation,
        ':registUser' => $UserId,
        ':updateUser' => $UserId
    ]);

    Database::Commit();

    Logger::Info($FunctionName, $Operation . '処理終了', $UserId);

    Response::Success([
        'StoryId' => $NewStoryId,
        'StoryName' => $NewStoryName,
        'UpdateDate' => $NewUpdateDate,
        'Message' => 'ストーリーを' . $Operation . 'しました'
    ]);

} catch (Exception $E) {
    Database::Rollback();
    Logger::Error($FunctionName, 'コピー処理エラー', $UserId, $E->getMessage());
    Response::Error('ストーリーのコピーに失敗しました');
}
