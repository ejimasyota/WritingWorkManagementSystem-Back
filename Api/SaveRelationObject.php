<?php
/**
 * SaveRelationObject.php
 * 相関図オブジェクト（キャラクター・グループボックス）登録・更新API
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
    $ObjId = $Input['ObjId'] ?? null;
    $WorkId = $AuthWorkId ?? '';
    $ObjType = $Input['ObjType'] ?? 1;
    $TargetId = $Input['TargetId'] ?? null;
    $Label = $Input['Label'] ?? null;
    $PosX = $Input['PosX'] ?? 0;
    $PosY = $Input['PosY'] ?? 0;
    $Width = $Input['Width'] ?? null;
    $Height = $Input['Height'] ?? null;
    $BgColor = $Input['BgColor'] ?? null;
    $OrgIdGrpName = $Input['OrgIdGrpName'] ?? null;
    $TeamIdGrpName = $Input['TeamIdGrpName'] ?? null;
    $UserId = $AuthUserId;
    $UpdateDate = $Input['UpdateDate'] ?? null;

    $Operation = empty($ObjId) ? '登録' : '更新';
    Logger::Info($FunctionName, 'オブジェクト' . $Operation . '処理開始', $UserId);

    /** 入力チェック */
    if (empty($WorkId)) {
        Response::Error('作品IDが指定されていません');
    }

    /** キャラクタータイプの場合はTargetIdが必須 */
    if ($ObjType == 1 && empty($TargetId)) {
        Response::Error('キャラクターを選択してください');
    }

    /** グループボックスタイプの場合はLabelが必須 */
    if ($ObjType == 2 && empty($Label)) {
        Response::Error('グループ名を入力してください');
    }

    /** DB接続 */
    $Pdo = Database::GetConnection();
    Database::BeginTransaction();

    /** 空文字をNULLに変換 */
    $TargetId = !empty($TargetId) ? $TargetId : null;
    $Label = !empty($Label) ? $Label : null;
    $Width = !empty($Width) ? $Width : null;
    $Height = !empty($Height) ? $Height : null;
    $BgColor = !empty($BgColor) ? $BgColor : null;
    $OrgIdGrpName = !empty($OrgIdGrpName) ? (int)$OrgIdGrpName : null;
    $TeamIdGrpName = !empty($TeamIdGrpName) ? (int)$TeamIdGrpName : null;

    if (empty($ObjId)) {
        /** キャラクタータイプの場合、重複チェック */
        if ($ObjType == 1 && $TargetId) {
            $DuplicateCheckSql = "
                SELECT COUNT(*) AS cnt FROM RelationObjectInfo
                WHERE workId = :workId AND objType = 1 AND targetId = :targetId
            ";
            $DuplicateCheckStmt = $Pdo->prepare($DuplicateCheckSql);
            $DuplicateCheckStmt->execute([
                ':workId' => $WorkId,
                ':targetId' => $TargetId
            ]);
            $DuplicateResult = $DuplicateCheckStmt->fetch();
            if ($DuplicateResult['cnt'] > 0) {
                Database::Rollback();
                Response::Error('このキャラクターは既に相関図に配置されています');
            }
        }

        /** 新規登録 */
        $Sql = "
            INSERT INTO RelationObjectInfo (
                workId, objType, targetId, label, posX, posY, width, height, bgColor,
                orgIdGrpName, teamIdGrpName,
                registDate, updateDate, registUser, updateUser
            ) VALUES (
                :workId, :objType, :targetId, :label, :posX, :posY, :width, :height, :bgColor,
                :orgIdGrpName, :teamIdGrpName,
                CURRENT_TIMESTAMP(3), CURRENT_TIMESTAMP(3), :registUser, :updateUser
            )
            RETURNING objId, updateDate
        ";

        $Stmt = $Pdo->prepare($Sql);
        $Stmt->execute([
            ':workId' => $WorkId,
            ':objType' => $ObjType,
            ':targetId' => $TargetId,
            ':label' => $Label,
            ':posX' => $PosX,
            ':posY' => $PosY,
            ':width' => $Width,
            ':height' => $Height,
            ':bgColor' => $BgColor,
            ':orgIdGrpName' => $OrgIdGrpName,
            ':teamIdGrpName' => $TeamIdGrpName,
            ':registUser' => $UserId,
            ':updateUser' => $UserId
        ]);

        $Result = $Stmt->fetch();
        $NewObjId = $Result['objid'];
        $NewUpdateDate = $Result['updatedate'];

    } else {
        /** 更新 */
        $Sql = "
            UPDATE RelationObjectInfo SET
                objType = :objType,
                targetId = :targetId,
                label = :label,
                posX = :posX,
                posY = :posY,
                width = :width,
                height = :height,
                bgColor = :bgColor,
                orgIdGrpName = :orgIdGrpName,
                teamIdGrpName = :teamIdGrpName,
                updateDate = CURRENT_TIMESTAMP(3),
                updateUser = :updateUser
            WHERE objId = :objId AND workId = :workId AND updateDate = :updateDate
            RETURNING updateDate
        ";

        $Stmt = $Pdo->prepare($Sql);
        $Stmt->execute([
            ':objId' => $ObjId,
            ':workId' => $WorkId,
            ':objType' => $ObjType,
            ':targetId' => $TargetId,
            ':label' => $Label,
            ':posX' => $PosX,
            ':posY' => $PosY,
            ':width' => $Width,
            ':height' => $Height,
            ':bgColor' => $BgColor,
            ':orgIdGrpName' => $OrgIdGrpName,
            ':teamIdGrpName' => $TeamIdGrpName,
            ':updateUser' => $UserId,
            ':updateDate' => $UpdateDate
        ]);

        if ($Stmt->rowCount() === 0) {
            Database::Rollback();
            Response::ConflictError();
        }

        $Result = $Stmt->fetch();
        $NewUpdateDate = $Result['updatedate'];
        $NewObjId = $ObjId;
    }

    /**
     * WorkInfo.updateDate の更新および UpdateHistory への登録は、
     * 相関図保存フロー末尾の SaveRelationHistory.php で 1 回だけ実施するため、
     * ここでは行わない（複数レコード発生を防止）。
     */

    Database::Commit();

    Logger::Info($FunctionName, 'オブジェクト' . $Operation . '処理終了', $UserId);

    Response::Success([
        'ObjId' => $NewObjId,
        'UpdateDate' => $NewUpdateDate,
        'Message' => '相関図オブジェクトを' . $Operation . 'しました'
    ]);

} catch (Exception $E) {
    Database::Rollback();
    Logger::Error($FunctionName, 'オブジェクト保存処理エラー', $UserId, $E->getMessage());
    Response::Error('相関図オブジェクトの保存に失敗しました');
}
