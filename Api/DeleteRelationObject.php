<?php
/**
 * DeleteRelationObject.php
 * 相関図オブジェクト削除API
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
    $ObjId = $Input['ObjId'] ?? null;
    $WorkId = $AuthWorkId ?? '';
    $UserId = $AuthUserId;
    $UpdateDate = $Input['UpdateDate'] ?? null;

    Logger::Info($FunctionName, 'オブジェクト削除処理開始', $UserId);

    /** 入力チェック */
    if (empty($ObjId)) {
        Response::Error('オブジェクトIDが指定されていません');
    }

    if (empty($WorkId)) {
        Response::Error('作品IDが指定されていません');
    }

    /** DB接続 */
    $Pdo = Database::GetConnection();
    Database::BeginTransaction();

    /** 削除対象のオブジェクトが存在するか確認 */
    $CheckSql = "SELECT objId FROM RelationObjectInfo WHERE objId = :objId AND workId = :workId";
    $CheckStmt = $Pdo->prepare($CheckSql);
    $CheckStmt->execute([':objId' => $ObjId, ':workId' => $WorkId]);
    $ObjInfo = $CheckStmt->fetch();

    if (!$ObjInfo) {
        Database::Rollback();
        Response::Error('削除対象のオブジェクトが見つかりません');
    }

    /** 関連する関係線を先に削除（外部キー制約対応） */
    $DeleteRelationSql = "
        DELETE FROM RelationInfo
        WHERE workId = :workId AND (sourceObjId = :objId OR targetObjId = :objId)
    ";
    $DeleteRelationStmt = $Pdo->prepare($DeleteRelationSql);
    $DeleteRelationStmt->execute([
        ':workId' => $WorkId,
        ':objId' => $ObjId
    ]);

    /** オブジェクトを削除 */
    $Sql = "DELETE FROM RelationObjectInfo WHERE objId = :objId AND workId = :workId AND updateDate = :updateDate";
    $Stmt = $Pdo->prepare($Sql);
    $Stmt->execute([
        ':objId' => $ObjId,
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

    Logger::Info($FunctionName, 'オブジェクト削除処理終了', $UserId);

    Response::Success([
        'Message' => '相関図オブジェクトを削除しました'
    ]);

} catch (Exception $E) {
    Database::Rollback();
    Logger::Error($FunctionName, 'オブジェクト削除処理エラー', $UserId, $E->getMessage());
    Response::Error('相関図オブジェクトの削除に失敗しました');
}
