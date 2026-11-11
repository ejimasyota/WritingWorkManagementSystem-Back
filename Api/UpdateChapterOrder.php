<?php
/**
 * UpdateChapterOrder.php
 * チャプター順序更新API（ドラッグ&ドロップ対応）
 *
 * 「関連年表ありカード ➝ 関連年表ありカード」の並び替えは仕様上不可のため、
 * フロント側の抑止に加えてサーバ側でも検証し、違反時はエラーを返す。
 */

require_once __DIR__ . '/../Common/Database.php';
require_once __DIR__ . '/../Common/Logger.php';
require_once __DIR__ . '/../Common/Response.php';
require_once __DIR__ . '/../Common/Auth.php';
// チャプターの並び順 正規化・検証
require_once __DIR__ . '/../Common/ChapterOrder.php';

// POSTメソッドのみ許可
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::Error('不正なリクエストです', 405);
}

$FunctionName = 'チャプター設定';
$UserId = '';

try {
    $Input = Response::GetJsonInput();
    $WorkId = $AuthWorkId ?? '';
    $OrderList = $Input['OrderList'] ?? [];
    $UserId = $AuthUserId;

    Logger::Info($FunctionName, '順序更新処理開始', $UserId);

    if (empty($WorkId)) {
        Response::Error('作品IDが指定されていません');
    }
    if (empty($OrderList) || !is_array($OrderList)) {
        Response::Error('順序情報が指定されていません');
    }

    $Pdo = Database::GetConnection();
    Database::BeginTransaction();

    // 並び順の同時実行競合を防ぐため、workIdでアドバイザリロックを取得
    $LockStmt = $Pdo->prepare("SELECT pg_advisory_xact_lock(" . ChapterOrder::ADVISORY_LOCK_KEY . ", hashtext(:workId))");
    $LockStmt->execute([':workId' => (string) $WorkId]);

    // 新フロントは全行の読み込み時の版を送る。件数だけでなくIDの重複と更新競合も確認する。
    $VersionStmt = $Pdo->prepare('SELECT chapterId, updateDate FROM ChapterInfo WHERE workId = :workId ORDER BY chapterId FOR UPDATE');
    $VersionStmt->execute([':workId' => $WorkId]);
    $Versions = [];
    foreach ($VersionStmt->fetchAll() as $Row) $Versions[(string) $Row['chapterid']] = (string) $Row['updatedate'];
    $HasVersions = array_filter($OrderList, static fn($Row): bool => is_array($Row) && array_key_exists('UpdateDate', $Row));
    $Seen = [];
    foreach ($OrderList as $Row) {
        $Id = is_array($Row) ? (string) ($Row['ChapterId'] ?? '') : '';
        if ($Id === '' || isset($Seen[$Id]) || !isset($Versions[$Id])
            || ($HasVersions && $Versions[$Id] !== (string) ($Row['UpdateDate'] ?? ''))) {
            Database::Rollback(); Response::ConflictError();
        }
        $Seen[$Id] = true;
    }

    // OrderList が対象作品の全チャプターを網羅しているか検証
    $CountStmt = $Pdo->prepare("SELECT COUNT(*) AS cnt FROM ChapterInfo WHERE workId = :workId");
    $CountStmt->execute([':workId' => $WorkId]);
    $CountResult = $CountStmt->fetch();
    if ((int) $CountResult['cnt'] !== count($OrderList)) {
        Database::Rollback();
        Response::Error('順序情報が不完全です。画面をリフレッシュしてください', 409);
    }

    // OrderList 内の IndexNum の重複を検出
    $Indexes = array_map(fn($O) => (int) $O['IndexNum'], $OrderList);
    if (count(array_unique($Indexes)) !== count($Indexes)) {
        Database::Rollback();
        Response::Error('順序情報に重複があります');
    }

    // 要求された並び順（IndexNum昇順）のチャプターIDリストを組み立てる
    $SortedOrderList = $OrderList;
    usort($SortedOrderList, fn($A, $B) => (int) $A['IndexNum'] <=> (int) $B['IndexNum']);
    $OrderedChapterIds = array_map(fn($O) => $O['ChapterId'], $SortedOrderList);

    // 年表ありカード同士の並び替えになっていないか検証
    if (!ChapterOrder::IsValidOrder($Pdo, $WorkId, $OrderedChapterIds)) {
        Database::Rollback();
        Response::Error('関連年表が設定されているカード同士の並び替えはできません');
    }

    // 一時的に負値へ退避してから確定値を設定する（並び順の一意制約・衝突回避のため）
    $TempUpdateSql = "
        UPDATE ChapterInfo SET
            indexNum = :tempIndexNum
        WHERE chapterId = :chapterId AND workId = :workId
    ";
    $TempUpdateStmt = $Pdo->prepare($TempUpdateSql);
    foreach ($OrderList as $Order) {
        $TempUpdateStmt->execute([
            ':chapterId' => $Order['ChapterId'],
            ':workId' => $WorkId,
            ':tempIndexNum' => -1 * (int) $Order['IndexNum']
        ]);
    }

    $UpdateSql = "
        UPDATE ChapterInfo SET
            indexNum = :indexNum,
            updateDate = CURRENT_TIMESTAMP(3),
            updateUser = :updateUser
        WHERE chapterId = :chapterId AND workId = :workId
    ";
    $UpdateStmt = $Pdo->prepare($UpdateSql);
    foreach ($OrderList as $Order) {
        $UpdateStmt->execute([
            ':chapterId' => $Order['ChapterId'],
            ':workId' => $WorkId,
            ':updateUser' => $UserId,
            ':indexNum' => (int) $Order['IndexNum']
        ]);
    }

    /** 並び順を正規化（連番の詰め直し・年表順の担保） */
    ChapterOrder::Normalize($Pdo, $WorkId);

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

    $HistorySql = "
        INSERT INTO UpdateHistory (
            workId, functionName, operation, registDate, updateDate, registUser, updateUser
        ) VALUES (
            :workId, :functionName, :operation, CURRENT_TIMESTAMP(3), CURRENT_TIMESTAMP(3), :registUser, :updateUser
        )
    ";
    $HistoryStmt = $Pdo->prepare($HistorySql);
    $HistoryStmt->execute([
        ':workId' => $WorkId,
        ':functionName' => $FunctionName,
        ':operation' => '順序更新',
        ':registUser' => $UserId,
        ':updateUser' => $UserId
    ]);

    Database::Commit();

    Logger::Info($FunctionName, '順序更新処理終了', $UserId);

    Response::Success([
        'Message' => 'チャプターの順序を更新しました'
    ]);

} catch (Exception $E) {
    Database::Rollback();
    Logger::Error($FunctionName, '順序更新処理エラー', $UserId, $E->getMessage());
    Response::Error('チャプターの順序更新に失敗しました');
}
