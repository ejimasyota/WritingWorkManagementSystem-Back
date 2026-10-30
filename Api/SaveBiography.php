<?php
/* ==========================================================
 * 経歴情報保存
 * ----------------------------------------------------------
 * ・経歴情報を登録・更新する
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
    *  1. リクエスト取得
    * --------------------------------------------- */
    $Input = Response::GetJsonInput();

   /* ---------------------------------------------
    *  2. リクエスト内容定義
    * --------------------------------------------- */
    // 1. 経歴ID
    $BiographyId  = $Input['BiographyId'] ?? null;
    // 2. 作品ID
    $WorkId       = $AuthWorkId ?? '';
    // 3. キャラID
    $CharaId      = $Input['CharaId'] ?? '';
    // 4. 年月日
    $EventDate    = $Input['EventDate'] ?? '';
    // 5. 年齢
    $Age          = $Input['Age'] ?? '';
    // 6. 内容
    $Content      = $Input['Content'] ?? '';
    // 7. ユーザーID
    $UserId = $AuthUserId;
    // 8. 更新年月日（排他制御用）
    $UpdateDate   = $Input['UpdateDate'] ?? null;

   /* ---------------------------------------------
    *  3. 操作設定
    * --------------------------------------------- */
    $Operation = empty($BiographyId) ? '登録' : '更新';
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
    if (empty($CharaId)) {
        Response::Error('キャラクターが選択されていません');
    }
    if (empty($EventDate)) {
        Response::Error('年月日が入力されていません');
    }
    if ($Age === '') {
        Response::Error('年齢が入力されていません');
    }
    if (empty($Content)) {
        Response::Error('内容が入力されていません');
    }

   /* ---------------------------------------------
    *  6. DB接続
    * --------------------------------------------- */
    // 1. 接続開始
    $Pdo = Database::GetConnection();
    // 2. トランザクション開始
    Database::BeginTransaction();

   /* ---------------------------------------------
    *  6-2. キャラ名解決ヘルパ（FK charaId → CharacterInfo.charaName）
    * --------------------------------------------- */
    $ResolveCharaName = function ($Id) use ($Pdo, $WorkId) {
        if ($Id === null || $Id === '') {
            return null;
        }
        $S = $Pdo->prepare("SELECT charaName AS nm FROM CharacterInfo WHERE charaId = :id AND workId = :workId");
        $S->bindValue(':id', $Id, PDO::PARAM_STR);
        $S->bindValue(':workId', $WorkId, PDO::PARAM_STR);
        $S->execute();
        $R = $S->fetch();
        return $R ? $R['nm'] : null;
    };

   /* ---------------------------------------------
    *  7. 新規登録処理（BiographyIdが空の場合）
    * --------------------------------------------- */
    if (empty($BiographyId)) {
        // 1. クエリを定義
        $Sql = "
            INSERT INTO BiographyInfo (
                workId, charaId, eventDate, age, content,
                registDate, updateDate, registUser, updateUser
            ) VALUES (
                :workId, :charaId, :eventDate, :age, :content,
                CURRENT_TIMESTAMP(3), CURRENT_TIMESTAMP(3), :registUser, :updateUser
            )
            RETURNING biographyId, updateDate
        ";
        // 2. ステートメント定義
        $Stmt = $Pdo->prepare($Sql);
        // 3. パラメータ設定
        $Stmt->bindValue(':workId',      $WorkId,    PDO::PARAM_STR);
        $Stmt->bindValue(':charaId',     $CharaId,   PDO::PARAM_STR);
        $Stmt->bindValue(':eventDate',   $EventDate, PDO::PARAM_STR);
        $Stmt->bindValue(':age',         $Age,        PDO::PARAM_STR);
        $Stmt->bindValue(':content',     $Content,   PDO::PARAM_STR);
        $Stmt->bindValue(':registUser',  $UserId,    PDO::PARAM_STR);
        $Stmt->bindValue(':updateUser',  $UserId,    PDO::PARAM_STR);
        // 4. SQL実行
        $Stmt->execute();
        // 5. 新規登録されたレコード取得
        $ResultRow = $Stmt->fetch();
        // 6. 経歴ID取得
        $NewBiographyId = $ResultRow['biographyid'];
        $NewUpdateDate = $ResultRow['updatedate'];
        // 7. 更新履歴の詳細メッセージを生成（登録。名称は対象キャラ名）
        $Detail = HistoryDetail::Register($ResolveCharaName($CharaId));

   /* ---------------------------------------------
    *  8. 更新処理（BiographyIdが存在する場合）
    * --------------------------------------------- */
    } else {
        /* 差分履歴用：更新前の表示値を取得（FK charaId は名称へ解決） */
        $OldRowStmt = $Pdo->prepare("
            SELECT b.charaId, c.charaName, b.eventDate, b.age, b.content
            FROM BiographyInfo b
            LEFT JOIN CharacterInfo c ON b.charaId = c.charaId
            WHERE b.biographyId = :biographyId AND b.workId = :workId
        ");
        $OldRowStmt->bindValue(':biographyId', $BiographyId, PDO::PARAM_INT);
        $OldRowStmt->bindValue(':workId',      $WorkId,      PDO::PARAM_STR);
        $OldRowStmt->execute();
        $OldRow = $OldRowStmt->fetch() ?: [];

        // 1. クエリを定義
        $Sql = "
            UPDATE BiographyInfo SET
                charaId = :charaId,
                eventDate = :eventDate,
                age = :age,
                content = :content,
                updateDate = CURRENT_TIMESTAMP(3),
                updateUser = :updateUser
            WHERE biographyId = :biographyId AND workId = :workId AND updateDate = :updateDate
            RETURNING updateDate
        ";
        // 2. ステートメント定義
        $Stmt = $Pdo->prepare($Sql);
        // 3. パラメータ設定
        $Stmt->bindValue(':biographyId', $BiographyId, PDO::PARAM_INT);
        $Stmt->bindValue(':workId',      $WorkId,      PDO::PARAM_STR);
        $Stmt->bindValue(':charaId',     $CharaId,     PDO::PARAM_STR);
        $Stmt->bindValue(':eventDate',   $EventDate,   PDO::PARAM_STR);
        $Stmt->bindValue(':age',         $Age,          PDO::PARAM_STR);
        $Stmt->bindValue(':content',     $Content,     PDO::PARAM_STR);
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
        // 6. 経歴ID設定
        $NewBiographyId = $BiographyId;
        $NewUpdateDate = $UpdateResult['updatedate'];

        /* 7. 更新履歴の詳細メッセージを生成（更新・列単位差分） */
        // 差分対象の列 → 和名（PK/workId/監査列は対象外。FKは概念名）
        $Labels = [
            'charaId'   => 'キャラクター',
            'eventDate' => '年月日',
            'age'       => '年齢',
            'content'   => '内容',
        ];
        // 更新前の表示値（FKは旧名称）
        $OldDisplay = [
            'charaId'   => $OldRow['charaname'] ?? null,
            'eventDate' => $OldRow['eventdate'] ?? null,
            'age'       => $OldRow['age']       ?? null,
            'content'   => $OldRow['content']   ?? null,
        ];
        // 更新後の表示値（FKは新id→名称解決。新id==旧idは旧名称を再利用）
        $NewCharaName = ((string) ($CharaId ?? '') === (string) ($OldRow['charaid'] ?? ''))
            ? ($OldRow['charaname'] ?? null)
            : $ResolveCharaName($CharaId);
        $NewDisplay = [
            'charaId'   => $NewCharaName,
            'eventDate' => $EventDate,
            'age'       => $Age,
            'content'   => $Content,
        ];
        $Detail = HistoryDetail::Update($NewCharaName, $OldDisplay, $NewDisplay, $Labels);
    }

   /* ---------------------------------------------
    *  9. 作品情報更新
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
    // 3. 更新ユーザーID設定
    $UpdateWorkStmt->bindValue(':updateUser', $UserId, PDO::PARAM_STR);
    // 4. 作品ID設定
    $UpdateWorkStmt->bindValue(':workId',     $WorkId, PDO::PARAM_STR);
    // 5. 実行
    $UpdateWorkStmt->execute();

   /* ---------------------------------------------
    *  10. 更新履歴登録
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
    *  11. トランザクションをコミット
    * --------------------------------------------- */
    Database::Commit();

   /* ---------------------------------------------
    *  12. 操作ログ書き込み
    * --------------------------------------------- */
    Logger::Info($FunctionName, $Operation . '処理終了', $UserId);

   /* ---------------------------------------------
    *  13. 成功レスポンス
    * --------------------------------------------- */
    Response::Success([
        'BiographyId' => $NewBiographyId,
        'UpdateDate' => $NewUpdateDate,
        'Message' => '経歴を' . $Operation . 'しました'
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
    Response::Error('経歴の保存に失敗しました');
}
