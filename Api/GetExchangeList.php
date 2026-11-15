<?php
/* ==========================================================
 * 掛け合い一覧取得API
 * ----------------------------------------------------------
 * ・キャラ間の掛け合い一覧の取得を行うAPI
 * ----------------------------------------------------------
 * 更新履歴：
 * 2026-08-14 作成
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
// 4. ユーザー認証
require_once __DIR__ . '/../Common/Auth.php';

/* ==========================================================
 * 2. リクエストチェック
 * ========================================================== */
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    // 1. GET以外であれば例外を返却
    Response::Error('不正なリクエストです', 405);
}

/* ==========================================================
 * 3. 事前処理
 * ========================================================== */
// 1. 機能名定義
$FunctionName = '掛け合い';
// 2. ユーザーID初期化
$UserId = '';

/* ==========================================================
 * 4. 処理部
 * ========================================================== */
try {
    /* ------------------------------
     * 1. 事前処理
     * ------------------------------*/
    // 1. セッションから作品IDを取得
    $WorkId = $AuthWorkId ?? '';
    // 2. セッションからユーザーIDを取得
    $UserId = $AuthUserId;
    // 3. リクエストからIDを取得
    $ExchangeId = $_GET['ExchangeId'] ?? null;
    // 4. 開始LOGの書き込み
    Logger::Info($FunctionName, '一覧取得処理開始', $UserId);

    /* ------------------------------
     * 2. セッションに作品IDが存在しない場合
     * ------------------------------*/
    if (empty($WorkId)) {
        // 1. 例外を返却
        Response::Error('作品IDが指定されていません');
    }

    /* ------------------------------
     * 3. DB接続処理
     * ------------------------------*/
    // 1. DB接続開始
    $Pdo = Database::GetConnection();

    /* ------------------------------
     * 4. 掛け合い一覧の取得
     * ------------------------------*/
    // 1. クエリの定義
    $Sql = "
        SELECT
            e.exchangeId AS \"ExchangeId\",
            e.workId AS \"WorkId\",
            e.exchangeName AS \"ExchangeName\",
            e.description AS \"Description\",
            (SELECT COUNT(*) FROM ExchangeRows r WHERE r.exchangeId = e.exchangeId) AS \"RowCount\",
            e.registDate AS \"RegistDate\",
            e.updateDate AS \"UpdateDate\",
            e.registUser AS \"RegistUser\",
            e.updateUser AS \"UpdateUser\"
        FROM ExchangeInfo e
        WHERE e.workId = :workId
        ORDER BY e.updateDate DESC
    ";
    // 2. ステートメント定義
    $Stmt = $Pdo->prepare($Sql);
    // 3. クエリを実行
    $Stmt->execute([':workId' => $WorkId]);
    // 4. 結果セットを保持
    $ExchangeList = $Stmt->fetchAll();

    /* ------------------------------
     * 5. キャラ一覧を取得
     * ------------------------------*/
    // 1. クエリの定義
    $CharaSql = "
        SELECT
            charaId AS \"CharaId\",
            charaName AS \"CharaName\",
            imgPath AS \"ImgPath\"
        FROM CharacterInfo
        WHERE workId = :workId
        ORDER BY charaName ASC
    ";
    // 2. ステートメント定義
    $CharaStmt = $Pdo->prepare($CharaSql);
    // 3. クエリを実行
    $CharaStmt->execute([':workId' => $WorkId]);
    // 4. 結果セットを保持
    $CharacterList = $CharaStmt->fetchAll();

    /* ------------------------------
     * 6. セリフ一覧を取得
     * ------------------------------*/
    // 1. クエリの定義
    $SerifSql = "
        SELECT
            s.serifId AS \"SerifId\",
            s.charaId AS \"CharaId\",
            s.content AS \"Content\",
            s.monologue AS \"Monologue\"
        FROM SerifInfo s
        WHERE s.workId = :workId
        ORDER BY s.registDate DESC
    ";
    // 2. ステートメント定義
    $SerifStmt = $Pdo->prepare($SerifSql);
    // 3. クエリを実行
    $SerifStmt->execute([':workId' => $WorkId]);
    // 4. 結果セットを保持
    $SerifList = $SerifStmt->fetchAll();

    /* ------------------------------
     * 7. 掛け合い行取得前定義
     * ------------------------------*/
    // 1. 選択された掛け合いを保持するリスト
    $SelectedExchange = null;
    // 2. 掛け合い行を保持する配列
    $ExchangeRowList = [];

    /* ------------------------------
     * 8. 掛け合い行取得
     * ------------------------------*/
    if (!empty($ExchangeId)) {
        /* 1. 選択された掛け合いの取得 */
        foreach ($ExchangeList as $Exchange) {
            /* リクエストのIDと同じ掛け合いIDの場合 */
            if ((string) $Exchange['ExchangeId'] === (string) $ExchangeId) {
                // 1. その掛け合い情報を保持
                $SelectedExchange = $Exchange;
                // 2. 処理終了
                break;
            }
        }

        /* 2. 選択された掛け合い情報が存在する場合 */
        if ($SelectedExchange !== null) {
            // 1. クエリを定義
            $RowSql = "
                SELECT
                    r.exchangeRowId AS \"ExchangeRowId\",
                    r.exchangeId AS \"ExchangeId\",
                    r.indexNum AS \"IndexNum\",
                    r.charaId AS \"CharaId\",
                    c.charaName AS \"CharaName\",
                    c.imgPath AS \"CharaImgPath\",
                    r.speaker AS \"Speaker\",
                    r.serifId AS \"SerifId\",
                    s.content AS \"SerifContent\",
                    r.content AS \"Content\",
                    r.monologue AS \"Monologue\",
                    r.actionLine AS \"ActionLine\",
                    s.monologue AS \"SerifMonologue\",
                    r.feeling AS \"Feeling\",
                    r.registDate AS \"RegistDate\",
                    r.updateDate AS \"UpdateDate\",
                    r.registUser AS \"RegistUser\",
                    r.updateUser AS \"UpdateUser\"
                FROM ExchangeRows r
                LEFT JOIN CharacterInfo c ON r.charaId = c.charaId
                LEFT JOIN SerifInfo     s ON r.serifId = s.serifId
                WHERE r.exchangeId = :exchangeId
                ORDER BY r.indexNum ASC
            ";
            // 2. ステートメント定義
            $RowStmt = $Pdo->prepare($RowSql);
            // 3. クエリを実行
            $RowStmt->execute([':exchangeId' => $ExchangeId]);
            // 4. 結果セットを取得
            $ExchangeRowList = $RowStmt->fetchAll();
        }
    }

    /* ------------------------------
     * 9. 終了時処理
     * ------------------------------*/
    // 1. 終了LOG
    Logger::Info($FunctionName, '一覧取得処理終了', $UserId);

    /* ------------------------------
     * 10. レスポンス返却
     * ------------------------------*/
    Response::Success([
        // 1. 掛け合い一覧
        'ExchangeList' => $ExchangeList,
        // 2. 掛け合い行
        'ExchangeRowList' => $ExchangeRowList,
        // 3. 選択された掛け合い
        'SelectedExchange' => $SelectedExchange,
        // 4. キャラ一覧
        'CharacterList' => $CharacterList,
        // 5. セリフ一覧
        'SerifList' => $SerifList,
        // 6. 件数
        'TotalCount' => count($ExchangeList)
    ]);

/* ==========================================================
 * 5. 例外処理
 * ========================================================== */
} catch (Exception $E) {
    // 1. エラーLOG
    Logger::Error($FunctionName, '一覧取得処理エラー', $UserId, $E->getMessage());
    // 2. 例外を返却
    Response::Error('掛け合い一覧の取得に失敗しました');
}
