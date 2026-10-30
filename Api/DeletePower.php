<?php
/**
 * DeletePower.php
 * 能力削除API
 */

require_once __DIR__ . '/../Common/Database.php';
require_once __DIR__ . '/../Common/Logger.php';
require_once __DIR__ . '/../Common/Response.php';
require_once __DIR__ . '/../Common/Auth.php';
// 更新履歴 詳細メッセージ生成
require_once __DIR__ . '/../Common/HistoryDetail.php';
// キャラクターの能力・技（JSON保持）の操作
require_once __DIR__ . '/../Common/CharacterSkill.php';

// POSTメソッドのみ許可
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::Error('不正なリクエストです', 405);
}

$FunctionName = '能力設定';
$UserId = '';

try {
    /** リクエストデータを取得 */
    $Input = Response::GetJsonInput();
    $PowerId = $Input['PowerId'] ?? null;
    $WorkId = $AuthWorkId ?? '';
    $UserId = $AuthUserId;
    $UpdateDate = $Input['UpdateDate'] ?? null;

    Logger::Info($FunctionName, '削除処理開始', $UserId);

    /** 入力チェック */
    if (empty($PowerId)) {
        Response::Error('能力IDが指定されていません');
    }

    if (empty($WorkId)) {
        Response::Error('作品IDが指定されていません');
    }

    /** DB接続 */
    $Pdo = Database::GetConnection();
    Database::BeginTransaction();

    /** 削除対象の能力が存在するか確認 */
    $CheckSql = "SELECT powerId, powerName FROM PowerInfo WHERE powerId = :powerId AND workId = :workId";
    $CheckStmt = $Pdo->prepare($CheckSql);
    $CheckStmt->execute([':powerId' => $PowerId, ':workId' => $WorkId]);
    $PowerInfo = $CheckStmt->fetch();

    if (!$PowerInfo) {
        Database::Rollback();
        Response::Error('削除対象の能力が見つかりません');
    }

    // 更新履歴の詳細メッセージを生成（削除。主キーから名称変換した値を使用）
    $Detail = HistoryDetail::Delete($PowerInfo['powername'] ?? null);

    /** 能力を削除 */
    $Sql = "DELETE FROM PowerInfo WHERE powerId = :powerId AND workId = :workId AND updateDate = :updateDate";
    $Stmt = $Pdo->prepare($Sql);
    $Stmt->execute([
        ':powerId' => $PowerId,
        ':workId' => $WorkId,
        ':updateDate' => $UpdateDate
    ]);
    if ($Stmt->rowCount() === 0) {
        Database::Rollback();
        Response::ConflictError();
    }

    /** キャラクターの能力一覧（JSON）から削除した能力を除去
     *  能力・技はJSON保持のため外部キーによる ON DELETE SET NULL が効かない。
     *  なお当該能力に紐づく技は MoveInfo.powerId が SET NULL され関連能力なしの技となり、
     *  そのまま選択可能な状態を保つため、キャラクターの技一覧からは除去しない。 */
    CharacterSkill::RemoveId($Pdo, $WorkId, CharacterSkill::POWER, $PowerId);

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
        'Message' => '能力を削除しました'
    ]);

} catch (Exception $E) {
    Database::Rollback();
    Logger::Error($FunctionName, '削除処理エラー', $UserId, $E->getMessage());
    Response::Error('能力の削除に失敗しました');
}
