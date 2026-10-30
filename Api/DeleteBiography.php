<?php
/* ==========================================================
 * 経歴削除
 * ----------------------------------------------------------
 * ・指定された経歴を削除する
 * ----------------------------------------------------------
 * 更新履歴：
 * 2026-02-02 作成
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
$FunctionName = '経歴設定';
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
    // 1. 経歴ID
    $BiographyId = $Input['BiographyId'] ?? null;
    // 2. 作品ID
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
    /* 1. 経歴ID取得失敗 */
    if (empty($BiographyId)) {
        Response::Error('経歴IDが指定されていません');
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
    // 1. 経歴のworkId・対象キャラ名を確認するために取得（名称は削除詳細メッセージに使用）
    $GetWorkSql = "
        SELECT b.workId, c.charaName
        FROM BiographyInfo b
        LEFT JOIN CharacterInfo c ON b.charaId = c.charaId
        WHERE b.biographyId = :biographyId
    ";
    // 2. ステートメント定義
    $GetWorkStmt = $Pdo->prepare($GetWorkSql);
    // 3. 実行
    $GetWorkStmt->execute([':biographyId' => $BiographyId]);
    // 4. 結果取得
    $BiographyInfo = $GetWorkStmt->fetch();

    /* 2. 存在チェック */
    if (!$BiographyInfo) {
        throw new Exception('削除対象の経歴が見つかりません');
    }

    // 5. 認可チェック：削除対象の作品IDがセッションの現作品IDと一致すること
    if ($BiographyInfo['workid'] !== $WorkId) {
        throw new Exception('作品IDが一致しません');
    }

    // 6. 更新履歴の詳細メッセージを生成（削除。名称は対象キャラ名）
    $Detail = HistoryDetail::Delete($BiographyInfo['charaname'] ?? null);

    /* ---------------------------------------------
     * 8. 経歴削除実行
     * --------------------------------------------- */
    // 1. クエリを定義
    $Sql = "DELETE FROM BiographyInfo WHERE biographyId = :biographyId AND workId = :workId AND updateDate = :updateDate";
    // 2. ステートメント定義
    $Stmt = $Pdo->prepare($Sql);
    // 3. 実行
    $Stmt->execute([':biographyId' => $BiographyId, ':workId' => $WorkId, ':updateDate' => $UpdateDate]);
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
     * 12. 操作ログ書き込み
     * --------------------------------------------- */
    Logger::Info($FunctionName, $Operation . '処理終了', $UserId);

    /* ---------------------------------------------
     * 13. 成功レスポンス
     * --------------------------------------------- */
    Response::Success([
        'Message' => '経歴を削除しました'
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
    Response::Error('経歴の削除に失敗しました');
}
