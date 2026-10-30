<?php
/**
 * GetWbsTaskList.php
 * WBS一覧・WBSタスク一覧取得API
 *
 * 画面が 作品 ＞ WBS一覧（親画面）＞ WBS画面（子画面）の2階層のため、
 * 呼び出し方によって返す内容を切り替える。
 *  - WbsId 未指定 … WBS一覧（親画面）の描画に必要な情報
 *  - WbsId 指定   … 上記に加えて、そのWBSに属するタスク一覧（子画面）の情報
 *
 * 返却内容：
 *  - WbsList      : WBS一覧（タスク件数・期間・進捗率を集計済み）
 *  - SelectedWbs  : 選択中のWBS（WbsId 未指定時は null）
 *  - TaskList     : タスク一覧（親タスク名・遅延状態を解決済み）
 *  - AssigneeList : 担当者プルダウン用の参加者一覧（BAN済みユーザーは除外）
 *  - ParentList   : 親タスクプルダウン用の一覧（同一WBS内で親を持たないタスクのみ）
 *  - CanEdit      : WBS・タスクの作成・削除・全項目更新が可能か（作成者または管理者）
 *  - LoginUserId  : ログイン中のユーザーID（参加メンバーの進捗率編集判定に使用）
 */

require_once __DIR__ . '/../Common/Database.php';
require_once __DIR__ . '/../Common/Logger.php';
require_once __DIR__ . '/../Common/Response.php';
require_once __DIR__ . '/../Common/Auth.php';

// GETメソッドのみ許可
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    Response::Error('不正なリクエストです', 405);
}

$FunctionName = 'WBS';
$UserId = '';

