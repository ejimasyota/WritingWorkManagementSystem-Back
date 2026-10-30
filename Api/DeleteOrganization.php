<?php
/* ==========================================================
 * 組織削除
 * ----------------------------------------------------------
 * ・指定された組織を削除する
 * ----------------------------------------------------------
 * 更新履歴：
 * 2026-01-10 作成
 * ========================================================== */

/* ==========================================================
 * 1. 共通モジュール読み込み
 * ========================================================== */
// 1. データベース接続クラス
require_once __DIR__ . '/../Common/Database.php';
// 2. ログ出力クラス
require_once __DIR__ . '/../Common/Logger.php';
// 3. レスポンスクラス
require_once __DIR__ . '/../Common/Response.php';
require_once __DIR__ . '/../Common/Auth.php';
// 画像一覧（画像の物理削除）の共通処理
require_once __DIR__ . '/../Common/ImageList.php';
// 更新履歴 詳細メッセージ生成
require_once __DIR__ . '/../Common/HistoryDetail.php';

/* ==========================================================
 * 2. リクエストチェック
 * ========================================================== */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    // 1. 例外レスポンスを返す
    Response::Error('不正なリクエストです', 405);
}

/* ==========================================================
 * 3. 共通定義
 * ========================================================== */
// 1. 機能名
$FunctionName = '組織設定';
// 2. ユーザーID
$UserId = '';

/* ==========================================================
 * 4. 処理
 * ========================================================== */
