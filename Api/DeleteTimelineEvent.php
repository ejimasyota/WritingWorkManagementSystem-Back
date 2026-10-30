<?php
/**
 * DeleteTimelineEvent.php
 * 年表詳細 削除API
 */

require_once __DIR__ . '/../Common/Database.php';
require_once __DIR__ . '/../Common/Logger.php';
require_once __DIR__ . '/../Common/Response.php';
require_once __DIR__ . '/../Common/Auth.php';
// 更新履歴 詳細メッセージ生成
require_once __DIR__ . '/../Common/HistoryDetail.php';

/**
 * 年表詳細を削除する
 */
function DeleteTimelineEvent(): void
{
    global $AuthUserId;
    global $AuthWorkId;
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        // 1. エラーを返す
        Response::Error('不正なリクエストです', 405);
    }
    $FunctionName = '年表詳細';
    $UserId = '';

    try {
        /** リクエストデータを取得 */
        $Input = Response::GetJsonInput();
        $TimeEventId = $Input['TimeEventId'] ?? null;
        $WorkId = $AuthWorkId ?? '';
        $UserId = $AuthUserId;
        $UpdateDate = $Input['UpdateDate'] ?? null;

        Logger::Info($FunctionName, '削除処理開始', $UserId);

        /** 入力チェック */
        if (empty($TimeEventId)) {
            Response::Error('年表詳細IDが指定されていません');
            return;
        }

        if (empty($WorkId)) {
            Response::Error('作品IDが指定されていません');
            return;
        }

        /** DB接続 */
        $Pdo = Database::GetConnection();
        Database::BeginTransaction();

        /** 削除詳細メッセージ用にタイトルを取得 */
        $NameStmt = $Pdo->prepare("
            SELECT title FROM TimelineEventInfo
            WHERE timeEventId = :timeEventId AND workId = :workId
        ");
        $NameStmt->execute([':timeEventId' => $TimeEventId, ':workId' => $WorkId]);
        $NameRow = $NameStmt->fetch();
        $Detail = HistoryDetail::Delete($NameRow['title'] ?? null);

        /** 年表詳細を削除 */
        $Stmt = $Pdo->prepare("
            DELETE FROM TimelineEventInfo
            WHERE timeEventId = :timeEventId AND workId = :workId AND updateDate = :updateDate
        ");
        $Stmt->execute([
            ':timeEventId' => $TimeEventId,
            ':workId' => $WorkId,
            ':updateDate' => $UpdateDate
        ]);
        if ($Stmt->rowCount() === 0) {
            Database::Rollback();
            Response::ConflictError();
            return;
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
            'Message' => '年表詳細を削除しました'
        ]);

    } catch (Exception $E) {
        Database::Rollback();
        Logger::Error($FunctionName, '削除処理エラー', $UserId, $E->getMessage());
        Response::Error('年表詳細の削除に失敗しました');
    }
}

/** API実行 */
DeleteTimelineEvent();
