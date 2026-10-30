<?php
/**
 * GetChapterList.php
 * チャプター一覧取得API
 *
 * 参照は v_chapter_detail（関連年表の名称解決済みビュー）を使用する。
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
 * チャプター一覧を取得する
 */
function GetChapterList(): void
{
    global $AuthUserId;
    global $AuthWorkId;
    $FunctionName = 'チャプター設定';
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

        /** チャプター一覧を取得（並び順の昇順） */
        $Sql = "
            SELECT
                chapterId AS \"ChapterId\",
                workId AS \"WorkId\",
                chapterName AS \"ChapterName\",
                description AS \"Description\",
                timeId AS \"TimeId\",
                timelineTitle AS \"TimelineTitle\",
                timelineEventDate AS \"TimelineEventDate\",
                timelineEndDate AS \"TimelineEndDate\",
                charaText AS \"CharaText\",
                indexNum AS \"IndexNum\",
                registDate AS \"RegistDate\",
                updateDate AS \"UpdateDate\",
                registUser AS \"RegistUser\",
                updateUser AS \"UpdateUser\"
            FROM v_chapter_detail
            WHERE workId = :workId
            ORDER BY indexNum ASC, chapterId ASC
        ";
        $Stmt = $Pdo->prepare($Sql);
        $Stmt->execute([':workId' => $WorkId]);
        $ChapterList = $Stmt->fetchAll();

        /*
         * 関連キャラの解決
         *   charaText列の{"CharaInfo":[{"CharaId":..}]}を、CharacterInfoとJOINして
         *   {"CharaInfo":[{"CharaId":..,"CharaName":..}]}に変換しレスポンスに含める。
         *   キャラ設定画面のタグ情報（tagText）と同一の方式。
         */
        // 1. 全チャプターのCharaTextからユニークなCharaIdを抽出
        $CharaIds = [];
        foreach ($ChapterList as $Row) {
            if (empty($Row['CharaText'])) continue;
            $Decoded = json_decode($Row['CharaText'], true);
            if (!is_array($Decoded) || empty($Decoded['CharaInfo']) || !is_array($Decoded['CharaInfo'])) continue;
            foreach ($Decoded['CharaInfo'] as $C) {
                // UUID の形式でない値（改ざん・不正データ）を IN 句へ渡すと型変換エラーで一覧全体が取得できなくなるため除外する
                if (is_array($C) && isset($C['CharaId']) && is_string($C['CharaId'])
                    && preg_match('/\A[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}\z/', $C['CharaId'])) {
                    $CharaIds[$C['CharaId']] = true;
                }
            }
        }

        // 2. CharacterInfoからCharaId→CharaNameのマップを取得（IN句で1回）
        $CharaNameMap = [];
        if (!empty($CharaIds)) {
            $IdList = array_keys($CharaIds);
            $Placeholders = implode(',', array_fill(0, count($IdList), '?'));
            $CharaSql = "SELECT charaId, charaName FROM CharacterInfo WHERE workId = ? AND charaId IN ($Placeholders)";
            $CharaStmt = $Pdo->prepare($CharaSql);
            $CharaStmt->execute(array_merge([$WorkId], $IdList));
            foreach ($CharaStmt->fetchAll() as $CharaRow) {
                $CharaNameMap[(string) $CharaRow['charaid']] = $CharaRow['charaname'];
            }
        }

        // 3. 各チャプターのCharaInfoを構築してレスポンスに反映（CharaTextは取り除く）
        foreach ($ChapterList as &$Row) {
            $Charas = [];
            $Decoded = !empty($Row['CharaText']) ? json_decode($Row['CharaText'], true) : null;
            if (is_array($Decoded) && !empty($Decoded['CharaInfo']) && is_array($Decoded['CharaInfo'])) {
                foreach ($Decoded['CharaInfo'] as $C) {
                    $CharaId = (string) ($C['CharaId'] ?? '');
                    // マスタに存在しないキャラ（削除済み）は表示対象から除外
                    if ($CharaId !== '' && isset($CharaNameMap[$CharaId])) {
                        $Charas[] = [
                            'CharaId' => $CharaId,
                            'CharaName' => $CharaNameMap[$CharaId],
                        ];
                    }
                }
            }
            $Row['CharaInfo'] = empty($Charas)
                ? null
                : json_encode(['CharaInfo' => $Charas], JSON_UNESCAPED_UNICODE);
            unset($Row['CharaText']);
        }
        unset($Row);

        /** 年表リストを取得（関連年表プルダウン用） */
        $TimelineSql = "
            SELECT
                timeId AS \"TimeId\",
                title AS \"Title\",
                eventDate AS \"EventDate\"
            FROM TimelineInfo
            WHERE workId = :workId
            ORDER BY eventDate ASC, timeId ASC
        ";
        $TimelineStmt = $Pdo->prepare($TimelineSql);
        $TimelineStmt->execute([':workId' => $WorkId]);
        $TimelineList = $TimelineStmt->fetchAll();

        /** キャラリストを取得（関連キャラ選択ダイアログ用） */
        $CharacterSql = "
            SELECT
                charaId AS \"CharaId\",
                charaName AS \"CharaName\"
            FROM CharacterInfo
            WHERE workId = :workId
            ORDER BY charaName ASC
        ";
        $CharacterStmt = $Pdo->prepare($CharacterSql);
        $CharacterStmt->execute([':workId' => $WorkId]);
        $CharacterList = $CharacterStmt->fetchAll();

        Logger::Info($FunctionName, '一覧取得処理終了', $UserId);

        Response::Success([
            'ChapterList' => $ChapterList,
            'TimelineList' => $TimelineList,
            'CharacterList' => $CharacterList,
            'TotalCount' => count($ChapterList)
        ]);

    } catch (Exception $E) {
        Logger::Error($FunctionName, '一覧取得処理エラー', $UserId, $E->getMessage());
        Response::Error('チャプター一覧の取得に失敗しました');
    }
}

/** API実行 */
GetChapterList();
