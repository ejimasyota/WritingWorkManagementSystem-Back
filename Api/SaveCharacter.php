<?php
/* ==========================================================
 * キャラ情報更新
 * ----------------------------------------------------------
 * ・キャラ情報を更新する
 * ----------------------------------------------------------
 * 更新履歴：
 * 2026-01-10 作成
 * 2026-01-16 能力・武器・信念・好き嫌い列追加
 * 2026-08-12 二つ名列追加、好き嫌い・地雷・長所・短所をJSON形式へ変更
 * 2026-08-31 武器の複数設定対応（weaponId → weapons のJSON形式へ変更）
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
// 画像一覧（画像の複数登録）の共通処理（ファイル操作の安全化 FileSafety も読み込む）
require_once __DIR__ . '/../Common/ImageList.php';
require_once __DIR__ . '/../Common/Auth.php';
// 更新履歴 詳細メッセージ生成
require_once __DIR__ . '/../Common/HistoryDetail.php';
// コード区分値 → 名称変換
require_once __DIR__ . '/../Common/CodeLabel.php';
// 8. キャラクターの表形式項目（JSON保持）の共通処理
require_once __DIR__ . '/../Common/EntryTable.php';

/* ==========================================================
 * 2. リクエストチェック
 * ========================================================== */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    // 1. 例外レスポンスを返す
    Response::Error('不正なリクエストです', 405);
}

/* ==========================================================
 * 3. 共通定義
 * ========================================================== */
// 1. 機能名
$FunctionName = 'キャラ設定';
// 2. ユーザーID
$UserId = '';

/**
 * 能力・武器・技のJSON（IDのみ保持）を一意なIDの配列へ変換する
 * 数値以外・重複・空のIDは無視する
 * @param string $Key   JSONのルート要素名（Powers / Weapons / Moves）
 * @param string $IdKey 各行が持つID項目名（PowerId / WeaponId / MoveId）
 * @param string|null $Value フロントから受け取ったJSON文字列
 * @return array<string> IDの配列（入力順を維持する）
 */
function ParseCharaSkillIds($Key, $IdKey, $Value) {
    // 1. 未設定は空とする
    if ($Value === null || trim((string) $Value) === '') {
        return [];
    }
    // 2. JSONとして解釈できない場合は空とする（IDのみ保持のため復元できない）
    $Decoded = json_decode((string) $Value, true);
    if (!is_array($Decoded) || !isset($Decoded[$Key]) || !is_array($Decoded[$Key])) {
        return [];
    }
    // 3. 数値のIDのみを重複を除いて取り出す
    $Ids = [];
    foreach ($Decoded[$Key] as $Row) {
        if (!is_array($Row) || !isset($Row[$IdKey])) {
            continue;
        }
        $Id = trim((string) $Row[$IdKey]);
        if ($Id === '' || !ctype_digit($Id) || in_array($Id, $Ids, true)) {
            continue;
        }
        $Ids[] = $Id;
    }
    return $Ids;
}

/**
 * IDの配列をDB保存用のJSON文字列へ変換する
 * 妥当性確認を通したIDのみを格納するため、保存値は本関数の戻り値を使用する
 * @param string $Key   JSONのルート要素名（Powers / Weapons / Moves）
 * @param string $IdKey 各行が持つID項目名（PowerId / WeaponId / MoveId）
 * @param array<string> $Ids IDの配列
 * @return string|null JSON文字列（1件も無い場合はNULL）
 */
