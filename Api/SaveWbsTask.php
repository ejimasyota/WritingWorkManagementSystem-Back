<?php
/**
 * SaveWbsTask.php
 * WBSタスク登録・更新API
 *
 * 権限による更新範囲の制御：
 *  - 作成者・管理者   : 登録・全項目の更新が可能
 *  - 参加メンバー     : 登録は不可。自身が担当するタスクの進捗率のみ更新可能
 *                      （画面側でも制御するが、直接APIを叩かれた場合に備えサーバ側でも判定する）
 *
 * 担当者は WbsTaskAssignee（中間テーブル）で保持し、1タスクに複数人を割り当てられる。
 * 「担当者は1人以上」はDB制約で表現できないため、本APIで担保する。
 */

require_once __DIR__ . '/../Common/Database.php';
require_once __DIR__ . '/../Common/Logger.php';
require_once __DIR__ . '/../Common/Response.php';
require_once __DIR__ . '/../Common/Auth.php';
require_once __DIR__ . '/../Common/HistoryDetail.php';
require_once __DIR__ . '/../Common/DateUtil.php';

// POSTメソッドのみ許可
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::Error('不正なリクエストです', 405);
}

$FunctionName = 'WBS';
$UserId = '';

/**
 * タスクの担当者を洗い替える
 * 既存の紐づけを全件削除してから登録し直すことで、追加・解除を一度に反映する
 * @param PDO $Pdo DB接続
 * @param int|string $TaskId 対象タスクID
 * @param array $AssigneeIds 担当者のユーザーID配列（正規化済み）
 * @param string $UserId 操作者のユーザーID
 * @return void
 */
