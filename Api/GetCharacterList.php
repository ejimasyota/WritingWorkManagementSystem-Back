<?php
/* ==========================================================
 * キャラクター一覧取得
 * ----------------------------------------------------------
 * ・キャラクター一覧情報取得
 * ----------------------------------------------------------
 * 更新履歴：
 * 2026-01-11 作成
 * 2026-01-16 能力・武器・信念・好き嫌い列追加
 * 2026-08-12 二つ名・地雷・長所・短所列追加
 * 2026-08-31 武器の複数設定対応（weaponId → weapons）
 * ========================================================== */

/* ==========================================================
 * 1. 共通モジュール読み込み
 * ========================================================== */
// 1. データベース接続クラス
require_once __DIR__ . '/../Common/Database.php';
// 2. ログ出力クラス
require_once __DIR__ . '/../Common/Logger.php';
// 3. レスポンスクラス
require_once __DIR__ . '/../Common/Response.php';
require_once __DIR__ . '/../Common/Auth.php';

/* ==========================================================
 * 2. リクエストチェック
 * ========================================================== */
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    Response::Error('不正なリクエストです', 405);
}

/* ==========================================================
 * 3. 共通定義
 * ========================================================== */
// 1. 機能名
$FunctionName = 'キャラ設定';
// 2. ユーザーID
$UserId = '';

/* ==========================================================
 * 4. 処理
 * ========================================================== */
