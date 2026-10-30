<?php
/**
 * SaveSchedule.php
 * スケジュール登録・更新API
 */

require_once __DIR__ . '/../Common/Database.php';
require_once __DIR__ . '/../Common/Logger.php';
require_once __DIR__ . '/../Common/Response.php';
require_once __DIR__ . '/../Common/Auth.php';
// 更新履歴 詳細メッセージ生成
require_once __DIR__ . '/../Common/HistoryDetail.php';

/**
 * スケジュールを登録・更新する
 */
function SaveSchedule(): void
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
        $Title = $Input['Title'] ?? '';
        $StartDate = $Input['StartDate'] ?? null;
        $EndDate = $Input['EndDate'] ?? null;
        $Color = $Input['Color'] ?? null;
        $Description = $Input['Description'] ?? null;
        $UserId = $AuthUserId;
        $UpdateDate = $Input['UpdateDate'] ?? null;

        $Operation = empty($ScheduleId) ? '登録' : '更新';
        // 更新履歴の詳細メッセージ（登録/更新分岐でそれぞれ生成）
        $Detail = null;
        Logger::Info($FunctionName, $Operation . '処理開始', $UserId);

        /** 入力チェック */
        if (empty($WorkId)) {
            Response::Error('作品IDが指定されていません');
            return;
        }

        if (empty($Title)) {
            Response::Error('タイトルを入力してください');
            return;
        }

        /** DB接続 */
        $Pdo = Database::GetConnection();
        Database::BeginTransaction();

        /** 空文字をNULLに変換 */
        $StartDate = !empty($StartDate) ? $StartDate : null;
        $EndDate = !empty($EndDate) ? $EndDate : null;
        $Color = !empty($Color) ? $Color : null;
        $Description = !empty($Description) ? $Description : null;

        if (empty($ScheduleId)) {
            /** 新規登録 */
            $Sql = "
                INSERT INTO ScheduleInfo (
                    workId, title, startDate, endDate, color, description,
                    registDate, updateDate, registUser, updateUser
                ) VALUES (
                    :workId, :title, :startDate, :endDate, :color, :description,
                    CURRENT_TIMESTAMP(3), CURRENT_TIMESTAMP(3), :registUser, :updateUser
                )
                RETURNING scheduleId, updateDate
            ";

            $Stmt = $Pdo->prepare($Sql);
            $Stmt->execute([
                ':workId' => $WorkId,
                ':title' => $Title,
                ':startDate' => $StartDate,
                ':endDate' => $EndDate,
                ':color' => $Color,
                ':description' => $Description,
                ':registUser' => $UserId,
                ':updateUser' => $UserId
            ]);

            $Result = $Stmt->fetch();
            $NewScheduleId = $Result['scheduleid'];
            $NewUpdateDate = $Result['updatedate'];

            // 更新履歴の詳細メッセージを生成（登録）
            $Detail = HistoryDetail::Register($Title);

        } else {
            /** 差分履歴用：更新前の表示値を取得（FKなし） */
            $OldRowStmt = $Pdo->prepare("
                SELECT title, startDate, endDate, color, description
                FROM ScheduleInfo
                WHERE scheduleId = :scheduleId AND workId = :workId
            ");
            $OldRowStmt->bindValue(':scheduleId', $ScheduleId, PDO::PARAM_INT);
            $OldRowStmt->bindValue(':workId',     $WorkId,     PDO::PARAM_STR);
            $OldRowStmt->execute();
            $OldRow = $OldRowStmt->fetch() ?: [];

            /** 更新 */
            $Sql = "
                UPDATE ScheduleInfo SET
                    title = :title,
                    startDate = :startDate,
                    endDate = :endDate,
                    color = :color,
                    description = :description,
                    updateDate = CURRENT_TIMESTAMP(3),
                    updateUser = :updateUser
                WHERE scheduleId = :scheduleId AND workId = :workId AND updateDate = :updateDate
                RETURNING updateDate
            ";

            $Stmt = $Pdo->prepare($Sql);
            $Stmt->execute([
                ':scheduleId' => $ScheduleId,
                ':workId' => $WorkId,
                ':title' => $Title,
                ':startDate' => $StartDate,
                ':endDate' => $EndDate,
                ':color' => $Color,
                ':description' => $Description,
                ':updateUser' => $UserId,
                ':updateDate' => $UpdateDate
            ]);
            $UpdateResult = $Stmt->fetch();

            if (!$UpdateResult) {
                Database::Rollback();
                Response::ConflictError();
            }

            $NewScheduleId = $ScheduleId;
            $NewUpdateDate = $UpdateResult['updatedate'];

            /** 更新履歴の詳細メッセージを生成（更新・列単位差分） */
            // 差分対象の列 → 和名（PK/workId/監査列は対象外）
            $Labels = [
                'title'       => 'タイトル',
                'startDate'   => '開始日',
                'endDate'     => '終了日',
                'color'       => '色',
                'description' => '説明',
            ];
            // 更新前の表示値
            $OldDisplay = [
                'title'       => $OldRow['title']       ?? null,
                'startDate'   => $OldRow['startdate']   ?? null,
                'endDate'     => $OldRow['enddate']     ?? null,
                'color'       => $OldRow['color']       ?? null,
                'description' => $OldRow['description'] ?? null,
            ];
            // 更新後の表示値
            $NewDisplay = [
                'title'       => $Title,
                'startDate'   => $StartDate,
                'endDate'     => $EndDate,
                'color'       => $Color,
                'description' => $Description,
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
            'ScheduleId' => $NewScheduleId,
            'UpdateDate' => $NewUpdateDate,
            'Message' => 'スケジュールを' . $Operation . 'しました'
        ]);

    } catch (Exception $E) {
        Database::Rollback();
        Logger::Error($FunctionName, '保存処理エラー', $UserId, $E->getMessage());
        Response::Error('スケジュールの保存に失敗しました');
    }
}

/** API実行 */
SaveSchedule();
