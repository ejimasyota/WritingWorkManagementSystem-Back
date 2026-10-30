<?php
/**
 * SaveTimeline.php
 * 年表登録・更新API
 */

require_once __DIR__ . '/../Common/Database.php';
require_once __DIR__ . '/../Common/Logger.php';
require_once __DIR__ . '/../Common/Response.php';
require_once __DIR__ . '/../Common/Auth.php';
// 更新履歴 詳細メッセージ生成
require_once __DIR__ . '/../Common/HistoryDetail.php';
// チャプターの並び順 正規化（年表の発生時期変更に追従させるため）
require_once __DIR__ . '/../Common/ChapterOrder.php';
// 日付文字列の日数計算（年表詳細の追従移動に使用）
require_once __DIR__ . '/../Common/DateUtil.php';

// POSTメソッドのみ許可
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::Error('不正なリクエストです', 405);
}

/**
 * 年表詳細の開始・終了年月日を、親年表の発生時期の移動量だけずらす
 * --------------------------------------------------------------------------
 * 年表詳細は親の出来事の内訳であるため、親の期間を動かした際に
 * 内訳だけが取り残されると期間の対応が崩れる。
 * 発生時期の差分（日数）を全ての年表詳細へ一律に加算することで、
 * 親の期間内での相対的な位置関係を保ったまま追従させる。
 * 移動の結果が親の終了時期を超える場合は、親の終了時期へ丸める。
 *
 * @param PDO         $Pdo            DB接続
 * @param string      $WorkId         作品ID
 * @param mixed       $TimeId         親年表ID
 * @param int         $ShiftDays      移動する日数（負値は過去へ）
 * @param string|null $ParentEndDate  親年表の終了時期（未登録の場合は null）
 * @param string      $UserId         更新者
 * @return void
 */
