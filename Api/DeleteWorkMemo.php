<?php
/**
 * DeleteWorkMemo.php
 * 作品作成用メモ削除API
 * ※削除は自身の投稿のみ可能
 */

require_once __DIR__ . '/../Common/Database.php';
require_once __DIR__ . '/../Common/Logger.php';
require_once __DIR__ . '/../Common/Response.php';
// 画像一覧（画像の物理削除）の共通処理
require_once __DIR__ . '/../Common/ImageList.php';
require_once __DIR__ . '/../Common/Auth.php';
require_once __DIR__ . '/../Common/HistoryDetail.php';

// POSTメソッドのみ許可
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::Error('不正なリクエストです', 405);
}

$FunctionName = '作品作成用メモ';
$UserId = '';

try {
    /** リクエストデータを取得 */
    $Input = Response::GetJsonInput();
    $MemoId = $Input['MemoId'] ?? null;
    $WorkId = $AuthWorkId ?? '';
    $UserId = $AuthUserId;
    $UpdateDate = $Input['UpdateDate'] ?? null;

    Logger::Info($FunctionName, '削除処理開始', $UserId);

    /** 入力チェック */
    if (empty($MemoId)) {
        Response::Error('メモIDが指定されていません');
    }

    if (empty($WorkId)) {
        Response::Error('作品IDが指定されていません');
    }

    /** DB接続 */
    $Pdo = Database::GetConnection();
    Database::BeginTransaction();

    /** 削除対象のメモが存在するか確認
     *  画像は削除のコミット後に物理削除するため、代表画像のパスと画像一覧も併せて取得する */
    $CheckSql = "SELECT memoId, registUser, imgPath, imgList, title FROM WorkMemoInfo WHERE memoId = :memoId AND workId = :workId";
    $CheckStmt = $Pdo->prepare($CheckSql);
    $CheckStmt->execute([':memoId' => $MemoId, ':workId' => $WorkId]);
    $MemoInfo = $CheckStmt->fetch();

    if (!$MemoInfo) {
        Database::Rollback();
        Response::Error('削除対象のメモが見つかりません');
    }

    /** 自身の投稿でない場合はエラー */
    if ($MemoInfo['registuser'] !== $UserId) {
        Database::Rollback();
        Response::Error('他のユーザが投稿したメモは削除できません');
    }

    // 更新履歴の詳細メッセージを生成（削除。タイトルを名称として使用）
    $Detail = HistoryDetail::Delete($MemoInfo['title'] ?? null);

    /** コミット後に削除する画像のパス（画像一覧の全画像と代表画像） */
    $DeleteImgPaths = ImageList::CollectPaths($MemoInfo['imglist'] ?? null, $MemoInfo['imgpath'] ?? null);

    /** メモを削除 */
    $Sql = "DELETE FROM WorkMemoInfo WHERE memoId = :memoId AND workId = :workId AND updateDate = :updateDate";
    $Stmt = $Pdo->prepare($Sql);
    $Stmt->execute([
        ':memoId' => $MemoId,
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

    /** Commit 後に画像ファイルを物理削除
     *  ファイル操作失敗は DB 状態を巻き戻せないため、削除できなくても処理を継続する。
     *  Uploads/ 配下以外のパスは FileSafety 側で削除しない。 */
    ImageList::DeleteFiles($DeleteImgPaths);

    Logger::Info($FunctionName, '削除処理終了', $UserId);

    Response::Success([
        'Message' => 'メモを削除しました'
    ]);

} catch (Exception $E) {
    Database::Rollback();
    Logger::Error($FunctionName, '削除処理エラー', $UserId, $E->getMessage());
    Response::Error('メモの削除に失敗しました');
}
