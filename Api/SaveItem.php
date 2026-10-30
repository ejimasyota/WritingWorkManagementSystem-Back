<?php
/* ==========================================================
 * アイテム情報更新
 * ----------------------------------------------------------
 * ・アイテム情報を登録・更新する
 * ----------------------------------------------------------
 * 更新履歴：
 * 2026-02-17 作成
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
// 画像一覧（画像の複数登録）の共通処理（ファイル操作の安全化 FileSafety も読み込む）
require_once __DIR__ . '/../Common/ImageList.php';
require_once __DIR__ . '/../Common/Auth.php';
// 更新履歴 詳細メッセージ生成
require_once __DIR__ . '/../Common/HistoryDetail.php';

/* ==========================================================
 * 2. リクエストチェック
 * ========================================================== */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::Error('不正なリクエストです', 405);
}

/* ==========================================================
 * 3. 共通定義
 * ========================================================== */
// 1. 機能名
$FunctionName = 'アイテム設定';
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
    // 1. アイテムID
    $ItemId      = $Input['ItemId'] ?? null;
    // 2. 作品ID
    $WorkId      = $AuthWorkId ?? '';
    // 3. アイテム名
    $ItemName    = $Input['ItemName'] ?? '';
    // 4. 説明
    $Description = $Input['Description'] ?? null;
    // 5. ユーザーID
    $UserId = $AuthUserId;
    // 6. 画像一覧（[{Path:既存画像のパス, Data:新規画像のデータURL, Caption:説明文}]。先頭が代表画像）
    //    指定が無い場合（配列でない場合）は画像を変更しない
    $Images      = $Input['Images'] ?? null;
    // 7. 更新年月日（排他制御用）
    $UpdateDate  = $Input['UpdateDate'] ?? null;

   /* ---------------------------------------------
    *  3. 操作設定
    * --------------------------------------------- */
    $Operation = empty($ItemId) ? '登録' : '更新';
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
    if (empty($ItemName)) {
        Response::Error('道具名を入力してください');
    }
    if (empty($UserId)) {
        Response::Error('ユーザーIDが指定されていません');
    }

   /* ---------------------------------------------
    *  6. DB接続
    * --------------------------------------------- */
    $Pdo = Database::GetConnection();
    Database::BeginTransaction();

   /* ---------------------------------------------
    *  7. 空文字をNULLに変換
    * --------------------------------------------- */
    $ItemId      = !empty($ItemId) ? $ItemId : null;
    $Description = !empty($Description) ? $Description : null;

   /* ---------------------------------------------
    *  8. 画像の処理（保存・削除の判定）
    *    - DB Commit 前にファイル書込/削除は行わず、ここではメモリ上に
    *      「新規書込データ」「削除対象パス」を積み、Commit 後の 13-2. で実際のファイル操作を行う。
    *    - 検証・保留リストの作成は ImageList（他画面と共通）で行う。
    * --------------------------------------------- */
    /* 1. 定義 */
    // 1. 代表画像（先頭の画像）のパス
    $ImgPath = null;
    // 2. 画像一覧（JSON）
    $ImgList = null;
    // 3. 画像更新フラグ（画像一覧が送られてきた場合のみ更新する）
    $IsImageUpdated = is_array($Images);
    // 4. Commit後のファイル操作の保留リスト（ImageList::Prepare の結果）
    $ImagePrepared = [];

    if ($IsImageUpdated) {
        /* 2. 更新時は保持している画像一覧を取得 */
        $OldImages = [];
        if (!empty($ItemId)) {
            $OldImgStmt = $Pdo->prepare("SELECT imgPath, imgList FROM ItemInfo WHERE itemId = :itemId AND workId = :workId");
            $OldImgStmt->bindValue(':itemId', $ItemId, PDO::PARAM_STR);
            $OldImgStmt->bindValue(':workId', $WorkId, PDO::PARAM_STR);
            $OldImgStmt->execute();
            $OldImgRow = $OldImgStmt->fetch();
            if ($OldImgRow) {
                $OldImages = ImageList::Parse($OldImgRow['imglist'] ?? null, $OldImgRow['imgpath'] ?? null);
            }
        }

        /* 3. 検証・DB登録値・保留リストの作成 */
        $ImagePrepared = ImageList::Prepare($Images, $OldImages, 'Items', 'item');
        if (isset($ImagePrepared['Error'])) {
            Database::Rollback();
            Response::Error($ImagePrepared['Error']);
        }
        $ImgPath = $ImagePrepared['ImgPath'];
        $ImgList = $ImagePrepared['ImgList'];
    }

   /* ---------------------------------------------
    *  9. 新規登録処理
    * --------------------------------------------- */
    if (empty($ItemId)) {
        $Sql = "
            INSERT INTO ItemInfo (
                workId, itemName, description, imgPath, imgList,
                registDate, updateDate, registUser, updateUser
            ) VALUES (
                :workId, :itemName, :description, :imgPath, :imgList,
                CURRENT_TIMESTAMP(3), CURRENT_TIMESTAMP(3), :registUser, :updateUser
            )
            RETURNING itemId, updateDate
        ";

        $Stmt = $Pdo->prepare($Sql);
        $Stmt->bindValue(':workId',      $WorkId,      PDO::PARAM_STR);
        $Stmt->bindValue(':itemName',    $ItemName,    PDO::PARAM_STR);
        $Stmt->bindValue(':description', $Description, $Description === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $Stmt->bindValue(':imgPath',     $ImgPath,     $ImgPath === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $Stmt->bindValue(':imgList',     $ImgList,     $ImgList === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $Stmt->bindValue(':registUser',  $UserId,      PDO::PARAM_STR);
        $Stmt->bindValue(':updateUser',  $UserId,      PDO::PARAM_STR);
        $Stmt->execute();

        $InsertResult = $Stmt->fetch();
        $NewItemId = $InsertResult['itemid'];
        $NewUpdateDate = $InsertResult['updatedate'];

        // 更新履歴の詳細メッセージを生成（登録）
        $Detail = HistoryDetail::Register($ItemName);

   /* ---------------------------------------------
    *  10. 更新処理
    * --------------------------------------------- */
    } else {
        /* 更新前の表示値を取得（列単位差分用。画像は差分対象外） */
        $OldRowStmt = $Pdo->prepare("
            SELECT itemName, description
            FROM ItemInfo
            WHERE itemId = :itemId AND workId = :workId
        ");
        $OldRowStmt->bindValue(':itemId', $ItemId, PDO::PARAM_STR);
        $OldRowStmt->bindValue(':workId', $WorkId, PDO::PARAM_STR);
        $OldRowStmt->execute();
        $OldRow = $OldRowStmt->fetch() ?: [];

        /* 画像更新用のSQL句を生成（画像一覧が送られてきた場合のみ）
           代表画像のパスと画像一覧は常に揃えて更新する */
        $UpdateImgPath = $IsImageUpdated ? ", imgPath = :imgPath, imgList = :imgList" : "";

        $Sql = "
            UPDATE ItemInfo SET
                itemName = :itemName,
                description = :description,
                updateDate = CURRENT_TIMESTAMP(3),
                updateUser = :updateUser
                {$UpdateImgPath}
            WHERE itemId = :itemId AND workId = :workId AND updateDate = :updateDate
            RETURNING updateDate
        ";

        $Stmt = $Pdo->prepare($Sql);
        $Stmt->bindValue(':itemId',     $ItemId,     PDO::PARAM_STR);
        $Stmt->bindValue(':workId',     $WorkId,     PDO::PARAM_STR);
        $Stmt->bindValue(':updateDate', $UpdateDate, PDO::PARAM_STR);
        $Stmt->bindValue(':itemName',   $ItemName,   PDO::PARAM_STR);
        $Stmt->bindValue(':description',$Description,$Description === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $Stmt->bindValue(':updateUser', $UserId,     PDO::PARAM_STR);

        /* 画像が更新対象の場合のみ代表画像のパスと画像一覧をバインド（NULLも許容） */
        if ($IsImageUpdated) {
            $Stmt->bindValue(':imgPath', $ImgPath, $ImgPath === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $Stmt->bindValue(':imgList', $ImgList, $ImgList === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        }

        $Stmt->execute();

        $UpdateResult = $Stmt->fetch();
        if (!$UpdateResult) {
            Database::Rollback();
            Response::ConflictError();
        }

        $NewItemId = $ItemId;
        $NewUpdateDate = $UpdateResult['updatedate'];

        /* 更新履歴の詳細メッセージを生成（更新・列単位差分。FKなし、画像は対象外） */
        $Labels = [
            'itemName'    => 'アイテム名',
            'description' => '説明',
        ];
        $OldDisplay = [
            'itemName'    => $OldRow['itemname']    ?? null,
            'description' => $OldRow['description']  ?? null,
        ];
        $NewDisplay = [
            'itemName'    => $ItemName,
            'description' => $Description,
        ];
        $Detail = HistoryDetail::Update($ItemName, $OldDisplay, $NewDisplay, $Labels);
    }

   /* ---------------------------------------------
    *  11. 作品情報更新
    * --------------------------------------------- */
    $UpdateWorkStmt = $Pdo->prepare("
        UPDATE WorkInfo SET
            updateDate = CURRENT_TIMESTAMP(3),
            updateUser = :updateUser
        WHERE workId = :workId
    ");
    $UpdateWorkStmt->bindValue(':updateUser', $UserId, PDO::PARAM_STR);
    $UpdateWorkStmt->bindValue(':workId',     $WorkId, PDO::PARAM_STR);
    $UpdateWorkStmt->execute();

   /* ---------------------------------------------
    *  12. 更新履歴登録
    * --------------------------------------------- */
    $HistoryStmt = $Pdo->prepare("
        INSERT INTO UpdateHistory (
            workId, functionName, operation, detail,
            registDate, updateDate, registUser, updateUser
        ) VALUES (
            :workId, :functionName, :operation, :detail,
            CURRENT_TIMESTAMP(3), CURRENT_TIMESTAMP(3), :registUser, :updateUser
        )
    ");
    /* 変更が無い更新は履歴に残す情報が無いため登録しない */
    if (HistoryDetail::HasChanges($Detail)) {
        $HistoryStmt->bindValue(':workId',       $WorkId,       PDO::PARAM_STR);
        $HistoryStmt->bindValue(':functionName', $FunctionName, PDO::PARAM_STR);
        $HistoryStmt->bindValue(':operation',    $Operation,    PDO::PARAM_STR);
        $HistoryStmt->bindValue(':detail',       $Detail,       $Detail === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $HistoryStmt->bindValue(':registUser',   $UserId,       PDO::PARAM_STR);
        $HistoryStmt->bindValue(':updateUser',   $UserId,       PDO::PARAM_STR);
        $HistoryStmt->execute();
    }

   /* ---------------------------------------------
    *  13. コミット
    * --------------------------------------------- */
    Database::Commit();

   /* ---------------------------------------------
    *  13-2. Commit 後のファイル操作
    *    - コミット成功後に画像を物理書込・旧画像を物理削除する。
    *    - ファイル操作失敗は DB 状態を巻き戻せないため、ログ記録に留める。
    * --------------------------------------------- */
    ImageList::ApplyFiles($ImagePrepared, $FunctionName, $UserId);

   /* ---------------------------------------------
    *  14. ログ出力
    * --------------------------------------------- */
    Logger::Info($FunctionName, $Operation . '処理終了', $UserId);

   /* ---------------------------------------------
    *  15. 成功レスポンス
    * --------------------------------------------- */
    Response::Success([
        'ItemId' => $NewItemId,
        'UpdateDate' => $NewUpdateDate,
        'Message'  => 'アイテムを' . $Operation . 'しました'
    ]);

} catch (Exception $E) {
   /* ==========================================================
    * 5. 例外処理
    * ========================================================== */
    Database::Rollback();
    Logger::Error($FunctionName, '保存処理エラー', $UserId, $E->getMessage());
    Response::Error('アイテムの保存に失敗しました');
}