try {
    /* ---------------------------------------------
     * 1. リクエスト取得
     * --------------------------------------------- */
    $Input = Response::GetJsonInput();

    /* ---------------------------------------------
     * 2. リクエスト内容定義
     * --------------------------------------------- */
    // 1. 組織ID
    $OrgId  = $Input['OrgId'] ?? null;
    // 2. 作品ID（初期値。DBから再取得することを想定）
    $WorkId = $AuthWorkId ?? '';
    // 3. ユーザーID
    $UserId = $AuthUserId;
    // 4. 更新年月日（排他制御用）
    $UpdateDate = $Input['UpdateDate'] ?? null;

    /* ---------------------------------------------
     * 3. 操作設定
     * --------------------------------------------- */
    $Operation = '削除';

    /* ---------------------------------------------
     * 4. バリデーションチェック
     * --------------------------------------------- */
    /* 1. 組織ID取得失敗 */
    if (empty($OrgId)) {
        Response::Error('組織IDが指定されていません');
    }
    /* 2. 作品ID取得失敗 */
    if (empty($WorkId)) {
        Response::Error('作品IDが指定されていません');
    }
    /* 3. ユーザーID取得失敗 */
    if (empty($UserId)) {
        Response::Error('ユーザーIDが指定されていません');
    }

    /* ---------------------------------------------
     * 5. 操作ログ出力
     * --------------------------------------------- */
    Logger::Info($FunctionName, $Operation . '処理開始', $UserId);

    /* ---------------------------------------------
     * 6. DB接続
     * --------------------------------------------- */
    // 1. 接続開始
    $Pdo = Database::GetConnection();
    // 2. トランザクション開始
    Database::BeginTransaction();

    /* ---------------------------------------------
     * 7. 事前情報取得
     * --------------------------------------------- */
    // 1. 組織のworkIdを確認するために取得（削除する画像のパスも併せて取得）
    $GetWorkSql = "SELECT workId, orgName, imgPath, imgList FROM OrganizationInfo WHERE orgId = :orgId";
    // 2. ステートメント定義
    $GetWorkStmt = $Pdo->prepare($GetWorkSql);
    // 3. 実行
    $GetWorkStmt->execute([':orgId' => $OrgId]);
    // 4. 結果取得
    $OrgInfo = $GetWorkStmt->fetch();

    /* 存在チェック */
    if (!$OrgInfo) {
        throw new Exception('削除対象の組織が見つかりません');
    }

    // 5. 認可チェック：削除対象の作品IDがセッションの現作品IDと一致すること
    if ($OrgInfo['workid'] !== $WorkId) {
        throw new Exception('作品IDが一致しません');
    }

    // 6. 更新履歴の詳細メッセージを生成（削除。主キーから名称変換した値を使用）
    $Detail = HistoryDetail::Delete($OrgInfo['orgname'] ?? null);

    // 7. コミット後に削除する画像のパス（画像一覧の全画像と代表画像）
    //    ※従来は組織を削除しても画像ファイルがサーバーに残っていたため、併せて削除する
    $DeleteImgPaths = ImageList::CollectPaths($OrgInfo['imglist'] ?? null, $OrgInfo['imgpath'] ?? null);

    /* ---------------------------------------------
     * 8. 組織削除実行
     * --------------------------------------------- */
    // 1. クエリを定義
    $Sql = "DELETE FROM OrganizationInfo WHERE orgId = :orgId AND workId = :workId AND updateDate = :updateDate";
    // 2. ステートメント定義
    $Stmt = $Pdo->prepare($Sql);
    // 3. 実行
    $Stmt->execute([':orgId' => $OrgId, ':workId' => $WorkId, ':updateDate' => $UpdateDate]);
    // 4. 排他制御チェック
    if ($Stmt->rowCount() === 0) {
        Database::Rollback();
        Response::ConflictError();
    }

    /* ---------------------------------------------
     * 9. 作品情報更新
     * --------------------------------------------- */
    // 1. クエリを定義
    $UpdateWorkSql = "
        UPDATE WorkInfo SET
            updateDate = CURRENT_TIMESTAMP(3),
            updateUser = :updateUser
        WHERE workId = :workId
    ";
    // 2. ステートメント定義
    $UpdateWorkStmt = $Pdo->prepare($UpdateWorkSql);
    // 3. 実行
    $UpdateWorkStmt->execute([
        ':workId' => $WorkId,
        ':updateUser' => $UserId
    ]);

    /* ---------------------------------------------
     * 10. 更新履歴登録
     * --------------------------------------------- */
    // 1. クエリを定義
    $HistorySql = "
        INSERT INTO UpdateHistory (
            workId, functionName, operation, detail, registDate, updateDate, registUser, updateUser
        ) VALUES (
            :workId, :functionName, :operation, :detail, CURRENT_TIMESTAMP(3), CURRENT_TIMESTAMP(3), :registUser, :updateUser
        )
    ";
    // 2. ステートメント定義
    $HistoryStmt = $Pdo->prepare($HistorySql);
    // 3. 実行
    $HistoryStmt->execute([
        ':workId' => $WorkId,
        ':functionName' => $FunctionName,
        ':operation' => $Operation,
        ':detail' => $Detail,
        ':registUser' => $UserId,
        ':updateUser' => $UserId
    ]);

    /* ---------------------------------------------
     * 11. トランザクションをコミット
     * --------------------------------------------- */
    Database::Commit();

    /* ---------------------------------------------
     * 11-2. Commit 後の画像の物理削除
     *    - ファイル操作失敗は DB 状態を巻き戻せないため、削除できなくても処理を継続する。
     *    - Uploads/ 配下以外のパスは FileSafety 側で削除しない。
     * --------------------------------------------- */
    ImageList::DeleteFiles($DeleteImgPaths);

    /* ---------------------------------------------
     * 12. 操作ログ書き込み
     * --------------------------------------------- */
    Logger::Info($FunctionName, $Operation . '処理終了', $UserId);

    /* ---------------------------------------------
     * 13. 成功レスポンス
     * --------------------------------------------- */
    Response::Success([
        'Message' => '組織を削除しました'
    ]);

} catch (Exception $E) {
    /* ==========================================================
     * 5. 例外処理
     * ========================================================== */
    // 1. トランザクションをロールバック
    Database::Rollback();
    // 2. エラーログ書き込み
    Logger::Error($FunctionName, '削除処理エラー', $UserId, $E->getMessage());
    // 3. エラーレスポンス
    Response::Error('組織の削除に失敗しました');
}
