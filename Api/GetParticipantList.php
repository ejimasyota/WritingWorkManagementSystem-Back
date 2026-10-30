<?php
/**
 * GetParticipantList.php
 * 参加者一覧取得API
 */

require_once __DIR__ . '/../Common/Database.php';
require_once __DIR__ . '/../Common/Logger.php';
require_once __DIR__ . '/../Common/Response.php';
require_once __DIR__ . '/../Common/Auth.php';

// GETメソッドのみ許可
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    Response::Error('不正なリクエストです', 405);
}

$FunctionName = '参加者一覧';
$UserId = '';

try {
    /** リクエストデータを取得 */
    $WorkId = $AuthWorkId ?? '';
    $UserId = $AuthUserId;

    Logger::Info($FunctionName, '一覧取得処理開始', $UserId);

    /** 入力チェック */
    if (empty($WorkId)) {
        Response::Error('作品IDが指定されていません');
    }

    /** DB接続 */
    $Pdo = Database::GetConnection();

    /** 参加者一覧を取得 */
    $Sql = "
        SELECT
            c.id AS \"Id\",
            c.workId AS \"WorkId\",
            c.userId AS \"UserId\",
            c.isCreator AS \"IsCreator\",
            c.userLockFlg AS \"UserLockFlg\",
            c.registDate AS \"RegistDate\",
            c.updateDate AS \"UpdateDate\"
        FROM CreaterList c
        WHERE c.workId = :workId
        ORDER BY c.isCreator DESC, c.registDate ASC
    ";

    $Stmt = $Pdo->prepare($Sql);
    $Stmt->execute([':workId' => $WorkId]);
    $ParticipantList = $Stmt->fetchAll();

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

    /** 編集権限があるかどうか */
    $CanEdit = $IsCreator || $IsAdmin;

    /** 本当の作成者（isCreator=TRUEで登録年月日が最も古いユーザー）を取得 */
    $OriginalCreatorSql = "
        SELECT userId
        FROM CreaterList
        WHERE workId = :workId AND isCreator = TRUE
        ORDER BY registDate ASC
        LIMIT 1
    ";
    $OriginalCreatorStmt = $Pdo->prepare($OriginalCreatorSql);
    $OriginalCreatorStmt->execute([':workId' => $WorkId]);
    $OriginalCreator = $OriginalCreatorStmt->fetch();

    /** 本当の作成者かどうか */
    $IsOriginalCreator = $OriginalCreator && $OriginalCreator['userid'] === $UserId;

    /** 権限付与列を表示するかどうか（本当の作成者または管理者のみ） */
    $CanManageRole = $IsOriginalCreator || $IsAdmin;

    Logger::Info($FunctionName, '一覧取得処理終了', $UserId);

    Response::Success([
        'ParticipantList'    => $ParticipantList,
        'TotalCount'         => count($ParticipantList),
        'CanEdit'            => $CanEdit,
        'CanManageRole'      => $CanManageRole,
        'OriginalCreatorId'  => $OriginalCreator ? $OriginalCreator['userid'] : null
    ]);

} catch (Exception $E) {
    Logger::Error($FunctionName, '一覧取得処理エラー', $UserId, $E->getMessage());
    Response::Error('参加者一覧の取得に失敗しました');
}
