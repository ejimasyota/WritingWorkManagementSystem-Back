<?php
/**
 * SaveWorkMemo.php
 * 作品作成用メモ登録・更新API
 * ※全ユーザが登録可能。更新は自身の投稿のみ可能。
 */

require_once __DIR__ . '/../Common/Database.php';
require_once __DIR__ . '/../Common/Logger.php';
require_once __DIR__ . '/../Common/Response.php';
// 画像一覧（画像の複数登録）の共通処理（ファイル操作の安全化 FileSafety も読み込む）
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
    $Title = $Input['Title'] ?? '';
    $Content = $Input['Content'] ?? null;
    // 画像一覧（[{Path:既存画像のパス, Data:新規画像のデータURL, Caption:説明文}]。先頭が代表画像）
    // 指定が無い場合（配列でない場合）は画像を変更しない
    $Images = $Input['Images'] ?? null;
    $UserId = $AuthUserId;
    $UpdateDate = $Input['UpdateDate'] ?? null;

    $Operation = empty($MemoId) ? '登録' : '更新';
    // 更新履歴の詳細メッセージ（登録/更新分岐でそれぞれ生成）
    $Detail = null;
    Logger::Info($FunctionName, $Operation . '処理開始', $UserId);

    /** 入力チェック */
    if (empty($WorkId)) {
        Response::Error('作品IDが指定されていません');
    }

    if (empty($Title)) {
        Response::Error('タイトルを入力してください');
    }

    /** DB接続 */
    $Pdo = Database::GetConnection();
    Database::BeginTransaction();

    /** 空文字をNULLに変換 */
    $MemoId = !empty($MemoId) ? $MemoId : null;
    $Content = !empty($Content) ? $Content : null;

    /** 画像処理
     *  DB Commit 前にファイル書込/削除は行わず、Commit 後に実行するための
     *  保留リストを構築する。
     *  検証・保留リストの作成は ImageList（他画面と共通）で行う。 */
    // 代表画像（先頭の画像）のパス
    $ImgPath = null;
    // 画像一覧（JSON）
    $ImgList = null;
    // 画像更新フラグ（画像一覧が送られてきた場合のみ更新する）
    $IsImageUpdated = is_array($Images);
    // Commit後のファイル操作の保留リスト（ImageList::Prepare の結果）
    $ImagePrepared = [];

    if ($IsImageUpdated) {
        /* 更新時は保持している画像一覧を取得 */
        $OldImages = [];
        if (!empty($MemoId)) {
            $OldImgStmt = $Pdo->prepare("SELECT imgPath, imgList FROM WorkMemoInfo WHERE memoId = :memoId AND workId = :workId");
            $OldImgStmt->bindValue(':memoId', $MemoId, PDO::PARAM_INT);
            $OldImgStmt->bindValue(':workId', $WorkId, PDO::PARAM_STR);
            $OldImgStmt->execute();
            $OldImgRow = $OldImgStmt->fetch();
            if ($OldImgRow) {
                $OldImages = ImageList::Parse($OldImgRow['imglist'] ?? null, $OldImgRow['imgpath'] ?? null);
            }
        }

        /* 検証・DB登録値・保留リストの作成 */
        $ImagePrepared = ImageList::Prepare($Images, $OldImages, 'WorkMemos', 'memo');
        if (isset($ImagePrepared['Error'])) {
            Database::Rollback();
            Response::Error($ImagePrepared['Error']);
        }
        $ImgPath = $ImagePrepared['ImgPath'];
        $ImgList = $ImagePrepared['ImgList'];
    }

    if (empty($MemoId)) {
        /** 新規登録（全ユーザが可能） */
        $Sql = "
            INSERT INTO WorkMemoInfo (
                workId, title, content, imgPath, imgList,
                registDate, updateDate, registUser, updateUser
            ) VALUES (
                :workId, :title, :content, :imgPath, :imgList,
                CURRENT_TIMESTAMP(3), CURRENT_TIMESTAMP(3), :registUser, :updateUser
            )
            RETURNING memoId, updateDate
        ";

        $Stmt = $Pdo->prepare($Sql);
        $Stmt->bindValue(':workId', $WorkId, PDO::PARAM_STR);
        $Stmt->bindValue(':title', $Title, PDO::PARAM_STR);
        $Stmt->bindValue(':content', $Content, $Content === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $Stmt->bindValue(':imgPath', $ImgPath, $ImgPath === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $Stmt->bindValue(':imgList', $ImgList, $ImgList === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $Stmt->bindValue(':registUser', $UserId, PDO::PARAM_STR);
        $Stmt->bindValue(':updateUser', $UserId, PDO::PARAM_STR);
        $Stmt->execute();

        $Result = $Stmt->fetch();
        $NewMemoId = $Result['memoid'];
        $NewUpdateDate = $Result['updatedate'];

        // 更新履歴の詳細メッセージを生成（登録。タイトルを名称として使用）
        $Detail = HistoryDetail::Register($Title);

    } else {
        /** 更新の場合は自身の投稿かどうかを確認（title/contentは差分履歴用に取得。画像は対象外） */
        $CheckSql = "SELECT registUser, title, content FROM WorkMemoInfo WHERE memoId = :memoId AND workId = :workId";
        $CheckStmt = $Pdo->prepare($CheckSql);
        $CheckStmt->execute([':memoId' => $MemoId, ':workId' => $WorkId]);
        $MemoInfo = $CheckStmt->fetch();

        if (!$MemoInfo) {
            Database::Rollback();
            Response::Error('更新対象のメモが見つかりません');
        }

        /** 自身の投稿でない場合はエラー */
        if ($MemoInfo['registuser'] !== $UserId) {
            Database::Rollback();
            Response::Error('他のユーザが投稿したメモは更新できません');
        }

        /** 更新 */
        // 代表画像のパスと画像一覧は常に揃えて更新する（画像一覧が送られてきた場合のみ）
        $UpdateImgPath = $IsImageUpdated ? ", imgPath = :imgPath, imgList = :imgList" : "";
        $Sql = "
            UPDATE WorkMemoInfo SET
                title = :title,
                content = :content
                {$UpdateImgPath},
                updateDate = CURRENT_TIMESTAMP(3),
                updateUser = :updateUser
            WHERE memoId = :memoId AND workId = :workId AND updateDate = :updateDate
            RETURNING updateDate
        ";

        $Stmt = $Pdo->prepare($Sql);
        $Stmt->bindValue(':memoId', $MemoId, PDO::PARAM_INT);
        $Stmt->bindValue(':workId', $WorkId, PDO::PARAM_STR);
        $Stmt->bindValue(':title', $Title, PDO::PARAM_STR);
        $Stmt->bindValue(':content', $Content, $Content === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $Stmt->bindValue(':updateUser', $UserId, PDO::PARAM_STR);
        $Stmt->bindValue(':updateDate', $UpdateDate, PDO::PARAM_STR);
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

        $NewMemoId = $MemoId;
        $NewUpdateDate = $UpdateResult['updatedate'];

        // 更新履歴の詳細メッセージを生成（更新・列単位差分。FK列なし。画像は対象外）
        $Labels = [
            'title'   => 'タイトル',
            'content' => '内容',
        ];
        $OldDisplay = [
            'title'   => $MemoInfo['title']   ?? null,
            'content' => $MemoInfo['content'] ?? null,
        ];
        $NewDisplay = [
            'title'   => $Title,
            'content' => $Content,
        ];
        $Detail = HistoryDetail::Update($Title, $OldDisplay, $NewDisplay, $Labels);
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

    /** 更新履歴を登録（変更が無い更新は履歴に残す情報が無いため登録しない） */
    $HistorySql = "
        INSERT INTO UpdateHistory (
            workId, functionName, operation, detail, registDate, updateDate, registUser, updateUser
        ) VALUES (
            :workId, :functionName, :operation, :detail, CURRENT_TIMESTAMP(3), CURRENT_TIMESTAMP(3), :registUser, :updateUser
        )
    ";
    $HistoryStmt = $Pdo->prepare($HistorySql);
    if (HistoryDetail::HasChanges($Detail)) {
        $HistoryStmt->execute([
            ':workId' => $WorkId,
            ':functionName' => $FunctionName,
            ':operation' => $Operation,
            ':detail' => $Detail,
            ':registUser' => $UserId,
            ':updateUser' => $UserId
        ]);
    }

    Database::Commit();

   /* ---------------------------------------------
    *  Commit 後のファイル操作
    *    - コミット成功後に新規画像を物理書込・一覧から外れた画像を物理削除する。
    *    - ファイル操作失敗は DB 状態を巻き戻せないため、ログ記録に留める。
    * --------------------------------------------- */
    ImageList::ApplyFiles($ImagePrepared, $FunctionName, $UserId);

    Logger::Info($FunctionName, $Operation . '処理終了', $UserId);

    Response::Success([
        'MemoId' => $NewMemoId,
        'UpdateDate' => $NewUpdateDate,
        'Message' => 'メモを' . $Operation . 'しました'
    ]);

} catch (Exception $E) {
    Database::Rollback();
    Logger::Error($FunctionName, '保存処理エラー', $UserId, $E->getMessage());
    Response::Error('メモの保存に失敗しました');
}
