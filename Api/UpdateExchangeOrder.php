<?php
/**
 * UpdateExchangeOrder.php
 * 掛け合い行の順序更新API（ドラッグ&ドロップ対応）
 *
 * (exchangeId, indexNum) の一意制約と競合しないよう、
 * ストーリー行と同じく一度負値へ退避してから確定させる。
 */

require_once __DIR__ . '/../Common/Database.php';
require_once __DIR__ . '/../Common/Logger.php';
require_once __DIR__ . '/../Common/Response.php';
require_once __DIR__ . '/../Common/Auth.php';

// POSTメソッドのみ許可
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::Error('不正なリクエストです', 405);
}

$FunctionName = '掛け合い';
$UserId = '';

try {
    $Input = Response::GetJsonInput();
    $WorkId = $AuthWorkId ?? '';
    $ExchangeId = $Input['ExchangeId'] ?? null;
    $OrderList = $Input['OrderList'] ?? [];
    $UserId = $AuthUserId;

    Logger::Info($FunctionName, '順序更新処理開始', $UserId);

    if (empty($WorkId)) {
        Response::Error('作品IDが指定されていません');
    }
    if (empty($ExchangeId)) {
        Response::Error('掛け合いIDが指定されていません');
    }
    if (empty($OrderList) || !is_array($OrderList)) {
        Response::Error('順序情報が指定されていません');
    }

    $Pdo = Database::GetConnection();
    Database::BeginTransaction();

    /** 対象の掛け合いが自作品のものであることを確認する */
    $OwnerStmt = $Pdo->prepare("SELECT 1 FROM ExchangeInfo WHERE exchangeId = :exchangeId AND workId = :workId");
    $OwnerStmt->execute([':exchangeId' => $ExchangeId, ':workId' => $WorkId]);
    if (!$OwnerStmt->fetch()) {
        Database::Rollback();
        Response::Error('対象の掛け合いが見つかりません');
    }

    // 並び順の同時実行競合を防ぐため、exchangeIdでアドバイザリロックを取得
    $LockStmt = $Pdo->prepare("SELECT pg_advisory_xact_lock(1002, hashtext(:exchangeId))");
    $LockStmt->execute([':exchangeId' => (string)$ExchangeId]);

    // OrderList が対象掛け合いの全行を網羅しているか検証
    $CountStmt = $Pdo->prepare("SELECT COUNT(*) AS cnt FROM ExchangeRows WHERE exchangeId = :exchangeId");
    $CountStmt->execute([':exchangeId' => $ExchangeId]);
    if ((int)$CountStmt->fetch()['cnt'] !== count($OrderList)) {
        Database::Rollback();
        Response::Error('順序情報が不完全です。画面をリフレッシュしてください', 409);
    }

    // OrderList 内の IndexNum の重複を検出
    $Indexes = array_map(fn($O) => (int)$O['IndexNum'], $OrderList);
    if (count(array_unique($Indexes)) !== count($Indexes)) {
        Database::Rollback();
        Response::Error('順序情報に重複があります');
    }

    /* 1. いったん負値へ退避する */
    $TempUpdateStmt = $Pdo->prepare("
        UPDATE ExchangeRows SET
            indexNum = :tempIndexNum,
            updateDate = CURRENT_TIMESTAMP(3),
            updateUser = :updateUser
        WHERE exchangeRowId = :exchangeRowId AND exchangeId = :exchangeId
    ");
    foreach ($OrderList as $Order) {
        $TempUpdateStmt->execute([
            ':exchangeRowId' => $Order['ExchangeRowId'],
            ':exchangeId' => $ExchangeId,
            ':tempIndexNum' => -1 * (int)$Order['IndexNum'],
            ':updateUser' => $UserId
        ]);
    }

    /* 2. 正の値へ確定させる */
    $UpdateStmt = $Pdo->prepare("
        UPDATE ExchangeRows SET
            indexNum = :indexNum,
            updateDate = CURRENT_TIMESTAMP(3),
            updateUser = :updateUser
        WHERE exchangeRowId = :exchangeRowId AND exchangeId = :exchangeId
    ");
    foreach ($OrderList as $Order) {
        $UpdateStmt->execute([
            ':exchangeRowId' => $Order['ExchangeRowId'],
            ':exchangeId' => $ExchangeId,
            ':indexNum' => (int)$Order['IndexNum'],
            ':updateUser' => $UserId
        ]);
    }

    /** 親の掛け合いおよび作品情報の更新年月日を更新 */
    $TouchStmt = $Pdo->prepare("
        UPDATE ExchangeInfo SET
            updateDate = CURRENT_TIMESTAMP(3),
            updateUser = :updateUser
        WHERE exchangeId = :exchangeId AND workId = :workId
    ");
    $TouchStmt->execute([
        ':exchangeId' => $ExchangeId,
        ':workId' => $WorkId,
        ':updateUser' => $UserId
    ]);

    $UpdateWorkStmt = $Pdo->prepare("
        UPDATE WorkInfo SET
            updateDate = CURRENT_TIMESTAMP(3),
            updateUser = :updateUser
        WHERE workId = :workId
    ");
    $UpdateWorkStmt->execute([
        ':workId' => $WorkId,
        ':updateUser' => $UserId
    ]);

    Database::Commit();

    Logger::Info($FunctionName, '順序更新処理終了', $UserId);

    Response::Success([
        'Message' => '掛け合いの並び順を更新しました'
    ]);

} catch (Exception $E) {
    Database::Rollback();
    Logger::Error($FunctionName, '順序更新処理エラー', $UserId, $E->getMessage());
    Response::Error('掛け合いの並び順の更新に失敗しました');
}
