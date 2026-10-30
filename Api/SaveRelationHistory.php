<?php
/**
 * SaveRelationHistory.php
 * 相関図更新履歴登録API
 *
 * 相関図保存フロー（SaveRelationData）は複数の個別 API
 * （SaveRelation / SaveRelationObject / DeleteRelation / DeleteRelationObject）
 * をシーケンシャルに呼び出すため、各 API で UpdateHistory を書くと
 * 1 回の保存で大量のレコードが積まれてしまう。
 * 本 API は個別 API 呼び出しの完了後にフロントから 1 回だけ呼ばれ、
 *   - WorkInfo.updateDate の更新
 *   - UpdateHistory への 1 レコード追加
 * を担当する。
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
    $WorkId = $AuthWorkId ?? '';
    $UserId = $AuthUserId;

    if (empty($WorkId)) {
        Response::Error('作品IDが指定されていません');
    }

    $Pdo = Database::GetConnection();
    Database::BeginTransaction();

    /** 作品情報の更新年月日を更新 */
    $UpdateWorkSql = "
        UPDATE WorkInfo SET
            updateDate = CURRENT_TIMESTAMP(3),
            updateUser = :updateUser
        WHERE workId = :workId
    ";
    $UpdateWorkStmt = $Pdo->prepare($UpdateWorkSql);
    $UpdateWorkStmt->execute([
        ':workId'     => $WorkId,
        ':updateUser' => $UserId,
    ]);

    /** 更新履歴を 1 行だけ登録 */
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
        ':operation'    => '更新',
        ':registUser'   => $UserId,
        ':updateUser'   => $UserId,
    ]);

    Database::Commit();

    Logger::Info($FunctionName, '更新履歴登録', $UserId);

    Response::Success(null);

} catch (Exception $E) {
    Database::Rollback();
    Logger::Error($FunctionName, '更新履歴登録エラー', $UserId, $E->getMessage());
    Response::Error('相関図の履歴登録に失敗しました', 500);
}
