<?php
/**
 * DeleteSchedule.php
 * スケジュール削除API
 */

require_once __DIR__ . '/../Common/Database.php';
require_once __DIR__ . '/../Common/Logger.php';
require_once __DIR__ . '/../Common/Response.php';
require_once __DIR__ . '/../Common/Auth.php';
// 更新履歴 詳細メッセージ生成
require_once __DIR__ . '/../Common/HistoryDetail.php';

/**
 * スケジュールを削除する
 */
function DeleteSchedule(): void
{
    global $AuthUserId;
    global $AuthWorkId;
    // POSTメソッドのみ許可
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        Response::Error('不正なリクエストです', 405);
        return;
    }
    $FunctionName = 'スケジュール設定';
    $UserId = '';

    try {
        /** リクエストデータを取得 */
        $Input = Response::GetJsonInput();
        $ScheduleId = $Input['ScheduleId'] ?? null;
        $WorkId = $AuthWorkId ?? '';
        $UserId = $AuthUserId;
        $UpdateDate = $Input['UpdateDate'] ?? null;

        Logger::Info($FunctionName, '削除処理開始', $UserId);

        /** 入力チェック */
        if (empty($ScheduleId)) {
            Response::Error('スケジュールIDが指定されていません');
            return;
        }

        if (empty($WorkId)) {
            Response::Error('作品IDが指定されていません');
            return;
        }

        /** DB接続 */
        $Pdo = Database::GetConnection();
        Database::BeginTransaction();

        /** 削除詳細メッセージ用に名称（タイトル）を取得 */
        $NameStmt = $Pdo->prepare("SELECT title FROM ScheduleInfo WHERE scheduleId = :scheduleId AND workId = :workId");
        $NameStmt->execute([':scheduleId' => $ScheduleId, ':workId' => $WorkId]);
        $ScheduleInfo = $NameStmt->fetch();
        // 更新履歴の詳細メッセージを生成（削除）
        $Detail = HistoryDetail::Delete($ScheduleInfo['title'] ?? null);

        /** スケジュールを削除 */
        $Sql = "DELETE FROM ScheduleInfo WHERE scheduleId = :scheduleId AND workId = :workId AND updateDate = :updateDate";
        $Stmt = $Pdo->prepare($Sql);
        $Stmt->execute([
            ':scheduleId' => $ScheduleId,
            ':workId' => $WorkId,
            ':updateDate' => $UpdateDate
        ]);
        if ($Stmt->rowCount() === 0) {
            Database::Rollback();
            Response::ConflictError();
            return;
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

        /** 更新履歴を登録 */
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
            'Message' => 'スケジュールを削除しました'
        ]);

    } catch (Exception $E) {
        Database::Rollback();
        Logger::Error($FunctionName, '削除処理エラー', $UserId, $E->getMessage());
        Response::Error('スケジュールの削除に失敗しました');
    }
}

/** API実行 */
DeleteSchedule();
