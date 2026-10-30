<?php
/**
 * DeleteStage.php
 * 舞台削除API
 */

require_once __DIR__ . '/../Common/Database.php';
require_once __DIR__ . '/../Common/Logger.php';
require_once __DIR__ . '/../Common/Response.php';
require_once __DIR__ . '/../Common/Auth.php';
// 画像一覧（画像の物理削除）の共通処理
require_once __DIR__ . '/../Common/ImageList.php';
// 更新履歴 詳細メッセージ生成
require_once __DIR__ . '/../Common/HistoryDetail.php';

// POSTメソッドのみ許可
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::Error('不正なリクエストです', 405);
}

$FunctionName = '舞台設定';
$UserId = '';

try {
    /** リクエストデータを取得 */
    $Input = Response::GetJsonInput();
    $StageId = $Input['StageId'] ?? null;
    $WorkId = $AuthWorkId ?? '';
    $UserId = $AuthUserId;
    $UpdateDate = $Input['UpdateDate'] ?? null;

    Logger::Info($FunctionName, '削除処理開始', $UserId);

    /** 入力チェック */
    if (empty($StageId)) {
        Response::Error('舞台IDが指定されていません');
    }

    if (empty($WorkId)) {
        Response::Error('作品IDが指定されていません');
    }

    /** DB接続 */
    $Pdo = Database::GetConnection();
    Database::BeginTransaction();

    /** 削除対象の舞台が存在するか確認（名称は削除詳細メッセージに使用）
     *  画像は削除のコミット後に物理削除するため、代表画像のパスと画像一覧も併せて取得する */
    $CheckSql = "SELECT stageId, stageName, imgPath, imgList FROM StageInfo WHERE stageId = :stageId AND workId = :workId";
    $CheckStmt = $Pdo->prepare($CheckSql);
    $CheckStmt->execute([':stageId' => $StageId, ':workId' => $WorkId]);
    $StageInfo = $CheckStmt->fetch();

    if (!$StageInfo) {
        Database::Rollback();
        Response::Error('削除対象の舞台が見つかりません');
    }

    // 更新履歴の詳細メッセージを生成（削除。主キーから名称変換した値を使用）
    $Detail = HistoryDetail::Delete($StageInfo['stagename'] ?? null);

    // コミット後に削除する画像のパス（画像一覧の全画像と代表画像）
    $DeleteImgPaths = ImageList::CollectPaths($StageInfo['imglist'] ?? null, $StageInfo['imgpath'] ?? null);

    /** 舞台を削除 */
    $Sql = "DELETE FROM StageInfo WHERE stageId = :stageId AND workId = :workId AND updateDate = :updateDate";
    $Stmt = $Pdo->prepare($Sql);
    $Stmt->execute([
        ':stageId' => $StageId,
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

    /** Commit 後の画像の物理削除
     *  ・ファイル操作失敗は DB 状態を巻き戻せないため、削除できなくても処理を継続する
     *  ・Uploads/ 配下以外のパスは FileSafety 側で削除しない */
    ImageList::DeleteFiles($DeleteImgPaths);

    Logger::Info($FunctionName, '削除処理終了', $UserId);

    Response::Success([
        'Message' => '舞台を削除しました'
    ]);

} catch (Exception $E) {
    Database::Rollback();
    Logger::Error($FunctionName, '削除処理エラー', $UserId, $E->getMessage());
    Response::Error('舞台の削除に失敗しました');
}
