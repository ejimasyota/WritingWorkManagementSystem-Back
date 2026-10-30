<?php
/* ==========================================================
 * プロジェクト参加API（一時URLトークン経由）
 * ----------------------------------------------------------
 * ・一時参加URLのトークンを検証し、ログイン中ユーザーをプロジェクトへ参加登録する。
 * ・トークンは有効期限内かつ未使用のもののみ有効。使用時に usedDate をセットして一度きりとする。
 * ・トークン行を FOR UPDATE でロックし、同時アクセスによる多重利用を排他する。
 * ----------------------------------------------------------
 * 更新履歴：
 * 2026-07-04 作成
 * ========================================================== */

require_once __DIR__ . '/../Common/Database.php';
require_once __DIR__ . '/../Common/Logger.php';
require_once __DIR__ . '/../Common/Response.php';
require_once __DIR__ . '/../Common/Auth.php';

// POSTメソッドのみ許可
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::Error('不正なリクエストです', 405);
}

$FunctionName = 'プロジェクト参加';
$Input = Response::GetJsonInput();
$Token = $Input['Token'] ?? '';
$UserId = $AuthUserId;

try {
    Logger::Info($FunctionName, '参加処理開始', $UserId);

    // 入力チェック
    if (empty($Token)) {
        Response::Error('参加URLが不正です');
    }
    if (empty($UserId)) {
        Response::Error('ユーザーIDが指定されていません');
    }

    $Db = Database::GetConnection();

    Database::BeginTransaction();

    /** トークン行をロックして取得（同時参加の多重利用を防止） */
    $TokenStmt = $Db->prepare('
        SELECT workId, usedDate, (expireDate > current_timestamp(3)) AS isvalid
        FROM ParticipationToken
        WHERE token = :token
        FOR UPDATE
    ');
    $TokenStmt->execute([':token' => $Token]);
    $TokenRow = $TokenStmt->fetch();

    /** トークンが存在しない／使用済み／期限切れ */
    if (!$TokenRow || !empty($TokenRow['useddate']) || empty($TokenRow['isvalid'])) {
        Database::Rollback();
        Logger::Info($FunctionName, '参加失敗：無効なトークン', $UserId);
        Response::Error('この参加URLは無効か、有効期限が切れています');
    }

    $WorkId = $TokenRow['workid'];

    /** 作品存在・参加禁止チェック */
    $WorkStmt = $Db->prepare('SELECT workTitle, projectLockFlg FROM WorkInfo WHERE workId = :workId');
    $WorkStmt->execute([':workId' => $WorkId]);
    $Work = $WorkStmt->fetch();

    if (!$Work) {
        Database::Rollback();
        Logger::Info($FunctionName, '参加失敗：作品が存在しません', $UserId);
        Response::Error('対象の作品が存在しません', 404);
    }

    if (!empty($Work['projectlockflg'])) {
        Database::Rollback();
        Logger::Info($FunctionName, '参加失敗：参加禁止プロジェクト', $UserId);
        Response::Error('このプロジェクトには参加できません');
    }

    /** 既に参加済みかチェック */
    $CheckStmt = $Db->prepare('SELECT id FROM CreaterList WHERE workId = :workId AND userId = :userId');
    $CheckStmt->execute([':workId' => $WorkId, ':userId' => $UserId]);

    if ($CheckStmt->fetch()) {
        Database::Rollback();
        Logger::Info($FunctionName, '参加失敗：既に参加済み', $UserId);
        Response::Error('既に参加済みの作品です');
    }

    /** 参加登録（作成者フラグ FALSE） */
    $InsertStmt = $Db->prepare('
        INSERT INTO CreaterList (workId, userId, isCreator, registDate, updateDate, registUser, updateUser)
        VALUES (:workId, :userId, FALSE, current_timestamp(3), current_timestamp(3), :registUser, :updateUser)
    ');
    $InsertStmt->execute([
        ':workId'     => $WorkId,
        ':userId'     => $UserId,
        ':registUser' => $UserId,
        ':updateUser' => $UserId,
    ]);

    /** トークンを使用済みにする */
    $UsedStmt = $Db->prepare('
        UPDATE ParticipationToken SET
            usedDate = current_timestamp(3),
            updateDate = current_timestamp(3),
            updateUser = :updateUser
        WHERE token = :token
    ');
    $UsedStmt->execute([':token' => $Token, ':updateUser' => $UserId]);

    /** 作品情報の更新年月日を更新 */
    $UpdateWorkStmt = $Db->prepare('
        UPDATE WorkInfo SET
            updateDate = current_timestamp(3),
            updateUser = :updateUser
        WHERE workId = :workId
    ');
    $UpdateWorkStmt->execute([':workId' => $WorkId, ':updateUser' => $UserId]);

    /** 更新履歴を登録 */
    $HistoryStmt = $Db->prepare('
        INSERT INTO UpdateHistory (
            workId, functionName, operation, registDate, updateDate, registUser, updateUser
        ) VALUES (
            :workId, :functionName, :operation,
            current_timestamp(3), current_timestamp(3),
            :registUser, :updateUser
        )
    ');
    $HistoryStmt->execute([
        ':workId'       => $WorkId,
        ':functionName' => $FunctionName,
        ':operation'    => '一時URLから参加',
        ':registUser'   => $UserId,
        ':updateUser'   => $UserId,
    ]);

    Database::Commit();

    Logger::Info($FunctionName, '参加処理終了', $UserId);

    Response::Success([
        'WorkId'    => $WorkId,
        'WorkTitle' => $Work['worktitle'],
    ], 'プロジェクトに参加しました');

} catch (Exception $E) {
    Database::Rollback();
    Logger::Error($FunctionName, '参加処理エラー', $UserId, $E->getMessage());
    Response::Error('プロジェクト参加中にエラーが発生しました', 500);
}