function ShiftTimelineEventDates(PDO $Pdo, string $WorkId, $TimeId, int $ShiftDays, ?string $ParentEndDate, string $UserId): void
{
    $SelectStmt = $Pdo->prepare("
        SELECT timeEventId, startDate, endDate
        FROM TimelineEventInfo
        WHERE workId = :workId AND timeId = :timeId
    ");
    $SelectStmt->execute([':workId' => $WorkId, ':timeId' => $TimeId]);
    $Rows = $SelectStmt->fetchAll();
    if (empty($Rows)) {
        return;
    }

    /** 親の終了時期を超える場合に丸める */
    $ClampToParentEnd = static function (?string $DateStr) use ($ParentEndDate): ?string {
        if ($DateStr === null || empty($ParentEndDate)) {
            return $DateStr;
        }
        $Compare = DateUtil::Compare($DateStr, $ParentEndDate);
        return ($Compare !== null && $Compare > 0) ? $ParentEndDate : $DateStr;
    };

    $UpdateStmt = $Pdo->prepare("
        UPDATE TimelineEventInfo SET
            startDate = :startDate,
            endDate = :endDate,
            updateDate = CURRENT_TIMESTAMP(3),
            updateUser = :updateUser
        WHERE timeEventId = :timeEventId AND workId = :workId
    ");

    foreach ($Rows as $Row) {
        /* 解釈できない日付は移動させず、元の値のまま残す */
        $NewStart = DateUtil::Shift($Row['startdate'], $ShiftDays) ?? $Row['startdate'];
        $NewEnd = !empty($Row['enddate'])
            ? (DateUtil::Shift($Row['enddate'], $ShiftDays) ?? $Row['enddate'])
            : null;

        $NewStart = $ClampToParentEnd($NewStart);
        $NewEnd = $ClampToParentEnd($NewEnd);

        /* 値が変わらない行は更新年月日だけを進めないよう、対象から外す */
        if ((string) $NewStart === (string) $Row['startdate']
            && (string) ($NewEnd ?? '') === (string) ($Row['enddate'] ?? '')) {
            continue;
        }

        $UpdateStmt->execute([
            ':startDate' => $NewStart,
            ':endDate' => $NewEnd,
            ':updateUser' => $UserId,
            ':timeEventId' => $Row['timeeventid'],
            ':workId' => $WorkId
        ]);
    }
}

/**
 * 年表を登録・更新する
 */
function SaveTimeline(): void
{
    global $AuthUserId;
    global $AuthWorkId;
    $FunctionName = '年表設定';
    $UserId = '';

    try {
        /** リクエストデータを取得 */
        $Input = Response::GetJsonInput();
        $TimeId = $Input['TimeId'] ?? null;
        $WorkId = $AuthWorkId ?? '';
        $EventDate = $Input['EventDate'] ?? '';
        $EndDate = $Input['EndDate'] ?? null;
        $Title = $Input['Title'] ?? '';
        $Content = $Input['Content'] ?? null;
        $UserId = $AuthUserId;
        $UpdateDate = $Input['UpdateDate'] ?? null;
        $CaleId = null;
        $EndCaleId = null;

        $Operation = empty($TimeId) ? '登録' : '更新';
        // 更新履歴の詳細メッセージ（登録/更新分岐でそれぞれ生成）
        $Detail = null;
        Logger::Info($FunctionName, $Operation . '処理開始', $UserId);

        /** 入力チェック */
        if (empty($WorkId)) {
            Response::Error('作品IDが指定されていません');
            return;
        }

        if (empty($EventDate)) {
            Response::Error('発生時期を入力してください');
            return;
        }

        if (empty($Title)) {
            Response::Error('タイトルを入力してください');
            return;
        }

        /** DB接続 */
        $Pdo = Database::GetConnection();
        Database::BeginTransaction();

        /** 元号自動判定: EventDateから年を抽出し、CalendarInfoと照合 */
        $EventYear = preg_replace('/[^0-9]/', '', $EventDate);
        if (!empty($EventYear)) {
            // 先頭4桁を年として取得（YYYYMMDD形式を想定）
            $EventYear = substr($EventYear, 0, 4);
            $CaleSql = "
                SELECT caleId
                FROM CalendarInfo
                WHERE workId = :workId
                  AND CAST(SUBSTRING(REGEXP_REPLACE(startYear, '[^0-9]', '', 'g') FROM 1 FOR 4) AS BIGINT) <= :eventYear
                  AND CAST(SUBSTRING(REGEXP_REPLACE(endYear, '[^0-9]', '', 'g') FROM 1 FOR 4) AS BIGINT) >= :eventYear
                ORDER BY caleId ASC
                LIMIT 1
            ";
            $CaleStmt = $Pdo->prepare($CaleSql);
            $CaleStmt->execute([
                ':workId' => $WorkId,
                ':eventYear' => (int)$EventYear
            ]);
            $CaleRow = $CaleStmt->fetch();
            if ($CaleRow) {
                $CaleId = $CaleRow['caleid'];
            }
        }
        /** 元号自動判定: EndDateから年を抽出し、CalendarInfoと照合 */
        if (!empty($EndDate)) {
            $EndYear = preg_replace('/[^0-9]/', '', $EndDate);
            if (!empty($EndYear)) {
                $EndYear = substr($EndYear, 0, 4);
                $EndCaleSql = "
                    SELECT caleId
                    FROM CalendarInfo
                    WHERE workId = :workId
                      AND CAST(SUBSTRING(REGEXP_REPLACE(startYear, '[^0-9]', '', 'g') FROM 1 FOR 4) AS BIGINT) <= :endYear
                      AND CAST(SUBSTRING(REGEXP_REPLACE(endYear, '[^0-9]', '', 'g') FROM 1 FOR 4) AS BIGINT) >= :endYear
                    ORDER BY caleId ASC
                    LIMIT 1
                ";
                $EndCaleStmt = $Pdo->prepare($EndCaleSql);
                $EndCaleStmt->execute([
                    ':workId' => $WorkId,
                    ':endYear' => (int)$EndYear
                ]);
                $EndCaleRow = $EndCaleStmt->fetch();
                if ($EndCaleRow) {
                    $EndCaleId = $EndCaleRow['caleid'];
                }
            }
        }

        $EndDate = !empty($EndDate) ? $EndDate : null;
        $Content = !empty($Content) ? $Content : null;

        if (empty($TimeId)) {
            /** 新規登録 */
            $Sql = "
                INSERT INTO TimelineInfo (
                    workId, caleId, endCaleId, eventDate, endDate, title, content,
                    registDate, updateDate, registUser, updateUser
                ) VALUES (
                    :workId, :caleId, :endCaleId, :eventDate, :endDate, :title, :content,
                    CURRENT_TIMESTAMP(3), CURRENT_TIMESTAMP(3), :registUser, :updateUser
                )
                RETURNING timeId, updateDate
            ";

            $Stmt = $Pdo->prepare($Sql);
            $Stmt->execute([
                ':workId' => $WorkId,
                ':caleId' => $CaleId,
                ':endCaleId' => $EndCaleId,
                ':eventDate' => $EventDate,
                ':endDate' => $EndDate,
                ':title' => $Title,
                ':content' => $Content,
                ':registUser' => $UserId,
                ':updateUser' => $UserId
            ]);

            $Result = $Stmt->fetch();
            $NewTimeId = $Result['timeid'];
            $NewUpdateDate = $Result['updatedate'];
            // 更新履歴の詳細メッセージを生成（登録）
            $Detail = HistoryDetail::Register($Title);

        } else {
            /** 差分履歴用：更新前の表示値を取得 */
            $OldRowStmt = $Pdo->prepare("
                SELECT t.title, t.eventDate, t.endDate, t.content
                FROM TimelineInfo t
                WHERE t.timeId = :timeId AND t.workId = :workId
            ");
            $OldRowStmt->execute([':timeId' => $TimeId, ':workId' => $WorkId]);
            $OldRow = $OldRowStmt->fetch() ?: [];

            /** 更新 */
            $Sql = "
                UPDATE TimelineInfo SET
                    caleId = :caleId,
                    endCaleId = :endCaleId,
                    eventDate = :eventDate,
                    endDate = :endDate,
                    title = :title,
                    content = :content,
                    updateDate = CURRENT_TIMESTAMP(3),
                    updateUser = :updateUser
                WHERE timeId = :timeId AND workId = :workId AND updateDate = :updateDate
                RETURNING updateDate
            ";

            $Stmt = $Pdo->prepare($Sql);
            $Stmt->execute([
                ':timeId' => $TimeId,
                ':workId' => $WorkId,
                ':caleId' => $CaleId,
                ':endCaleId' => $EndCaleId,
                ':eventDate' => $EventDate,
                ':endDate' => $EndDate,
                ':title' => $Title,
                ':content' => $Content,
                ':updateUser' => $UserId,
                ':updateDate' => $UpdateDate
            ]);

            $UpdateResult = $Stmt->fetch();
            if (!$UpdateResult) {
                Database::Rollback();
                Response::ConflictError();
            }

            $NewTimeId = $TimeId;
            $NewUpdateDate = $UpdateResult['updatedate'];

            /* 更新履歴の詳細メッセージを生成（更新・列単位差分）
             * 元号（caleId/endCaleId）は日付から自動判定される派生値のため対象外。 */
            $Labels = [
                'title'          => 'タイトル',
                'eventDate'      => '発生時期',
                'endDate'        => '終了時期',
                'content'        => '内容',
            ];
            $OldDisplay = [
                'title'          => $OldRow['title']     ?? null,
                'eventDate'      => $OldRow['eventdate'] ?? null,
                'endDate'        => $OldRow['enddate']   ?? null,
                'content'        => $OldRow['content']   ?? null,
            ];
            $NewDisplay = [
                'title'          => $Title,
                'eventDate'      => $EventDate,
                'endDate'        => $EndDate,
                'content'        => $Content,
            ];
            $Detail = HistoryDetail::Update($Title, $OldDisplay, $NewDisplay, $Labels);

            /* 発生時期が動いた場合は、ぶら下がる年表詳細も同じ日数だけ移動する。
             * 終了時期のみが変わった場合も、親の期間から年表詳細がはみ出さないよう
             * 移動量0で丸め処理を通す。 */
            $ShiftDays = DateUtil::DiffDays($OldRow['eventdate'] ?? null, $EventDate) ?? 0;
            $IsEndDateChanged = (string) ($OldRow['enddate'] ?? '') !== (string) ($EndDate ?? '');
            if ($ShiftDays !== 0 || $IsEndDateChanged) {
                ShiftTimelineEventDates($Pdo, $WorkId, $TimeId, $ShiftDays, $EndDate, $UserId);
            }
        }

        /* チャプターの並び順を正規化
         * 年表ありカード同士の相対順序は年表の発生時期順が正のため、
         * 発生時期の変更・年表の追加に追従して並び順を更新する。 */
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
            'TimeId' => $NewTimeId,
            'UpdateDate' => $NewUpdateDate,
            'Message' => '年表を' . $Operation . 'しました'
        ]);

    } catch (Exception $E) {
        Database::Rollback();
        Logger::Error($FunctionName, '保存処理エラー', $UserId, $E->getMessage());
        Response::Error('年表の保存に失敗しました');
    }
}

/** API実行 */
SaveTimeline();
