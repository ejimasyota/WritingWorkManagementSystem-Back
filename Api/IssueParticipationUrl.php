<?php
/* ==========================================================
 * 参加一時URLトークン発行API
 * ----------------------------------------------------------
 * ・プロジェクト作成者（または管理者）が、他ユーザーを招待するための
 *   一時URLトークンを発行する。
 * ・トークンは一度きり・有効期限付き（.env の PARTICIPATION_URL_EXPIRE_MIN 分）。
 * ----------------------------------------------------------
 * 更新履歴：
 * 2026-07-04 作成
 * ========================================================== */

require_once __DIR__ . '/../Common/Database.php';
require_once __DIR__ . '/../Common/Logger.php';
require_once __DIR__ . '/../Common/Response.php';
require_once __DIR__ . '/../Common/Auth.php';
require_once __DIR__ . '/../Common/Env.php';

// POSTメソッドのみ許可
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::Error('不正なリクエストです', 405);
}

$FunctionName = '参加URL発行';
$Input = Response::GetJsonInput();
$WorkId = $Input['WorkId'] ?? '';
$UserId = $AuthUserId;

try {
    Logger::Info($FunctionName, '発行処理開始', $UserId);

    // 入力チェック
    if (empty($WorkId)) {
        Response::Error('作品IDが指定されていません');
    }

    $Db = Database::GetConnection();

    // 作品存在チェック・参加禁止チェック
    $WorkStmt = $Db->prepare('SELECT projectLockFlg FROM WorkInfo WHERE workId = :workId');
    $WorkStmt->execute([':workId' => $WorkId]);
    $Work = $WorkStmt->fetch();

    if (!$Work) {
        Logger::Info($FunctionName, '発行失敗：作品が存在しません', $UserId);
        Response::Error('対象の作品が存在しません', 404);
    }

    if (!empty($Work['projectlockflg'])) {
        Logger::Info($FunctionName, '発行失敗：参加禁止プロジェクト', $UserId);
        Response::Error('このプロジェクトには参加URLを発行できません');
    }

    // 権限チェック（作成者、または管理者のみ発行可能）
    $PermStmt = $Db->prepare('
        SELECT isCreator
        FROM CreaterList
        WHERE workId = :workId AND userId = :userId
    ');
    $PermStmt->execute([':workId' => $WorkId, ':userId' => $UserId]);
    $Permission = $PermStmt->fetch();
    $IsCreator = $Permission ? (bool)$Permission['iscreator'] : false;

    if (!$IsCreator && !$IsAuthAdmin) {
        Logger::Info($FunctionName, '発行失敗：権限なし', $UserId);
        Response::Error('参加URLを発行する権限がありません', 403);
    }

    // 有効期限（分）を取得
    $ExpireMin = (int) Env::Get('PARTICIPATION_URL_EXPIRE_MIN', 15);
    if ($ExpireMin <= 0) {
        $ExpireMin = 15;
    }

    // トークンを発行（有効期限を付与して登録）
    $InsertStmt = $Db->prepare('
        INSERT INTO ParticipationToken (token, workId, expireDate, registUser, updateUser)
        VALUES (
            gen_random_uuid(),
            :workId,
            current_timestamp(3) + make_interval(mins => :mins),
            :registUser,
            :updateUser
        )
        RETURNING token
    ');
    $InsertStmt->bindValue(':workId', $WorkId, PDO::PARAM_STR);
    $InsertStmt->bindValue(':mins', $ExpireMin, PDO::PARAM_INT);
    $InsertStmt->bindValue(':registUser', $UserId, PDO::PARAM_STR);
    $InsertStmt->bindValue(':updateUser', $UserId, PDO::PARAM_STR);
    $InsertStmt->execute();
    $Token = $InsertStmt->fetchColumn();

    Logger::Info($FunctionName, '発行処理終了', $UserId);

    Response::Success([
        'Token'     => $Token,
        'ExpireMin' => $ExpireMin,
    ], '参加URLを発行しました');

} catch (Exception $E) {
    Logger::Error($FunctionName, '発行処理エラー', $UserId, $E->getMessage());
    Response::Error('参加URLの発行中にエラーが発生しました', 500);
}
