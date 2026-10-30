<?php
/**
 * GetTimelineList.php
 * 年表一覧取得API
 */

require_once __DIR__ . '/../Common/Database.php';
require_once __DIR__ . '/../Common/Logger.php';
require_once __DIR__ . '/../Common/Response.php';
require_once __DIR__ . '/../Common/Auth.php';

// POSTメソッドのみ許可
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::Error('不正なリクエストです', 405);
}

/**
 * 年表一覧を取得する
 */
function GetTimelineList(): void
{
    global $AuthUserId;
    global $AuthWorkId;
    $FunctionName = '年表設定';
    $UserId = '';

    try {
        /** リクエストデータを取得 */
        $Input = Response::GetJsonInput();
        $WorkId = $AuthWorkId ?? '';
        $UserId = $AuthUserId;

        Logger::Info($FunctionName, '一覧取得処理開始', $UserId);

        /** 入力チェック */
        if (empty($WorkId)) {
            Response::Error('作品IDが指定されていません');
            return;
        }

        /** DB接続 */
        $Pdo = Database::GetConnection();

        /** 年表一覧を取得 */
        $Sql = "
            SELECT
                t.timeId AS \"TimeId\",
                t.workId AS \"WorkId\",
                t.caleId AS \"CaleId\",
                c.eraName AS \"EraName\",
                t.endCaleId AS \"EndCaleId\",
                ec.eraName AS \"EndEraName\",
                t.eventDate AS \"EventDate\",
                t.endDate AS \"EndDate\",
                t.title AS \"Title\",
                t.content AS \"Content\",
                t.registDate AS \"RegistDate\",
                t.updateDate AS \"UpdateDate\",
                t.registUser AS \"RegistUser\",
                t.updateUser AS \"UpdateUser\"
            FROM TimelineInfo t
            LEFT JOIN CalendarInfo c ON t.caleId = c.caleId
            LEFT JOIN CalendarInfo ec ON t.endCaleId = ec.caleId
            WHERE t.workId = :workId
            ORDER BY t.eventDate ASC, t.timeId ASC
        ";

        $Stmt = $Pdo->prepare($Sql);
        $Stmt->execute([':workId' => $WorkId]);
        $TimelineList = $Stmt->fetchAll();

        /** 元号リストを取得（プルダウン用・年度計算用） */
        $CalendarSql = "
            SELECT caleId AS \"CaleId\", eraName AS \"EraName\", startYear AS \"StartYear\"
            FROM CalendarInfo
            WHERE workId = :workId
            ORDER BY startYear ASC
        ";
        $CalendarStmt = $Pdo->prepare($CalendarSql);
        $CalendarStmt->execute([':workId' => $WorkId]);
        $CalendarList = $CalendarStmt->fetchAll();

        Logger::Info($FunctionName, '一覧取得処理終了', $UserId);

        Response::Success([
            'TimelineList' => $TimelineList,
            'CalendarList' => $CalendarList,
            'TotalCount' => count($TimelineList)
        ]);

    } catch (Exception $E) {
        Logger::Error($FunctionName, '一覧取得処理エラー', $UserId, $E->getMessage());
        Response::Error('年表一覧の取得に失敗しました');
    }
}

/** API実行 */
GetTimelineList();
