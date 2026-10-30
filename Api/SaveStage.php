<?php
/* ==========================================================
 * 舞台情報更新
 * ----------------------------------------------------------
 * ・舞台情報を登録・更新する
 * ----------------------------------------------------------
 * 更新履歴：
 * 2026-01-10 作成
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
// コード区分値 → 表示名称 変換
require_once __DIR__ . '/../Common/CodeLabel.php';

/* ==========================================================
 * 2. リクエストチェック
 * ========================================================== */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::Error('不正なリクエストです', 405);
}

/* ==========================================================
 * 3. 共通定義
 * ========================================================== */
// 1. 機能名
$FunctionName = '舞台設定';
// 2. ユーザーID
$UserId = '';

/**
 * 画面から送られた人口を整数へ変換する（StageInfo.population は BIGINT）
 * @param mixed $Population 画面から送られた人口
 * @return int|null 0以上の整数（15桁以内）の場合はその値、それ以外は null
 */
function ParseStagePopulation($Population) {
    $Str = trim((string) ($Population ?? ''));
    return preg_match('/\A[0-9]{1,15}\z/', $Str) ? (int) $Str : null;
}

/**
 * 人口のエラーメッセージ用に、舞台名と人口を「「舞台名」の人口（1,000）」の形へ整形する
 * @param array $Stage ['stagename' => .., 'population' => ..]
 * @return string 整形した文字列
 */
function FormatStagePopulation(array $Stage) {
    return '「' . ($Stage['stagename'] ?? '') . '」の人口（' . number_format((int) ($Stage['population'] ?? 0)) . '）';
}

/**
 * 配下の舞台の人口の総和を求める
 * 人口が未入力の舞台は飛ばし、その配下で人口が入力されている最も近い舞台の人口を合計する
 * （例：国 ＞ 県（未入力） ＞ 市 の場合、国の配下の総和には市の人口を含める）
 * @param string $StageKey 対象の舞台のキー
 * @param array $Stages キー => ['stagename' => .., 'population' => int|null]
 * @param array $Children 親のキー => 子のキーの配列
 * @param int $Depth 再帰の深さ（既存データの循環に備えた上限判定用）
 * @return int 配下の舞台の人口の総和（配下に人口が入力された舞台が無い場合は 0）
 */
