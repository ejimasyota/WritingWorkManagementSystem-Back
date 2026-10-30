<?php
/**
 * UpdateCreatorRole.php
 * 権限付与/剥奪API
 */

require_once __DIR__ . '/../Common/Database.php';
require_once __DIR__ . '/../Common/Logger.php';
require_once __DIR__ . '/../Common/Response.php';
require_once __DIR__ . '/../Common/Auth.php';

// POSTメソッドのみ許可
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::Error('不正なリクエストです', 405);
}

$FunctionName = '参加者一覧';
$UserId = '';

try {
    /** リクエストデータを取得 */
    $Input = Response::GetJsonInput();
    $TargetUserId = $Input['TargetUserId'] ?? '';
    // 作品ID（未指定時はセッションの $AuthWorkId をフォールバック）
    $WorkId       = $Input['WorkId'] ?? ($AuthWorkId ?? '');
    $UpdateDate   = $Input['UpdateDate'] ?? null;
    $UserId = $AuthUserId;

    Logger::Info($FunctionName, '権限変更処理開始', $UserId);

    /** 入力チェック */
    if (empty($TargetUserId)) {
        Response::Error('対象ユーザーIDが指定されていません');
    }

    if (empty($WorkId)) {
        Response::Error('作品IDが指定されていません');
    }

    /** DB接続 */
    $Pdo = Database::GetConnection();

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

    /** 管理者かどうかを確認 */
    $AdminSql = "SELECT userId FROM AdminInfo WHERE userId = :userId";
    $AdminStmt = $Pdo->prepare($AdminSql);
    $AdminStmt->execute([':userId' => $UserId]);
    $IsAdmin = $AdminStmt->fetch() ? true : false;

    /** 権限チェック（本当の作成者または管理者のみ操作可能） */
    $IsOriginalCreator = $OriginalCreator && $OriginalCreator['userid'] === $UserId;

    if (!$IsOriginalCreator && !$IsAdmin) {
        Response::Error('この操作を行う権限がありません');
    }

    /** 対象ユーザーが本当の作成者の場合は変更不可 */
    if ($OriginalCreator && $OriginalCreator['userid'] === $TargetUserId) {
        Response::Error('本当の作成者の権限は変更できません');
    }

    /** 管理者は自分自身の権限を変更できない
     *  管理者は全権限を有しており、自身の作成者権限を操作する必要が無いため */
    if ($IsAdmin && $TargetUserId === $UserId) {
        Response::Error('管理者は自分自身の権限を変更できません');
    }

    Database::BeginTransaction();

    /** 対象の参加者情報を取得 */
    $CheckSql = "
        SELECT isCreator
        FROM CreaterList
        WHERE workId = :workId AND userId = :userId
    ";
    $CheckStmt = $Pdo->prepare($CheckSql);
    $CheckStmt->execute([':workId' => $WorkId, ':userId' => $TargetUserId]);
    $TargetInfo = $CheckStmt->fetch();

    if (!$TargetInfo) {
        Database::Rollback();
        Response::Error('対象の参加者が見つかりません');
    }

    /** 現在のisCreatorを反転 */
    $CurrentIsCreator = (bool)$TargetInfo['iscreator'];
    $NewIsCreator = !$CurrentIsCreator;

    /** isCreatorを更新 */
    $UpdateSql = "
        UPDATE CreaterList SET
            isCreator = :isCreator,
            updateDate = CURRENT_TIMESTAMP(3),
            updateUser = :updateUser
        WHERE workId = :workId AND userId = :targetUserId
          AND updateDate = :updateDate
    ";
    $UpdateStmt = $Pdo->prepare($UpdateSql);
    $UpdateStmt->bindValue(':isCreator', $NewIsCreator, PDO::PARAM_BOOL);
    $UpdateStmt->bindValue(':workId', $WorkId, PDO::PARAM_STR);
    $UpdateStmt->bindValue(':targetUserId', $TargetUserId, PDO::PARAM_STR);
    $UpdateStmt->bindValue(':updateUser', $UserId, PDO::PARAM_STR);
    $UpdateStmt->bindValue(':updateDate', $UpdateDate, PDO::PARAM_STR);
    $UpdateStmt->execute();

    if ($UpdateStmt->rowCount() === 0) {
        Database::Rollback();
        Response::ConflictError();
    }

    /** 更新履歴を登録 */
    $HistorySql = "
        INSERT INTO UpdateHistory (
            workId, functionName, operation, registDate, updateDate, registUser, updateUser
        ) VALUES (
            :workId, :functionName, :operation,
            CURRENT_TIMESTAMP(3), CURRENT_TIMESTAMP(3),
            :registUser, :updateUser
        )
    ";
    $HistoryStmt = $Pdo->prepare($HistorySql);
    $HistoryStmt->execute([
        ':workId'       => $WorkId,
        ':functionName' => $FunctionName,
        ':operation'    => $NewIsCreator ? '権限を付与' : '権限を剥奪',
        ':registUser'   => $UserId,
        ':updateUser'   => $UserId
    ]);

    Database::Commit();

    Logger::Info($FunctionName, '権限変更処理終了', $UserId);

    Response::Success([
        'IsCreator' => $NewIsCreator,
        'Message'   => $NewIsCreator ? '権限を付与しました' : '権限を剥奪しました'
    ]);

} catch (Exception $E) {
    Database::Rollback();
    Logger::Error($FunctionName, '権限変更処理エラー', $UserId, $E->getMessage());
    Response::Error('権限変更処理中にエラーが発生しました', 500);
}