function SaveWbsTaskAssignees(PDO $Pdo, $TaskId, array $AssigneeIds, string $UserId): void
{
    $DeleteStmt = $Pdo->prepare('DELETE FROM WbsTaskAssignee WHERE taskId = :taskId');
    $DeleteStmt->execute([':taskId' => $TaskId]);

    if (empty($AssigneeIds)) {
        return;
    }

    $InsertStmt = $Pdo->prepare("
        INSERT INTO WbsTaskAssignee (
            taskId, userId, registDate, updateDate, registUser, updateUser
        ) VALUES (
            :taskId, :userId, CURRENT_TIMESTAMP(3), CURRENT_TIMESTAMP(3), :registUser, :updateUser
        )
    ");
    foreach ($AssigneeIds as $AssigneeId) {
        $InsertStmt->execute([
            ':taskId' => $TaskId,
            ':userId' => $AssigneeId,
            ':registUser' => $UserId,
            ':updateUser' => $UserId
        ]);
    }
}

try {
    /** リクエストデータを取得 */
    $Input = Response::GetJsonInput();
    $TaskId = $Input['TaskId'] ?? null;
    $WorkId = $AuthWorkId ?? '';
    /** 所属するWBS（タスクは必ずいずれかのWBSに属する） */
    $WbsId = $Input['WbsId'] ?? null;
    $ParentTaskId = $Input['ParentTaskId'] ?? null;
    $TaskName = $Input['TaskName'] ?? '';
    $Description = $Input['Description'] ?? null;
    $AssigneeIds = $Input['AssigneeIds'] ?? [];
    $StartDate = $Input['StartDate'] ?? null;
    $EndDate = $Input['EndDate'] ?? null;
    $Progress = $Input['Progress'] ?? 0;
    $UserId = $AuthUserId;
    $UpdateDate = $Input['UpdateDate'] ?? null;

    $Operation = empty($TaskId) ? '登録' : '更新';
    // 更新履歴の詳細メッセージ（登録/更新分岐でそれぞれ生成）
    $Detail = null;
    Logger::Info($FunctionName, $Operation . '処理開始', $UserId);

    /** 入力チェック */
    if (empty($WorkId)) {
        Response::Error('作品IDが指定されていません');
    }

    /** DB接続 */
    $Pdo = Database::GetConnection();

    /* ==========================================================================
     * 権限判定
     * ========================================================================== */
    /** ログイン者が作成者かどうか */
    $PermStmt = $Pdo->prepare("
        SELECT isCreator FROM CreaterList
        WHERE workId = :workId AND userId = :userId
    ");
    $PermStmt->execute([':workId' => $WorkId, ':userId' => $UserId]);
    $PermRow = $PermStmt->fetch();
    $IsCreator = $PermRow ? (bool)$PermRow['iscreator'] : false;

    /** 管理者かどうか */
    $AdminStmt = $Pdo->prepare("SELECT userId FROM AdminInfo WHERE userId = :userId");
    $AdminStmt->execute([':userId' => $UserId]);
    $IsAdmin = $AdminStmt->fetch() ? true : false;

    /** 全項目の編集が可能か */
    $CanEdit = $IsCreator || $IsAdmin;

    /** 参加メンバーはタスクの新規登録が行えない */
    if (!$CanEdit && empty($TaskId)) {
        Response::Error('タスクを登録する権限がありません', 403);
    }

    /** 空文字をNULLに変換 */
    $TaskId = !empty($TaskId) ? $TaskId : null;
    $WbsId = !empty($WbsId) ? $WbsId : null;
    $ParentTaskId = !empty($ParentTaskId) ? $ParentTaskId : null;
    $Description = !empty($Description) ? $Description : null;
    $StartDate = !empty($StartDate) ? $StartDate : null;
    $EndDate = !empty($EndDate) ? $EndDate : null;

    /** 担当者は配列で受け取る。空要素・重複を除去して正規化する */
    if (!is_array($AssigneeIds)) {
        $AssigneeIds = [];
    }
    $AssigneeIds = array_values(array_unique(array_filter(
        array_map(function ($V) {
            return is_string($V) ? trim($V) : '';
        }, $AssigneeIds),
        function ($V) {
            return $V !== '';
        }
    )));

    /** 進捗率は0〜100の整数に丸める */
    if (!is_numeric($Progress)) {
        Response::Error('進捗率は数値で入力してください');
    }
    $Progress = (int)$Progress;
    if ($Progress < 0 || $Progress > 100) {
        Response::Error('進捗率は0〜100の範囲で入力してください');
    }

    /* ==========================================================================
     * 参加メンバーによる進捗率のみの更新
     * ========================================================================== */
    if (!$CanEdit) {
        Database::BeginTransaction();

        /** 更新対象が自身の担当タスクかを確認（中間テーブルに自身が含まれるか） */
        $OwnStmt = $Pdo->prepare("
            SELECT taskName, progress
            FROM WbsTaskInfo
            WHERE taskId = :taskId AND workId = :workId
        ");
        $OwnStmt->execute([':taskId' => $TaskId, ':workId' => $WorkId]);
        $OwnRow = $OwnStmt->fetch();

        if (!$OwnRow) {
            Database::Rollback();
            Response::Error('対象のタスクが見つかりません');
        }

        $OwnAssigneeStmt = $Pdo->prepare("
            SELECT userId FROM WbsTaskAssignee
            WHERE taskId = :taskId AND userId = :userId
        ");
        $OwnAssigneeStmt->execute([':taskId' => $TaskId, ':userId' => $UserId]);
        if (!$OwnAssigneeStmt->fetch()) {
            Database::Rollback();
            Response::Error('自身が担当するタスクの進捗率のみ更新できます', 403);
        }

        /** 進捗率のみを更新（他の列は変更しない） */
        $ProgressStmt = $Pdo->prepare("
            UPDATE WbsTaskInfo SET
                progress = :progress,
                updateDate = CURRENT_TIMESTAMP(3),
                updateUser = :updateUser
            WHERE taskId = :taskId AND workId = :workId AND updateDate = :updateDate
            RETURNING updateDate
        ");
        $ProgressStmt->execute([
            ':progress' => $Progress,
            ':updateUser' => $UserId,
            ':taskId' => $TaskId,
            ':workId' => $WorkId,
            ':updateDate' => $UpdateDate
        ]);
        $ProgressResult = $ProgressStmt->fetch();

        if (!$ProgressResult) {
            Database::Rollback();
            Response::ConflictError();
        }

        $NewTaskId = $TaskId;
        $NewUpdateDate = $ProgressResult['updatedate'];

        // 更新履歴の詳細メッセージを生成（進捗率のみ）
        $Detail = HistoryDetail::Update(
            $OwnRow['taskname'],
            ['progress' => $OwnRow['progress'] . '%'],
            ['progress' => $Progress . '%'],
            ['progress' => '進捗率']
        );

    } else {
        /* ==========================================================================
         * 作成者・管理者による登録／全項目更新
         * ========================================================================== */
        if (empty($TaskName)) {
            Response::Error('タスク名を入力してください');
        }

        /** 所属するWBSが対象作品のものかを確認する */
        if ($WbsId === null) {
            Response::Error('WBSが指定されていません');
        }
        $WbsStmt = $Pdo->prepare("
            SELECT
                wbsId,
                to_char(startDate, 'YYYY-MM-DD') AS startdate,
                to_char(endDate,   'YYYY-MM-DD') AS enddate
            FROM WbsInfo
            WHERE wbsId = :wbsId AND workId = :workId
        ");
        $WbsStmt->execute([':wbsId' => $WbsId, ':workId' => $WorkId]);
        $WbsRow = $WbsStmt->fetch();
        if (!$WbsRow) {
            Response::Error('指定されたWBSが見つかりません');
        }

        /** 更新時は、対象タスクが指定されたWBSに属していることを確認する */
        if ($TaskId !== null) {
            $BelongStmt = $Pdo->prepare("
                SELECT taskId FROM WbsTaskInfo
                WHERE taskId = :taskId AND workId = :workId AND wbsId = :wbsId
            ");
            $BelongStmt->execute([
                ':taskId' => $TaskId,
                ':workId' => $WorkId,
                ':wbsId' => $WbsId
            ]);
            if (!$BelongStmt->fetch()) {
                Response::Error('対象のタスクが見つかりません');
            }
        }

        /** 必須項目チェック（ガント描画・期限通知・遅延判定の前提となるため必須） */
        if ($StartDate === null) {
            Response::Error('開始予定日を入力してください');
        }
        if ($EndDate === null) {
            Response::Error('終了予定日を入力してください');
        }
        if (empty($AssigneeIds)) {
            Response::Error('担当者を1人以上選択してください');
        }

        /** 開始予定日と終了予定日の前後関係をチェック */
        if ($StartDate !== null && $EndDate !== null && $StartDate > $EndDate) {
            Response::Error('終了予定日は開始予定日以降の日付を入力してください');
        }

        /** 扱える日付の範囲をチェック（期間が極端に長いと帳票の日付列が増え続けるため） */
        if (!DateUtil::IsInScheduleRange($StartDate)) {
            Response::Error(DateUtil::GetScheduleRangeMessage('開始予定日'));
        }
        if (!DateUtil::IsInScheduleRange($EndDate)) {
            Response::Error(DateUtil::GetScheduleRangeMessage('終了予定日'));
        }

        /**
         * 期間はWBSの期間内に収める（チェックの優先順位1）
         * 子タスクの場合は、この後に親タスクの期間（優先順位2）も併せてチェックする
         * 日付は YYYY-MM-DD の固定長のため、文字列比較で前後関係を判定できる
         */
        if ($StartDate < $WbsRow['startdate']) {
            Response::Error('開始予定日はWBSの開始予定日以降の日付を入力してください');
        }
        if ($EndDate > $WbsRow['enddate']) {
            Response::Error('終了予定日はWBSの終了予定日以前の日付を入力してください');
        }

        /**
         * 担当者が作品の参加者かをチェック
         * ログインユーザー自身は、CreaterList に存在しない管理者であっても
         * 担当者候補として画面に表示されるため、自身への割り当ては許可する
         */
        $AssigneeStmt = $Pdo->prepare("
            SELECT userId FROM CreaterList
            WHERE workId = :workId AND userId = :userId AND userLockFlg = FALSE
        ");
        foreach ($AssigneeIds as $CheckUserId) {
            if ($CheckUserId === $UserId) {
                continue;
            }
            $AssigneeStmt->execute([':workId' => $WorkId, ':userId' => $CheckUserId]);
            if (!$AssigneeStmt->fetch()) {
                Response::Error('担当者に指定されたユーザーはこの作品の参加者ではありません');
            }
        }

        /** 親タスクの妥当性をチェック（同一WBS内・2階層のため、親自身が子を持つことは不可） */
        $ParentTaskName = null;
        if ($ParentTaskId !== null) {
            if ($TaskId !== null && (string)$ParentTaskId === (string)$TaskId) {
                Response::Error('自分自身を親タスクに指定することはできません');
            }
            $ParentStmt = $Pdo->prepare("
                SELECT
                    taskName,
                    parentTaskId,
                    to_char(startDate, 'YYYY-MM-DD') AS startDate,
                    to_char(endDate,   'YYYY-MM-DD') AS endDate
                FROM WbsTaskInfo
                WHERE taskId = :taskId AND workId = :workId AND wbsId = :wbsId
            ");
            $ParentStmt->execute([
                ':taskId' => $ParentTaskId,
                ':workId' => $WorkId,
                ':wbsId' => $WbsId
            ]);
            $ParentRow = $ParentStmt->fetch();
            if (!$ParentRow) {
                Response::Error('指定された親タスクが見つかりません');
            }
            if ($ParentRow['parenttaskid'] !== null) {
                Response::Error('子タスクを親タスクに指定することはできません');
            }
            $ParentTaskName = $ParentRow['taskname'];

            /**
             * 子タスクの期間は親タスクの期間内に収める
             * （画面側でも制御するが、直接APIを叩かれた場合に備えサーバ側でも判定する）
             * 日付は YYYY-MM-DD の固定長のため、文字列比較で前後関係を判定できる
             */
            if ($ParentRow['startdate'] !== null && $StartDate < $ParentRow['startdate']) {
                Response::Error('開始予定日は親タスクの開始予定日以降の日付を入力してください');
            }
            if ($ParentRow['enddate'] !== null && $EndDate > $ParentRow['enddate']) {
                Response::Error('終了予定日は親タスクの終了予定日以前の日付を入力してください');
            }

            /** 更新時、自身が子タスクを持つ場合は子タスクにできない */
            if ($TaskId !== null) {
                $ChildStmt = $Pdo->prepare("
                    SELECT taskId FROM WbsTaskInfo
                    WHERE parentTaskId = :taskId AND workId = :workId
                    LIMIT 1
                ");
                $ChildStmt->execute([':taskId' => $TaskId, ':workId' => $WorkId]);
                if ($ChildStmt->fetch()) {
                    Response::Error('子タスクを持つタスクに親タスクを設定することはできません');
                }
            }
        }

        Database::BeginTransaction();

        if ($TaskId === null) {
            /** 新規登録：同一WBS・同一階層の末尾に配置する */
            $IndexStmt = $Pdo->prepare("
                SELECT COALESCE(MAX(indexNum), -1) + 1 AS nextindex
                FROM WbsTaskInfo
                WHERE workId = :workId
                  AND wbsId = :wbsId
                  AND parentTaskId IS NOT DISTINCT FROM CAST(:parentTaskId AS BIGINT)
            ");
            $IndexStmt->execute([
                ':workId' => $WorkId,
                ':wbsId' => $WbsId,
                ':parentTaskId' => $ParentTaskId
            ]);
            $IndexRow = $IndexStmt->fetch();
            $IndexNum = (int)$IndexRow['nextindex'];

            $Sql = "
                INSERT INTO WbsTaskInfo (
                    workId, wbsId, parentTaskId, taskName, description,
                    startDate, endDate, progress, indexNum,
                    registDate, updateDate, registUser, updateUser
                ) VALUES (
                    :workId, :wbsId, :parentTaskId, :taskName, :description,
                    :startDate, :endDate, :progress, :indexNum,
                    CURRENT_TIMESTAMP(3), CURRENT_TIMESTAMP(3), :registUser, :updateUser
                )
                RETURNING taskId, updateDate
            ";

            $Stmt = $Pdo->prepare($Sql);
            $Stmt->execute([
                ':workId' => $WorkId,
                ':wbsId' => $WbsId,
                ':parentTaskId' => $ParentTaskId,
                ':taskName' => $TaskName,
                ':description' => $Description,
                ':startDate' => $StartDate,
                ':endDate' => $EndDate,
                ':progress' => $Progress,
                ':indexNum' => $IndexNum,
                ':registUser' => $UserId,
                ':updateUser' => $UserId
            ]);

            $Result = $Stmt->fetch();
            $NewTaskId = $Result['taskid'];
            $NewUpdateDate = $Result['updatedate'];

            /** 担当者を登録 */
            SaveWbsTaskAssignees($Pdo, $NewTaskId, $AssigneeIds, $UserId);

            // 更新履歴の詳細メッセージを生成（登録）
            $Detail = HistoryDetail::Register($TaskName);

        } else {
            /** 更新 */
            // 差分履歴用：更新前の表示値を取得（親タスクは名称へ解決する）
            $OldRowStmt = $Pdo->prepare("
                SELECT
                    w.taskName,
                    w.description,
                    to_char(w.startDate, 'YYYY-MM-DD') AS startdate,
                    to_char(w.endDate,   'YYYY-MM-DD') AS enddate,
                    w.progress,
                    p.taskName AS parenttaskname
                FROM WbsTaskInfo w
                LEFT JOIN WbsTaskInfo p ON w.parentTaskId = p.taskId
                WHERE w.taskId = :taskId AND w.workId = :workId
            ");
            $OldRowStmt->execute([':taskId' => $TaskId, ':workId' => $WorkId]);
            $OldRow = $OldRowStmt->fetch() ?: [];

            /** 差分履歴用：更新前の担当者を取得 */
            $OldAssigneeStmt = $Pdo->prepare("
                SELECT userId FROM WbsTaskAssignee
                WHERE taskId = :taskId
                ORDER BY userId ASC
            ");
            $OldAssigneeStmt->execute([':taskId' => $TaskId]);
            $OldAssigneeIds = array_column($OldAssigneeStmt->fetchAll(), 'userid');

            $Sql = "
                UPDATE WbsTaskInfo SET
                    parentTaskId = :parentTaskId,
                    taskName = :taskName,
                    description = :description,
                    startDate = :startDate,
                    endDate = :endDate,
                    progress = :progress,
                    updateDate = CURRENT_TIMESTAMP(3),
                    updateUser = :updateUser
                WHERE taskId = :taskId AND workId = :workId AND updateDate = :updateDate
                RETURNING updateDate
            ";

            $Stmt = $Pdo->prepare($Sql);
            $Stmt->execute([
                ':parentTaskId' => $ParentTaskId,
                ':taskName' => $TaskName,
                ':description' => $Description,
                ':startDate' => $StartDate,
                ':endDate' => $EndDate,
                ':progress' => $Progress,
                ':updateUser' => $UserId,
                ':taskId' => $TaskId,
                ':workId' => $WorkId,
                ':updateDate' => $UpdateDate
            ]);
            $UpdateResult = $Stmt->fetch();

            if (!$UpdateResult) {
                Database::Rollback();
                Response::ConflictError();
            }

            $NewTaskId = $TaskId;
            $NewUpdateDate = $UpdateResult['updatedate'];

            /** 担当者を洗い替え */
            SaveWbsTaskAssignees($Pdo, $TaskId, $AssigneeIds, $UserId);

            // 更新履歴の詳細メッセージを生成（更新・列単位差分）
            // 担当者は複数のため、並び順を揃えたうえでカンマ連結して比較する
            $SortedNewAssignees = $AssigneeIds;
            sort($SortedNewAssignees);
            $Labels = [
                'parentTaskName' => '親タスク',
                'taskName'       => 'タスク名',
                'description'    => '内容',
                'assignees'      => '担当者',
                'startDate'      => '開始予定日',
                'endDate'        => '終了予定日',
                'progress'       => '進捗率',
            ];
            $OldDisplay = [
                'parentTaskName' => $OldRow['parenttaskname'] ?? null,
                'taskName'       => $OldRow['taskname']       ?? null,
                'description'    => $OldRow['description']    ?? null,
                'assignees'      => implode('、', $OldAssigneeIds),
                'startDate'      => $OldRow['startdate']      ?? null,
                'endDate'        => $OldRow['enddate']        ?? null,
                'progress'       => isset($OldRow['progress']) ? $OldRow['progress'] . '%' : null,
            ];
            $NewDisplay = [
                'parentTaskName' => $ParentTaskName,
                'taskName'       => $TaskName,
                'description'    => $Description,
                'assignees'      => implode('、', $SortedNewAssignees),
                'startDate'      => $StartDate,
                'endDate'        => $EndDate,
                'progress'       => $Progress . '%',
            ];
            $Detail = HistoryDetail::Update($TaskName, $OldDisplay, $NewDisplay, $Labels);
        }
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
        'TaskId' => $NewTaskId,
        'UpdateDate' => $NewUpdateDate,
        'Message' => 'タスクを' . $Operation . 'しました'
    ]);

} catch (Exception $E) {
    Database::Rollback();
    Logger::Error($FunctionName, '保存処理エラー', $UserId, $E->getMessage());
    Response::Error('タスクの保存に失敗しました');
}
