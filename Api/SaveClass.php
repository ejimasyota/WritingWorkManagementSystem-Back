<?php
/**
 * SaveClass.php
 * 階級登録・更新API
 */

require_once __DIR__ . '/../Common/Database.php';
require_once __DIR__ . '/../Common/Logger.php';
require_once __DIR__ . '/../Common/Response.php';
require_once __DIR__ . '/../Common/Auth.php';
// 更新履歴 詳細メッセージ生成
require_once __DIR__ . '/../Common/HistoryDetail.php';

// POSTメソッドのみ許可
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::Error('不正なリクエストです', 405);
}

$FunctionName = '階級設定';
$UserId = '';

try {
    /** リクエストデータを取得 */
    $Input = Response::GetJsonInput();
    $ClassId = $Input['ClassId'] ?? null;
    $WorkId = $AuthWorkId ?? '';
    $OrgId = $Input['OrgId'] ?? null;
    $ClassName = $Input['ClassName'] ?? '';
    $RankOrder = $Input['RankOrder'] ?? null;
    $Description = $Input['Description'] ?? null;
    $UserId = $AuthUserId;
    $UpdateDate = $Input['UpdateDate'] ?? null;

    $Operation = empty($ClassId) ? '登録' : '更新';
    // 更新履歴の詳細メッセージ（登録/更新分岐でそれぞれ生成）
    $Detail = null;
    Logger::Info($FunctionName, $Operation . '処理開始', $UserId);

    /** 入力チェック */
    if (empty($WorkId)) {
        Response::Error('作品IDが指定されていません');
    }

    if (empty($ClassName)) {
        Response::Error('階級名を入力してください');
    }

    /** DB接続 */
    $Pdo = Database::GetConnection();
    Database::BeginTransaction();

    /** 空文字をNULLに変換 */
    $ClassId = !empty($ClassId) ? $ClassId : null;
    $OrgId = !empty($OrgId) ? $OrgId : null;
    $RankOrder = !empty($RankOrder) || $RankOrder === 0 || $RankOrder === '0' ? (int)$RankOrder : null;
    $Description = !empty($Description) ? $Description : null;

    if (empty($ClassId)) {
        /** 新規登録 */
        $Sql = "
            INSERT INTO ClassInfo (
                workId, orgId, className, rankOrder, description,
                registDate, updateDate, registUser, updateUser
            ) VALUES (
                :workId, :orgId, :className, :rankOrder, :description,
                CURRENT_TIMESTAMP(3), CURRENT_TIMESTAMP(3), :registUser, :updateUser
            )
            RETURNING classId, updateDate
        ";

        $Stmt = $Pdo->prepare($Sql);
        $Stmt->execute([
            ':workId' => $WorkId,
            ':orgId' => $OrgId,
            ':className' => $ClassName,
            ':rankOrder' => $RankOrder,
            ':description' => $Description,
            ':registUser' => $UserId,
            ':updateUser' => $UserId
        ]);

        $Result = $Stmt->fetch();
        $NewClassId = $Result['classid'];
        $NewUpdateDate = $Result['updatedate'];

        // 更新履歴の詳細メッセージを生成（登録）
        $Detail = HistoryDetail::Register($ClassName);

    } else {
        /** 更新 */
        /* 差分履歴用：更新前の表示値を取得（FK列は名称へ解決するため JOIN。取得キーは小文字） */
        $OldRowStmt = $Pdo->prepare("
            SELECT cl.orgId, o.orgName, cl.className, cl.rankOrder, cl.description
            FROM ClassInfo cl
            LEFT JOIN OrganizationInfo o ON cl.orgId = o.orgId
            WHERE cl.classId = :classId AND cl.workId = :workId
        ");
        $OldRowStmt->bindValue(':classId', $ClassId, PDO::PARAM_STR);
        $OldRowStmt->bindValue(':workId', $WorkId, PDO::PARAM_STR);
        $OldRowStmt->execute();
        $OldRow = $OldRowStmt->fetch() ?: [];

        $Sql = "
            UPDATE ClassInfo SET
                orgId = :orgId,
                className = :className,
                rankOrder = :rankOrder,
                description = :description,
                updateDate = CURRENT_TIMESTAMP(3),
                updateUser = :updateUser
            WHERE classId = :classId AND workId = :workId AND updateDate = :updateDate
            RETURNING updateDate
        ";

        $Stmt = $Pdo->prepare($Sql);
        $Stmt->execute([
            ':classId' => $ClassId,
            ':workId' => $WorkId,
            ':orgId' => $OrgId,
            ':className' => $ClassName,
            ':rankOrder' => $RankOrder,
            ':description' => $Description,
            ':updateUser' => $UserId,
            ':updateDate' => $UpdateDate
        ]);

        $UpdateResult = $Stmt->fetch();
        if (!$UpdateResult) {
            Database::Rollback();
            Response::ConflictError();
        }

        $NewClassId = $ClassId;
        $NewUpdateDate = $UpdateResult['updatedate'];

        /* 更新履歴の詳細メッセージを生成（更新・列単位差分）
         * FK列は新IDが変わった場合のみ名称を再解決する（未変更は旧名称を再利用）。 */
        $ResolveName = function ($Table, $IdCol, $NameCol, $Id) use ($Pdo, $WorkId) {
            if ($Id === null || $Id === '') {
                return null;
            }
            $S = $Pdo->prepare("SELECT {$NameCol} AS nm FROM {$Table} WHERE {$IdCol} = :id AND workId = :workId");
            $S->bindValue(':id', $Id);
            $S->bindValue(':workId', $WorkId, PDO::PARAM_STR);
            $S->execute();
            $R = $S->fetch();
            return $R ? $R['nm'] : null;
        };
        $NewFkName = function ($Table, $IdCol, $NameCol, $NewId, $OldId, $OldName) use ($ResolveName) {
            if ((string) ($NewId ?? '') === (string) ($OldId ?? '')) {
                return $OldName;
            }
            return $ResolveName($Table, $IdCol, $NameCol, $NewId);
        };

        $Labels = [
            'orgId'       => '組織',
            'className'   => '階級名',
            'rankOrder'   => '順位',
            'description' => '説明',
        ];
        $OldDisplay = [
            'orgId'       => $OldRow['orgname']     ?? null,
            'className'   => $OldRow['classname']   ?? null,
            'rankOrder'   => $OldRow['rankorder']   ?? null,
            'description' => $OldRow['description'] ?? null,
        ];
        $NewDisplay = [
            'orgId'       => $NewFkName('OrganizationInfo', 'orgId', 'orgName', $OrgId, $OldRow['orgid'] ?? null, $OldRow['orgname'] ?? null),
            'className'   => $ClassName,
            'rankOrder'   => $RankOrder,
            'description' => $Description,
        ];
        $Detail = HistoryDetail::Update($ClassName, $OldDisplay, $NewDisplay, $Labels);
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
        'ClassId' => $NewClassId,
        'UpdateDate' => $NewUpdateDate,
        'Message' => '階級を' . $Operation . 'しました'
    ]);

} catch (Exception $E) {
    Database::Rollback();
    Logger::Error($FunctionName, '保存処理エラー', $UserId, $E->getMessage());
    Response::Error('階級の保存に失敗しました');
}