try {
   /* ---------------------------------------------
    *  1. リクエスト取得
    * --------------------------------------------- */
    // 1. 作品ID
    $WorkId = $AuthWorkId ?? '';
    // 2. ユーザーID
    $UserId = $AuthUserId;

   /* ---------------------------------------------
    *  2. 操作LOG書き込み
    * --------------------------------------------- */
    Logger::Info($FunctionName, '一覧取得処理開始', $UserId);

   /* ---------------------------------------------
    *  3. 作品IDバリデーションチェック
    * --------------------------------------------- */
    if (empty($WorkId)) {
        // 1. エラーレスポンス返却
        Response::Error('作品IDが指定されていません');
    }

   /* ---------------------------------------------
    *  4. DB接続
    * --------------------------------------------- */
    $Pdo = Database::GetConnection();

   /* ---------------------------------------------
    *  5. クエリを定義
    * --------------------------------------------- */
    $Sql = "
        SELECT
            c.charaId AS \"CharaId\",
            c.workId AS \"WorkId\",
            c.charaName AS \"CharaName\",
            c.gender AS \"Gender\",
            c.birthDate AS \"BirthDate\",
            c.age AS \"Age\",
            c.height AS \"Height\",
            c.weight AS \"Weight\",
            c.bloodType AS \"BloodType\",
            c.raceId AS \"RaceId\",
            c.raceName AS \"RaceName\",
            c.orgId AS \"OrgId\",
            c.orgName AS \"OrgName\",
            c.teamId AS \"TeamId\",
            c.teamName AS \"TeamName\",
            c.classId AS \"ClassId\",
            c.className AS \"ClassName\",
            c.powers AS \"Powers\",
            c.moves AS \"Moves\",
            c.weapons AS \"Weapons\",
            c.roleInfo AS \"RoleInfo\",
            c.personality AS \"Personality\",
            c.birthplaceId AS \"BirthplaceId\",
            c.birthplaceName AS \"BirthplaceName\",
            c.biography AS \"Biography\",
            c.belief AS \"Belief\",
            c.likes AS \"Likes\",
            c.dislikes AS \"Dislikes\",
            c.landmine AS \"Landmine\",
            c.strongPoint AS \"StrongPoint\",
            c.weakPoint AS \"WeakPoint\",
            c.note AS \"Note\",
            c.firstPerson AS \"FirstPerson\",
            c.secondPerson AS \"SecondPerson\",
            c.imgPath AS \"ImgPath\",
            c.imgList AS \"ImgList\",
            c.purpose AS \"Purpose\",
            c.deathDate AS \"DeathDate\",
            c.habit AS \"Habit\",
            c.verbalTic AS \"VerbalTic\",
            c.hobby AS \"Hobby\",
            c.causeOfDeath AS \"CauseOfDeath\",
            c.furigana AS \"Furigana\",
            c.nickname AS \"Nickname\",
            c.alias AS \"Alias\",
            c.hairColor AS \"HairColor\",
            c.eyeColor AS \"EyeColor\",
            c.skinColor AS \"SkinColor\",
            c.model AS \"Model\",
            c.appearanceRemarks AS \"AppearanceRemarks\",
            c.transition AS \"Transition\",
            c.pendingSetting AS \"PendingSetting\",
            c.tagText AS \"TagText\",
            c.registDate AS \"RegistDate\",
            c.updateDate AS \"UpdateDate\",
            c.registUser AS \"RegistUser\",
            c.updateUser AS \"UpdateUser\"
        FROM v_character_detail c
        WHERE c.workId = :workId
        ORDER BY c.registDate DESC
    ";

   /* ---------------------------------------------
    *  6. ステートメント定義
    * --------------------------------------------- */
    $Stmt = $Pdo->prepare($Sql);

   /* ---------------------------------------------
    *  7. パラメータ設定
    * --------------------------------------------- */
    $Stmt->bindValue(':workId', $WorkId);

   /* ---------------------------------------------
    *  8. 実行
    * --------------------------------------------- */
    $Stmt->execute();

   /* ---------------------------------------------
    *  9. 一覧取得
    * --------------------------------------------- */
    $CharacterList = $Stmt->fetchAll();

   /* ---------------------------------------------
    *  9-2. タグ情報の整合性付与（IN句で1回のみ問い合わせ・N+1回避）
    *      TagText列の{"TagInfo":[{"TagId":..}]}を、TagInfoマスタとJOINして
    *      {"TagInfo":[{"TagId":..,"TagName":..}]}に変換しレスポンスに含める。
    *      マスタに存在しないTagIdは含めない。
    * --------------------------------------------- */
    // 1. 全キャラのTagTextからユニークなTagIdを抽出
    $AllTagIds = [];
    $ParsedTagsByIndex = [];
    foreach ($CharacterList as $Idx => $Row) {
        $ParsedTagsByIndex[$Idx] = [];
        if (empty($Row['TagText'])) continue;
        $Decoded = json_decode($Row['TagText'], true);
        if (!is_array($Decoded) || empty($Decoded['TagInfo']) || !is_array($Decoded['TagInfo'])) continue;
        foreach ($Decoded['TagInfo'] as $T) {
            if (!is_array($T) || !isset($T['TagId'])) continue;
            $TagId = (int)$T['TagId'];
            $ParsedTagsByIndex[$Idx][] = $TagId;
            $AllTagIds[$TagId] = true;
        }
    }

    // 2. TagInfoマスタからTagId→{TagName,Description}のマップを取得（IN句で1回）
    $TagInfoMap = [];
    if (!empty($AllTagIds)) {
        $TagIdList = array_keys($AllTagIds);
        $Placeholders = implode(',', array_fill(0, count($TagIdList), '?'));
        $TagSql = "SELECT tagId, tagName, description FROM TagInfo WHERE workId = ? AND tagId IN ($Placeholders)";
        $TagStmt = $Pdo->prepare($TagSql);
        $TagStmt->execute(array_merge([$WorkId], $TagIdList));
        foreach ($TagStmt->fetchAll() as $TagRow) {
            $TagInfoMap[(int)$TagRow['tagid']] = [
                'TagName' => $TagRow['tagname'],
                'Description' => $TagRow['description'],
            ];
        }
    }

    // 3. 各キャラのTagInfoを構築してレスポンスに反映（TagTextは取り除く）
    foreach ($CharacterList as $Idx => &$Row) {
        $Tags = [];
        foreach ($ParsedTagsByIndex[$Idx] as $TagId) {
            if (isset($TagInfoMap[$TagId])) {
                $Tags[] = [
                    'TagId' => $TagId,
                    'TagName' => $TagInfoMap[$TagId]['TagName'],
                    'Description' => $TagInfoMap[$TagId]['Description'],
                ];
            }
        }
        $Row['TagInfo'] = empty($Tags)
            ? null
            : json_encode(['TagInfo' => $Tags], JSON_UNESCAPED_UNICODE);
        unset($Row['TagText']);
    }
    unset($Row);

   /* ---------------------------------------------
    *  10.操作LOG書き込み
    * --------------------------------------------- */
    Logger::Info($FunctionName, '一覧取得処理終了', $UserId);

   /* ---------------------------------------------
    *  11.レスポンス返却
    * --------------------------------------------- */
    Response::Success($CharacterList);

/* ==========================================================
 * 5. 例外処理
 * ========================================================== */
} catch (Exception $E) {
    // 1. エラーLOG書き込み
    Logger::Error($FunctionName, '一覧取得処理エラー', $UserId, $E->getMessage());
    // 2. エラーレスポンス返却
    Response::Error('キャラ一覧の取得に失敗しました');
}
