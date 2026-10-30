<?php
/* ==========================================================
 * キャラ削除
 * ----------------------------------------------------------
 * ・指定されたキャラクターを削除する
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
$FunctionName = 'キャラ設定';
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
    // 1. キャラID
    $CharaId = $Input['CharaId'] ?? null;
    // 2. 作品ID（初期値）
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
    /* 1. キャラID取得失敗 */
    if (empty($CharaId)) {
        Response::Error('キャラIDが指定されていません');
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
    // 1. キャラのworkId・名称を確認するために取得（名称は削除詳細メッセージに使用）
    //    画像は削除のコミット後に物理削除するため、代表画像のパスと画像一覧も併せて取得する
    $GetWorkSql = "SELECT workId, charaName, imgPath, imgList FROM CharacterInfo WHERE charaId = :charaId";
    // 2. ステートメント定義
    $GetWorkStmt = $Pdo->prepare($GetWorkSql);
    // 3. 実行
    $GetWorkStmt->execute([':charaId' => $CharaId]);
    // 4. 結果取得
    $CharaInfo = $GetWorkStmt->fetch();

    /* 2. 存在チェック */
    if (!$CharaInfo) {
        throw new Exception('削除対象のキャラが見つかりません');
    }

    // 5. 認可チェック：削除対象の作品IDがセッションの現作品IDと一致すること
    if ($CharaInfo['workid'] !== $WorkId) {
        throw new Exception('作品IDが一致しません');
    }

    // 6. 更新履歴の詳細メッセージを生成（削除。主キーから名称変換した値を使用）
    $Detail = HistoryDetail::Delete($CharaInfo['charaname'] ?? null);

    // 7. コミット後に削除する画像のパス（画像一覧の全画像と代表画像）
    $DeleteImgPaths = ImageList::CollectPaths($CharaInfo['imglist'] ?? null, $CharaInfo['imgpath'] ?? null);

    /* ---------------------------------------------
     * 8. キャラクター削除実行
     * --------------------------------------------- */
    // 1. クエリを定義
    $Sql = "DELETE FROM CharacterInfo WHERE charaId = :charaId AND workId = :workId AND updateDate = :updateDate";
    // 2. ステートメント定義
    $Stmt = $Pdo->prepare($Sql);
    // 3. 実行
    $Stmt->execute([':charaId' => $CharaId, ':workId' => $WorkId, ':updateDate' => $UpdateDate]);
    // 4. 排他制御チェック
    if ($Stmt->rowCount() === 0) {
        Database::Rollback();
        Response::ConflictError();
    }

    /* ---------------------------------------------
     * 9. 関連データ削除（相関図）
     * --------------------------------------------- */
    // 1. 関連する関係線を先に削除（外部キー制約対応）
    $DeleteRelLinesSql = "
        DELETE FROM RelationInfo
        WHERE workId = :workId AND (sourceObjId IN (
            SELECT objId FROM RelationObjectInfo WHERE objType = 1 AND targetId = :charaId
        ) OR targetObjId IN (
            SELECT objId FROM RelationObjectInfo WHERE objType = 1 AND targetId = :charaId2
        ))
    ";
    $DeleteRelLinesStmt = $Pdo->prepare($DeleteRelLinesSql);
    $DeleteRelLinesStmt->execute([
        ':workId' => $WorkId,
        ':charaId' => $CharaId,
        ':charaId2' => $CharaId
    ]);
    // 2. 相関図オブジェクトからも削除（objType=1:キャラクター）
    $DeleteRelationSql = "DELETE FROM RelationObjectInfo WHERE objType = 1 AND targetId = :charaId";
    // 3. ステートメント定義
    $DeleteRelationStmt = $Pdo->prepare($DeleteRelationSql);
    // 4. 実行
    $DeleteRelationStmt->execute([':charaId' => $CharaId]);

    /* ---------------------------------------------
     * 10. 作品情報更新
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
     * 11. 更新履歴登録
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
     * 12. トランザクションをコミット
     * --------------------------------------------- */
    Database::Commit();

    /* ---------------------------------------------
     * 12-2. Commit 後の画像の物理削除
     *    - ファイル操作失敗は DB 状態を巻き戻せないため、削除できなくても処理を継続する。
     *    - Uploads/ 配下以外のパスは FileSafety 側で削除しない。
     * --------------------------------------------- */
    ImageList::DeleteFiles($DeleteImgPaths);

    /* ---------------------------------------------
     * 13. 操作ログ書き込み
     * --------------------------------------------- */
    Logger::Info($FunctionName, $Operation . '処理終了', $UserId);

    /* ---------------------------------------------
     * 14. 成功レスポンス
     * --------------------------------------------- */
    Response::Success([
        'Message' => 'キャラを削除しました'
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
    Response::Error('キャラの削除に失敗しました');
}