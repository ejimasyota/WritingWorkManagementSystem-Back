<?php
/**
 * DeleteTemplateMaster.php
 * テンプレートマスタ削除API（管理者用）
 *
 * 全体共通のテンプレートマスタを削除する。updateDate 一致による楽観ロック。
 * テンプレートから作成済みのストーリー・プロットは、作成時点で行として
 * 複写されているため、テンプレートの削除による影響を受けない。
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

$FunctionName = 'テンプレートマスタ';
$UserId = $AuthUserId;

try {
    /** リクエストデータを取得 */
    $Input = Response::GetJsonInput();
    $TemplateId = $Input['TemplateId'] ?? null;
    $UpdateDate = $Input['UpdateDate'] ?? null;

    Logger::Info($FunctionName, '削除処理開始', $UserId);

    /** 入力チェック */
    if (empty($TemplateId)) {
        Response::Error('テンプレートIDが指定されていません');
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
    $CheckStmt = $Pdo->prepare('SELECT templateId FROM TemplateMasterInfo WHERE templateId = :templateId');
    $CheckStmt->execute([':templateId' => $TemplateId]);
    if (!$CheckStmt->fetch()) {
        Database::Rollback();
        Response::Error('削除対象のテンプレートが見つかりません');
    }

    /** 削除（updateDate 一致の楽観ロック） */
    $Stmt = $Pdo->prepare('DELETE FROM TemplateMasterInfo WHERE templateId = :templateId AND updateDate = :updateDate');
    $Stmt->execute([
        ':templateId' => $TemplateId,
        ':updateDate' => $UpdateDate,
    ]);
    if ($Stmt->rowCount() === 0) {
        Database::Rollback();
        Response::ConflictError();
    }

    Database::Commit();

    Logger::Info($FunctionName, '削除処理終了', $UserId);

    Response::Success([
        'Message' => 'テンプレートマスタを削除しました',
    ]);

} catch (Exception $E) {
    Database::Rollback();
    Logger::Error($FunctionName, '削除処理エラー', $UserId, $E->getMessage());
    Response::Error('テンプレートマスタの削除に失敗しました');
}
