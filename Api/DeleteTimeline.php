<?php
/**
 * DeleteTimeline.php
 * 年表削除API
 */

require_once __DIR__ . '/../Common/Database.php';
require_once __DIR__ . '/../Common/Logger.php';
require_once __DIR__ . '/../Common/Response.php';
require_once __DIR__ . '/../Common/Auth.php';
// 更新履歴 詳細メッセージ生成
require_once __DIR__ . '/../Common/HistoryDetail.php';
// チャプターの並び順 正規化（年表削除による紐づけ解除に追従させるため）
require_once __DIR__ . '/../Common/ChapterOrder.php';

/**
 * 年表を削除する
 */
function DeleteTimeline(): void
{
    global $AuthUserId;
    global $AuthWorkId;
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        // 1. エラーを返す
        Response::Error('不正なリクエストです', 405);
    }
    $FunctionName = '年表設定';
    $UserId = '';

    try {
        /** リクエストデータを取得 */
        $Input = Response::GetJsonInput();
        $TimeId = $Input['TimeId'] ?? null;
        $WorkId = $AuthWorkId ?? '';
        $UserId = $AuthUserId;
        $UpdateDate = $Input['UpdateDate'] ?? null;

        Logger::Info($FunctionName, '削除処理開始', $UserId);

        /** 入力チェック */
        if (empty($TimeId)) {
            Response::Error('年表IDが指定されていません');
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
        $NameStmt = $Pdo->prepare("SELECT title FROM TimelineInfo WHERE timeId = :timeId AND workId = :workId");
        $NameStmt->execute([':timeId' => $TimeId, ':workId' => $WorkId]);
        $NameRow = $NameStmt->fetch();
        $Detail = HistoryDetail::Delete($NameRow['title'] ?? null);

        /** 年表を削除 */
        $Sql = "DELETE FROM TimelineInfo WHERE timeId = :timeId AND workId = :workId AND updateDate = :updateDate";
        $Stmt = $Pdo->prepare($Sql);
        $Stmt->execute([
            ':timeId' => $TimeId,
            ':workId' => $WorkId,
            ':updateDate' => $UpdateDate
        ]);
        if ($Stmt->rowCount() === 0) {
            Database::Rollback();
            Response::ConflictError();
            return;
        }

        /* チャプターの並び順を正規化
         * 年表削除時は ChapterInfo.timeId が ON DELETE SET NULL で解除されるため、
         * 残った年表ありカード同士の相対順序を年表順へ揃え直す。 */
        ChapterOrder::Normalize($Pdo, $WorkId);

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

        /** 更新履歴を登録 */
        $HistorySql = "
            INSERT INTO UpdateHistory (
                workId, functionName, operation, detail, registDate, updateDate, registUser, updateUser
            ) VALUES (
                :workId, :functionName, :operation, :detail, CURRENT_TIMESTAMP(3), CURRENT_TIMESTAMP(3), :registUser, :updateUser
            )
        ";
        $HistoryStmt = $Pdo->prepare($HistorySql);
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
            'Message' => '年表を削除しました'
        ]);

    } catch (Exception $E) {
        Database::Rollback();
        Logger::Error($FunctionName, '削除処理エラー', $UserId, $E->getMessage());
        Response::Error('年表の削除に失敗しました');
    }
}

/** API実行 */
DeleteTimeline();
