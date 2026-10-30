<?php
/* ==========================================================
 * 建物情報更新
 * ----------------------------------------------------------
 * ・建物情報を登録・更新する
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
$FunctionName = '建物設定';
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
    // 1. 建物ID
    $BuildId     = $Input['BuildId'] ?? null;
    // 2. 作品ID
    $WorkId      = $AuthWorkId ?? '';
    // 3. 建物名
    $BuildName   = $Input['BuildName'] ?? '';
    // 4. 建築日
    $BuildDate   = $Input['BuildDate'] ?? null;
    // 5. 高さ
    $Height      = $Input['Height'] ?? null;
    // 6. 面積
    $Area        = $Input['Area'] ?? null;
    // 7. 説明
    $Description = $Input['Description'] ?? null;
    // 8. ユーザーID
    $UserId = $AuthUserId;
    // 9. 画像一覧（[{Path:既存画像のパス, Data:新規画像のデータURL, Caption:説明文}]。先頭が代表画像）
    //    指定が無い場合（配列でない場合）は画像を変更しない
    $Images      = $Input['Images'] ?? null;
    // 10. 更新年月日（排他制御用）
    $UpdateDate  = $Input['UpdateDate'] ?? null;

   /* ---------------------------------------------
    *  3. 操作設定
    * --------------------------------------------- */
    $Operation = empty($BuildId) ? '登録' : '更新';
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
    if (empty($BuildName)) {
        Response::Error('建物名を入力してください');
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
    $BuildId     = !empty($BuildId) ? $BuildId : null;
    $BuildDate   = !empty($BuildDate) ? $BuildDate : null;
    $Height      = !empty($Height) ? $Height : null;
    $Area        = !empty($Area) ? $Area : null;
    $Description = !empty($Description) ? $Description : null;

   /* ---------------------------------------------
    *  8. 画像の処理（保存・削除の判定）
    *    - DBトランザクション成功前にファイルを物理書込/削除しないため、
    *      ここではメモリ上に「新規書込データ」「削除対象パス」を積み、
    *      Commit 後の 13-2. で実際のファイル操作を行う。
    *    - 検証・保留リストの作成は ImageList（他画面と共通）で行う。
    * --------------------------------------------- */
    // 1. 代表画像（先頭の画像）のパス
    $ImgPath = null;
    // 2. 画像一覧（JSON）
    $ImgList = null;
    // 3. 画像更新フラグ（画像一覧が送られてきた場合のみ更新する）
    $IsImageUpdated = is_array($Images);
    // 4. Commit後のファイル操作の保留リスト（ImageList::Prepare の結果）
    $ImagePrepared = [];

    if ($IsImageUpdated) {
        /* 更新時は保持している画像一覧を取得 */
        $OldImages = [];
        if (!empty($BuildId)) {
            $OldImgStmt = $Pdo->prepare("SELECT imgPath, imgList FROM BuildInfo WHERE buildId = :buildId AND workId = :workId");
            $OldImgStmt->bindValue(':buildId', $BuildId, PDO::PARAM_STR);
            $OldImgStmt->bindValue(':workId', $WorkId, PDO::PARAM_STR);
            $OldImgStmt->execute();
            $OldImgRow = $OldImgStmt->fetch();
            if ($OldImgRow) {
                $OldImages = ImageList::Parse($OldImgRow['imglist'] ?? null, $OldImgRow['imgpath'] ?? null);
            }
        }

        /* 検証・DB登録値・保留リストの作成 */
        $ImagePrepared = ImageList::Prepare($Images, $OldImages, 'Buildings', 'build');
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
    if (empty($BuildId)) {
        $Sql = "
            INSERT INTO BuildInfo (
                workId, buildName, buildDate, height, area, description, imgPath, imgList,
                registDate, updateDate, registUser, updateUser
            ) VALUES (
                :workId, :buildName, :buildDate, :height, :area, :description, :imgPath, :imgList,
                CURRENT_TIMESTAMP(3), CURRENT_TIMESTAMP(3), :registUser, :updateUser
            )
            RETURNING buildId, updateDate
        ";

        $Stmt = $Pdo->prepare($Sql);
        $Stmt->bindValue(':workId',      $WorkId,      PDO::PARAM_STR);
        $Stmt->bindValue(':buildName',   $BuildName,   PDO::PARAM_STR);
        $Stmt->bindValue(':buildDate',   $BuildDate,   $BuildDate === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $Stmt->bindValue(':height',      $Height,      $Height === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $Stmt->bindValue(':area',        $Area,        $Area === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $Stmt->bindValue(':description', $Description,$Description === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        // 画像パス（代表画像）・画像一覧（JSON）
        $Stmt->bindValue(':imgPath',     $ImgPath,     $ImgPath === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $Stmt->bindValue(':imgList',     $ImgList,     $ImgList === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $Stmt->bindValue(':registUser',  $UserId,      PDO::PARAM_STR);
        $Stmt->bindValue(':updateUser',  $UserId,      PDO::PARAM_STR);
        $Stmt->execute();

        $InsertResult = $Stmt->fetch();
        $NewBuildId = $InsertResult['buildid'];
        $NewUpdateDate = $InsertResult['updatedate'];
        // 更新履歴の詳細メッセージを生成（登録）
        $Detail = HistoryDetail::Register($BuildName);

   /* ---------------------------------------------
    *  10. 更新処理
    * --------------------------------------------- */
    } else {
        /* 差分履歴用：更新前の表示値を取得（キーはPDO既定の小文字） */
        $OldRowStmt = $Pdo->prepare("
            SELECT buildName, buildDate, height, area, description
            FROM BuildInfo
            WHERE buildId = :buildId AND workId = :workId
        ");
        $OldRowStmt->bindValue(':buildId', $BuildId, PDO::PARAM_STR);
        $OldRowStmt->bindValue(':workId', $WorkId, PDO::PARAM_STR);
        $OldRowStmt->execute();
        $OldRow = $OldRowStmt->fetch() ?: [];

        /* 画像パス更新用のSQL句を生成（更新フラグがtrueの場合のみ）
         * 代表画像のパスと画像一覧は常に揃えて更新する */
        $UpdateImgPath = $IsImageUpdated ? ", imgPath = :imgPath, imgList = :imgList" : "";

        $Sql = "
            UPDATE BuildInfo SET
                buildName = :buildName,
                buildDate = :buildDate,
                height = :height,
                area = :area,
                description = :description,
                updateDate = CURRENT_TIMESTAMP(3),
                updateUser = :updateUser
                {$UpdateImgPath}
            WHERE buildId = :buildId AND workId = :workId AND updateDate = :updateDate
            RETURNING updateDate
        ";

        $Stmt = $Pdo->prepare($Sql);
        $Stmt->bindValue(':buildId',    $BuildId,    PDO::PARAM_STR);
        $Stmt->bindValue(':workId',     $WorkId,     PDO::PARAM_STR);
        $Stmt->bindValue(':updateDate', $UpdateDate, PDO::PARAM_STR);
        $Stmt->bindValue(':buildName',  $BuildName,  PDO::PARAM_STR);
        $Stmt->bindValue(':buildDate',  $BuildDate,  $BuildDate === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $Stmt->bindValue(':height',     $Height,     $Height === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $Stmt->bindValue(':area',       $Area,       $Area === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
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

        $NewBuildId = $BuildId;
        $NewUpdateDate = $UpdateResult['updatedate'];

        /* 更新履歴の詳細メッセージを生成（更新・列単位差分。画像は対象外） */
        // 差分対象の列 → 和名
        $Labels = [
            'buildName'   => '建物名',
            'buildDate'   => '建築日',
            'height'      => '高さ',
            'area'        => '面積',
            'description' => '説明',
        ];
        // 更新前の表示値
        $OldDisplay = [
            'buildName'   => $OldRow['buildname']   ?? null,
            'buildDate'   => $OldRow['builddate']   ?? null,
            'height'      => $OldRow['height']      ?? null,
            'area'        => $OldRow['area']        ?? null,
            'description' => $OldRow['description'] ?? null,
        ];
        // 更新後の表示値
        $NewDisplay = [
            'buildName'   => $BuildName,
            'buildDate'   => $BuildDate,
            'height'      => $Height,
            'area'        => $Area,
            'description' => $Description,
        ];
        $Detail = HistoryDetail::Update($BuildName, $OldDisplay, $NewDisplay, $Labels);
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
        'BuildId' => $NewBuildId,
        'UpdateDate' => $NewUpdateDate,
        'Message' => '建物を' . $Operation . 'しました'
    ]);

} catch (Exception $E) {
    Database::Rollback();
    Logger::Error($FunctionName, '保存処理エラー', $UserId, $E->getMessage());
    Response::Error('建物の保存に失敗しました');
}
