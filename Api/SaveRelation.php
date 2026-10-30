<?php
/**
 * SaveRelation.php
 * 相関関係・線情報 登録・更新API
 */

require_once __DIR__ . '/../Common/Database.php';
require_once __DIR__ . '/../Common/Logger.php';
require_once __DIR__ . '/../Common/Response.php';
require_once __DIR__ . '/../Common/Auth.php';

// POSTメソッドのみ許可
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::Error('不正なリクエストです', 405);
}

$FunctionName = '相関図作成';
$UserId = '';

try {
    /** リクエストデータを取得 */
    $Input = Response::GetJsonInput();
    $RelationId = $Input['RelationId'] ?? null;
    $WorkId = $AuthWorkId ?? '';
    $SourceObjId = $Input['SourceObjId'] ?? null;
    $TargetObjId = $Input['TargetObjId'] ?? null;
    $LineType = $Input['LineType'] ?? 1;
    $RelationType = $Input['RelationType'] ?? null;
    $Color = $Input['Color'] ?? null;
    $UserId = $AuthUserId;
    $UpdateDate = $Input['UpdateDate'] ?? null;

    $Operation = empty($RelationId) ? '登録' : '更新';
    Logger::Info($FunctionName, '関係線' . $Operation . '処理開始', $UserId);

    /** 入力チェック */
    if (empty($WorkId)) {
        Response::Error('作品IDが指定されていません');
    }

    if (empty($SourceObjId)) {
        Response::Error('開始オブジェクトが指定されていません');
    }

    if (empty($TargetObjId)) {
        Response::Error('終了オブジェクトが指定されていません');
    }

    if ($SourceObjId == $TargetObjId) {
        Response::Error('同じオブジェクト間には関係線を作成できません');
    }

    /** DB接続 */
    $Pdo = Database::GetConnection();
    Database::BeginTransaction();

    /** 空文字をNULLに変換 */
    $RelationType = !empty($RelationType) ? $RelationType : null;
    $Color = !empty($Color) ? $Color : null;

    if (empty($RelationId)) {
        /** 新規登録 */
        $Sql = "
            INSERT INTO RelationInfo (
                workId, sourceObjId, targetObjId, lineType, relationType, color,
                registDate, updateDate, registUser, updateUser
            ) VALUES (
                :workId, :sourceObjId, :targetObjId, :lineType, :relationType, :color,
                CURRENT_TIMESTAMP(3), CURRENT_TIMESTAMP(3), :registUser, :updateUser
            )
            RETURNING relationId, updateDate
        ";

        $Stmt = $Pdo->prepare($Sql);
        $Stmt->execute([
            ':workId' => $WorkId,
            ':sourceObjId' => $SourceObjId,
            ':targetObjId' => $TargetObjId,
            ':lineType' => $LineType,
            ':relationType' => $RelationType,
            ':color' => $Color,
            ':registUser' => $UserId,
            ':updateUser' => $UserId
        ]);

        $Result = $Stmt->fetch();
        $NewRelationId = $Result['relationid'];
        $NewUpdateDate = $Result['updatedate'];

    } else {
        /** 更新 */
        $Sql = "
            UPDATE RelationInfo SET
                sourceObjId = :sourceObjId,
                targetObjId = :targetObjId,
                lineType = :lineType,
                relationType = :relationType,
                color = :color,
                updateDate = CURRENT_TIMESTAMP(3),
                updateUser = :updateUser
            WHERE relationId = :relationId AND workId = :workId AND updateDate = :updateDate
            RETURNING updateDate
        ";

        $Stmt = $Pdo->prepare($Sql);
        $Stmt->execute([
            ':relationId' => $RelationId,
            ':workId' => $WorkId,
            ':sourceObjId' => $SourceObjId,
            ':targetObjId' => $TargetObjId,
            ':lineType' => $LineType,
            ':relationType' => $RelationType,
            ':color' => $Color,
            ':updateUser' => $UserId,
            ':updateDate' => $UpdateDate
        ]);

        if ($Stmt->rowCount() === 0) {
            Database::Rollback();
            Response::ConflictError();
        }

        $Result = $Stmt->fetch();
        $NewUpdateDate = $Result['updatedate'];
        $NewRelationId = $RelationId;
    }

    /**
     * WorkInfo.updateDate の更新および UpdateHistory への登録は、
     * 相関図保存フロー末尾の SaveRelationHistory.php で 1 回だけ実施するため、
     * ここでは行わない（複数レコード発生を防止）。
     */

    Database::Commit();

    Logger::Info($FunctionName, '関係線' . $Operation . '処理終了', $UserId);

    Response::Success([
        'RelationId' => $NewRelationId,
        'UpdateDate' => $NewUpdateDate,
        'Message' => '関係線を' . $Operation . 'しました'
    ]);

} catch (Exception $E) {
    Database::Rollback();
    Logger::Error($FunctionName, '関係線保存処理エラー', $UserId, $E->getMessage());
    Response::Error('関係線の保存に失敗しました');
}
