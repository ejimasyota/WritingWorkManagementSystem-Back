<?php
/**
 * SaveEraMaster.php
 * 元号マスタ登録・更新API（管理者用）
 *
 * 全体共通の実元号マスタを登録／更新する。作品スコープを持たないため
 * UpdateHistory や WorkInfo の更新は行わない。更新は updateDate 一致による楽観ロック。
 */

require_once __DIR__ . '/../Common/Database.php';
require_once __DIR__ . '/../Common/Logger.php';
require_once __DIR__ . '/../Common/Response.php';
require_once __DIR__ . '/../Common/Auth.php';

// POSTメソッドのみ許可
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::Error('不正なリクエストです', 405);
}

// 管理者権限チェック（セッション + AdminInfo 実表照合）
if (!$IsAuthAdmin) {
    Response::Error('管理者権限が必要です', 403);
}

$FunctionName = '元号マスタ';
$UserId = $AuthUserId;

try {
    /** リクエストデータを取得 */
    $Input = Response::GetJsonInput();
    $EraMasterId = $Input['EraMasterId'] ?? null;
    $EraName = trim($Input['EraName'] ?? '');
    $StartYear = $Input['StartYear'] ?? null;
    $EndYear = $Input['EndYear'] ?? null;
    $UpdateDate = $Input['UpdateDate'] ?? null;

    $Operation = empty($EraMasterId) ? '登録' : '更新';
    Logger::Info($FunctionName, $Operation . '処理開始', $UserId);

    /** 入力チェック */
    if ($EraName === '') {
        Response::Error('元号名を入力してください');
    }
    if (mb_strlen($EraName) > 100) {
        Response::Error('元号名は100文字以下で入力してください');
    }
    if (trim((string)($StartYear ?? '')) === '') {
        Response::Error('開始年を入力してください');
    }

    $Pdo = Database::GetConnection();

    // 二重防御：AdminInfo 実表照合
    $AdminStmt = $Pdo->prepare('SELECT userId FROM AdminInfo WHERE userId = :userId');
    $AdminStmt->execute([':userId' => $UserId]);
    if (!$AdminStmt->fetch()) {
        Response::Error('管理者権限が必要です', 403);
    }

    Database::BeginTransaction();

    /** 空文字をNULLに変換 */
    $EraMasterId = !empty($EraMasterId) ? $EraMasterId : null;
    $StartYear = ($StartYear !== null && $StartYear !== '') ? $StartYear : null;
    $EndYear = ($EndYear !== null && $EndYear !== '') ? $EndYear : null;

    if (empty($EraMasterId)) {
        /** 新規登録 */
        $Sql = "
            INSERT INTO EraMasterInfo (
                eraName, startYear, endYear,
                registDate, updateDate, registUser, updateUser
            ) VALUES (
                :eraName, :startYear, :endYear,
                CURRENT_TIMESTAMP(3), CURRENT_TIMESTAMP(3), :registUser, :updateUser
            )
            RETURNING eraMasterId, updateDate
        ";
        $Stmt = $Pdo->prepare($Sql);
        $Stmt->execute([
            ':eraName' => $EraName,
            ':startYear' => $StartYear,
            ':endYear' => $EndYear,
            ':registUser' => $UserId,
            ':updateUser' => $UserId,
        ]);
        $Result = $Stmt->fetch();
        $NewEraMasterId = $Result['eramasterid'];
        $NewUpdateDate = $Result['updatedate'];

    } else {
        /** 更新（updateDate 一致の楽観ロック） */
        $Sql = "
            UPDATE EraMasterInfo SET
                eraName = :eraName,
                startYear = :startYear,
                endYear = :endYear,
                updateDate = CURRENT_TIMESTAMP(3),
                updateUser = :updateUser
            WHERE eraMasterId = :eraMasterId AND updateDate = :updateDate
            RETURNING updateDate
        ";
        $Stmt = $Pdo->prepare($Sql);
        $Stmt->execute([
            ':eraMasterId' => $EraMasterId,
            ':eraName' => $EraName,
            ':startYear' => $StartYear,
            ':endYear' => $EndYear,
            ':updateUser' => $UserId,
            ':updateDate' => $UpdateDate,
        ]);
        $UpdateResult = $Stmt->fetch();

        if (!$UpdateResult) {
            Database::Rollback();
            Response::ConflictError();
        }

        $NewEraMasterId = $EraMasterId;
        $NewUpdateDate = $UpdateResult['updatedate'];
    }

    Database::Commit();

    Logger::Info($FunctionName, $Operation . '処理終了', $UserId);

    Response::Success([
        'EraMasterId' => $NewEraMasterId,
        'UpdateDate' => $NewUpdateDate,
        'Message' => '元号マスタを' . $Operation . 'しました',
    ]);

} catch (Exception $E) {
    Database::Rollback();
    Logger::Error($FunctionName, '保存処理エラー', $UserId, $E->getMessage());
    Response::Error('元号マスタの保存に失敗しました');
}