function SumChildPopulation($StageKey, array $Stages, array $Children, int $Depth = 0) {
    if ($Depth >= 1000) return 0;
    $Sum = 0;
    foreach ($Children[$StageKey] ?? [] as $ChildKey) {
        $Sum += $Stages[$ChildKey]['population'] !== null
            ? $Stages[$ChildKey]['population']
            : SumChildPopulation($ChildKey, $Stages, $Children, $Depth + 1);
    }
    return $Sum;
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
    // 1. 舞台ID
    $StageId     = $Input['StageId'] ?? null;
    // 2. 作品ID
    $WorkId      = $AuthWorkId ?? '';
    // 3. 舞台名
    $StageName   = $Input['StageName'] ?? '';
    // 3-2. 舞台区分（どのような舞台であるかを表すコード区分値）
    $StageType   = $Input['StageType'] ?? 0;
    // 3-3. 親舞台ID（未選択の場合は最上位の舞台とする）
    $ParentStageId = $Input['ParentStageId'] ?? null;
    // 4. 人口
    $Population  = $Input['Population'] ?? null;
    // 5. 面積
    $AreaSize    = $Input['AreaSize'] ?? null;
    // 6. 説明
    $Description = $Input['Description'] ?? null;
    // 7. ユーザーID
    $UserId = $AuthUserId;
    // 8. 画像一覧（[{Path:既存画像のパス, Data:新規画像のデータURL, Caption:説明文}]。先頭が代表画像）
    //    指定が無い場合（配列でない場合）は画像を変更しない
    $Images      = $Input['Images'] ?? null;
    // 9. 更新年月日（排他制御用）
    $UpdateDate  = $Input['UpdateDate'] ?? null;

   /* ---------------------------------------------
    *  3. 操作設定
    * --------------------------------------------- */
    $Operation = empty($StageId) ? '登録' : '更新';
    // 更新履歴の詳細メッセージ（登録/更新分岐でそれぞれ生成）
    $Detail = null;

   /* ---------------------------------------------
    *  4. バリデーションチェック
    * --------------------------------------------- */
    if (empty($WorkId)) {
        Response::Error('作品IDが指定されていません');
    }
    if (empty($StageName)) {
        Response::Error('舞台名を入力してください');
    }
    if (empty($UserId)) {
        Response::Error('ユーザーIDが指定されていません');
    }

   /* ---------------------------------------------
    *  5. 操作ログ出力
    * --------------------------------------------- */
    Logger::Info($FunctionName, $Operation . '処理開始', $UserId);

   /* ---------------------------------------------
    *  6. DB接続
    * --------------------------------------------- */
    $Pdo = Database::GetConnection();
    Database::BeginTransaction();

   /* ---------------------------------------------
    *  7. 空文字をNULLに変換
    * --------------------------------------------- */
    $StageId     = !empty($StageId) ? $StageId : null;
    // 舞台区分は未選択（0・空文字）をNULLとして保持する
    $StageType   = !empty($StageType) ? (int) $StageType : null;
    $ParentStageId = !empty($ParentStageId) ? (string) $ParentStageId : null;
    // 人口は0も有効な値のため、empty ではなく空文字かどうかで判定する
    $Population  = trim((string) ($Population ?? '')) !== '' ? $Population : null;
    $AreaSize    = !empty($AreaSize) ? $AreaSize : null;
    $Description = !empty($Description) ? $Description : null;

    // 人口は親子の整合性チェック（7-3）で比較するため BIGINT で保持する。数値以外は受け付けない
    if ($Population !== null) {
        $Population = ParseStagePopulation($Population);
        if ($Population === null) {
            Database::Rollback();
            Response::Error('人口は0以上の整数（15桁以内）で入力してください', 400, ['FieldId' => 'StagePopulation']);
        }
    }

   /* ---------------------------------------------
    *  7-2. 親舞台の検証
    *      ・同一作品の舞台であること
    *      ・自身、または自身の配下の舞台を親としていないこと（循環参照の防止）
    *        親舞台から上位へ辿り、自身に行き着く場合は循環となる
    * --------------------------------------------- */
    // 更新履歴に表示する親舞台の名称（未選択の場合はNULL）
    $NewParentStageName = null;
    // 同じ作品の舞台の保存を直列化する。A→B と B→A の親子付けが同時に保存されると、
    // どちらの循環チェックも相手の変更を見ずに通過し、循環が成立し得るため。
    // 人口の親子チェック（7-3）も、親子で同時に人口を変更されると互いの変更を見ずに通過し得るため、
    // 親舞台の有無に依らず保存のたびに直列化する。
    // キー 1003 は他処理（1001:ストーリー行 / 1002:プロット行・掛け合い行・章の並び順）と重複しない値とする。
    $LockStmt = $Pdo->prepare("SELECT pg_advisory_xact_lock(1003, hashtext(:workId))");
    $LockStmt->execute([':workId' => $WorkId]);
    if ($ParentStageId !== null) {
        if ($StageId !== null && $ParentStageId === (string) $StageId) {
            Database::Rollback();
            Response::Error('自身を親舞台に設定することはできません', 400, ['FieldId' => 'StageParentId']);
        }

        $ParentStmt = $Pdo->prepare("SELECT stageName FROM StageInfo WHERE stageId = :stageId AND workId = :workId");
        $ParentStmt->bindValue(':stageId', $ParentStageId, PDO::PARAM_STR);
        $ParentStmt->bindValue(':workId', $WorkId, PDO::PARAM_STR);
        $ParentStmt->execute();
        $ParentRow = $ParentStmt->fetch();
        if (!$ParentRow) {
            Database::Rollback();
            Response::Error('親舞台が存在しません。画面を再表示してください');
        }
        $NewParentStageName = $ParentRow['stagename'];

        if ($StageId !== null) {
            // UNION（重複除去）とすることで、既存データに循環があっても再帰が終了する
            $CycleStmt = $Pdo->prepare("
                WITH RECURSIVE Ancestors AS (
                    SELECT stageId, parentStageId
                    FROM StageInfo
                    WHERE stageId = :parentStageId AND workId = :workId
                    UNION
                    SELECT s.stageId, s.parentStageId
                    FROM StageInfo s
                    INNER JOIN Ancestors a ON s.stageId = a.parentStageId
                    WHERE s.workId = :ancestorWorkId
                )
                SELECT 1 FROM Ancestors WHERE stageId = :stageId LIMIT 1
            ");
            $CycleStmt->bindValue(':parentStageId', $ParentStageId, PDO::PARAM_STR);
            $CycleStmt->bindValue(':workId', $WorkId, PDO::PARAM_STR);
            $CycleStmt->bindValue(':ancestorWorkId', $WorkId, PDO::PARAM_STR);
            $CycleStmt->bindValue(':stageId', $StageId, PDO::PARAM_STR);
            $CycleStmt->execute();
            if ($CycleStmt->fetch()) {
                Database::Rollback();
                Response::Error('配下の舞台を親舞台に設定することはできません', 400, ['FieldId' => 'StageParentId']);
            }
        }
    }

   /* ---------------------------------------------
    *  7-3. 人口の親子チェック
    *      ・配下の舞台の人口の総和は、親の人口以下とする
    *        （例：国（900万）の配下の町（50万）・町（810万）の総和 860万 は 900万 以下のため登録できる）
    *      ・人口が未入力の階層は飛ばし、上位・配下それぞれで人口が入力されている
    *        最も近い舞台と比較する。これにより、下位の舞台を先に登録し、上位の人口を後から入力する順序にも対応する
    *      ・保存後の状態（自身の人口・親舞台）をメモリ上で再現し、以下の2点を判定する
    *        ① 自身の人口 ≧ 配下の舞台の人口の総和（親の人口の登録・更新時）
    *        ② 上位で人口が入力されている最も近い舞台の人口 ≧ その配下の舞台の人口の総和
    *           （子の人口の登録・更新時、親舞台の付け替え時。総和には自身の新しい人口を含める）
    *      ・人口または親舞台を変更した場合のみ判定する。親子の関係が崩れるのはこれらの変更時のみのため、
    *        既存データに矛盾があっても、説明文などのみの変更は妨げない
    *      ・エラーは画面の入力欄にエラーアイコンで表示するため、対象の入力欄の id を返す
    * --------------------------------------------- */
    /* 1. 人口・親舞台の変更有無を判定する（新規登録は変更ありとみなす） */
    $IsPopulationRelationChanged = true;
    if ($StageId !== null) {
        $OldRelationStmt = $Pdo->prepare("SELECT population, parentStageId FROM StageInfo WHERE stageId = :stageId AND workId = :workId");
        $OldRelationStmt->bindValue(':stageId', $StageId, PDO::PARAM_STR);
        $OldRelationStmt->bindValue(':workId', $WorkId, PDO::PARAM_STR);
        $OldRelationStmt->execute();
        $OldRelation = $OldRelationStmt->fetch();
        if ($OldRelation) {
            $IsPopulationRelationChanged =
                (string) ($OldRelation['population'] ?? '') !== (string) ($Population ?? '')
                || (string) ($OldRelation['parentstageid'] ?? '') !== (string) ($ParentStageId ?? '');
        }
    }

    if ($IsPopulationRelationChanged) {
        /* 2. 同じ作品の舞台を取得し、保存後の状態を再現する（新規登録の舞台は仮のキーで追加する） */
        $SelfKey = $StageId !== null ? (string) $StageId : '__New__';
        $Stages = [];
        $StageListStmt = $Pdo->prepare("SELECT stageId, stageName, population, parentStageId FROM StageInfo WHERE workId = :workId");
        $StageListStmt->bindValue(':workId', $WorkId, PDO::PARAM_STR);
        $StageListStmt->execute();
        foreach ($StageListStmt->fetchAll() as $Row) {
            $Stages[(string) $Row['stageid']] = [
                'stagename'  => $Row['stagename'],
                'population' => $Row['population'] !== null ? (int) $Row['population'] : null,
                'parent'     => $Row['parentstageid'] !== null ? (string) $Row['parentstageid'] : null,
            ];
        }
        $Stages[$SelfKey] = [
            'stagename'  => $StageName,
            'population' => $Population,
            'parent'     => $ParentStageId,
        ];
        $Children = [];
        foreach ($Stages as $Key => $Stage) {
            if ($Stage['parent'] !== null) {
                $Children[$Stage['parent']][] = (string) $Key;
            }
        }

        /* 3. ① 自身の人口 ≧ 配下の舞台の人口の総和 */
        if ($Population !== null) {
            $LowerSum = SumChildPopulation($SelfKey, $Stages, $Children);
            if ($Population < $LowerSum) {
                Database::Rollback();
                Response::Error(
                    '人口は配下の舞台の人口の総和（' . number_format($LowerSum) . '）以上を指定してください',
                    400,
                    ['FieldId' => 'StagePopulation']
                );
            }
        }

        /* 4. ② 上位で人口が入力されている最も近い舞台の人口 ≧ その配下の舞台の人口の総和 */
        // 自身が総和に加える人口（未入力の場合は、自身の配下で人口が入力されている舞台の総和）
        $SelfContribution = $Population ?? SumChildPopulation($SelfKey, $Stages, $Children);
        // 親舞台から上位へ辿り、人口が入力されている最も近い舞台を求める
        $UpperKey = $ParentStageId;
        for ($Depth = 0; $UpperKey !== null && isset($Stages[$UpperKey]) && $Depth < 1000; $Depth++) {
            if ($Stages[$UpperKey]['population'] !== null) break;
            $UpperKey = $Stages[$UpperKey]['parent'];
        }
        // 自身が総和に加える人口が無い場合は、既存データの矛盾で保存を妨げないよう判定しない
        if ($SelfContribution > 0 && $UpperKey !== null && isset($Stages[$UpperKey]) && $Stages[$UpperKey]['population'] !== null) {
            $UpperStage = $Stages[$UpperKey];
            $UpperSum = SumChildPopulation($UpperKey, $Stages, $Children);
            if ($UpperSum > $UpperStage['population']) {
                Database::Rollback();
                if ($Population !== null) {
                    // 自身の人口として指定できる上限（上位の人口から、他の配下の舞台の人口の総和を差し引いた値）
                    $OtherSum = $UpperSum - $SelfContribution;
                    Response::Error(
                        '人口は親舞台' . FormatStagePopulation($UpperStage) . 'から、他の配下の舞台の人口の総和（'
                        . number_format($OtherSum) . '）を差し引いた値（'
                        . number_format(max(0, $UpperStage['population'] - $OtherSum)) . '）以下を指定してください',
                        400,
                        ['FieldId' => 'StagePopulation']
                    );
                }
                // 自身の人口が未入力の場合は、配下の人口の総和が新しい上位の人口を超える（親舞台の付け替え時）
                Response::Error(
                    '配下の舞台の人口の総和が、親舞台' . FormatStagePopulation($UpperStage)
                    . 'を超えるため、この親舞台には設定できません',
                    400,
                    ['FieldId' => 'StageParentId']
                );
            }
        }
    }

   /* ---------------------------------------------
     * 8. 画像の処理（保存・削除の判定）
     *  - DBトランザクション成功前にファイルを物理書込/削除しないため、
     *    ここではメモリ上に「新規書込データ」「削除対象パス」を積み、
     *    Commit 後に実際のファイル操作を行う。
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
        if (!empty($StageId)) {
            $OldImgStmt = $Pdo->prepare("SELECT imgPath, imgList FROM StageInfo WHERE stageId = :stageId AND workId = :workId");
            $OldImgStmt->bindValue(':stageId', $StageId, PDO::PARAM_STR);
            $OldImgStmt->bindValue(':workId', $WorkId, PDO::PARAM_STR);
            $OldImgStmt->execute();
            $OldImgRow = $OldImgStmt->fetch();
            if ($OldImgRow) {
                $OldImages = ImageList::Parse($OldImgRow['imglist'] ?? null, $OldImgRow['imgpath'] ?? null);
            }
        }

        /* 3. 検証・DB登録値・保留リストの作成 */
        $ImagePrepared = ImageList::Prepare($Images, $OldImages, 'Stages', 'stage');
        if (isset($ImagePrepared['Error'])) {
            Database::Rollback();
            Response::Error($ImagePrepared['Error']);
        }
        $ImgPath = $ImagePrepared['ImgPath'];
        $ImgList = $ImagePrepared['ImgList'];
    }

   /* ---------------------------------------------
    *  9. 新規登録処理
    * --------------------------------------------- */
    if (empty($StageId)) {
        $Sql = "
            INSERT INTO StageInfo (
                workId, stageName, stageType, parentStageId, population, areaSize, description, imgPath, imgList,
                registDate, updateDate, registUser, updateUser
            ) VALUES (
                :workId, :stageName, :stageType, :parentStageId, :population, :areaSize, :description, :imgPath, :imgList,
                CURRENT_TIMESTAMP(3), CURRENT_TIMESTAMP(3), :registUser, :updateUser
            )
            RETURNING stageId, updateDate
        ";

        $Stmt = $Pdo->prepare($Sql);
        $Stmt->execute([
            ':workId'      => $WorkId,
            ':stageName'   => $StageName,
            ':stageType'   => $StageType,
            ':parentStageId' => $ParentStageId,
            ':population'  => $Population,
            ':areaSize'    => $AreaSize,
            ':description' => $Description,
            // 代表画像のパス・画像一覧（JSON）。未設定の場合はNULL
            ':imgPath'     => $ImgPath,
            ':imgList'     => $ImgList,
            ':registUser'  => $UserId,
            ':updateUser'  => $UserId,
        ]);

        $InsertResult = $Stmt->fetch();
        $NewStageId = $InsertResult['stageid'];
        $NewUpdateDate = $InsertResult['updatedate'];
        // 更新履歴の詳細メッセージを生成（登録）
        $Detail = HistoryDetail::Register($StageName);

   /* ---------------------------------------------
    *  10. 更新処理
    * --------------------------------------------- */
    } else {
        /* 差分履歴用：更新前の表示値を取得（キーはPDO既定の小文字） */
        // 親舞台は名称で比較・表示するため、親舞台の名称を結合して取得する
        $OldRowStmt = $Pdo->prepare("
            SELECT s.stageName, s.stageType, s.parentStageId, p.stageName AS parentStageName,
                   s.population, s.areaSize, s.description
            FROM StageInfo s
            LEFT JOIN StageInfo p ON s.parentStageId = p.stageId
            WHERE s.stageId = :stageId AND s.workId = :workId
        ");
        $OldRowStmt->bindValue(':stageId', $StageId, PDO::PARAM_STR);
        $OldRowStmt->bindValue(':workId', $WorkId, PDO::PARAM_STR);
        $OldRowStmt->execute();
        $OldRow = $OldRowStmt->fetch() ?: [];

        // 1. 画像パス更新用のSQL句を生成（更新フラグがtrueの場合のみ）
        //    代表画像のパスと画像一覧は常に揃えて更新する
        $UpdateImgPath = $IsImageUpdated ? ", imgPath = :imgPath, imgList = :imgList" : "";

        $Sql = "
            UPDATE StageInfo SET
                stageName = :stageName,
                stageType = :stageType,
                parentStageId = :parentStageId,
                population = :population,
                areaSize = :areaSize,
                description = :description,
                updateDate = CURRENT_TIMESTAMP(3),
                updateUser = :updateUser
                {$UpdateImgPath}
            WHERE stageId = :stageId AND workId = :workId AND updateDate = :updateDate
            RETURNING updateDate
        ";

        $Stmt = $Pdo->prepare($Sql);
        $Stmt->bindValue(':stageId', $StageId);
        $Stmt->bindValue(':workId', $WorkId);
        $Stmt->bindValue(':updateDate', $UpdateDate, PDO::PARAM_STR);
        $Stmt->bindValue(':stageName', $StageName);
        $Stmt->bindValue(':stageType', $StageType, $StageType === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $Stmt->bindValue(':parentStageId', $ParentStageId, $ParentStageId === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $Stmt->bindValue(':population', $Population);
        $Stmt->bindValue(':areaSize', $AreaSize);
        $Stmt->bindValue(':description', $Description);
        $Stmt->bindValue(':updateUser', $UserId);

        // 23.画像が更新対象の場合のみ代表画像のパスと画像一覧をバインド（NULLも許容）
        if ($IsImageUpdated) {
            $Stmt->bindValue(':imgPath',  $ImgPath,      $ImgPath === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $Stmt->bindValue(':imgList',  $ImgList,      $ImgList === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        }

        $Stmt->execute();
        $UpdateResult = $Stmt->fetch();

        if (!$UpdateResult) {
            Database::Rollback();
            Response::ConflictError();
        }

        $NewStageId = $StageId;
        $NewUpdateDate = $UpdateResult['updatedate'];

        /* 更新履歴の詳細メッセージを生成（更新・列単位差分。画像は対象外） */
        // 差分対象の列 → 和名
        $Labels = [
            'stageName'   => '舞台名',
            'stageType'   => '舞台区分',
            'parentStageId' => '親舞台',
            'population'  => '人口',
            'areaSize'    => '面積',
            'description' => '説明',
        ];
        // 更新前の表示値（舞台区分はコード値のままでは読めないため名称へ変換する）
        $OldDisplay = [
            'stageName'   => $OldRow['stagename']   ?? null,
            'stageType'   => CodeLabel::Get('StageType', $OldRow['stagetype'] ?? null),
            'parentStageId' => $OldRow['parentstagename'] ?? null,
            'population'  => $OldRow['population']   ?? null,
            'areaSize'    => $OldRow['areasize']     ?? null,
            'description' => $OldRow['description']  ?? null,
        ];
        // 更新後の表示値
        $NewDisplay = [
            'stageName'   => $StageName,
            'stageType'   => CodeLabel::Get('StageType', $StageType),
            'parentStageId' => $NewParentStageName,
            'population'  => $Population,
            'areaSize'    => $AreaSize,
            'description' => $Description,
        ];
        $Detail = HistoryDetail::Update($StageName, $OldDisplay, $NewDisplay, $Labels);
    }

   /* ---------------------------------------------
    *  11. 作品情報更新
    * --------------------------------------------- */
    $UpdateWorkStmt = $Pdo->prepare("
        UPDATE WorkInfo SET
            updateDate = CURRENT_TIMESTAMP(3),
            updateUser = :updateUser
        WHERE workId = :workId
    ");
    $UpdateWorkStmt->execute([
        ':workId'     => $WorkId,
        ':updateUser' => $UserId
    ]);

   /* ---------------------------------------------
    *  12. 更新履歴登録
    * --------------------------------------------- */
    $HistoryStmt = $Pdo->prepare("
        INSERT INTO UpdateHistory (
            workId, functionName, operation, detail,
            registDate, updateDate, registUser, updateUser
        ) VALUES (
            :workId, :functionName, :operation, :detail,
            CURRENT_TIMESTAMP(3), CURRENT_TIMESTAMP(3), :registUser, :updateUser
        )
    ");
    /* 変更が無い更新は履歴に残す情報が無いため登録しない */
    if (HistoryDetail::HasChanges($Detail)) {
        $HistoryStmt->execute([
            ':workId'       => $WorkId,
            ':functionName' => $FunctionName,
            ':operation'    => $Operation,
            ':detail'       => $Detail,
            ':registUser'   => $UserId,
            ':updateUser'   => $UserId
        ]);
    }

    Database::Commit();

   /* ---------------------------------------------
    *  Commit 後のファイル操作
    *    - コミット成功後に画像を物理書込・旧画像を物理削除する。
    *    - ファイル操作失敗は DB 状態を巻き戻せないため、ログ記録に留める。
    * --------------------------------------------- */
    ImageList::ApplyFiles($ImagePrepared, $FunctionName, $UserId);

    Logger::Info($FunctionName, $Operation . '処理終了', $UserId);

    Response::Success([
        'StageId' => $NewStageId,
        'UpdateDate' => $NewUpdateDate,
        'Message' => '舞台を' . $Operation . 'しました'
    ]);

} catch (Exception $E) {
    Database::Rollback();
    Logger::Error($FunctionName, '保存処理エラー', $UserId, $E->getMessage());
    Response::Error('舞台の保存に失敗しました');
}