try {
    /** リクエストデータを取得 */
    $WorkId = $AuthWorkId ?? '';
    $UserId = $AuthUserId;
    /** 選択中のWBS（未指定の場合は親画面としてWBS一覧のみを返す） */
    $WbsId = isset($_GET['WbsId']) && $_GET['WbsId'] !== '' ? $_GET['WbsId'] : null;

    Logger::Info($FunctionName, '一覧取得処理開始', $UserId);

    /** 入力チェック */
    if (empty($WorkId)) {
        Response::Error('作品IDが指定されていません');
    }

    /** DB接続 */
    $Pdo = Database::GetConnection();

    /**
     * WBS一覧を取得（親画面のカードに表示する集計値を併せて求める）
     * 進捗率は配下タスクの単純平均とする。大タスク・子タスクのいずれも
     * 個別に進捗率を持つため、重み付けは行わない。
     * タスク件数は画面には表示しないが、削除時の確認メッセージで使用する。
     */
    $WbsSql = "
        SELECT
            i.wbsId       AS \"WbsId\",
            i.wbsName     AS \"WbsName\",
            i.description AS \"Description\",
            to_char(i.startDate, 'YYYY-MM-DD') AS \"StartDate\",
            to_char(i.endDate,   'YYYY-MM-DD') AS \"EndDate\",
            i.indexNum    AS \"IndexNum\",
            i.registDate  AS \"RegistDate\",
            i.updateDate  AS \"UpdateDate\",
            i.registUser  AS \"RegistUser\",
            i.updateUser  AS \"UpdateUser\",
            COUNT(t.taskId) AS \"TaskCount\",
            COUNT(t.taskId) FILTER (
                WHERE t.endDate < CURRENT_DATE AND t.progress < 100
            ) AS \"DelayedCount\",
            COALESCE(ROUND(AVG(t.progress)), 0) AS \"Progress\"
        FROM WbsInfo i
        LEFT JOIN WbsTaskInfo t ON t.wbsId = i.wbsId
        WHERE i.workId = :workId
        GROUP BY i.wbsId
        ORDER BY i.indexNum ASC, i.wbsId ASC
    ";
    $WbsStmt = $Pdo->prepare($WbsSql);
    $WbsStmt->execute([':workId' => $WorkId]);
    $WbsList = array_map(function ($R) {
        return [
            'WbsId'        => (int)$R['WbsId'],
            'WbsName'      => $R['WbsName'],
            'Description'  => $R['Description'],
            'StartDate'    => $R['StartDate'],
            'EndDate'      => $R['EndDate'],
            'IndexNum'     => (int)$R['IndexNum'],
            'TaskCount'    => (int)$R['TaskCount'],
            'DelayedCount' => (int)$R['DelayedCount'],
            'Progress'     => (int)$R['Progress'],
            'RegistDate'   => $R['RegistDate'],
            'UpdateDate'   => $R['UpdateDate'],
            'RegistUser'   => $R['RegistUser'],
            'UpdateUser'   => $R['UpdateUser'],
        ];
    }, $WbsStmt->fetchAll());

    /** 選択中のWBS（他作品のIDを指定された場合は選択なしとして扱う） */
    $SelectedWbs = null;
    if ($WbsId !== null) {
        foreach ($WbsList as $Wbs) {
            if ((string)$Wbs['WbsId'] === (string)$WbsId) {
                $SelectedWbs = $Wbs;
                break;
            }
        }
        if ($SelectedWbs === null) {
            $WbsId = null;
        }
    }

    /** タスク一覧を取得（親タスク名・遅延状態はビューで解決） */
    $Sql = "
        SELECT
            w.taskId         AS \"TaskId\",
            w.parentTaskId   AS \"ParentTaskId\",
            w.parentTaskName AS \"ParentTaskName\",
            w.taskName       AS \"TaskName\",
            w.description    AS \"Description\",
            to_char(w.startDate, 'YYYY-MM-DD') AS \"StartDate\",
            to_char(w.endDate,   'YYYY-MM-DD') AS \"EndDate\",
            w.progress       AS \"Progress\",
            w.indexNum       AS \"IndexNum\",
            w.isDelayed      AS \"IsDelayed\",
            w.registDate     AS \"RegistDate\",
            w.updateDate     AS \"UpdateDate\",
            w.registUser     AS \"RegistUser\",
            w.updateUser     AS \"UpdateUser\"
        FROM v_wbstask_detail w
        LEFT JOIN WbsTaskInfo p ON w.parentTaskId = p.taskId
        WHERE w.workId = :workId
          AND w.wbsId = :wbsId
        ORDER BY
            COALESCE(p.indexNum, w.indexNum) ASC,
            COALESCE(w.parentTaskId, w.taskId) ASC,
            w.parentTaskId ASC NULLS FIRST,
            w.indexNum ASC,
            w.taskId ASC
    ";

    /** WBSが選択されていない場合（親画面）はタスクを取得しない */
    $TaskList = [];
    $AssigneeMap = [];
    if ($WbsId !== null) {
        $Stmt = $Pdo->prepare($Sql);
        $Stmt->execute([':workId' => $WorkId, ':wbsId' => $WbsId]);
        $TaskList = $Stmt->fetchAll();

        /**
         * 担当者を取得してタスクIDごとにまとめる
         * 1タスクに複数人を割り当てられるため、中間テーブルから別途取得して紐づける
         */
        $TaskAssigneeStmt = $Pdo->prepare("
            SELECT a.taskId, a.userId
            FROM WbsTaskAssignee a
            JOIN WbsTaskInfo w ON w.taskId = a.taskId
            WHERE w.workId = :workId
              AND w.wbsId = :wbsId
            ORDER BY a.taskId ASC, a.userId ASC
        ");
        $TaskAssigneeStmt->execute([':workId' => $WorkId, ':wbsId' => $WbsId]);
        foreach ($TaskAssigneeStmt->fetchAll() as $R) {
            $AssigneeMap[(int)$R['taskid']][] = $R['userid'];
        }
    }

    /** 型を整える（PDOは数値・真偽値を文字列で返すため） */
    $TaskList = array_map(function ($R) use ($AssigneeMap) {
        $TaskId = (int)$R['TaskId'];
        return [
            'TaskId'         => $TaskId,
            'ParentTaskId'   => $R['ParentTaskId'] !== null ? (int)$R['ParentTaskId'] : null,
            'ParentTaskName' => $R['ParentTaskName'],
            'TaskName'       => $R['TaskName'],
            'Description'    => $R['Description'],
            'AssigneeIds'    => $AssigneeMap[$TaskId] ?? [],
            'StartDate'      => $R['StartDate'],
            'EndDate'        => $R['EndDate'],
            'Progress'       => (int)$R['Progress'],
            'IndexNum'       => (int)$R['IndexNum'],
            'IsDelayed'      => (bool)$R['IsDelayed'],
            'RegistDate'     => $R['RegistDate'],
            'UpdateDate'     => $R['UpdateDate'],
            'RegistUser'     => $R['RegistUser'],
            'UpdateUser'     => $R['UpdateUser'],
        ];
    }, $TaskList);

    /** 担当者プルダウン用の参加者一覧（BAN済みユーザーは割り当て対象外） */
    $AssigneeSql = "
        SELECT
            c.userId    AS \"UserId\",
            c.isCreator AS \"IsCreator\"
        FROM CreaterList c
        WHERE c.workId = :workId
          AND c.userLockFlg = FALSE
        ORDER BY c.isCreator DESC, c.registDate ASC
    ";
    $AssigneeStmt = $Pdo->prepare($AssigneeSql);
    $AssigneeStmt->execute([':workId' => $WorkId]);
    $AssigneeList = array_map(function ($R) {
        return [
            'UserId'    => $R['UserId'],
            'IsCreator' => (bool)$R['IsCreator'],
        ];
    }, $AssigneeStmt->fetchAll());

    /** ログイン者の権限を取得 */
    $PermSql = "
        SELECT isCreator
        FROM CreaterList
        WHERE workId = :workId AND userId = :userId
    ";
    $PermStmt = $Pdo->prepare($PermSql);
    $PermStmt->execute([':workId' => $WorkId, ':userId' => $UserId]);
    $PermResult = $PermStmt->fetch();
    $IsCreator = $PermResult ? (bool)$PermResult['iscreator'] : false;

    /** 管理者かどうかを確認 */
    $AdminSql = "SELECT userId FROM AdminInfo WHERE userId = :userId";
    $AdminStmt = $Pdo->prepare($AdminSql);
    $AdminStmt->execute([':userId' => $UserId]);
    $IsAdmin = $AdminStmt->fetch() ? true : false;

    /** タスクの作成・削除・全項目更新が可能か */
    $CanEdit = $IsCreator || $IsAdmin;

    /**
     * ログインユーザーを担当者候補に必ず含める
     * CreaterList に存在しない管理者が操作した場合でも自身を割り当てられるようにする
     */
    $IsSelfListed = false;
    foreach ($AssigneeList as $Assignee) {
        if ($Assignee['UserId'] === $UserId) {
            $IsSelfListed = true;
            break;
        }
    }
    if (!$IsSelfListed) {
        array_unshift($AssigneeList, [
            'UserId'    => $UserId,
            'IsCreator' => $IsCreator,
        ]);
    }

    /** 親タスクプルダウン用の一覧（同一WBS内・2階層のため、親を持たないタスクのみ） */
    $ParentList = array_values(array_filter($TaskList, function ($T) {
        return $T['ParentTaskId'] === null;
    }));
    $ParentList = array_map(function ($T) {
        return [
            'TaskId'   => $T['TaskId'],
            'TaskName' => $T['TaskName'],
        ];
    }, $ParentList);

    Logger::Info($FunctionName, '一覧取得処理終了', $UserId);

    Response::Success([
        'WbsList'      => $WbsList,
        'SelectedWbs'  => $SelectedWbs,
        'TaskList'     => $TaskList,
        'AssigneeList' => $AssigneeList,
        'ParentList'   => $ParentList,
        'TotalCount'   => count($TaskList),
        'CanEdit'      => $CanEdit,
        'LoginUserId'  => $UserId
    ]);

} catch (Exception $E) {
    Logger::Error($FunctionName, '一覧取得処理エラー', $UserId, $E->getMessage());
    Response::Error('WBSタスク一覧の取得に失敗しました');
}