function BuildCharaSkillJson($Key, $IdKey, array $Ids) {
    if (count($Ids) === 0) {
        return null;
    }
    $Rows = [];
    foreach ($Ids as $Id) {
        $Rows[] = [$IdKey => (int) $Id];
    }
    return json_encode([$Key => $Rows], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/**
 * 能力・武器・技のIDの配列を更新履歴用の名称一覧へ整形する
 * @param array<string> $Ids IDの配列
 * @param array<string,string> $NameMap ID => 名称 の対応
 * @return string|null 「名称、名称」形式の文字列（1件も無い場合はNULL）
 */
function FormatCharaSkillForHistory(array $Ids, array $NameMap) {
    $Parts = [];
    foreach ($Ids as $Id) {
        $Parts[] = $NameMap[(string) $Id] ?? ('ID:' . $Id);
    }
    return (count($Parts) === 0) ? null : implode('、', $Parts);
}

/**
 * 関連ID（種族・組織・チーム・階級・出身地）が同一作品内に存在するかを確認する
 * 他作品のIDを保存されると、作品条件の無い結合（一覧API・ビュー）経由で
 * 他作品の名称が参照できてしまうため、保存前にサーバーサイドで検証する。
 * @param PDO    $Pdo    DB接続
 * @param string $Table  マスタのテーブル名（固定リテラルのみ）
 * @param string $IdCol  マスタのID列名（固定リテラルのみ）
 * @param mixed  $Id     検証するID（bigint）
 * @param string $WorkId 作品ID
 * @return bool 同一作品内に存在する場合 true
 */
function ExistsCharaRelationId(PDO $Pdo, $Table, $IdCol, $Id, $WorkId) {
    // 1. 数値以外のIDは型変換エラーとなるため、クエリを発行せずに不正とする
    if (!is_scalar($Id) || !preg_match('/\A[1-9][0-9]{0,18}\z/', (string) $Id)) {
        return false;
    }
    // 2. 同一作品内の存在確認
    $Stmt = $Pdo->prepare("SELECT 1 FROM {$Table} WHERE {$IdCol} = :id AND workId = :workId");
    $Stmt->bindValue(':id', (string) $Id, PDO::PARAM_STR);
    $Stmt->bindValue(':workId', $WorkId, PDO::PARAM_STR);
    $Stmt->execute();
    return $Stmt->fetchColumn() !== false;
}

/* ==========================================================
 * 4. 処理
 * ========================================================== */
try {
   /* ---------------------------------------------
    *  1. リクエスト取得
    * --------------------------------------------- */ 
    $Input = Response::GetJsonInput();
    
   /* ---------------------------------------------
    *  2. リクエスト内容定義
    * --------------------------------------------- */ 
    // 1. キャラID
    $CharaId      = $Input['CharaId'] ?? null;
    // 2. 作品ID
    $WorkId       = $AuthWorkId ?? '';
    // 3. キャラ名
    $CharaName    = $Input['CharaName'] ?? '';
    // 4. 性別
    $Gender       = $Input['Gender'] ?? 0;
    // 5. 生年月日
    $BirthDate    = $Input['BirthDate'] ?? null;
    // 6. 年齢
    $Age          = $Input['Age'] ?? null;
    // 7. 身長
    $Height       = $Input['Height'] ?? null;
    // 8. 体重
    $Weight       = $Input['Weight'] ?? null;
    // 9. 血液型
    $BloodType    = $Input['BloodType'] ?? null;
    // 10.種族ID
    $RaceId       = $Input['RaceId'] ?? null;
    // 11.組織ID
    $OrgId        = $Input['OrgId'] ?? null;
    // 12.チームID
    $TeamId       = $Input['TeamId'] ?? null;
    // 13.階級ID
    $ClassId      = $Input['ClassId'] ?? null;
    // 14.役割
    $RoleInfo     = $Input['RoleInfo'] ?? null;
    // 15.性格
    $Personality  = $Input['Personality'] ?? null;
    // 15-2.出身地（舞台ID）
    $BirthplaceId = $Input['BirthplaceId'] ?? null;
    // 16.生い立ち・経歴
    $Biography    = $Input['Biography'] ?? null;
    // 17.一人称
    $FirstPerson  = $Input['FirstPerson'] ?? null;
    // 18.二人称
    $SecondPerson = $Input['SecondPerson'] ?? null;
    // 19.ユーザーID
    $UserId = $AuthUserId;
    // 20.画像一覧（[{Path:既存画像のパス, Data:新規画像のデータURL, Caption:説明文}]。先頭が代表画像）
    //    指定が無い場合（配列でない場合）は画像を変更しない
    $Images       = $Input['Images'] ?? null;
    // 21.目的
    $Purpose      = $Input['Purpose'] ?? null;
    // 23.能力ID
    $Powers       = $Input['Powers'] ?? null;
    // 24.武器ID
    $Weapons      = $Input['Weapons'] ?? null;
    // 25.信念
    $Belief       = $Input['Belief'] ?? null;
    // 26.好きな物
    $Likes        = $Input['Likes'] ?? null;
    // 27.嫌いな物
    $Dislikes     = $Input['Dislikes'] ?? null;
    // 28.備考
    $Note         = $Input['Note'] ?? null;
    // 29.没年月日
    $DeathDate    = $Input['DeathDate'] ?? null;
    // 30.癖
    $Habit        = $Input['Habit'] ?? null;
    // 31.口癖
    $VerbalTic    = $Input['VerbalTic'] ?? null;
    // 32.趣味
    $Hobby        = $Input['Hobby'] ?? null;
    // 32-2.死因
    $CauseOfDeath = $Input['CauseOfDeath'] ?? null;
    // 32-3.変遷（JSON）
    $Transition     = $Input['Transition'] ?? null;
    // 32-4.検討中・未確定の設定
    $PendingSetting = $Input['PendingSetting'] ?? null;
    // 33.フリガナ
    $Furigana     = $Input['Furigana'] ?? null;
    // 34.ニックネーム
    $Nickname     = $Input['Nickname'] ?? null;
    // 34-2.二つ名
    $Alias        = $Input['Alias'] ?? null;
    // 34-3.地雷
    $Landmine     = $Input['Landmine'] ?? null;
    // 34-4.長所
    $StrongPoint  = $Input['StrongPoint'] ?? null;
    // 34-5.短所
    $WeakPoint    = $Input['WeakPoint'] ?? null;
    // 35.更新年月日（排他制御用）
    $UpdateDate   = $Input['UpdateDate'] ?? null;
    // 36.髪色
    $HairColor    = $Input['HairColor'] ?? null;
    // 37.目の色
    $EyeColor     = $Input['EyeColor'] ?? null;
    // 38.肌の色
    $SkinColor    = $Input['SkinColor'] ?? null;
    // 39.モデル
    $Model        = $Input['Model'] ?? null;
    // 40.身体備考
    $AppearanceRemarks = $Input['AppearanceRemarks'] ?? null;
    // 41.技ID
    $Moves        = $Input['Moves'] ?? null;
    // 42.タグ情報（フロントから [{TagId:1},{TagId:2}] 形式で送られる）
    $TagInfoInput = $Input['TagInfo'] ?? null;

   /* ---------------------------------------------
    *  2-2. タグ情報JSON文字列の正規化（共通ルール）
    * --------------------------------------------- */
    // 1. JSON保存形式: {"TagInfo":[{"TagId":1},{"TagId":2}]}
    // 2. タグ未指定時はNULLで保存
    $TagText = null;
    if (is_array($TagInfoInput) && count($TagInfoInput) > 0) {
        $NormalizedTags = [];
        foreach ($TagInfoInput as $T) {
            if (is_array($T) && isset($T['TagId']) && $T['TagId'] !== '' && $T['TagId'] !== null) {
                $NormalizedTags[] = ['TagId' => (int)$T['TagId']];
            }
        }
        if (count($NormalizedTags) > 0) {
            $TagText = json_encode(['TagInfo' => $NormalizedTags], JSON_UNESCAPED_UNICODE);
        }
    }

   /* ---------------------------------------------
    *  2-3. 表形式で保持する項目のJSON文字列を正規化
    *      好きな物・嫌いな物・癖・趣味・一人称・二人称は移行前のフリーテキストが
    *      送られる可能性があるため、EntryTable 側で1行の表へ変換する
    * --------------------------------------------- */
    $Likes        = EntryTable::NormalizeJson('Character', 'Likes',        $Likes);
    $Dislikes     = EntryTable::NormalizeJson('Character', 'Dislikes',     $Dislikes);
    $Landmine     = EntryTable::NormalizeJson('Character', 'Landmine',     $Landmine);
    $StrongPoint  = EntryTable::NormalizeJson('Character', 'StrongPoint',  $StrongPoint);
    $WeakPoint    = EntryTable::NormalizeJson('Character', 'WeakPoint',    $WeakPoint);
    $Habit        = EntryTable::NormalizeJson('Character', 'Habit',        $Habit);
    $Hobby        = EntryTable::NormalizeJson('Character', 'Hobby',        $Hobby);
    $FirstPerson  = EntryTable::NormalizeJson('Character', 'FirstPerson',  $FirstPerson);
    $SecondPerson = EntryTable::NormalizeJson('Character', 'SecondPerson', $SecondPerson);
    $Transition   = EntryTable::NormalizeJson('Character', 'Transition',   $Transition);

   /* ---------------------------------------------
    *  3. 操作設定
    * --------------------------------------------- */
    $Operation = empty($CharaId) ? '登録' : '更新';
    // 更新履歴の詳細メッセージ（登録/更新分岐でそれぞれ生成）
    $Detail = null;

   /* ---------------------------------------------
    *  4. 操作ログ出力
    * --------------------------------------------- */
    Logger::Info($FunctionName, $Operation . '処理開始', $UserId);

   /* ---------------------------------------------
    *  5. 作品IDバリデーションチェック
    * --------------------------------------------- */
    if (empty($WorkId)) {
        // 1. エラーレスポンスを返す
        Response::Error('作品IDが指定されていません');
    }

   /* ---------------------------------------------
    *  6. DB接続
    * --------------------------------------------- */
    // 1. 接続開始
    $Pdo = Database::GetConnection();
    // 2. トランザクション開始
    Database::BeginTransaction();

   /* ---------------------------------------------
    *  7. 空文字をNULLに変換
    * --------------------------------------------- */
    // 1. キャラID
    $CharaId      = !empty($CharaId) ? $CharaId : null;
    // 2. 生年月日
    $BirthDate    = !empty($BirthDate) ? $BirthDate : null;
    // 3. 年齢
    $Age          = !empty($Age) ? $Age : null;
    // 4. 身長
    $Height       = !empty($Height) ? $Height : null;
    // 5. 体重
    $Weight       = !empty($Weight) ? $Weight : null;
    // 6. 血液型
    $BloodType    = !empty($BloodType) ? $BloodType : null;
    // 7. 種族ID
    $RaceId       = !empty($RaceId) ? $RaceId : null;
    // 8. 組織ID
    $OrgId        = !empty($OrgId) ? $OrgId : null;
    // 9. チームID
    $TeamId       = !empty($TeamId) ? $TeamId : null;
    // 10.階級ID
    $ClassId      = !empty($ClassId) ? $ClassId : null;
    // 11.役割
    $RoleInfo     = !empty($RoleInfo) ? $RoleInfo : null;
    // 12.性格
    $Personality  = !empty($Personality) ? $Personality : null;
    // 12-2.出身地（舞台ID）
    $BirthplaceId = !empty($BirthplaceId) ? $BirthplaceId : null;
    // 13.生い立ち・経歴
    $Biography    = !empty($Biography) ? $Biography : null;
    // 14.一人称・15.二人称（JSONで保持するため 2-3. で正規化済み）
    // 16.目的
    $Purpose      = !empty($Purpose) ? $Purpose : null;
    // 17.能力ID
    // 能力・武器・技はJSONからIDの配列へ変換したうえで妥当性確認を行う（7-2.）
    $PowerIds     = ParseCharaSkillIds('Powers', 'PowerId', $Powers);
    // 18.武器ID
    $WeaponIds    = ParseCharaSkillIds('Weapons', 'WeaponId', $Weapons);
    // 19.信念
    $Belief       = !empty($Belief) ? $Belief : null;
    // 20.好きな物
    $Likes        = !empty($Likes) ? $Likes : null;
    // 21.嫌いな物
    $Dislikes     = !empty($Dislikes) ? $Dislikes : null;
    // 22.備考
    $Note         = !empty($Note) ? $Note : null;
    // 23.没年月日
    $DeathDate    = !empty($DeathDate) ? $DeathDate : null;
    // 24.癖
    $Habit        = !empty($Habit) ? $Habit : null;
    // 25.口癖
    $VerbalTic    = !empty($VerbalTic) ? $VerbalTic : null;
    // 26.趣味
    $Hobby        = !empty($Hobby) ? $Hobby : null;
    // 26-2.死因
    $CauseOfDeath = !empty($CauseOfDeath) ? $CauseOfDeath : null;
    // 26-3.検討中・未確定の設定（変遷は 2-3. で正規化済み）
    $PendingSetting = !empty($PendingSetting) ? $PendingSetting : null;
    // 27.フリガナ
    $Furigana     = !empty($Furigana) ? $Furigana : null;
    // 28.ニックネーム
    $Nickname     = !empty($Nickname) ? $Nickname : null;
    // 28-2.二つ名
    $Alias        = !empty($Alias) ? $Alias : null;
    // 29.髪色
    $HairColor    = !empty($HairColor) ? $HairColor : null;
    // 30.目の色
    $EyeColor     = !empty($EyeColor) ? $EyeColor : null;
    // 31.肌の色
    $SkinColor    = !empty($SkinColor) ? $SkinColor : null;
    // 32.モデル
    $Model        = !empty($Model) ? $Model : null;
    // 33.身体備考
    $AppearanceRemarks = !empty($AppearanceRemarks) ? $AppearanceRemarks : null;
    $MoveIds      = ParseCharaSkillIds('Moves', 'MoveId', $Moves);

   /* ---------------------------------------------
     * 7-1. 関連ID（種族・所属組織・所属チーム・階級・出身地）の検証
     *  ・ いずれも同一作品内に存在するIDであることを要求する。
     * --------------------------------------------- */
    // 1. 検証対象（項目名 => [テーブル名, ID列名, 値]）
    $RelationChecks = [
        '種族'     => ['RaceInfo',         'raceId',  $RaceId],
        '所属組織' => ['OrganizationInfo', 'orgId',   $OrgId],
        '所属チーム' => ['TeamInfo',       'teamId',  $TeamId],
        '階級'     => ['ClassInfo',        'classId', $ClassId],
        '出身地'   => ['StageInfo',        'stageId', $BirthplaceId],
    ];
    // 2. 未選択（NULL）以外を検証する
    foreach ($RelationChecks as $RelationLabel => [$RelationTable, $RelationIdCol, $RelationId]) {
        if ($RelationId === null) {
            continue;
        }
        if (!ExistsCharaRelationId($Pdo, $RelationTable, $RelationIdCol, $RelationId, $WorkId)) {
            Database::Rollback();
            Response::Error($RelationLabel . 'が存在しません。画面を再表示してください');
        }
    }

   /* ---------------------------------------------
     * 7-2. 能力・武器・技の整合性チェック
     *  ・ 技の選択肢は能力一覧の内容に依存するため、
     *    フロント以外からの直接呼出しを想定したサーバーサイドの妥当性確認。
     *  ・ 能力・武器・技はいずれも作品内に存在するIDであることを要求する。
     *  ・ 技が指定されている場合、その技の powerId が能力一覧に含まれるか、
     *    または powerId が NULL（汎用技）であることを要求する。
     *  ・ 名称の解決は更新履歴の差分でも使用するため、ここで取得した内容を使い回す。
     * --------------------------------------------- */
    // 1. 能力の存在確認（作品内のIDのみ許可する）
    $PowerNameMap = [];
    if (count($PowerIds) > 0) {
        $PowerPlaceholders = implode(',', array_fill(0, count($PowerIds), '?'));
        $PowerCheckStmt = $Pdo->prepare("SELECT powerId, powerName FROM PowerInfo WHERE workId = ? AND powerId IN ($PowerPlaceholders)");
        $PowerCheckStmt->execute(array_merge([$WorkId], $PowerIds));
        foreach ($PowerCheckStmt->fetchAll() as $PowerRow) {
            $PowerNameMap[(string) $PowerRow['powerid']] = $PowerRow['powername'];
        }
        if (count($PowerNameMap) !== count($PowerIds)) {
            Database::Rollback();
            Response::Error('指定された能力が見つかりません');
        }
    }

    // 1-2. 武器の存在確認（作品内のIDのみ許可する）
    $WeaponNameMap = [];
    if (count($WeaponIds) > 0) {
        $WeaponPlaceholders = implode(',', array_fill(0, count($WeaponIds), '?'));
        $WeaponCheckStmt = $Pdo->prepare("SELECT weaponId, weaponName FROM WeaponInfo WHERE workId = ? AND weaponId IN ($WeaponPlaceholders)");
        $WeaponCheckStmt->execute(array_merge([$WorkId], $WeaponIds));
        foreach ($WeaponCheckStmt->fetchAll() as $WeaponRow) {
            $WeaponNameMap[(string) $WeaponRow['weaponid']] = $WeaponRow['weaponname'];
        }
        if (count($WeaponNameMap) !== count($WeaponIds)) {
            Database::Rollback();
            Response::Error('指定された武器が見つかりません');
        }
    }

    // 2. 技の存在確認と、能力一覧との整合性確認
    $MoveNameMap = [];
    if (count($MoveIds) > 0) {
        $MovePlaceholders = implode(',', array_fill(0, count($MoveIds), '?'));
        $MoveCheckStmt = $Pdo->prepare("SELECT moveId, moveName, powerId FROM MoveInfo WHERE workId = ? AND moveId IN ($MovePlaceholders)");
        $MoveCheckStmt->execute(array_merge([$WorkId], $MoveIds));
        $MoveRows = $MoveCheckStmt->fetchAll();
        if (count($MoveRows) !== count($MoveIds)) {
            Database::Rollback();
            Response::Error('指定された技が見つかりません');
        }
        foreach ($MoveRows as $MoveRow) {
            $MoveNameMap[(string) $MoveRow['moveid']] = $MoveRow['movename'];
            $MovePowerId = $MoveRow['powerid'];
            if ($MovePowerId !== null && !in_array((string) $MovePowerId, $PowerIds, true)) {
                Database::Rollback();
                Response::Error('指定された技は登録された能力と整合性がありません');
            }
        }
    }

    // 3. 妥当性確認を通したIDのみを保存対象のJSONとする
    $Powers  = BuildCharaSkillJson('Powers',  'PowerId',  $PowerIds);
    $Weapons = BuildCharaSkillJson('Weapons', 'WeaponId', $WeaponIds);
    $Moves   = BuildCharaSkillJson('Moves',   'MoveId',   $MoveIds);

   /* ---------------------------------------------
     * 8. 画像の処理（保存・削除の判定）
     *  - DBトランザクション成功前にファイルを物理書込/削除しないため、
     *    ここではメモリ上に「新規書込データ」「削除対象パス」を積み、
     *    Commit 後の 13-2. で実際のファイル操作を行う。
     *  - 検証・保留リストの作成は ImageList（他画面と共通）で行う。
     * --------------------------------------------- */
    /* 1. 定義 */
    // 1. 代表画像（先頭の画像）のパス
    $ImgPath = null;
    // 2. 画像一覧（JSON）
    $ImgList = null;
    // 3. 画像更新フラグ（画像一覧が送られてきた場合のみ更新する）
    $IsImageUpdated = is_array($Images);
    // 4. Commit後のファイル操作の保留リスト（ImageList::Prepare の結果）
    $ImagePrepared = [];

    if ($IsImageUpdated) {
        /* 2. 更新時は保持している画像一覧を取得 */
        $OldImages = [];
        if (!empty($CharaId)) {
            $OldImgStmt = $Pdo->prepare("SELECT imgPath, imgList FROM CharacterInfo WHERE charaId = :charaId AND workId = :workId");
            $OldImgStmt->bindValue(':charaId', $CharaId, PDO::PARAM_STR);
            $OldImgStmt->bindValue(':workId', $WorkId, PDO::PARAM_STR);
            $OldImgStmt->execute();
            $OldImgRow = $OldImgStmt->fetch();
            if ($OldImgRow) {
                $OldImages = ImageList::Parse($OldImgRow['imglist'] ?? null, $OldImgRow['imgpath'] ?? null);
            }
        }

        /* 3. 検証・DB登録値・保留リストの作成 */
        $ImagePrepared = ImageList::Prepare($Images, $OldImages, 'Characters', 'chara');
        if (isset($ImagePrepared['Error'])) {
            Database::Rollback();
            Response::Error($ImagePrepared['Error']);
        }
        $ImgPath = $ImagePrepared['ImgPath'];
        $ImgList = $ImagePrepared['ImgList'];
    }

    /* ---------------------------------------------
     * 9. 新規登録処理（CharaIdが空の場合）
     * --------------------------------------------- */
    if (empty($CharaId)) {
        // 1. クエリを定義
        $Sql = "
            INSERT INTO CharacterInfo (
                workId, charaName, gender, birthDate, age, height, weight, bloodType,
                raceId, orgId, teamId, classId, powers, weapons, roleInfo, personality, biography,
                belief, likes, dislikes, firstPerson, secondPerson, imgPath, imgList, purpose, note,
                deathDate, habit, verbalTic, hobby, causeOfDeath, furigana, nickname,
                hairColor, eyeColor, skinColor, model, appearanceRemarks,
                moves, tagText,
                alias, landmine, strongPoint, weakPoint, birthplaceId,
                transition, pendingSetting,
                registDate, updateDate, registUser, updateUser
            ) VALUES (
                :workId, :charaName, :gender, :birthDate, :age, :height, :weight, :bloodType,
                :raceId, :orgId, :teamId, :classId, :powers, :weapons, :roleInfo, :personality, :biography,
                :belief, :likes, :dislikes, :firstPerson, :secondPerson, :imgPath, :imgList, :purpose, :note,
                :deathDate, :habit, :verbalTic, :hobby, :causeOfDeath, :furigana, :nickname,
                :hairColor, :eyeColor, :skinColor, :model, :appearanceRemarks,
                :moves, :tagText,
                :alias, :landmine, :strongPoint, :weakPoint, :birthplaceId,
                :transition, :pendingSetting,
                CURRENT_TIMESTAMP(3), CURRENT_TIMESTAMP(3), :registUser, :updateUser
            )
            RETURNING charaId, updateDate
        ";
        // 2. ステートメント定義
        $Stmt = $Pdo->prepare($Sql);
        // 3. 作品ID設定
        $Stmt->bindValue(':workId',       $WorkId,       PDO::PARAM_STR);
        // 4. キャラ名設定
        $Stmt->bindValue(':charaName',    $CharaName,    PDO::PARAM_STR);
        // 5. 性別設定
        $Stmt->bindValue(':gender',       $Gender,       PDO::PARAM_INT);
        // 6. 生年月日設定
        $Stmt->bindValue(':birthDate',    $BirthDate,    $BirthDate === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        // 7. 年齢設定
        $Stmt->bindValue(':age',          $Age,          $Age === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        // 8. 身長設定
        $Stmt->bindValue(':height',       $Height,       $Height === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        // 9. 体重設定
        $Stmt->bindValue(':weight',       $Weight,       $Weight === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        // 10. 血液型設定
        $Stmt->bindValue(':bloodType',    $BloodType,    $BloodType === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        // 11. 種族ID設定
        $Stmt->bindValue(':raceId',       $RaceId,       $RaceId === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        // 12. 組織ID設定
        $Stmt->bindValue(':orgId',        $OrgId,        $OrgId === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        // 13. チームID設定
        $Stmt->bindValue(':teamId',       $TeamId,       $TeamId === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        // 14. 階級ID設定
        $Stmt->bindValue(':classId',      $ClassId,      $ClassId === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        // 15. 能力（JSON）設定
        $Stmt->bindValue(':powers',       $Powers,       $Powers === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        // 16. 武器（JSON）設定
        $Stmt->bindValue(':weapons',      $Weapons,      $Weapons === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        // 17. 役割情報設定
        $Stmt->bindValue(':roleInfo',     $RoleInfo,     $RoleInfo === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        // 18. 性格設定
        $Stmt->bindValue(':personality',  $Personality,  $Personality === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        // 18-2. 出身地（舞台ID）設定
        $Stmt->bindValue(':birthplaceId', $BirthplaceId, $BirthplaceId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        // 19. 略歴設定
        $Stmt->bindValue(':biography',    $Biography,    $Biography === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        // 20. 信念設定
        $Stmt->bindValue(':belief',       $Belief,       $Belief === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        // 21. 好きな物設定
        $Stmt->bindValue(':likes',        $Likes,        $Likes === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        // 22. 嫌いな物設定
        $Stmt->bindValue(':dislikes',     $Dislikes,     $Dislikes === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        // 23. 一人称設定
        $Stmt->bindValue(':firstPerson',  $FirstPerson,  $FirstPerson === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        // 19. 二人称設定
        $Stmt->bindValue(':secondPerson', $SecondPerson, $SecondPerson === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        // 20. 画像パス（代表画像）設定
        $Stmt->bindValue(':imgPath',      $ImgPath,      $ImgPath === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        // 20-2. 画像一覧（JSON）設定
        $Stmt->bindValue(':imgList',      $ImgList,      $ImgList === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        // 21. 登録ユーザーID設定
        $Stmt->bindValue(':registUser',   $UserId,       PDO::PARAM_STR);
        // 22. 更新ユーザーID設定
        $Stmt->bindValue(':updateUser',   $UserId,       PDO::PARAM_STR);
        // 23. 目的設定
        $Stmt->bindValue(':purpose',      $Purpose,      $Purpose === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        // 24. 備考設定
        $Stmt->bindValue(':note',         $Note,         $Note === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        // 25. 没年月日設定
        $Stmt->bindValue(':deathDate',    $DeathDate,    $DeathDate === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        // 26. 癖設定
        $Stmt->bindValue(':habit',        $Habit,        $Habit === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        // 27. 口癖設定
        $Stmt->bindValue(':verbalTic',    $VerbalTic,    $VerbalTic === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        // 28. 趣味設定
        $Stmt->bindValue(':hobby',        $Hobby,        $Hobby === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        // 28-2. 死因設定
        $Stmt->bindValue(':causeOfDeath', $CauseOfDeath, $CauseOfDeath === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        // 28-3. 変遷（JSON）設定
        $Stmt->bindValue(':transition',     $Transition,     $Transition     === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        // 28-4. 検討中・未確定の設定
        $Stmt->bindValue(':pendingSetting', $PendingSetting, $PendingSetting === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        // 29. フリガナ設定
        $Stmt->bindValue(':furigana',     $Furigana,     $Furigana === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        // 30. ニックネーム設定
        $Stmt->bindValue(':nickname',     $Nickname,     $Nickname === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        // 31. 髪色設定
        $Stmt->bindValue(':hairColor',    $HairColor,    $HairColor === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        // 32. 目の色設定
        $Stmt->bindValue(':eyeColor',     $EyeColor,     $EyeColor === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        // 33. 肌の色設定
        $Stmt->bindValue(':skinColor',    $SkinColor,    $SkinColor === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        // 34. モデル設定
        $Stmt->bindValue(':model',        $Model,        $Model === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        // 35. 身体備考設定
        $Stmt->bindValue(':appearanceRemarks', $AppearanceRemarks, $AppearanceRemarks === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        // 36. 技（JSON）設定
        $Stmt->bindValue(':moves',        $Moves,        $Moves === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        // 36-2. タグテキスト設定
        $Stmt->bindValue(':tagText',      $TagText,      $TagText === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        // 37. 二つ名設定
        $Stmt->bindValue(':alias',        $Alias,        $Alias === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        // 38. 地雷設定
        $Stmt->bindValue(':landmine',     $Landmine,     $Landmine === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        // 39. 長所設定
        $Stmt->bindValue(':strongPoint',  $StrongPoint,  $StrongPoint === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        // 40. 短所設定
        $Stmt->bindValue(':weakPoint',    $WeakPoint,    $WeakPoint === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        // 41. SQL実行
        $Stmt->execute();
        // 26. 新規登録されたレコード取得
        $ResultRow = $Stmt->fetch();
        // 27. キャラID取得
        $NewCharaId = $ResultRow['charaid'];
        // 28. 更新年月日取得
        $NewUpdateDate = $ResultRow['updatedate'];
        // 29. 更新履歴の詳細メッセージを生成（登録）
        $Detail = HistoryDetail::Register($CharaName);

    /* ---------------------------------------------
     * 10. 更新処理（CharaIdが存在する場合）
     * --------------------------------------------- */
    } else {
        /* 2. 更新の場合 */
        /* ---------------------------------------------
         *  2-A. 差分履歴用：更新前の表示値を取得
         *      FK列（種族・組織等）は名称へ解決するため JOIN で取得する。
         *      取得キーはPDO既定の小文字。
         * --------------------------------------------- */
        $OldRowStmt = $Pdo->prepare("
            SELECT
                c.charaName, c.furigana, c.nickname, c.alias, c.gender, c.bloodType,
                c.birthDate, c.age, c.height, c.weight,
                c.deathDate, c.causeOfDeath, c.roleInfo, c.personality, c.biography,
                c.firstPerson, c.secondPerson, c.purpose, c.belief, c.likes, c.dislikes,
                c.landmine, c.strongPoint, c.weakPoint,
                c.note, c.habit, c.verbalTic, c.hobby, c.hairColor, c.eyeColor, c.skinColor,
                c.model, c.appearanceRemarks, c.transition, c.pendingSetting,
                c.raceId, c.raceName, c.orgId, c.orgName, c.teamId, c.teamName,
                c.classId, c.className, c.powers, c.weapons,
                c.moves, c.birthplaceId, c.birthplaceName
            FROM v_character_detail c
            WHERE c.charaId = :charaId AND c.workId = :workId
        ");
        $OldRowStmt->bindValue(':charaId', $CharaId, PDO::PARAM_STR);
        $OldRowStmt->bindValue(':workId',  $WorkId,  PDO::PARAM_STR);
        $OldRowStmt->execute();
        $OldRow = $OldRowStmt->fetch() ?: [];
        /* ---------------------------------------------
         *  2-0. 経歴整合性チェック
         *  生年月日を空にしようとしているキャラに経歴が登録
         *  されている場合はエラーとして処理を中断する
         * --------------------------------------------- */
        if ($BirthDate === null) {
            $BiographyCheckSql = "SELECT 1 FROM BiographyInfo WHERE workId = :workId AND charaId = :charaId LIMIT 1";
            $BiographyCheckStmt = $Pdo->prepare($BiographyCheckSql);
            $BiographyCheckStmt->bindValue(':workId',  $WorkId,  PDO::PARAM_STR);
            $BiographyCheckStmt->bindValue(':charaId', $CharaId, PDO::PARAM_STR);
            $BiographyCheckStmt->execute();
            if ($BiographyCheckStmt->fetchColumn() !== false) {
                Database::Rollback();
                Response::Error(
                    '経歴情報の登録されているキャラクターの生年月日は削除できません。経歴情報の削除後に生年月日の削除を行ってください',
                    400,
                );
            }
        }

        // 1. 画像パス更新用のSQL句を生成（更新フラグがtrueの場合のみ）
        //    代表画像のパスと画像一覧は常に揃えて更新する
        $UpdateImgPath = $IsImageUpdated ? ", imgPath = :imgPath, imgList = :imgList" : "";
        // 2. クエリを定義
        $Sql = "
            UPDATE CharacterInfo SET
                charaName = :charaName,
                gender = :gender,
                birthDate = :birthDate,
                age = :age,
                height = :height,
                weight = :weight,
                bloodType = :bloodType,
                raceId = :raceId,
                orgId = :orgId,
                teamId = :teamId,
                classId = :classId,
                powers = :powers,
                weapons = :weapons,
                roleInfo = :roleInfo,
                personality = :personality,
                biography = :biography,
                belief = :belief,
                likes = :likes,
                dislikes = :dislikes,
                note = :note,
                deathDate = :deathDate,
                habit = :habit,
                verbalTic = :verbalTic,
                hobby = :hobby,
                causeOfDeath = :causeOfDeath,
                transition = :transition,
                pendingSetting = :pendingSetting,
                furigana = :furigana,
                nickname = :nickname,
                hairColor = :hairColor,
                eyeColor = :eyeColor,
                skinColor = :skinColor,
                model = :model,
                appearanceRemarks = :appearanceRemarks,
                moves = :moves,
                tagText = :tagText,
                alias = :alias,
                landmine = :landmine,
                strongPoint = :strongPoint,
                weakPoint = :weakPoint,
                birthplaceId = :birthplaceId,
                firstPerson = :firstPerson,
                secondPerson = :secondPerson,
                purpose = :purpose,
                updateDate = CURRENT_TIMESTAMP(3),
                updateUser = :updateUser
                {$UpdateImgPath}
            WHERE charaId = :charaId AND workId = :workId AND updateDate = :updateDate
            RETURNING updateDate
        ";
        // 3. ステートメント定義
        $Stmt = $Pdo->prepare($Sql);
        // 4. キャラID設定
        $Stmt->bindValue(':charaId',      $CharaId,      PDO::PARAM_STR);
        // 5. 作品ID設定
        $Stmt->bindValue(':workId',       $WorkId,       PDO::PARAM_STR);
        // 5-2. 更新年月日設定（排他制御用）
        $Stmt->bindValue(':updateDate',   $UpdateDate,   PDO::PARAM_STR);
        // 6. キャラ名設定
        $Stmt->bindValue(':charaName',    $CharaName,    PDO::PARAM_STR);
        // 7. 性別設定
        $Stmt->bindValue(':gender',       $Gender,       PDO::PARAM_INT);
        // 8. 生年月日設定
        $Stmt->bindValue(':birthDate',    $BirthDate,    $BirthDate === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        // 9. 年齢設定
        $Stmt->bindValue(':age',          $Age,          $Age === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        // 10.身長設定
        $Stmt->bindValue(':height',       $Height,       $Height === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        // 11.体重設定
        $Stmt->bindValue(':weight',       $Weight,       $Weight === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        // 12.血液型設定
        $Stmt->bindValue(':bloodType',    $BloodType,    $BloodType === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        // 13.種族ID設定
        $Stmt->bindValue(':raceId',       $RaceId,       $RaceId === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        // 14.組織ID設定
        $Stmt->bindValue(':orgId',        $OrgId,        $OrgId === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        // 15.チームID設定
        $Stmt->bindValue(':teamId',       $TeamId,       $TeamId === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        // 16.階級ID設定
        $Stmt->bindValue(':classId',      $ClassId,      $ClassId === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        // 17.能力（JSON）設定
        $Stmt->bindValue(':powers',       $Powers,       $Powers === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        // 18.武器（JSON）設定
        $Stmt->bindValue(':weapons',      $Weapons,      $Weapons === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        // 19.役割情報設定
        $Stmt->bindValue(':roleInfo',     $RoleInfo,     $RoleInfo === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        // 20.性格設定
        $Stmt->bindValue(':personality',  $Personality,  $Personality === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        // 20-2.出身地（舞台ID）設定
        $Stmt->bindValue(':birthplaceId', $BirthplaceId, $BirthplaceId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        // 21.略歴設定
        $Stmt->bindValue(':biography',    $Biography,    $Biography === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        // 22.信念設定
        $Stmt->bindValue(':belief',       $Belief,       $Belief === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        // 23.好きな物設定
        $Stmt->bindValue(':likes',        $Likes,        $Likes === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        // 24.嫌いな物設定
        $Stmt->bindValue(':dislikes',     $Dislikes,     $Dislikes === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        // 25.一人称設定
        $Stmt->bindValue(':firstPerson',  $FirstPerson,  $FirstPerson === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        // 26.二人称設定
        $Stmt->bindValue(':secondPerson', $SecondPerson, $SecondPerson === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        // 27.更新ユーザーID設定
        $Stmt->bindValue(':updateUser',   $UserId,       PDO::PARAM_STR);
        // 28.画像が更新対象の場合のみ代表画像のパスと画像一覧をバインド（NULLも許容）
        if ($IsImageUpdated) {
            $Stmt->bindValue(':imgPath',  $ImgPath,      $ImgPath === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $Stmt->bindValue(':imgList',  $ImgList,      $ImgList === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        }
        // 29.目的
        $Stmt->bindValue(':purpose', $Purpose, $Purpose === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        // 30.備考
        $Stmt->bindValue(':note',    $Note,     $Note === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        // 31.没年月日
        $Stmt->bindValue(':deathDate', $DeathDate, $DeathDate === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        // 32.癖
        $Stmt->bindValue(':habit',     $Habit,     $Habit === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        // 33.口癖
        $Stmt->bindValue(':verbalTic', $VerbalTic, $VerbalTic === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        // 34.趣味
        $Stmt->bindValue(':hobby',     $Hobby,     $Hobby === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        // 34-2.死因
        $Stmt->bindValue(':causeOfDeath', $CauseOfDeath, $CauseOfDeath === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        // 34-3.変遷（JSON）
        $Stmt->bindValue(':transition',     $Transition,     $Transition     === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        // 34-4.検討中・未確定の設定
        $Stmt->bindValue(':pendingSetting', $PendingSetting, $PendingSetting === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        // 35.フリガナ
        $Stmt->bindValue(':furigana',  $Furigana,  $Furigana === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        // 36.ニックネーム
        $Stmt->bindValue(':nickname',  $Nickname,  $Nickname === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        // 37.髪色
        $Stmt->bindValue(':hairColor', $HairColor, $HairColor === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        // 38.目の色
        $Stmt->bindValue(':eyeColor',  $EyeColor,  $EyeColor === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        // 39.肌の色
        $Stmt->bindValue(':skinColor', $SkinColor, $SkinColor === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        // 40.モデル
        $Stmt->bindValue(':model',     $Model,     $Model === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        // 41.身体備考
        $Stmt->bindValue(':appearanceRemarks', $AppearanceRemarks, $AppearanceRemarks === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        // 42.技（JSON）設定
        $Stmt->bindValue(':moves',        $Moves,        $Moves === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        // 42-2.タグテキスト設定
        $Stmt->bindValue(':tagText',      $TagText,      $TagText === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        // 43.二つ名設定
        $Stmt->bindValue(':alias',        $Alias,        $Alias === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        // 44.地雷設定
        $Stmt->bindValue(':landmine',     $Landmine,     $Landmine === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        // 45.長所設定
        $Stmt->bindValue(':strongPoint',  $StrongPoint,  $StrongPoint === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        // 46.短所設定
        $Stmt->bindValue(':weakPoint',    $WeakPoint,    $WeakPoint === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        // 47.SQL実行
        $Stmt->execute();
        // 38.更新結果取得
        $UpdateResult = $Stmt->fetch();
        // 39.排他制御チェック
        if (!$UpdateResult) {
            Database::Rollback();
            Response::ConflictError();
        }
        // 40.キャラIDを設定
        $NewCharaId = $CharaId;
        // 41.更新年月日取得
        $NewUpdateDate = $UpdateResult['updatedate'];

        /* ---------------------------------------------
         *  42.更新履歴の詳細メッセージを生成（更新・列単位差分）
         *      FK列は新IDが変わった場合のみ名称を再解決する（未変更は旧名称を再利用）。
         * --------------------------------------------- */
        // FK名称解決ヘルパ（テーブル・列名は固定リテラルのみ）
        $ResolveName = function ($Table, $IdCol, $NameCol, $Id) use ($Pdo, $WorkId) {
            if ($Id === null || $Id === '') {
                return null;
            }
            $S = $Pdo->prepare("SELECT {$NameCol} AS nm FROM {$Table} WHERE {$IdCol} = :id AND workId = :workId");
            $S->bindValue(':id', $Id);
            $S->bindValue(':workId', $WorkId, PDO::PARAM_STR);
            $S->execute();
            $R = $S->fetch();
            return $R ? $R['nm'] : null;
        };
        // 新IDが旧IDと同じならクエリせず旧名称を再利用する
        $NewFkName = function ($Table, $IdCol, $NameCol, $NewId, $OldId, $OldName) use ($ResolveName) {
            if ((string) ($NewId ?? '') === (string) ($OldId ?? '')) {
                return $OldName;
            }
            return $ResolveName($Table, $IdCol, $NameCol, $NewId);
        };
        /* 能力・技（JSON）の名称解決ヘルパ
         *   更新前後のIDをまとめて引き、ID => 名称 の対応を作る。
         *   更新前のIDは 7-2. の妥当性確認の対象外（マスタから削除済みの可能性がある）ため、
         *   引けなかったIDは FormatCharaSkillForHistory 側で「ID:n」と表示する。 */
        $ResolveNameMap = function ($Table, $IdCol, $NameCol, array $Ids) use ($Pdo, $WorkId) {
            if (count($Ids) === 0) {
                return [];
            }
            $Placeholders = implode(',', array_fill(0, count($Ids), '?'));
            $S = $Pdo->prepare("SELECT {$IdCol} AS id, {$NameCol} AS nm FROM {$Table} WHERE workId = ? AND {$IdCol} IN ($Placeholders)");
            $S->execute(array_merge([$WorkId], $Ids));
            $Map = [];
            foreach ($S->fetchAll() as $R) {
                $Map[(string) $R['id']] = $R['nm'];
            }
            return $Map;
        };
        // 更新前の能力・武器・技のID
        $OldPowerIds  = ParseCharaSkillIds('Powers',  'PowerId',  $OldRow['powers']  ?? null);
        $OldWeaponIds = ParseCharaSkillIds('Weapons', 'WeaponId', $OldRow['weapons'] ?? null);
        $OldMoveIds   = ParseCharaSkillIds('Moves',   'MoveId',   $OldRow['moves']   ?? null);
        // 更新前後のIDを合わせた名称の対応（7-2. で取得済みの新IDの名称も取り込む）
        $PowerHistoryNameMap  = $ResolveNameMap('PowerInfo',  'powerId',  'powerName',  array_values(array_unique(array_merge($OldPowerIds,  $PowerIds))))  + $PowerNameMap;
        $WeaponHistoryNameMap = $ResolveNameMap('WeaponInfo', 'weaponId', 'weaponName', array_values(array_unique(array_merge($OldWeaponIds, $WeaponIds)))) + $WeaponNameMap;
        $MoveHistoryNameMap   = $ResolveNameMap('MoveInfo',   'moveId',   'moveName',   array_values(array_unique(array_merge($OldMoveIds,   $MoveIds))))   + $MoveNameMap;

        // 差分対象の列 → 和名（この範囲・順序で差分判定する。性別・血液型・画像・タグは対象外）
        $Labels = [
            'charaName'        => 'キャラ名',
            'furigana'         => 'フリガナ',
            'nickname'         => 'ニックネーム',
            'alias'            => '二つ名',
            'gender'           => '性別',
            'bloodType'        => '血液型',
            'birthDate'        => '生年月日',
            'age'              => '年齢',
            'height'           => '身長',
            'weight'           => '体重',
            'deathDate'        => '没年月日',
            'causeOfDeath'     => '死因',
            'raceId'           => '種族',
            'orgId'            => '組織',
            'teamId'           => 'チーム',
            'classId'          => '階級',
            'powers'           => '能力',
            'weapons'          => '武器',
            'moves'            => '技',
            'roleInfo'         => '役割',
            'personality'      => '性格',
            'birthplaceId'     => '出身地',
            'biography'        => '生い立ち・経歴',
            'firstPerson'      => '一人称',
            'secondPerson'     => '二人称',
            'purpose'          => '目的',
            'belief'           => '信念',
            'likes'            => '好きな物',
            'dislikes'         => '嫌いな物',
            'landmine'         => '地雷',
            'strongPoint'      => '長所',
            'weakPoint'        => '短所',
            'note'             => '備考',
            'habit'            => '癖',
            'verbalTic'        => '口癖',
            'hobby'            => '趣味',
            'hairColor'        => '髪色',
            'eyeColor'         => '目の色',
            'skinColor'        => '肌の色',
            'model'            => 'モデル',
            'appearanceRemarks' => '身体備考',
            'transition'       => '変遷',
            'pendingSetting'   => '検討中・未確定の設定',
        ];

        // 更新前の表示値（FKは名称）
        $OldDisplay = [
            'charaName'    => $OldRow['charaname']    ?? null,
            'furigana'     => $OldRow['furigana']     ?? null,
            'nickname'     => $OldRow['nickname']     ?? null,
            'alias'        => $OldRow['alias']        ?? null,
            'gender'       => CodeLabel::Get('Gender',    $OldRow['gender']    ?? null),
            'bloodType'    => CodeLabel::Get('BloodType', $OldRow['bloodtype'] ?? null),
            'birthDate'    => $OldRow['birthdate']    ?? null,
            'age'          => $OldRow['age']          ?? null,
            'height'       => $OldRow['height']       ?? null,
            'weight'       => $OldRow['weight']       ?? null,
            'deathDate'    => $OldRow['deathdate']    ?? null,
            'causeOfDeath' => $OldRow['causeofdeath'] ?? null,
            'raceId'       => $OldRow['racename']     ?? null,
            'orgId'        => $OldRow['orgname']      ?? null,
            'teamId'       => $OldRow['teamname']     ?? null,
            'classId'      => $OldRow['classname']    ?? null,
            'powers'       => FormatCharaSkillForHistory($OldPowerIds,  $PowerHistoryNameMap),
            'weapons'      => FormatCharaSkillForHistory($OldWeaponIds, $WeaponHistoryNameMap),
            'moves'        => FormatCharaSkillForHistory($OldMoveIds,   $MoveHistoryNameMap),
            'roleInfo'     => $OldRow['roleinfo']     ?? null,
            'personality'  => $OldRow['personality']  ?? null,
            'birthplaceId' => $OldRow['birthplacename'] ?? null,
            'biography'    => $OldRow['biography']     ?? null,
            'firstPerson'  => EntryTable::FormatForHistory('Character', 'FirstPerson',  $OldRow['firstperson']  ?? null),
            'secondPerson' => EntryTable::FormatForHistory('Character', 'SecondPerson', $OldRow['secondperson'] ?? null),
            'purpose'      => $OldRow['purpose']      ?? null,
            'belief'       => $OldRow['belief']       ?? null,
            // 表形式で保持する項目は「内容（理由）」の読める形へ整形してから比較・表示する
            'likes'        => EntryTable::FormatForHistory('Character', 'Likes',       $OldRow['likes']       ?? null),
            'dislikes'     => EntryTable::FormatForHistory('Character', 'Dislikes',    $OldRow['dislikes']    ?? null),
            'landmine'     => EntryTable::FormatForHistory('Character', 'Landmine',    $OldRow['landmine']    ?? null),
            'strongPoint'  => EntryTable::FormatForHistory('Character', 'StrongPoint', $OldRow['strongpoint'] ?? null),
            'weakPoint'    => EntryTable::FormatForHistory('Character', 'WeakPoint',   $OldRow['weakpoint']   ?? null),
            'note'         => $OldRow['note']         ?? null,
            'habit'        => EntryTable::FormatForHistory('Character', 'Habit', $OldRow['habit'] ?? null),
            'verbalTic'    => $OldRow['verbaltic']    ?? null,
            'hobby'        => EntryTable::FormatForHistory('Character', 'Hobby', $OldRow['hobby'] ?? null),
            'hairColor'    => $OldRow['haircolor']    ?? null,
            'eyeColor'     => $OldRow['eyecolor']     ?? null,
            'skinColor'    => $OldRow['skincolor']    ?? null,
            'model'        => $OldRow['model']        ?? null,
            'appearanceRemarks' => $OldRow['appearanceremarks'] ?? null,
            'transition'     => EntryTable::FormatForHistory('Character', 'Transition', $OldRow['transition'] ?? null),
            'pendingSetting' => $OldRow['pendingsetting'] ?? null,
        ];

        // 更新後の表示値（FKは名称へ解決）
        $NewDisplay = [
            'charaName'    => $CharaName,
            'furigana'     => $Furigana,
            'nickname'     => $Nickname,
            'alias'        => $Alias,
            'gender'       => CodeLabel::Get('Gender',    $Gender),
            'bloodType'    => CodeLabel::Get('BloodType', $BloodType),
            'birthDate'    => $BirthDate,
            'age'          => $Age,
            'height'       => $Height,
            'weight'       => $Weight,
            'deathDate'    => $DeathDate,
            'causeOfDeath' => $CauseOfDeath,
            'raceId'   => $NewFkName('RaceInfo',        'raceId',   'raceName',   $RaceId,   $OldRow['raceid']   ?? null, $OldRow['racename']   ?? null),
            'orgId'    => $NewFkName('OrganizationInfo', 'orgId',    'orgName',    $OrgId,    $OldRow['orgid']    ?? null, $OldRow['orgname']    ?? null),
            'teamId'   => $NewFkName('TeamInfo',         'teamId',   'teamName',   $TeamId,   $OldRow['teamid']   ?? null, $OldRow['teamname']   ?? null),
            'classId'  => $NewFkName('ClassInfo',        'classId',  'className',  $ClassId,  $OldRow['classid']  ?? null, $OldRow['classname']  ?? null),
            'powers'   => FormatCharaSkillForHistory($PowerIds,  $PowerHistoryNameMap),
            'weapons'  => FormatCharaSkillForHistory($WeaponIds, $WeaponHistoryNameMap),
            'moves'    => FormatCharaSkillForHistory($MoveIds,   $MoveHistoryNameMap),
            'roleInfo'     => $RoleInfo,
            'personality'  => $Personality,
            'birthplaceId' => $NewFkName('StageInfo',    'stageId',  'stageName',  $BirthplaceId, $OldRow['birthplaceid'] ?? null, $OldRow['birthplacename'] ?? null),
            'biography'    => $Biography,
            'firstPerson'  => EntryTable::FormatForHistory('Character', 'FirstPerson',  $FirstPerson),
            'secondPerson' => EntryTable::FormatForHistory('Character', 'SecondPerson', $SecondPerson),
            'purpose'      => $Purpose,
            'belief'       => $Belief,
            // 表形式で保持する項目は「内容（理由）」の読める形へ整形してから比較・表示する
            'likes'        => EntryTable::FormatForHistory('Character', 'Likes',       $Likes),
            'dislikes'     => EntryTable::FormatForHistory('Character', 'Dislikes',    $Dislikes),
            'landmine'     => EntryTable::FormatForHistory('Character', 'Landmine',    $Landmine),
            'strongPoint'  => EntryTable::FormatForHistory('Character', 'StrongPoint', $StrongPoint),
            'weakPoint'    => EntryTable::FormatForHistory('Character', 'WeakPoint',   $WeakPoint),
            'note'         => $Note,
            'habit'        => EntryTable::FormatForHistory('Character', 'Habit', $Habit),
            'verbalTic'    => $VerbalTic,
            'hobby'        => EntryTable::FormatForHistory('Character', 'Hobby', $Hobby),
            'hairColor'    => $HairColor,
            'eyeColor'     => $EyeColor,
            'skinColor'    => $SkinColor,
            'model'        => $Model,
            'appearanceRemarks' => $AppearanceRemarks,
            'transition'     => EntryTable::FormatForHistory('Character', 'Transition', $Transition),
            'pendingSetting' => $PendingSetting,
        ];

        $Detail = HistoryDetail::Update($CharaName, $OldDisplay, $NewDisplay, $Labels);
    }

   /* ---------------------------------------------
    *  11.作品情報更新
    * --------------------------------------------- */
    // 1. クエリを定義
    $UpdateWorkSql = "
        UPDATE WorkInfo SET
            updateDate = CURRENT_TIMESTAMP(3),
            updateUser = :updateUser
        WHERE workId = :workId
    ";
    // 2. ステートメント定義
    $UpdateWorkStmt = $Pdo->prepare($UpdateWorkSql);
    // 3. 更新ユーザーID設定
    $UpdateWorkStmt->bindValue(':updateUser', $UserId, PDO::PARAM_STR);
    // 4. 作品ID設定
    $UpdateWorkStmt->bindValue(':workId',     $WorkId, PDO::PARAM_STR);
    // 5. 実行
    $UpdateWorkStmt->execute();

   /* ---------------------------------------------
    *  12.更新履歴登録
    * --------------------------------------------- */
    // 1. クエリを定義
    $HistorySql = "
        INSERT INTO UpdateHistory (
            workId, functionName, operation, detail, registDate, updateDate, registUser, updateUser
        ) VALUES (
            :workId, :functionName, :operation, :detail, CURRENT_TIMESTAMP(3), CURRENT_TIMESTAMP(3), :registUser, :updateUser
        )
    ";
    // 2. ステートメント定義
    $HistoryStmt = $Pdo->prepare($HistorySql);
    // 3. パラメータ設定・実行（変更が無い更新は履歴に残す情報が無いため登録しない）
    if (HistoryDetail::HasChanges($Detail)) {
        $HistoryStmt->bindValue(':workId',       $WorkId,       PDO::PARAM_STR);
        // 4. 機能名設定
        $HistoryStmt->bindValue(':functionName', $FunctionName, PDO::PARAM_STR);
        // 5. 操作内容設定
        $HistoryStmt->bindValue(':operation',    $Operation,    PDO::PARAM_STR);
        // 5-2. 更新内容詳細設定
        $HistoryStmt->bindValue(':detail',       $Detail,       $Detail === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        // 6. 登録ユーザーID設定
        $HistoryStmt->bindValue(':registUser',   $UserId,       PDO::PARAM_STR);
        // 7. 更新ユーザーID設定
        $HistoryStmt->bindValue(':updateUser',   $UserId,       PDO::PARAM_STR);
        // 8. 実行
        $HistoryStmt->execute();
    }

   /* ---------------------------------------------
    *  13.トランザクションをコミット
    * --------------------------------------------- */
    Database::Commit();

   /* ---------------------------------------------
    *  13-2. Commit 後のファイル操作
    *    - コミット成功後に画像を物理書込・旧画像を物理削除する。
    *    - ファイル操作失敗は DB 状態を巻き戻せないため、ログ記録に留める。
    * --------------------------------------------- */
    ImageList::ApplyFiles($ImagePrepared, $FunctionName, $UserId);

   /* ---------------------------------------------
    *  14.操作ログ書き込み
    * --------------------------------------------- */
    Logger::Info($FunctionName, $Operation . '処理終了', $UserId);

   /* ---------------------------------------------
    *  15.成功レスポンス
    * --------------------------------------------- */
    Response::Success([
        'CharaId' => $NewCharaId,
        'UpdateDate' => $NewUpdateDate,
        'Message' => 'キャラを' . $Operation . 'しました'
    ]);

} catch (Exception $E) {
   /* ==========================================================
    * 5. 例外処理
    * ========================================================== */
    // 1. トランザクションをロールバック
    Database::Rollback();
    // 2. エラーログ書き込み
    Logger::Error($FunctionName, '保存処理エラー', $UserId, $E->getMessage());
    // 3. エラーレスポンス
    Response::Error('キャラの保存に失敗しました');
}