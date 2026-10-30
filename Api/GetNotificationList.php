<?php
/**
 * GetNotificationList.php
 * 通知パネル表示用リスト取得API
 *
 * ベルアイコン押下時に開くパネルで表示する詳細リストを、2種別まとめて返す。
 *  - 001 システム通知: notificationId / title / message / registDate の一覧
 *  - 002 作品更新通知: 作品ごとの未読更新件数（作品名 + 件数）
 *
 * ※ タスク期限通知は廃止した。他の通知が「登録・更新」を契機とするのに対し、
 *    期限到来は日付の経過が契機となり、既読の考え方も異なるため、
 *    バッチによる通知生成を含めて設計を整理したうえで改めて実装する。
 */

require_once __DIR__ . '/../Common/Database.php';
require_once __DIR__ . '/../Common/Logger.php';
require_once __DIR__ . '/../Common/Response.php';
require_once __DIR__ . '/../Common/Auth.php';

// GETメソッドのみ許可
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    Response::Error('不正なリクエストです', 405);
}

$FunctionName = '通知';
$UserId = $AuthUserId;

try {
    $Pdo = Database::GetConnection();

    /* --------------------------------------------------
     * 001 システム通知
     * -------------------------------------------------- */
    $SysStmt = $Pdo->prepare("
        SELECT n.notificationId, n.title, n.message, n.registDate
        FROM SystemNotification n
        JOIN UserInfo u ON u.userId = :userId1
        WHERE n.registDate >= u.notificationBaseDate
          AND (n.expireDate IS NULL OR n.expireDate > current_timestamp(3))
          AND NOT EXISTS (
              SELECT 1 FROM SystemNotificationDismiss d
              WHERE d.notificationId = n.notificationId AND d.userId = :userId2
          )
        ORDER BY n.registDate DESC
        LIMIT 100
    ");
    $SysStmt->execute([':userId1' => $UserId, ':userId2' => $UserId]);
    $SystemNotifications = array_map(function ($R) {
        return [
            'NotificationId' => (int)$R['notificationid'],
            'Title'          => $R['title'],
            'Message'        => $R['message'],
            'RegistDate'     => $R['registdate'],
        ];
    }, $SysStmt->fetchAll());

    /* --------------------------------------------------
     * 002 作品更新通知（作品別に件数集計）
     * -------------------------------------------------- */
    $UpdStmt = $Pdo->prepare("
        SELECT h.workId, w.workTitle, COUNT(*) AS cnt, MAX(h.registDate) AS latestdate
        FROM UpdateHistory h
        JOIN CreaterList c ON c.workId = h.workId AND c.userId = :userId1
        JOIN WorkInfo w    ON w.workId = h.workId
        LEFT JOIN WorkReadState r ON r.workId = h.workId AND r.userId = :userId2
        WHERE h.registDate > COALESCE(r.lastReadDate, c.registDate)
          AND (h.registUser IS NULL OR h.registUser <> :userId3)
        GROUP BY h.workId, w.workTitle
        ORDER BY MAX(h.registDate) DESC
        LIMIT 100
    ");
    $UpdStmt->execute([
        ':userId1' => $UserId,
        ':userId2' => $UserId,
        ':userId3' => $UserId,
    ]);
    $WorkUpdateNotifications = array_map(function ($R) {
        return [
            'WorkId'     => $R['workid'],
            'WorkTitle'  => $R['worktitle'],
            'Count'      => (int)$R['cnt'],
            'LatestDate' => $R['latestdate'],
        ];
    }, $UpdStmt->fetchAll());

    /* --------------------------------------------------
     * 各種別の正確な件数（表示用一覧は LIMIT 100 のため、
     * バッジ・セクション件数が 100 で頭打ちにならないよう別途集計する）
     * GetNotificationSummary と同一ロジック。
     * -------------------------------------------------- */
    $CntStmt = $Pdo->prepare("
        WITH sys AS (
            SELECT COUNT(*) AS cnt
            FROM SystemNotification n
            JOIN UserInfo u ON u.userId = :userId1
            WHERE n.registDate >= u.notificationBaseDate
              AND (n.expireDate IS NULL OR n.expireDate > current_timestamp(3))
              AND NOT EXISTS (
                  SELECT 1 FROM SystemNotificationDismiss d
                  WHERE d.notificationId = n.notificationId AND d.userId = :userId2
              )
        ),
        upd AS (
            SELECT COUNT(*) AS cnt
            FROM UpdateHistory h
            JOIN CreaterList c ON c.workId = h.workId AND c.userId = :userId3
            LEFT JOIN WorkReadState r ON r.workId = h.workId AND r.userId = :userId4
            WHERE h.registDate > COALESCE(r.lastReadDate, c.registDate)
              AND (h.registUser IS NULL OR h.registUser <> :userId5)
        )
        SELECT
            (SELECT cnt FROM sys)  AS systotal,
            (SELECT cnt FROM upd)  AS updtotal
    ");
    $CntStmt->execute([
        ':userId1' => $UserId,
        ':userId2' => $UserId,
        ':userId3' => $UserId,
        ':userId4' => $UserId,
        ':userId5' => $UserId,
    ]);
    $Cnt = $CntStmt->fetch();

    Response::Success([
        'SystemNotifications'     => $SystemNotifications,
        'WorkUpdateNotifications' => $WorkUpdateNotifications,
        'SystemTotalCnt'          => (int)$Cnt['systotal'],
        'WorkUpdateTotalCnt'      => (int)$Cnt['updtotal'],
    ]);

} catch (Exception $E) {
    Logger::Error($FunctionName, 'リスト取得エラー', $UserId, $E->getMessage());
    Response::Error('通知リストの取得に失敗しました', 500);
}
