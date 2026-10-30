<?php
/**
 * GetNotificationSummary.php
 * 通知バッジ用サマリ取得API
 *
 * サイドバーのベルアイコンに表示するバッジ用に、2種別の未対応件数と合計を返す。
 *  - 001 システム通知: SystemNotification のうち、UserInfo.notificationBaseDate 以降かつ
 *                     期限切れでなく、当ユーザーが Dismiss していないもの
 *  - 002 作品更新通知: UpdateHistory のうち、当ユーザーの WorkReadState.lastReadDate
 *                     （未登録なら CreaterList.registDate）以降かつ本人操作以外
 *
 * ※ タスク期限通知は廃止した（GetNotificationList.php の説明を参照）。
 *
 * バッジは 1000 で頭打ち。フロントは 1000 以上を "999+" として表示する。
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

    $Sql = "
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
            (SELECT cnt FROM sys)  AS systemcnt,
            (SELECT cnt FROM upd)  AS workupdatecnt,
            LEAST(
                (SELECT cnt FROM sys) + (SELECT cnt FROM upd),
                1000
            ) AS totalcnt
    ";
    $Stmt = $Pdo->prepare($Sql);
    $Stmt->execute([
        ':userId1' => $UserId,
        ':userId2' => $UserId,
        ':userId3' => $UserId,
        ':userId4' => $UserId,
        ':userId5' => $UserId,
    ]);
    $Row = $Stmt->fetch();

    Response::Success([
        'SystemCnt'     => (int)$Row['systemcnt'],
        'WorkUpdateCnt' => (int)$Row['workupdatecnt'],
        'TotalCnt'      => (int)$Row['totalcnt'],
    ]);

} catch (Exception $E) {
    Logger::Error($FunctionName, 'サマリ取得エラー', $UserId, $E->getMessage());
    Response::Error('通知サマリの取得に失敗しました', 500);
}
