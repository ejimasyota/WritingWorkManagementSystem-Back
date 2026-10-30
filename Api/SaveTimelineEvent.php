<?php
/**
 * SaveTimelineEvent.php
 * 年表詳細 登録・更新API
 *
 * 年表設定（TimelineInfo）にぶら下がる年表詳細（TimelineEventInfo）を保存する。
 * 関連セクション（キャラ・舞台・建物）は中間テーブルを設けず、
 * chapterinfo.charaText と同じくJSONでIDのみを保持する。
 */

require_once __DIR__ . '/../Common/Database.php';
require_once __DIR__ . '/../Common/Logger.php';
require_once __DIR__ . '/../Common/Response.php';
require_once __DIR__ . '/../Common/Auth.php';
// 更新履歴 詳細メッセージ生成
require_once __DIR__ . '/../Common/HistoryDetail.php';
// 日付文字列の前後比較
require_once __DIR__ . '/../Common/DateUtil.php';
// 関連要素（キャラ・舞台・建物）のJSON保持 共通処理
require_once __DIR__ . '/../Common/RelationJson.php';

// POSTメソッドのみ許可
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::Error('不正なリクエストです', 405);
}

/**
 * 「日付 + 時分」の前後関係を比較する
 *   日付の粒度が異なる場合は DateUtil::Compare の規則（粗い方に合わせる）に従い、
 *   日付が同値と判定された場合にのみ時分を比較する。
 *
 * @param string|null $LeftDate  比較元の日付
 * @param string|null $LeftTime  比較元の時分（HH:MM）
 * @param string|null $RightDate 比較先の日付
 * @param string|null $RightTime 比較先の時分（HH:MM）
 * @return int|null 負:比較元が過去 / 0:同じ / 正:比較元が未来。比較できない場合は null
 */
function CompareTimelineEventMoment(?string $LeftDate, ?string $LeftTime, ?string $RightDate, ?string $RightTime): ?int
{
    $DateCompare = DateUtil::Compare($LeftDate, $RightDate);
    if ($DateCompare === null || $DateCompare !== 0) {
        return $DateCompare;
    }
    /* 時分はどちらか一方でも未設定なら、日付までで同値とみなす */
    if (empty($LeftTime) || empty($RightTime)) {
        return 0;
    }
    /* HH:MM は0詰めのため、文字列の大小がそのまま時刻の前後になる */
    return $LeftTime <=> $RightTime;
}

/**
 * 年表詳細を登録・更新する
 */
