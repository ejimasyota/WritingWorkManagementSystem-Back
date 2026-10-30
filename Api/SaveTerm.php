<?php
/* ==========================================================
 * 作中用語情報更新
 * ----------------------------------------------------------
 * ・作中用語情報を登録・更新する
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
    $TermId       = $Input['TermId'] ?? null;
    // 2. 作品ID
    $WorkId       = $AuthWorkId ?? '';
    // 3. 用語名
    $TermName     = $Input['TermName'] ?? '';
    // 4. 説明
    $Description  = $Input['Description'] ?? null;
    // 5. ユーザーID
    $UserId = $AuthUserId;
    // 6. 更新年月日（排他制御用）
    $UpdateDate   = $Input['UpdateDate'] ?? null;

   /* ---------------------------------------------
    *  3. 操作設定
    * --------------------------------------------- */
    $Operation = empty($TermId) ? '登録' : '更新';
    // 更新履歴の詳細メッセージ（登録/更新分岐でそれぞれ生成）
    $Detail = null;

   /* ---------------------------------------------
    *  4. 操作ログ出力
    * --------------------------------------------- */
    Logger::Info($FunctionName, $Operation . '処理開始', $UserId);

   /* ---------------------------------------------
    *  5. バリデーションチェック
    * --------------------------------------------- */
    if (empty($WorkId)) {
        Response::Error('作品IDが指定されていません');
    }
    if (empty($TermName)) {
        Response::Error('用語名を入力してください');
    }

   /* ---------------------------------------------
    *  6. DB接続
    * --------------------------------------------- */
    // 1. 接続開始
    $Pdo = Database::GetConnection();
    // 2. トランザクション開始
    Database::BeginTransaction();

   /* ---------------------------------------------
    *  7. 空文字をNULLに変換
    * --------------------------------------------- */
    $TermId      = !empty($TermId) ? $TermId : null;
    $Description = !empty($Description) ? $Description : null;

   /* ---------------------------------------------
    *  8. 新規登録処理（TermIdが空の場合）
    * --------------------------------------------- */
    if (empty($TermId)) {
        // 1. クエリを定義
        $Sql = "
            INSERT INTO TermInfo (
                workId, termName, description, registDate, updateDate, registUser, updateUser
            ) VALUES (
                :workId, :termName, :description, CURRENT_TIMESTAMP(3), CURRENT_TIMESTAMP(3), :registUser, :updateUser
            )
            RETURNING termId, updateDate
        ";
        // 2. ステートメント定義
        $Stmt = $Pdo->prepare($Sql);
        // 3. パラメータ設定
        $Stmt->bindValue(':workId',      $WorkId,      PDO::PARAM_STR);
        $Stmt->bindValue(':termName',    $TermName,    PDO::PARAM_STR);
        $Stmt->bindValue(':description', $Description, $Description === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $Stmt->bindValue(':registUser',  $UserId,      PDO::PARAM_STR);
        $Stmt->bindValue(':updateUser',  $UserId,      PDO::PARAM_STR);
        // 4. SQL実行
        $Stmt->execute();
        // 5. 新規登録されたレコード取得
        $ResultRow = $Stmt->fetch();
        // 6. 用語ID取得
        $NewTermId = $ResultRow['termid'];
        $NewUpdateDate = $ResultRow['updatedate'];
        // 7. 更新履歴の詳細メッセージを生成（登録）
        $Detail = HistoryDetail::Register($TermName);

   /* ---------------------------------------------
    *  9. 更新処理（TermIdが存在する場合）
    * --------------------------------------------- */
    } else {
        /* 差分履歴用：更新前の表示値を取得（キーはPDO既定の小文字） */
        $OldRowStmt = $Pdo->prepare("
            SELECT termName, description
            FROM TermInfo
            WHERE termId = :termId AND workId = :workId
        ");
        $OldRowStmt->bindValue(':termId', $TermId, PDO::PARAM_STR);
        $OldRowStmt->bindValue(':workId', $WorkId, PDO::PARAM_STR);
        $OldRowStmt->execute();
        $OldRow = $OldRowStmt->fetch() ?: [];

        // 1. クエリを定義
        $Sql = "
            UPDATE TermInfo SET
                termName = :termName,
                description = :description,
                updateDate = CURRENT_TIMESTAMP(3),
                updateUser = :updateUser
            WHERE termId = :termId AND workId = :workId AND updateDate = :updateDate
            RETURNING updateDate
        ";
        // 2. ステートメント定義
        $Stmt = $Pdo->prepare($Sql);
        // 3. パラメータ設定
        $Stmt->bindValue(':termId',      $TermId,      PDO::PARAM_STR);
        $Stmt->bindValue(':workId',      $WorkId,      PDO::PARAM_STR);
        $Stmt->bindValue(':termName',    $TermName,    PDO::PARAM_STR);
        $Stmt->bindValue(':description', $Description, $Description === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $Stmt->bindValue(':updateUser',  $UserId,      PDO::PARAM_STR);
        $Stmt->bindValue(':updateDate',  $UpdateDate,  PDO::PARAM_STR);
        // 4. SQL実行
        $Stmt->execute();
        // 5. 排他制御チェック
        $UpdateResult = $Stmt->fetch();
        if (!$UpdateResult) {
            Database::Rollback();
            Response::ConflictError();
        }
        // 6. 用語IDを設定
        $NewTermId = $TermId;
        $NewUpdateDate = $UpdateResult['updatedate'];

        /* 7. 更新履歴の詳細メッセージを生成（更新・列単位差分） */
        // 差分対象の列 → 和名
        $Labels = [
            'termName'    => '用語名',
            'description' => '説明',
        ];
        // 更新前の表示値
        $OldDisplay = [
            'termName'    => $OldRow['termname']    ?? null,
            'description' => $OldRow['description'] ?? null,
        ];
        // 更新後の表示値
        $NewDisplay = [
            'termName'    => $TermName,
            'description' => $Description,
        ];
        $Detail = HistoryDetail::Update($TermName, $OldDisplay, $NewDisplay, $Labels);
    }

   /* ---------------------------------------------
    *  10.作品情報更新
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
    *  11.更新履歴登録
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
    // 3. パラメータ設定・実行（変更が無い更新は履歴に残す情報が無いため登録しない）
    if (HistoryDetail::HasChanges($Detail)) {
        $HistoryStmt->bindValue(':workId',       $WorkId,       PDO::PARAM_STR);
        $HistoryStmt->bindValue(':functionName', $FunctionName, PDO::PARAM_STR);
        $HistoryStmt->bindValue(':operation',    $Operation,    PDO::PARAM_STR);
        $HistoryStmt->bindValue(':detail',       $Detail,       $Detail === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $HistoryStmt->bindValue(':registUser',   $UserId,       PDO::PARAM_STR);
        $HistoryStmt->bindValue(':updateUser',   $UserId,       PDO::PARAM_STR);
        // 4. 実行
        $HistoryStmt->execute();
    }

   /* ---------------------------------------------
    *  12.トランザクションをコミット
    * --------------------------------------------- */
    Database::Commit();

   /* ---------------------------------------------
    *  13.操作ログ書き込み
    * --------------------------------------------- */
    Logger::Info($FunctionName, $Operation . '処理終了', $UserId);

   /* ---------------------------------------------
    *  14.成功レスポンス
    * --------------------------------------------- */
    Response::Success([
        'TermId' => $NewTermId,
        'UpdateDate' => $NewUpdateDate,
        'Message' => '作中用語を' . $Operation . 'しました'
    ]);

} catch (Exception $E) {
   /* ==========================================================
    * 5. 例外処理
    * ========================================================== */
    // 1. トランザクションをロールバック
    Database::Rollback();
    // 2. エラーログ書き込み
    Logger::Error($FunctionName, '保存処理エラー', $UserId, $E->getMessage());
    // 3. エラーレスポンス
    Response::Error('作中用語の保存に失敗しました');
}
