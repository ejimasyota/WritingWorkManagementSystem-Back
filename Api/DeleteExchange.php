<?php
/**
 * DeleteExchange.php
 * 掛け合い削除API
 *
 * Mode = 'info' … 掛け合い（見出し）を削除する。行は外部キーのCASCADEで併せて削除される
 * Mode = 'row'  … 掛け合い行を1件削除する（以降の並び順を詰める）
 * Mode = 'rows' … 掛け合いの行を全件削除する
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

$FunctionName = '掛け合い';
$UserId = '';

try {
    $Input = Response::GetJsonInput();
    $Mode = $Input['Mode'] ?? 'row';
    $WorkId = $AuthWorkId ?? '';
    $UserId = $AuthUserId;
    $ExchangeId = $Input['ExchangeId'] ?? null;
    $ExchangeRowId = $Input['ExchangeRowId'] ?? null;
    $UpdateDate = $Input['UpdateDate'] ?? null;

    Logger::Info($FunctionName, '削除処理開始', $UserId);

    if (empty($WorkId)) {
        Response::Error('作品IDが指定されていません');
    }
    if (empty($ExchangeId)) {
        Response::Error('掛け合いIDが指定されていません');
    }

    $Pdo = Database::GetConnection();
    Database::BeginTransaction();

    /** 対象の掛け合いが自作品のものであることを確認する */
    $OwnerStmt = $Pdo->prepare("
        SELECT exchangeName FROM ExchangeInfo WHERE exchangeId = :exchangeId AND workId = :workId
    ");
    $OwnerStmt->execute([':exchangeId' => $ExchangeId, ':workId' => $WorkId]);
    $OwnerRow = $OwnerStmt->fetch();
    if (!$OwnerRow) {
        Database::Rollback();
        Response::Error('削除対象の掛け合いが見つかりません');
    }
    $ExchangeName = $OwnerRow['exchangename'] ?? null;

    if ($Mode === 'info') {
        /* ==========================================================
         * 掛け合い（見出し）の削除。行は CASCADE で併せて削除される
         * ========================================================== */
        $Stmt = $Pdo->prepare("
            DELETE FROM ExchangeInfo
            WHERE exchangeId = :exchangeId AND workId = :workId AND updateDate = :updateDate
        ");
        $Stmt->execute([
            ':exchangeId' => $ExchangeId,
            ':workId' => $WorkId,
            ':updateDate' => $UpdateDate
        ]);
        if ($Stmt->rowCount() === 0) {
            Database::Rollback();
            Response::ConflictError();
        }
        $Detail = HistoryDetail::Delete($ExchangeName);
    } else {
        // 並び順の同時実行競合を防ぐため、exchangeIdでアドバイザリロックを取得
        $LockStmt = $Pdo->prepare("SELECT pg_advisory_xact_lock(1002, hashtext(:exchangeId))");
        $LockStmt->execute([':exchangeId' => (string)$ExchangeId]);

        if ($Mode === 'rows') {
            /* ==========================================================
             * 掛け合いの行を全件削除
             * ========================================================== */
            $Stmt = $Pdo->prepare("DELETE FROM ExchangeRows WHERE exchangeId = :exchangeId");
            $Stmt->execute([':exchangeId' => $ExchangeId]);
        } else {
            /* ==========================================================
             * 掛け合い行を1件削除し、以降の並び順を詰める
             * ========================================================== */
            if (empty($ExchangeRowId)) {
                Database::Rollback();
                Response::Error('掛け合い行IDが指定されていません');
            }

            $TargetStmt = $Pdo->prepare("
                SELECT indexNum FROM ExchangeRows
                WHERE exchangeRowId = :exchangeRowId AND exchangeId = :exchangeId AND updateDate = :updateDate
            ");
            $TargetStmt->execute([
                ':exchangeRowId' => $ExchangeRowId,
                ':exchangeId' => $ExchangeId,
                ':updateDate' => $UpdateDate
            ]);
            $TargetRow = $TargetStmt->fetch();
            if (!$TargetRow) {
                $ExistsStmt = $Pdo->prepare("SELECT 1 FROM ExchangeRows WHERE exchangeRowId = :exchangeRowId AND exchangeId = :exchangeId");
                $ExistsStmt->execute([
                    ':exchangeRowId' => $ExchangeRowId,
                    ':exchangeId' => $ExchangeId
                ]);
                if (!$ExistsStmt->fetch()) {
                    Database::Rollback();
                    Response::Error('削除対象の掛け合い行が見つかりません');
                }
                Database::Rollback();
                Response::ConflictError();
            }
            $DeletedIndexNum = (int)$TargetRow['indexnum'];

            $DeleteStmt = $Pdo->prepare("
                DELETE FROM ExchangeRows WHERE exchangeRowId = :exchangeRowId AND exchangeId = :exchangeId
            ");
            $DeleteStmt->execute([
                ':exchangeRowId' => $ExchangeRowId,
                ':exchangeId' => $ExchangeId
            ]);

            /* 以降の行の並び順を詰める（一意制約を避けるため負値を経由する） */
            $ShiftTempStmt = $Pdo->prepare("
                UPDATE ExchangeRows SET
                    indexNum = -1 * (indexNum - 1),
                    updateDate = CURRENT_TIMESTAMP(3),
                    updateUser = :updateUser
                WHERE exchangeId = :exchangeId
                  AND indexNum > :deletedIndexNum
            ");
            $ShiftTempStmt->execute([
                ':exchangeId' => $ExchangeId,
                ':deletedIndexNum' => $DeletedIndexNum,
                ':updateUser' => $UserId
            ]);

            $ShiftApplyStmt = $Pdo->prepare("
                UPDATE ExchangeRows SET
                    indexNum = -1 * indexNum,
                    updateDate = CURRENT_TIMESTAMP(3),
                    updateUser = :updateUser
                WHERE exchangeId = :exchangeId
                  AND indexNum < 0
            ");
            $ShiftApplyStmt->execute([
                ':exchangeId' => $ExchangeId,
                ':updateUser' => $UserId
            ]);
        }

        /* 行を削除した場合は親の掛け合いの更新年月日も更新する */
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

        $Detail = HistoryDetail::Delete($ExchangeName);
    }

    /** 作品情報の更新年月日を更新 */
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

    /** 更新履歴を登録 */
    $HistoryStmt = $Pdo->prepare("
        INSERT INTO UpdateHistory (
            workId, functionName, operation, detail, registDate, updateDate, registUser, updateUser
        ) VALUES (
            :workId, :functionName, :operation, :detail, CURRENT_TIMESTAMP(3), CURRENT_TIMESTAMP(3), :registUser, :updateUser
        )
    ");
    $HistoryStmt->execute([
        ':workId' => $WorkId,
        ':functionName' => $FunctionName,
        ':operation' => '削除',
        ':detail' => $Detail,
        ':registUser' => $UserId,
        ':updateUser' => $UserId
    ]);

    Database::Commit();

    Logger::Info($FunctionName, '削除処理終了', $UserId);

    Response::Success([
        'Message' => '掛け合いを削除しました'
    ]);

} catch (Exception $E) {
    Database::Rollback();
    Logger::Error($FunctionName, '削除処理エラー', $UserId, $E->getMessage());
    Response::Error('掛け合いの削除に失敗しました');
}
