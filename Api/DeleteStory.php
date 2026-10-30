<?php
/**
 * DeleteStory.php
 * ストーリー削除API
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

$FunctionName = 'ストーリー作成';
$UserId = '';

try {
    $Input = Response::GetJsonInput();
    $Mode = $Input['Mode'] ?? 'row';
    $StoryId = $Input['StoryId'] ?? null;
    $StoryRowId = $Input['StoryRowId'] ?? null;
    $WorkId = $AuthWorkId ?? '';
    $UserId = $AuthUserId;
    $UpdateDate = $Input['UpdateDate'] ?? null;
    // 更新履歴の詳細メッセージ（削除対象の名称から生成）
    $Detail = null;

    Logger::Info($FunctionName, '削除処理開始', $UserId);

    if (empty($WorkId)) {
        Response::Error('作品IDが指定されていません');
    }

    $Pdo = Database::GetConnection();
    Database::BeginTransaction();

    if ($Mode === 'info') {
        if (empty($StoryId)) {
            Response::Error('ストーリーIDが指定されていません');
        }

        // 削除詳細メッセージ用にストーリー名を取得
        $NameStmt = $Pdo->prepare("SELECT storyName FROM StoryInfo WHERE storyId = :storyId AND workId = :workId");
        $NameStmt->execute([':storyId' => $StoryId, ':workId' => $WorkId]);
        $NameRow = $NameStmt->fetch();
        $Detail = HistoryDetail::Delete($NameRow['storyname'] ?? null);

        $Sql = "DELETE FROM StoryInfo WHERE storyId = :storyId AND workId = :workId AND updateDate = :updateDate";
        $Stmt = $Pdo->prepare($Sql);
        $Stmt->execute([
            ':storyId' => $StoryId,
            ':workId' => $WorkId,
            ':updateDate' => $UpdateDate
        ]);
        if ($Stmt->rowCount() === 0) {
            Database::Rollback();
            Response::ConflictError();
        }
    } else {
        if (empty($StoryId)) {
            Response::Error('ストーリーIDが指定されていません');
        }

        // 対象ストーリーが現在の作品に属することを検証（他作品への越境削除を防止）
        $OwnStmt = $Pdo->prepare("SELECT storyName FROM StoryInfo WHERE storyId = :storyId AND workId = :workId");
        $OwnStmt->execute([':storyId' => $StoryId, ':workId' => $WorkId]);
        $OwnRow = $OwnStmt->fetch();
        if (!$OwnRow) {
            Database::Rollback();
            Response::Error('対象のストーリーが見つかりません', 403);
        }

        if (empty($StoryRowId)) {
            // 全行削除：親ストーリー名を削除詳細の名称とする
            $Detail = HistoryDetail::Delete($OwnRow['storyname'] ?? null);
            $Sql = "DELETE FROM StoryRows WHERE storyId = :storyId";
            $Stmt = $Pdo->prepare($Sql);
            $Stmt->execute([
                ':storyId' => $StoryId
            ]);
        } else {
            $GetIndexSql = "SELECT indexNum, content FROM StoryRows WHERE storyRowId = :storyRowId AND storyId = :storyId";
            $GetIndexStmt = $Pdo->prepare($GetIndexSql);
            $GetIndexStmt->execute([
                ':storyRowId' => $StoryRowId,
                ':storyId' => $StoryId
            ]);
            $StoryRow = $GetIndexStmt->fetch();

            if (!$StoryRow) {
                Database::Rollback();
                Response::Error('削除対象のストーリー行が見つかりません');
            }

            $DeletedIndexNum = $StoryRow['indexnum'];
            // 単一行削除：本文を削除詳細の名称とする
            $Detail = HistoryDetail::Delete($StoryRow['content'] ?? null);

            $Sql = "DELETE FROM StoryRows WHERE storyRowId = :storyRowId AND storyId = :storyId";
            $Stmt = $Pdo->prepare($Sql);
            $Stmt->execute([
                ':storyRowId' => $StoryRowId,
                ':storyId' => $StoryId
            ]);

            $TempReorderSql = "
                UPDATE StoryRows SET
                    indexNum = -(indexNum - 1)
                WHERE storyId = :storyId AND indexNum > :deletedIndex
            ";
            $TempReorderStmt = $Pdo->prepare($TempReorderSql);
            $TempReorderStmt->execute([
                ':storyId' => $StoryId,
                ':deletedIndex' => $DeletedIndexNum
            ]);

            $ReorderSql = "
                UPDATE StoryRows SET
                    indexNum = -indexNum,
                    updateDate = CURRENT_TIMESTAMP(3),
                    updateUser = :updateUser
                WHERE storyId = :storyId AND indexNum < 0
            ";
            $ReorderStmt = $Pdo->prepare($ReorderSql);
            $ReorderStmt->execute([
                ':storyId' => $StoryId,
                ':updateUser' => $UserId
            ]);
        }
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
    $HistoryStmt->execute([
        ':workId' => $WorkId,
        ':functionName' => $FunctionName,
        ':operation' => '削除',
        ':detail' => $Detail,
        ':registUser' => $UserId,
        ':updateUser' => $UserId
    ]);

    Database::Commit();

    Logger::Info($FunctionName, '削除処理終了', $UserId);

    Response::Success([
        'Message' => $Mode === 'info' ? 'ストーリーを削除しました' : (empty($StoryRowId) ? 'ストーリー行を全削除しました' : 'ストーリー行を削除しました')
    ]);

} catch (Exception $E) {
    Database::Rollback();
    Logger::Error($FunctionName, '削除処理エラー', $UserId, $E->getMessage());
    Response::Error('ストーリーの削除に失敗しました');
}


