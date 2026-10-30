<?php
/**
 * SaveWbsInfo.php
 * WBS登録・更新API
 *
 * WBSは 作品 ＞ WBS ＞ 大タスク ＞ 子タスク の2階層目にあたり、
 * タスクを束ねる単位（画面上はWBS一覧のカード）を表す。
 *
 * 権限：作成者・管理者のみ登録・更新が可能
 *       （画面側でも制御するが、直接APIを叩かれた場合に備えサーバ側でも判定する）
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

try {
    /** リクエストデータを取得 */
    $Input = Response::GetJsonInput();
    $WbsId = $Input['WbsId'] ?? null;
    $WorkId = $AuthWorkId ?? '';
    $WbsName = $Input['WbsName'] ?? '';
    $Description = $Input['Description'] ?? null;
    $StartDate = $Input['StartDate'] ?? null;
    $EndDate = $Input['EndDate'] ?? null;
    $UserId = $AuthUserId;
    $UpdateDate = $Input['UpdateDate'] ?? null;

    $Operation = empty($WbsId) ? '登録' : '更新';
    // 更新履歴の詳細メッセージ（登録/更新分岐でそれぞれ生成）
    $Detail = null;
    Logger::Info($FunctionName, 'WBS' . $Operation . '処理開始', $UserId);

    /** 入力チェック */
    if (empty($WorkId)) {
        Response::Error('作品IDが指定されていません');
    }

    /** DB接続 */
    $Pdo = Database::GetConnection();

    /* ==========================================================================
     * 権限判定（作成者・管理者のみ登録・更新可能）
     * ========================================================================== */
    $PermStmt = $Pdo->prepare("
        SELECT isCreator FROM CreaterList
        WHERE workId = :workId AND userId = :userId
    ");
    $PermStmt->execute([':workId' => $WorkId, ':userId' => $UserId]);
    $PermRow = $PermStmt->fetch();
    $IsCreator = $PermRow ? (bool)$PermRow['iscreator'] : false;

    $AdminStmt = $Pdo->prepare("SELECT userId FROM AdminInfo WHERE userId = :userId");
    $AdminStmt->execute([':userId' => $UserId]);
    $IsAdmin = $AdminStmt->fetch() ? true : false;

    if (!$IsCreator && !$IsAdmin) {
        Response::Error('WBSを' . $Operation . 'する権限がありません', 403);
    }

    /** 空文字をNULLに変換 */
    $WbsId = !empty($WbsId) ? $WbsId : null;
    $Description = !empty($Description) ? $Description : null;
    $StartDate = !empty($StartDate) ? $StartDate : null;
    $EndDate = !empty($EndDate) ? $EndDate : null;

    if (empty($WbsName)) {
        Response::Error('WBS名を入力してください');
    }

    /**
     * 期間はタスクの期間チェックの基準となるため必須とする
     * （画面側でも制御するが、直接APIを叩かれた場合に備えサーバ側でも判定する）
     */
    if ($StartDate === null) {
        Response::Error('開始予定日を入力してください');
    }
    if ($EndDate === null) {
        Response::Error('終了予定日を入力してください');
    }
    if (!DateUtil::IsInScheduleRange($StartDate)) {
        Response::Error(DateUtil::GetScheduleRangeMessage('開始予定日'));
    }
    if (!DateUtil::IsInScheduleRange($EndDate)) {
        Response::Error(DateUtil::GetScheduleRangeMessage('終了予定日'));
    }
    // 日付は yyyy-MM-dd の固定長のため、文字列比較で前後関係を判定できる
    if ($StartDate > $EndDate) {
        Response::Error('終了予定日は開始予定日以降の日付を入力してください');
    }

    /** 期間を狭めた場合、配下のタスクが期間外へはみ出さないことを確認する */
    if ($WbsId !== null) {
        $OutOfRangeStmt = $Pdo->prepare("
            SELECT COUNT(*) AS cnt FROM WbsTaskInfo
            WHERE wbsId = :wbsId AND workId = :workId
              AND (startDate < CAST(:startDate AS DATE) OR endDate > CAST(:endDate AS DATE))
        ");
        $OutOfRangeStmt->execute([
            ':wbsId' => $WbsId,
            ':workId' => $WorkId,
            ':startDate' => $StartDate,
            ':endDate' => $EndDate
        ]);
        $OutOfRangeCount = (int)$OutOfRangeStmt->fetch()['cnt'];
        if ($OutOfRangeCount > 0) {
            Response::Error(
                '指定した期間の外にあるタスクが' . $OutOfRangeCount . '件あります。'
                . '先にタスクの期間を修正してください'
            );
        }
    }

    Database::BeginTransaction();

    if ($WbsId === null) {
        /** 新規登録：作品内の末尾に配置する */
        $IndexStmt = $Pdo->prepare("
            SELECT COALESCE(MAX(indexNum), -1) + 1 AS nextindex
            FROM WbsInfo
            WHERE workId = :workId
        ");
        $IndexStmt->execute([':workId' => $WorkId]);
        $IndexNum = (int)$IndexStmt->fetch()['nextindex'];

        $Stmt = $Pdo->prepare("
            INSERT INTO WbsInfo (
                workId, wbsName, description, startDate, endDate, indexNum,
                registDate, updateDate, registUser, updateUser
            ) VALUES (
                :workId, :wbsName, :description, :startDate, :endDate, :indexNum,
                CURRENT_TIMESTAMP(3), CURRENT_TIMESTAMP(3), :registUser, :updateUser
            )
            RETURNING wbsId, updateDate
        ");
        $Stmt->execute([
            ':workId' => $WorkId,
            ':wbsName' => $WbsName,
            ':description' => $Description,
            ':startDate' => $StartDate,
            ':endDate' => $EndDate,
            ':indexNum' => $IndexNum,
            ':registUser' => $UserId,
            ':updateUser' => $UserId
        ]);

        $Result = $Stmt->fetch();
        $NewWbsId = $Result['wbsid'];
        $NewUpdateDate = $Result['updatedate'];

        // 更新履歴の詳細メッセージを生成（登録）
        $Detail = HistoryDetail::Register($WbsName);

    } else {
        /** 更新 */
        // 差分履歴用：更新前の表示値を取得
        $OldRowStmt = $Pdo->prepare("
            SELECT
                wbsName,
                description,
                to_char(startDate, 'YYYY-MM-DD') AS startdate,
                to_char(endDate,   'YYYY-MM-DD') AS enddate
            FROM WbsInfo
            WHERE wbsId = :wbsId AND workId = :workId
        ");
        $OldRowStmt->execute([':wbsId' => $WbsId, ':workId' => $WorkId]);
        $OldRow = $OldRowStmt->fetch();

        if (!$OldRow) {
            Database::Rollback();
            Response::Error('対象のWBSが見つかりません');
        }

        $Stmt = $Pdo->prepare("
            UPDATE WbsInfo SET
                wbsName = :wbsName,
                description = :description,
                startDate = :startDate,
                endDate = :endDate,
                updateDate = CURRENT_TIMESTAMP(3),
                updateUser = :updateUser
            WHERE wbsId = :wbsId AND workId = :workId AND updateDate = :updateDate
            RETURNING updateDate
        ");
        $Stmt->execute([
            ':wbsName' => $WbsName,
            ':description' => $Description,
            ':startDate' => $StartDate,
            ':endDate' => $EndDate,
            ':updateUser' => $UserId,
            ':wbsId' => $WbsId,
            ':workId' => $WorkId,
            ':updateDate' => $UpdateDate
        ]);
        $UpdateResult = $Stmt->fetch();

        if (!$UpdateResult) {
            Database::Rollback();
            Response::ConflictError();
        }

        $NewWbsId = $WbsId;
        $NewUpdateDate = $UpdateResult['updatedate'];

        // 更新履歴の詳細メッセージを生成（更新・列単位差分）
        $Detail = HistoryDetail::Update(
            $WbsName,
            [
                'wbsName'     => $OldRow['wbsname'],
                'description' => $OldRow['description'],
                'startDate'   => $OldRow['startdate'],
                'endDate'     => $OldRow['enddate'],
            ],
            [
                'wbsName'     => $WbsName,
                'description' => $Description,
                'startDate'   => $StartDate,
                'endDate'     => $EndDate,
            ],
            [
                'wbsName'     => 'WBS名',
                'description' => '内容',
                'startDate'   => '開始予定日',
                'endDate'     => '終了予定日',
            ]
        );
    }

    /** 作品情報の更新年月日を更新 */
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

    /** 更新履歴を登録（変更が無い更新は履歴に残す情報が無いため登録しない） */
    $HistoryStmt = $Pdo->prepare("
        INSERT INTO UpdateHistory (
            workId, functionName, operation, detail, registDate, updateDate, registUser, updateUser
        ) VALUES (
            :workId, :functionName, :operation, :detail, CURRENT_TIMESTAMP(3), CURRENT_TIMESTAMP(3), :registUser, :updateUser
        )
    ");
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

    Logger::Info($FunctionName, 'WBS' . $Operation . '処理終了', $UserId);

    Response::Success([
        'WbsId' => $NewWbsId,
        'UpdateDate' => $NewUpdateDate,
        'Message' => 'WBSを' . $Operation . 'しました'
    ]);

} catch (Exception $E) {
    Database::Rollback();
    Logger::Error($FunctionName, 'WBS保存処理エラー', $UserId, $E->getMessage());
    Response::Error('WBSの保存に失敗しました');
}
