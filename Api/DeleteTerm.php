<?php
/* ==========================================================
 * 作中用語削除
 * ----------------------------------------------------------
 * ・作中用語情報を削除する
 * ----------------------------------------------------------
 * 更新履歴：
 * 2026-01-16 作成
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
$FunctionName = '作中用語設定';
// 2. ユーザーID
$UserId = '';

/* ==========================================================
 * 4. 処理
 * ========================================================== */
try {
   /* ---------------------------------------------
    *  1. リクエスト取得
    * --------------------------------------------- */
    $Input = Response::GetJsonInput();

   /* ---------------------------------------------
    *  2. リクエスト内容定義
    * --------------------------------------------- */
    // 1. 用語ID
    $TermId = $Input['TermId'] ?? '';
    // 2. 作品ID
    $WorkId = $AuthWorkId ?? '';
    // 3. ユーザーID
    $UserId = $AuthUserId;
    // 4. 更新年月日（排他制御用）
    $UpdateDate = $Input['UpdateDate'] ?? null;

   /* ---------------------------------------------
    *  3. 操作ログ出力
    * --------------------------------------------- */
    Logger::Info($FunctionName, '削除処理開始', $UserId);

   /* ---------------------------------------------
    *  4. バリデーションチェック
    * --------------------------------------------- */
    if (empty($TermId)) {
        Response::Error('用語IDが指定されていません');
    }
    if (empty($WorkId)) {
        Response::Error('作品IDが指定されていません');
    }

   /* ---------------------------------------------
    *  5. DB接続
    * --------------------------------------------- */
    // 1. 接続開始
    $Pdo = Database::GetConnection();
    // 2. トランザクション開始
    Database::BeginTransaction();

   /* ---------------------------------------------
    *  6. 削除処理
    * --------------------------------------------- */
    // 0. 削除対象の名称を取得（削除詳細メッセージに使用）
    $NameStmt = $Pdo->prepare("SELECT termName FROM TermInfo WHERE termId = :termId AND workId = :workId");
    $NameStmt->bindValue(':termId', $TermId, PDO::PARAM_STR);
    $NameStmt->bindValue(':workId', $WorkId, PDO::PARAM_STR);
    $NameStmt->execute();
    $TermInfo = $NameStmt->fetch();
    // 更新履歴の詳細メッセージを生成（削除。主キーから名称変換した値を使用）
    $Detail = HistoryDetail::Delete($TermInfo['termname'] ?? null);

    // 1. クエリを定義
    $Sql = "DELETE FROM TermInfo WHERE termId = :termId AND workId = :workId AND updateDate = :updateDate";
    // 2. ステートメント定義
    $Stmt = $Pdo->prepare($Sql);
    // 3. パラメータ設定
    $Stmt->bindValue(':termId', $TermId, PDO::PARAM_STR);
    $Stmt->bindValue(':workId', $WorkId, PDO::PARAM_STR);
    $Stmt->bindValue(':updateDate', $UpdateDate, PDO::PARAM_STR);
    // 4. SQL実行
    $Stmt->execute();
    // 5. 排他制御チェック
    if ($Stmt->rowCount() === 0) {
        Database::Rollback();
        Response::ConflictError();
    }

   /* ---------------------------------------------
    *  7. 作品情報更新
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
    // 3. パラメータ設定
    $UpdateWorkStmt->bindValue(':updateUser', $UserId, PDO::PARAM_STR);
    $UpdateWorkStmt->bindValue(':workId',     $WorkId, PDO::PARAM_STR);
    // 4. 実行
    $UpdateWorkStmt->execute();

   /* ---------------------------------------------
    *  8. 更新履歴登録
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
    // 3. パラメータ設定
    $HistoryStmt->bindValue(':workId',       $WorkId,       PDO::PARAM_STR);
    $HistoryStmt->bindValue(':functionName', $FunctionName, PDO::PARAM_STR);
    $HistoryStmt->bindValue(':operation',    '削除',        PDO::PARAM_STR);
    $HistoryStmt->bindValue(':detail',       $Detail,       $Detail === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
    $HistoryStmt->bindValue(':registUser',   $UserId,       PDO::PARAM_STR);
    $HistoryStmt->bindValue(':updateUser',   $UserId,       PDO::PARAM_STR);
    // 4. 実行
    $HistoryStmt->execute();

   /* ---------------------------------------------
    *  9. トランザクションをコミット
    * --------------------------------------------- */
    Database::Commit();

   /* ---------------------------------------------
    *  10.操作ログ書き込み
    * --------------------------------------------- */
    Logger::Info($FunctionName, '削除処理終了', $UserId);

   /* ---------------------------------------------
    *  11.成功レスポンス
    * --------------------------------------------- */
    Response::Success([
        'Message' => '作中用語を削除しました'
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
    Response::Error('作中用語の削除に失敗しました');
}
