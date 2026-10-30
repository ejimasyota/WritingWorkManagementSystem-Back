<?php
/**
 * DeleteClass.php
 * 階級削除API
 */

require_once __DIR__ . '/../Common/Database.php';
require_once __DIR__ . '/../Common/Logger.php';
require_once __DIR__ . '/../Common/Response.php';
require_once __DIR__ . '/../Common/Auth.php';
// 更新履歴 詳細メッセージ生成
require_once __DIR__ . '/../Common/HistoryDetail.php';

// POSTメソッドのみ許可
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::Error('不正なリクエストです', 405);
}

$FunctionName = '階級設定';
$UserId = '';

try {
    /** リクエストデータを取得 */
    $Input = Response::GetJsonInput();
    $ClassId = $Input['ClassId'] ?? null;
    $WorkId = $AuthWorkId ?? '';
    $UserId = $AuthUserId;
    $UpdateDate = $Input['UpdateDate'] ?? null;

    Logger::Info($FunctionName, '削除処理開始', $UserId);

    /** 入力チェック */
    if (empty($ClassId)) {
        Response::Error('階級IDが指定されていません');
    }

    if (empty($WorkId)) {
        Response::Error('作品IDが指定されていません');
    }

    /** DB接続 */
    $Pdo = Database::GetConnection();
    Database::BeginTransaction();

    /** 削除対象の階級が存在するか確認 */
    $CheckSql = "SELECT classId, className FROM ClassInfo WHERE classId = :classId AND workId = :workId";
    $CheckStmt = $Pdo->prepare($CheckSql);
    $CheckStmt->execute([':classId' => $ClassId, ':workId' => $WorkId]);
    $ClassInfo = $CheckStmt->fetch();

    if (!$ClassInfo) {
        Database::Rollback();
        Response::Error('削除対象の階級が見つかりません');
    }

    // 更新履歴の詳細メッセージを生成（削除。主キーから名称変換した値を使用）
    $Detail = HistoryDetail::Delete($ClassInfo['classname'] ?? null);

    /** 階級を削除 */
    $Sql = "DELETE FROM ClassInfo WHERE classId = :classId AND workId = :workId AND updateDate = :updateDate";
    $Stmt = $Pdo->prepare($Sql);
    $Stmt->execute([
        ':classId' => $ClassId,
        ':workId' => $WorkId,
        ':updateDate' => $UpdateDate
    ]);
    if ($Stmt->rowCount() === 0) {
        Database::Rollback();
        Response::ConflictError();
    }

    /** 作品情報の更新年月日を更新 */
    $UpdateWorkSql = "
        UPDATE WorkInfo SET
            updateDate = CURRENT_TIMESTAMP(3),
            updateUser = :updateUser
        WHERE workId = :workId
    ";
    $UpdateWorkStmt = $Pdo->prepare($UpdateWorkSql);
    $UpdateWorkStmt->execute([
        ':workId' => $WorkId,
        ':updateUser' => $UserId
    ]);

    /** 更新履歴を登録 */
    $HistorySql = "
        INSERT INTO UpdateHistory (
            workId, functionName, operation, detail, registDate, updateDate, registUser, updateUser
        ) VALUES (
            :workId, :functionName, :operation, :detail, CURRENT_TIMESTAMP(3), CURRENT_TIMESTAMP(3), :registUser, :updateUser
        )
    ";
    $HistoryStmt = $Pdo->prepare($HistorySql);
    $HistoryStmt->execute([
        ':workId' => $WorkId,
        ':functionName' => $FunctionName,
        ':operation' => '削除',
        ':detail' => $Detail,
        ':registUser' => $UserId,
        ':updateUser' => $UserId
    ]);

    Database::Commit();

    Logger::Info($FunctionName, '削除処理終了', $UserId);

    Response::Success([
        'Message' => '階級を削除しました'
    ]);

} catch (Exception $E) {
    Database::Rollback();
    Logger::Error($FunctionName, '削除処理エラー', $UserId, $E->getMessage());
    Response::Error('階級の削除に失敗しました');
}
