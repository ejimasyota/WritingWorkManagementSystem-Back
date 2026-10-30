<?php
/**
 * DeleteEraMaster.php
 * 元号マスタ削除API（管理者用）
 *
 * 全体共通の実元号マスタを削除する。updateDate 一致による楽観ロック。
 */

require_once __DIR__ . '/../Common/Database.php';
require_once __DIR__ . '/../Common/Logger.php';
require_once __DIR__ . '/../Common/Response.php';
require_once __DIR__ . '/../Common/Auth.php';

// POSTメソッドのみ許可
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::Error('不正なリクエストです', 405);
}

// 管理者権限チェック（セッション + AdminInfo 実表照合）
if (!$IsAuthAdmin) {
    Response::Error('管理者権限が必要です', 403);
}

$FunctionName = '元号マスタ';
$UserId = $AuthUserId;

try {
    /** リクエストデータを取得 */
    $Input = Response::GetJsonInput();
    $EraMasterId = $Input['EraMasterId'] ?? null;
    $UpdateDate = $Input['UpdateDate'] ?? null;

    Logger::Info($FunctionName, '削除処理開始', $UserId);

    /** 入力チェック */
    if (empty($EraMasterId)) {
        Response::Error('元号マスタIDが指定されていません');
    }

    $Pdo = Database::GetConnection();

    // 二重防御：AdminInfo 実表照合
    $AdminStmt = $Pdo->prepare('SELECT userId FROM AdminInfo WHERE userId = :userId');
    $AdminStmt->execute([':userId' => $UserId]);
    if (!$AdminStmt->fetch()) {
        Response::Error('管理者権限が必要です', 403);
    }

    Database::BeginTransaction();

    /** 削除対象が存在するか確認 */
    $CheckStmt = $Pdo->prepare('SELECT eraMasterId FROM EraMasterInfo WHERE eraMasterId = :eraMasterId');
    $CheckStmt->execute([':eraMasterId' => $EraMasterId]);
    if (!$CheckStmt->fetch()) {
        Database::Rollback();
        Response::Error('削除対象の元号マスタが見つかりません');
    }

    /** 削除（updateDate 一致の楽観ロック） */
    $Stmt = $Pdo->prepare('DELETE FROM EraMasterInfo WHERE eraMasterId = :eraMasterId AND updateDate = :updateDate');
    $Stmt->execute([
        ':eraMasterId' => $EraMasterId,
        ':updateDate' => $UpdateDate,
    ]);
    if ($Stmt->rowCount() === 0) {
        Database::Rollback();
        Response::ConflictError();
    }

    Database::Commit();

    Logger::Info($FunctionName, '削除処理終了', $UserId);

    Response::Success([
        'Message' => '元号マスタを削除しました',
    ]);

} catch (Exception $E) {
    Database::Rollback();
    Logger::Error($FunctionName, '削除処理エラー', $UserId, $E->getMessage());
    Response::Error('元号マスタの削除に失敗しました');
}
