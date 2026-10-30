<?php
/**
 * GetTimelineEventList.php
 * 年表詳細一覧取得API
 *
 * 年表設定（TimelineInfo）にぶら下がる年表詳細（TimelineEventInfo）を取得する。
 * 年表設定画面のツリー表示と年表詳細ダイアログの双方から使用するため、
 * TimeId の指定が無い場合は作品全体の年表詳細を返す。
 * あわせて、関連セクションのタグ選択で使用するキャラ・舞台・建物のリストも返す。
 */

require_once __DIR__ . '/../Common/Database.php';
require_once __DIR__ . '/../Common/Logger.php';
require_once __DIR__ . '/../Common/Response.php';
require_once __DIR__ . '/../Common/Auth.php';
// 関連要素（キャラ・舞台・建物）のJSON保持 共通処理
require_once __DIR__ . '/../Common/RelationJson.php';

// POSTメソッドのみ許可
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::Error('不正なリクエストです', 405);
}

/**
 * 年表詳細一覧を取得する
 */
function GetTimelineEventList(): void
{
    global $AuthUserId;
    global $AuthWorkId;
    $FunctionName = '年表詳細';
    $UserId = '';

    try {
        /** リクエストデータを取得 */
        $Input = Response::GetJsonInput();
        $WorkId = $AuthWorkId ?? '';
        $UserId = $AuthUserId;
        $TimeId = $Input['TimeId'] ?? null;

        Logger::Info($FunctionName, '一覧取得処理開始', $UserId);

        /** 入力チェック */
        if (empty($WorkId)) {
            Response::Error('作品IDが指定されていません');
            return;
        }

        /** DB接続 */
        $Pdo = Database::GetConnection();

        /** 年表詳細一覧を取得（親年表ごとに開始年月日の昇順） */
        $Sql = "
            SELECT
                timeEventId AS \"TimeEventId\",
                workId AS \"WorkId\",
                timeId AS \"TimeId\",
                title AS \"Title\",
                startDate AS \"StartDate\",
                startTime AS \"StartTime\",
                endDate AS \"EndDate\",
                endTime AS \"EndTime\",
                content AS \"Content\",
                charaText AS \"CharaText\",
                stageText AS \"StageText\",
                buildText AS \"BuildText\",
                registDate AS \"RegistDate\",
                updateDate AS \"UpdateDate\",
                registUser AS \"RegistUser\",
                updateUser AS \"UpdateUser\"
            FROM TimelineEventInfo
            WHERE workId = :workId
        ";
        $Params = [':workId' => $WorkId];
        if (!empty($TimeId)) {
            $Sql .= " AND timeId = :timeId";
            $Params[':timeId'] = $TimeId;
        }
        $Sql .= " ORDER BY timeId ASC, startDate ASC, startTime ASC NULLS FIRST, timeEventId ASC";

        $Stmt = $Pdo->prepare($Sql);
        $Stmt->execute($Params);
        $TimelineEventList = $Stmt->fetchAll();

        /*
         * 関連セクション（キャラ・舞台・建物）の名称解決
         *   各列は {"CharaInfo":[{"CharaId":..}]} 形式でIDのみを保持しているため、
         *   マスタと突き合わせて名称を付与する（マスタに無いIDは除外される）。
         */
        $CharaTexts = array_column($TimelineEventList, 'CharaText');
        $StageTexts = array_column($TimelineEventList, 'StageText');
        $BuildTexts = array_column($TimelineEventList, 'BuildText');

        $CharaNameMap = RelationJson::BuildNameMap(
            $Pdo, $WorkId, $CharaTexts, 'CharaInfo', 'CharaId', 'CharacterInfo', 'charaId', 'charaName'
        );
        $StageNameMap = RelationJson::BuildNameMap(
            $Pdo, $WorkId, $StageTexts, 'StageInfo', 'StageId', 'StageInfo', 'stageId', 'stageName'
        );
        $BuildNameMap = RelationJson::BuildNameMap(
            $Pdo, $WorkId, $BuildTexts, 'BuildInfo', 'BuildId', 'BuildInfo', 'buildId', 'buildName'
        );

        foreach ($TimelineEventList as &$Row) {
            $Row['CharaInfo'] = RelationJson::Resolve(
                $Row['CharaText'], $CharaNameMap, 'CharaInfo', 'CharaId', 'CharaName'
            );
            $Row['StageInfo'] = RelationJson::Resolve(
                $Row['StageText'], $StageNameMap, 'StageInfo', 'StageId', 'StageName'
            );
            $Row['BuildInfo'] = RelationJson::Resolve(
                $Row['BuildText'], $BuildNameMap, 'BuildInfo', 'BuildId', 'BuildName'
            );
            unset($Row['CharaText'], $Row['StageText'], $Row['BuildText']);
        }
        unset($Row);

        /** キャラリストを取得（関連セクションのタグ選択用） */
        $CharacterStmt = $Pdo->prepare("
            SELECT charaId AS \"CharaId\", charaName AS \"CharaName\"
            FROM CharacterInfo
            WHERE workId = :workId
            ORDER BY charaName ASC
        ");
        $CharacterStmt->execute([':workId' => $WorkId]);
        $CharacterList = $CharacterStmt->fetchAll();

        /** 舞台リストを取得（関連セクションのタグ選択用） */
        $StageStmt = $Pdo->prepare("
            SELECT stageId AS \"StageId\", stageName AS \"StageName\"
            FROM StageInfo
            WHERE workId = :workId
            ORDER BY stageName ASC
        ");
        $StageStmt->execute([':workId' => $WorkId]);
        $StageList = $StageStmt->fetchAll();

        /** 建物リストを取得（関連セクションのタグ選択用） */
        $BuildStmt = $Pdo->prepare("
            SELECT buildId AS \"BuildId\", buildName AS \"BuildName\"
            FROM BuildInfo
            WHERE workId = :workId
            ORDER BY buildName ASC
        ");
        $BuildStmt->execute([':workId' => $WorkId]);
        $BuildList = $BuildStmt->fetchAll();

        Logger::Info($FunctionName, '一覧取得処理終了', $UserId);

        Response::Success([
            'TimelineEventList' => $TimelineEventList,
            'CharacterList' => $CharacterList,
            'StageList' => $StageList,
            'BuildList' => $BuildList,
            'TotalCount' => count($TimelineEventList)
        ]);

    } catch (Exception $E) {
        Logger::Error($FunctionName, '一覧取得処理エラー', $UserId, $E->getMessage());
        Response::Error('年表詳細一覧の取得に失敗しました');
    }
}

/** API実行 */
GetTimelineEventList();
