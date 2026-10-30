<?php
/**
 * DeleteRelation.php
 * 相関関係・線削除API
 */

require_once __DIR__ . '/../Common/Database.php';
require_once __DIR__ . '/../Common/Logger.php';
require_once __DIR__ . '/../Common/Response.php';
require_once __DIR__ . '/../Common/Auth.php';

// POSTメソッドのみ許可
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::Error('不正なリクエストです', 405);
}

$FunctionName = '相関図作成';
$UserId = '';

try {
    /** リクエストデータを取得 */
    $Input = Response::GetJsonInput();
    $RelationId = $Input['RelationId'] ?? null;
    $WorkId = $AuthWorkId ?? '';
    $UserId = $AuthUserId;
    $UpdateDate = $Input['UpdateDate'] ?? null;

    Logger::Info($FunctionName, '関係線削除処理開始', $UserId);

    /** 入力チェック */
    if (empty($RelationId)) {
        Response::Error('関係線IDが指定されていません');
    }

    if (empty($WorkId)) {
        Response::Error('作品IDが指定されていません');
    }

    /** DB接続 */
    $Pdo = Database::GetConnection();
    Database::BeginTransaction();

    /** 削除対象の関係線が存在するか確認 */
    $CheckSql = "SELECT relationId FROM RelationInfo WHERE relationId = :relationId AND workId = :workId";
    $CheckStmt = $Pdo->prepare($CheckSql);
    $CheckStmt->execute([':relationId' => $RelationId, ':workId' => $WorkId]);
    $RelationInfo = $CheckStmt->fetch();

    if (!$RelationInfo) {
        Database::Rollback();
        Response::Error('削除対象の関係線が見つかりません');
    }

    /** 関係線を削除 */
    $Sql = "DELETE FROM RelationInfo WHERE relationId = :relationId AND workId = :workId AND updateDate = :updateDate";
    $Stmt = $Pdo->prepare($Sql);
    $Stmt->execute([
        ':relationId' => $RelationId,
        ':workId' => $WorkId,
        ':updateDate' => $UpdateDate
    ]);
    if ($Stmt->rowCount() === 0) {
        Database::Rollback();
        Response::ConflictError();
    }

    /**
     * WorkInfo.updateDate の更新および UpdateHistory への登録は、
     * 相関図保存フロー末尾の SaveRelationHistory.php で 1 回だけ実施するため、
     * ここでは行わない（複数レコード発生を防止）。
     */

    Database::Commit();

    Logger::Info($FunctionName, '関係線削除処理終了', $UserId);

    Response::Success([
        'Message' => '関係線を削除しました'
    ]);

} catch (Exception $E) {
    Database::Rollback();
    Logger::Error($FunctionName, '関係線削除処理エラー', $UserId, $E->getMessage());
    Response::Error('関係線の削除に失敗しました');
}
