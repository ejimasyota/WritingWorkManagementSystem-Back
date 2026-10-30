<?php
/**
 * UpdateAccountLock.php
 * アカウントロック状態更新API（管理者用）
 */

require_once __DIR__ . '/../Common/Database.php';
require_once __DIR__ . '/../Common/Logger.php';
require_once __DIR__ . '/../Common/Response.php';
require_once __DIR__ . '/../Common/Auth.php';

// POSTメソッドのみ許可
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::Error('不正なリクエストです', 405);
}

$FunctionName = 'アカウントロック更新';

// セッション上の管理者フラグに加えて DB 側の AdminInfo と実照合
if (!$IsAuthAdmin) {
    Response::Error('管理者権限が必要です', 403);
}

$Input = Response::GetJsonInput();
$UserId = $Input['UserId'] ?? '';
$AccountLockFlg = $Input['AccountLockFlg'] ?? false;
$UpdateDate = $Input['UpdateDate'] ?? null;
$AdminUserId = $AuthUserId;

try {
    Logger::Info($FunctionName, '更新処理開始', $AdminUserId);

    // 入力チェック
    if (empty($UserId)) {
        Response::Error('必要なパラメータが指定されていません');
    }

    // 自己ロック禁止
    if ($UserId === $AdminUserId) {
        Response::Error('自分自身のアカウントは操作できません', 403);
    }

    $Db = Database::GetConnection();

    // AdminInfo に実際に登録されているかを再照合（セッション改ざん耐性）
    $AdminCheckStmt = $Db->prepare('SELECT userId FROM AdminInfo WHERE userId = :userId');
    $AdminCheckStmt->execute([':userId' => $AdminUserId]);
    if (!$AdminCheckStmt->fetch()) {
        Response::Error('管理者権限が必要です', 403);
    }

    // 他管理者へのロック禁止（管理者はお互いの状態を変更できない）
    $TargetAdminStmt = $Db->prepare('SELECT userId FROM AdminInfo WHERE userId = :userId');
    $TargetAdminStmt->execute([':userId' => $UserId]);
    if ($TargetAdminStmt->fetch()) {
        Response::Error('他の管理者のアカウントは操作できません', 403);
    }

    Database::BeginTransaction();

    // アカウントロック状態を更新
    $Stmt = $Db->prepare('
        UPDATE UserInfo
        SET accountLockFlg = :accountLockFlg,
            updateDate = CURRENT_TIMESTAMP(3),
            updateUser = :updateUser
        WHERE userId = :userId
          AND updateDate = :updateDate
    ');

    $Stmt->execute([
        ':accountLockFlg' => $AccountLockFlg ? 'TRUE' : 'FALSE',
        ':updateUser' => $AdminUserId,
        ':userId' => $UserId,
        ':updateDate' => $UpdateDate
    ]);

    if ($Stmt->rowCount() === 0) {
        Database::Rollback();
        Response::ConflictError();
    }

    Database::Commit();

    $Action = $AccountLockFlg ? 'ロック' : '復旧';
    Logger::Info($FunctionName, "更新処理終了（{$Action}）", $AdminUserId);

    Response::Success(null, "アカウントを{$Action}しました");

} catch (Exception $E) {
    Database::Rollback();
    Logger::Error($FunctionName, '更新処理エラー', $AdminUserId, $E->getMessage());
    Response::Error('更新処理中にエラーが発生しました', 500);
}
