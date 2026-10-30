<?php
/**
 * SaveTag.php
 * タグ登録・更新API
 */

require_once __DIR__ . '/../Common/Database.php';
require_once __DIR__ . '/../Common/Logger.php';
require_once __DIR__ . '/../Common/Response.php';
require_once __DIR__ . '/../Common/Auth.php';
// 更新履歴 詳細メッセージ生成
require_once __DIR__ . '/../Common/HistoryDetail.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::Error('不正なリクエストです', 405);
}

$FunctionName = 'タグマスタ';
$UserId = '';

try {
    /** リクエストデータを取得 */
    $Input = Response::GetJsonInput();
    $TagId = $Input['TagId'] ?? null;
    $WorkId = $AuthWorkId ?? '';
    $TagName = $Input['TagName'] ?? '';
    $Description = $Input['Description'] ?? null;
    $TagColor = $Input['TagColor'] ?? null;
    $UserId = $AuthUserId;
    $UpdateDate = $Input['UpdateDate'] ?? null;

    $Operation = empty($TagId) ? '登録' : '更新';
    // 更新履歴の詳細メッセージ（登録/更新分岐でそれぞれ生成）
    $Detail = null;
    Logger::Info($FunctionName, $Operation . '処理開始', $UserId);

    /** 入力チェック */
    if (empty($WorkId)) {
        Response::Error('作品IDが指定されていません');
    }

    if (empty($TagName)) {
        Response::Error('タグ名を入力してください');
    }

    /** タグカラーは #RRGGBB 形式のみ許可（style属性へ埋め込まれるためCSSインジェクション対策） */
    if (!empty($TagColor) && (!is_string($TagColor) || !preg_match('/\A#[0-9A-Fa-f]{6}\z/', $TagColor))) {
        Response::Error('タグの色が不正です');
    }

    /** DB接続 */
    $Pdo = Database::GetConnection();
    Database::BeginTransaction();

    /** 空文字をNULLに変換 */
    $TagId = !empty($TagId) ? $TagId : null;
    $Description = !empty($Description) ? $Description : null;
    $TagColor = !empty($TagColor) ? $TagColor : null;

    if (empty($TagId)) {
        /** 新規登録 */
        $Sql = "
            INSERT INTO TagInfo (
                workId, tagName, description, tagColor,
                registDate, updateDate, registUser, updateUser
            ) VALUES (
                :workId, :tagName, :description, :tagColor,
                CURRENT_TIMESTAMP(3), CURRENT_TIMESTAMP(3), :registUser, :updateUser
            )
            RETURNING tagId, updateDate
        ";

        $Stmt = $Pdo->prepare($Sql);
        $Stmt->execute([
            ':workId' => $WorkId,
            ':tagName' => $TagName,
            ':description' => $Description,
            ':tagColor' => $TagColor,
            ':registUser' => $UserId,
            ':updateUser' => $UserId
        ]);

        $Result = $Stmt->fetch();
        $NewTagId = $Result['tagid'];
        $NewUpdateDate = $Result['updatedate'];
        // 更新履歴の詳細メッセージを生成（登録）
        $Detail = HistoryDetail::Register($TagName);

    } else {
        /** 更新 */
        /** 差分履歴用：更新前の表示値を取得（キーはPDO既定の小文字） */
        $OldRowStmt = $Pdo->prepare("
            SELECT tagName, description, tagColor
            FROM TagInfo
            WHERE tagId = :tagId AND workId = :workId
        ");
        $OldRowStmt->bindValue(':tagId', $TagId, PDO::PARAM_STR);
        $OldRowStmt->bindValue(':workId', $WorkId, PDO::PARAM_STR);
        $OldRowStmt->execute();
        $OldRow = $OldRowStmt->fetch() ?: [];

        $Sql = "
            UPDATE TagInfo SET
                tagName = :tagName,
                description = :description,
                tagColor = :tagColor,
                updateDate = CURRENT_TIMESTAMP(3),
                updateUser = :updateUser
            WHERE tagId = :tagId AND workId = :workId AND updateDate = :updateDate
            RETURNING updateDate
        ";

        $Stmt = $Pdo->prepare($Sql);
        $Stmt->execute([
            ':tagId' => $TagId,
            ':workId' => $WorkId,
            ':tagName' => $TagName,
            ':description' => $Description,
            ':tagColor' => $TagColor,
            ':updateUser' => $UserId,
            ':updateDate' => $UpdateDate
        ]);

        $UpdateResult = $Stmt->fetch();
        if (!$UpdateResult) {
            Database::Rollback();
            Response::ConflictError();
        }

        $NewTagId = $TagId;
        $NewUpdateDate = $UpdateResult['updatedate'];

        /** 更新履歴の詳細メッセージを生成（更新・列単位差分） */
        // 差分対象の列 → 和名
        $Labels = [
            'tagName'     => 'タグ名',
            'description' => '説明',
            'tagColor'    => 'タグカラー',
        ];
        // 更新前の表示値
        $OldDisplay = [
            'tagName'     => $OldRow['tagname']     ?? null,
            'description' => $OldRow['description'] ?? null,
            'tagColor'    => $OldRow['tagcolor']    ?? null,
        ];
        // 更新後の表示値
        $NewDisplay = [
            'tagName'     => $TagName,
            'description' => $Description,
            'tagColor'    => $TagColor,
        ];
        $Detail = HistoryDetail::Update($TagName, $OldDisplay, $NewDisplay, $Labels);
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

    Logger::Info($FunctionName, $Operation . '処理終了', $UserId);

    Response::Success([
        'TagId' => $NewTagId,
        'UpdateDate' => $NewUpdateDate,
        'Message' => 'タグを' . $Operation . 'しました'
    ]);

} catch (Exception $E) {
    Database::Rollback();
    Logger::Error($FunctionName, '保存処理エラー', $UserId, $E->getMessage());
    Response::Error('タグの保存に失敗しました');
}