function SaveTimelineEvent(): void
{
    global $AuthUserId;
    global $AuthWorkId;
    $FunctionName = '年表詳細';
    $UserId = '';

    try {
        /** リクエストデータを取得 */
        $Input = Response::GetJsonInput();
        $TimeEventId = $Input['TimeEventId'] ?? null;
        $TimeId = $Input['TimeId'] ?? null;
        $WorkId = $AuthWorkId ?? '';
        $Title = trim((string) ($Input['Title'] ?? ''));
        $StartDate = trim((string) ($Input['StartDate'] ?? ''));
        $StartTime = trim((string) ($Input['StartTime'] ?? ''));
        $EndDate = trim((string) ($Input['EndDate'] ?? ''));
        $EndTime = trim((string) ($Input['EndTime'] ?? ''));
        $Content = trim((string) ($Input['Content'] ?? ''));
        $UserId = $AuthUserId;
        $UpdateDate = $Input['UpdateDate'] ?? null;

        $Operation = empty($TimeEventId) ? '登録' : '更新';
        // 更新履歴の詳細メッセージ（登録/更新分岐でそれぞれ生成）
        $Detail = null;
        Logger::Info($FunctionName, $Operation . '処理開始', $UserId);

        /** 入力チェック（必須） */
        if (empty($WorkId)) {
            Response::Error('作品IDが指定されていません');
            return;
        }
        if (empty($TimeId)) {
            Response::Error('親となる年表が指定されていません');
            return;
        }
        if ($Title === '') {
            Response::Error('出来事タイトルを入力してください');
            return;
        }
        if (mb_strlen($Title) > 30) {
            Response::Error('出来事タイトルは30桁以内で入力してください');
            return;
        }
        if ($StartDate === '') {
            Response::Error('開始年月日を入力してください');
            return;
        }
        if ($Content === '') {
            Response::Error('内容を入力してください');
            return;
        }

        $StartTime = $StartTime !== '' ? $StartTime : null;
        $EndDate = $EndDate !== '' ? $EndDate : null;
        $EndTime = $EndDate !== null && $EndTime !== '' ? $EndTime : null;

        /** 入力チェック（論理・終了年月日時分 < 開始年月日時分） */
        if ($EndDate !== null) {
            $Compare = CompareTimelineEventMoment($EndDate, $EndTime, $StartDate, $StartTime);
            if ($Compare !== null && $Compare < 0) {
                Response::Error('終了年月日は開始年月日以降を設定してください');
                return;
            }
        }

        /** DB接続 */
        $Pdo = Database::GetConnection();
        Database::BeginTransaction();

        /** 親年表の存在確認と、期間の範囲チェック用の値を取得 */
        $ParentStmt = $Pdo->prepare("
            SELECT title, eventDate, endDate
            FROM TimelineInfo
            WHERE timeId = :timeId AND workId = :workId
        ");
        $ParentStmt->execute([':timeId' => $TimeId, ':workId' => $WorkId]);
        $Parent = $ParentStmt->fetch();
        if (!$Parent) {
            Database::Rollback();
            Response::Error('親となる年表が見つかりません');
            return;
        }

        /* 入力チェック（論理・親の年月日を超えていないか）
         *   親の発生時期より前、または親の終了時期より後の期間は、
         *   親の出来事の内訳として成立しないため許可しない。 */
        $StartVsParent = DateUtil::Compare($StartDate, $Parent['eventdate']);
        if ($StartVsParent !== null && $StartVsParent < 0) {
            Database::Rollback();
            Response::Error('開始年月日は年表の発生時期以降を設定してください');
            return;
        }
        if (!empty($Parent['enddate'])) {
            $CheckDate = $EndDate !== null ? $EndDate : $StartDate;
            $EndVsParent = DateUtil::Compare($CheckDate, $Parent['enddate']);
            if ($EndVsParent !== null && $EndVsParent > 0) {
                Database::Rollback();
                Response::Error('終了年月日は年表の終了時期以前を設定してください');
                return;
            }
        }

        /* 関連セクションの正規化
         *   JSON保存形式: {"CharaInfo":[{"CharaId":".."}]} / StageInfo / BuildInfo */
        $CharaText = RelationJson::Encode($Input['CharaInfo'] ?? null, 'CharaInfo', 'CharaId');
        $StageText = RelationJson::Encode($Input['StageInfo'] ?? null, 'StageInfo', 'StageId');
        $BuildText = RelationJson::Encode($Input['BuildInfo'] ?? null, 'BuildInfo', 'BuildId');

        /* 関連セクションの検証
         *   件数上限（RelationJson::MAX_IDS_PER_KIND）・IDの形式・同一作品内の存在を確認する。
         *   形式不正や他作品のIDを保存すると、一覧取得・Word出力が失敗する／他作品の名称が
         *   参照できてしまうため、保存前にサーバーサイドで検証する。 */
        $RelationChecks = [
            [$CharaText, 'CharaInfo', 'CharaId', 'CharacterInfo', 'charaId', '関連キャラ'],
            [$StageText, 'StageInfo', 'StageId', 'StageInfo',     'stageId', '関連舞台'],
            [$BuildText, 'BuildInfo', 'BuildId', 'BuildInfo',     'buildId', '関連建物'],
        ];
        foreach ($RelationChecks as [$RelationText, $ListKey, $IdKey, $Table, $IdColumn, $Label]) {
            $RelationError = RelationJson::ValidateIds($Pdo, $WorkId, $RelationText, $ListKey, $IdKey, $Table, $IdColumn, $Label);
            if ($RelationError !== null) {
                Database::Rollback();
                Response::Error($RelationError);
                return;
            }
        }

        if (empty($TimeEventId)) {
            /** 新規登録 */
            $Stmt = $Pdo->prepare("
                INSERT INTO TimelineEventInfo (
                    workId, timeId, title, startDate, startTime, endDate, endTime, content,
                    charaText, stageText, buildText,
                    registDate, updateDate, registUser, updateUser
                ) VALUES (
                    :workId, :timeId, :title, :startDate, :startTime, :endDate, :endTime, :content,
                    :charaText, :stageText, :buildText,
                    CURRENT_TIMESTAMP(3), CURRENT_TIMESTAMP(3), :registUser, :updateUser
                )
                RETURNING timeEventId, updateDate
            ");
            $Stmt->execute([
                ':workId' => $WorkId,
                ':timeId' => $TimeId,
                ':title' => $Title,
                ':startDate' => $StartDate,
                ':startTime' => $StartTime,
                ':endDate' => $EndDate,
                ':endTime' => $EndTime,
                ':content' => $Content,
                ':charaText' => $CharaText,
                ':stageText' => $StageText,
                ':buildText' => $BuildText,
                ':registUser' => $UserId,
                ':updateUser' => $UserId
            ]);

            $Result = $Stmt->fetch();
            $NewTimeEventId = $Result['timeeventid'];
            $NewUpdateDate = $Result['updatedate'];
            // 更新履歴の詳細メッセージを生成（登録）
            $Detail = HistoryDetail::Register($Title);

        } else {
            /** 差分履歴用：更新前の値を取得 */
            $OldRowStmt = $Pdo->prepare("
                SELECT title, startDate, startTime, endDate, endTime, content,
                       charaText, stageText, buildText
                FROM TimelineEventInfo
                WHERE timeEventId = :timeEventId AND workId = :workId
            ");
            $OldRowStmt->execute([':timeEventId' => $TimeEventId, ':workId' => $WorkId]);
            $OldRow = $OldRowStmt->fetch() ?: [];

            /** 更新 */
            $Stmt = $Pdo->prepare("
                UPDATE TimelineEventInfo SET
                    timeId = :timeId,
                    title = :title,
                    startDate = :startDate,
                    startTime = :startTime,
                    endDate = :endDate,
                    endTime = :endTime,
                    content = :content,
                    charaText = :charaText,
                    stageText = :stageText,
                    buildText = :buildText,
                    updateDate = CURRENT_TIMESTAMP(3),
                    updateUser = :updateUser
                WHERE timeEventId = :timeEventId AND workId = :workId AND updateDate = :updateDate
                RETURNING updateDate
            ");
            $Stmt->execute([
                ':timeEventId' => $TimeEventId,
                ':workId' => $WorkId,
                ':timeId' => $TimeId,
                ':title' => $Title,
                ':startDate' => $StartDate,
                ':startTime' => $StartTime,
                ':endDate' => $EndDate,
                ':endTime' => $EndTime,
                ':content' => $Content,
                ':charaText' => $CharaText,
                ':stageText' => $StageText,
                ':buildText' => $BuildText,
                ':updateUser' => $UserId,
                ':updateDate' => $UpdateDate
            ]);

            $UpdateResult = $Stmt->fetch();
            if (!$UpdateResult) {
                Database::Rollback();
                Response::ConflictError();
            }

            $NewTimeEventId = $TimeEventId;
            $NewUpdateDate = $UpdateResult['updatedate'];

            /* 更新履歴の詳細メッセージを生成（更新・列単位差分）
             * 関連セクションはIDのままでは非可読なため、名称のカンマ区切りへ変換する。 */
            $CharaNameMap = RelationJson::BuildNameMap(
                $Pdo, $WorkId, [$OldRow['charatext'] ?? null, $CharaText],
                'CharaInfo', 'CharaId', 'CharacterInfo', 'charaId', 'charaName'
            );
            $StageNameMap = RelationJson::BuildNameMap(
                $Pdo, $WorkId, [$OldRow['stagetext'] ?? null, $StageText],
                'StageInfo', 'StageId', 'StageInfo', 'stageId', 'stageName'
            );
            $BuildNameMap = RelationJson::BuildNameMap(
                $Pdo, $WorkId, [$OldRow['buildtext'] ?? null, $BuildText],
                'BuildInfo', 'BuildId', 'BuildInfo', 'buildId', 'buildName'
            );

            $Labels = [
                'title'     => '出来事タイトル',
                'startDate' => '開始年月日',
                'startTime' => '開始時分',
                'endDate'   => '終了年月日',
                'endTime'   => '終了時分',
                'content'   => '内容',
                'charaText' => '関連キャラ',
                'stageText' => '関連舞台',
                'buildText' => '関連建物',
            ];
            $OldDisplay = [
                'title'     => $OldRow['title']     ?? null,
                'startDate' => $OldRow['startdate'] ?? null,
                'startTime' => $OldRow['starttime'] ?? null,
                'endDate'   => $OldRow['enddate']   ?? null,
                'endTime'   => $OldRow['endtime']   ?? null,
                'content'   => $OldRow['content']   ?? null,
                'charaText' => RelationJson::ToDisplayText($OldRow['charatext'] ?? null, $CharaNameMap, 'CharaInfo', 'CharaId'),
                'stageText' => RelationJson::ToDisplayText($OldRow['stagetext'] ?? null, $StageNameMap, 'StageInfo', 'StageId'),
                'buildText' => RelationJson::ToDisplayText($OldRow['buildtext'] ?? null, $BuildNameMap, 'BuildInfo', 'BuildId'),
            ];
            $NewDisplay = [
                'title'     => $Title,
                'startDate' => $StartDate,
                'startTime' => $StartTime,
                'endDate'   => $EndDate,
                'endTime'   => $EndTime,
                'content'   => $Content,
                'charaText' => RelationJson::ToDisplayText($CharaText, $CharaNameMap, 'CharaInfo', 'CharaId'),
                'stageText' => RelationJson::ToDisplayText($StageText, $StageNameMap, 'StageInfo', 'StageId'),
                'buildText' => RelationJson::ToDisplayText($BuildText, $BuildNameMap, 'BuildInfo', 'BuildId'),
            ];
            $Detail = HistoryDetail::Update($Title, $OldDisplay, $NewDisplay, $Labels);
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

        /** 更新履歴を登録（変更が無い更新は履歴に残す情報が無いため登録しない） */
        if (HistoryDetail::HasChanges($Detail)) {
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
                ':operation' => $Operation,
                ':detail' => $Detail,
                ':registUser' => $UserId,
                ':updateUser' => $UserId
            ]);
        }

        Database::Commit();

        Logger::Info($FunctionName, $Operation . '処理終了', $UserId);

        Response::Success([
            'TimeEventId' => $NewTimeEventId,
            'UpdateDate' => $NewUpdateDate,
            'Message' => '年表詳細を' . $Operation . 'しました'
        ]);

    } catch (Exception $E) {
        Database::Rollback();
        Logger::Error($FunctionName, '保存処理エラー', $UserId, $E->getMessage());
        Response::Error('年表詳細の保存に失敗しました');
    }
}

/** API実行 */
SaveTimelineEvent();
