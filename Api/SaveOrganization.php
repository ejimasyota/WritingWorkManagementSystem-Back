<?php
/* ==========================================================
 * 組織情報更新
 * ----------------------------------------------------------
 * ・組織情報を登録・更新する
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
// 日付文字列の前後関係の判定
require_once __DIR__ . '/../Common/DateUtil.php';

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
$FunctionName = '組織設定';
// 2. ユーザーID
$UserId = '';

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
    // 1. 組織ID
    $OrgId        = $Input['OrgId'] ?? null;
    // 2. 作品ID
    $WorkId       = $AuthWorkId ?? '';
    // 3. 組織名
    $OrgName      = $Input['OrgName'] ?? '';
    // 4. 設立年月日
    $FoundedDate  = $Input['FoundedDate'] ?? null;
    // 5. 人員数
    $PeopleNum    = $Input['PeopleNum'] ?? null;
    // 6. 目的
    $Purpose      = $Input['Purpose'] ?? null;
    // 7. 活動内容
    $Activity     = $Input['Activity'] ?? null;
    // 8. 拠点建物ID
    $BaseBuildId  = $Input['BaseBuildId'] ?? null;
    // 9. ユーザーID
    $UserId = $AuthUserId;
    // 10. 画像一覧（先頭が代表画像。未送信の場合は画像を更新しない）
    $Images       = $Input['Images'] ?? null;
    // 11. 備考
    $Note         = $Input['Note'] ?? false;
    // 12. 更新年月日（排他制御用）
    $UpdateDate   = $Input['UpdateDate'] ?? null;
    // 13. リーダーID
    $LeaderId     = $Input['LeaderId'] ?? null;

   /* ---------------------------------------------
    *  3. 操作設定
    * --------------------------------------------- */
    $Operation = empty($OrgId) ? '登録' : '更新';
    // 更新履歴の詳細メッセージ（登録/更新分岐でそれぞれ生成）
    $Detail = null;

   /* ---------------------------------------------
    *  4. バリデーションチェック
    * --------------------------------------------- */
    if (empty($WorkId)) {
        Response::Error('作品IDが指定されていません');
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
    $OrgId       = !empty($OrgId) ? $OrgId : null;
    $FoundedDate = !empty($FoundedDate) ? $FoundedDate : null;
    $PeopleNum   = !empty($PeopleNum) ? $PeopleNum : null;
    $Purpose     = !empty($Purpose) ? $Purpose : null;
    $Activity    = !empty($Activity) ? $Activity : null;
    $BaseBuildId = !empty($BaseBuildId) ? $BaseBuildId : null;
    $Note        = !empty($Note) ? $Note : null;
    $LeaderId    = !empty($LeaderId) ? $LeaderId : null;

    // 人数は配下チームとの整合性チェック（7-3）で比較するため BIGINT で保持する。数値以外は受け付けない
    if ($PeopleNum !== null) {
        if (!preg_match('/\A[0-9]{1,15}\z/', trim((string) $PeopleNum))) {
            Database::Rollback();
            Response::Error('人数は0以上の整数（15桁以内）で入力してください');
        }
        $PeopleNum = (int) trim((string) $PeopleNum);
    }

   /* ---------------------------------------------
    *  7-1. 関連ID（拠点建物・リーダー）の検証
    *    他作品のIDを保存されると、作品条件の無い結合経由で
    *    他作品の名称が参照できてしまうため、同一作品内に存在することを要求する。
    *    形式不正のIDは型変換エラーとなるため、クエリ前に不正として扱う。
    * --------------------------------------------- */
    // 1. 検証対象（項目名 => [テーブル名, ID列名, 値, IDの形式]）
    $RelationChecks = [
        '拠点建物' => ['BuildInfo',     'buildId', $BaseBuildId, '/\A[1-9][0-9]{0,18}\z/'],
        'リーダー' => ['CharacterInfo', 'charaId', $LeaderId,    '/\A[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}\z/'],
    ];
    // 2. 未選択（NULL）以外を検証する
    foreach ($RelationChecks as $RelationLabel => [$RelationTable, $RelationIdCol, $RelationId, $RelationPattern]) {
        if ($RelationId === null) {
            continue;
        }
        $RelationExists = false;
        if (is_scalar($RelationId) && preg_match($RelationPattern, (string) $RelationId)) {
            $RelationStmt = $Pdo->prepare("SELECT 1 FROM {$RelationTable} WHERE {$RelationIdCol} = :id AND workId = :workId");
            $RelationStmt->bindValue(':id', (string) $RelationId, PDO::PARAM_STR);
            $RelationStmt->bindValue(':workId', $WorkId, PDO::PARAM_STR);
            $RelationStmt->execute();
            $RelationExists = $RelationStmt->fetchColumn() !== false;
        }
        if (!$RelationExists) {
            Database::Rollback();
            Response::Error($RelationLabel . 'が存在しません。画面を再表示してください');
        }
    }

   /* ---------------------------------------------
    *  7-2. 配下チームとの整合チェック
    *    チームは所属組織より後に結成されるため、
    *    設立日を配下チームの結成日より後へ変更することは認めない
    * --------------------------------------------- */
    if ($OrgId !== null && $FoundedDate !== null) {
        $TeamDateStmt = $Pdo->prepare("
            SELECT teamName, foundedDate
            FROM TeamInfo
            WHERE orgId = :orgId AND workId = :workId AND foundedDate IS NOT NULL
        ");
        $TeamDateStmt->bindValue(':orgId',  $OrgId,  PDO::PARAM_INT);
        $TeamDateStmt->bindValue(':workId', $WorkId, PDO::PARAM_STR);
        $TeamDateStmt->execute();

        foreach ($TeamDateStmt->fetchAll() as $TeamRow) {
            $Compared = DateUtil::Compare($FoundedDate, $TeamRow['foundeddate'] ?? null);
            if ($Compared !== null && $Compared > 0) {
                Database::Rollback();
                Response::Error(
                    '設立日は配下のチーム「' . ($TeamRow['teamname'] ?? '')
                    . '」の結成日（' . ($TeamRow['foundeddate'] ?? '') . '）以前を指定してください'
                );
            }
        }
    }

   /* ---------------------------------------------
    *  7-3. 配下チームとの人数の整合チェック
    *    チームの人数は所属組織の人数以下とする（チームどうしの合計ではなく1件ずつ比較する。
    *    1人が複数のチームに所属し得るため、合計では判定できない）。
    *    人数を変更した場合のみ判定し、配下チームのうち人数が最も多いチームと比較する
    *    （超えているチームが複数あっても、必要な下限が一度で分かるようにするため）。
    *    チームの保存（SaveTeam.php）と同時に実行されると互いの変更を見ずに通過し得るため、
    *    同じ作品の組織・チームの人数の判定はアドバイザリロック（キー1004）で直列化する。
    * --------------------------------------------- */
    if ($OrgId !== null && $PeopleNum !== null) {
        $LockStmt = $Pdo->prepare("SELECT pg_advisory_xact_lock(1004, hashtext(:workId))");
        $LockStmt->execute([':workId' => $WorkId]);

        $OldPeopleStmt = $Pdo->prepare("SELECT peopleNum FROM OrganizationInfo WHERE orgId = :orgId AND workId = :workId");
        $OldPeopleStmt->bindValue(':orgId',  $OrgId,  PDO::PARAM_INT);
        $OldPeopleStmt->bindValue(':workId', $WorkId, PDO::PARAM_STR);
        $OldPeopleStmt->execute();
        $OldPeopleRow = $OldPeopleStmt->fetch();

        if (!$OldPeopleRow || (string) ($OldPeopleRow['peoplenum'] ?? '') !== (string) $PeopleNum) {
            $TeamPeopleStmt = $Pdo->prepare("
                SELECT teamName, peopleNum
                FROM TeamInfo
                WHERE orgId = :orgId AND workId = :workId AND peopleNum IS NOT NULL
                ORDER BY peopleNum DESC
                LIMIT 1
            ");
            $TeamPeopleStmt->bindValue(':orgId',  $OrgId,  PDO::PARAM_INT);
            $TeamPeopleStmt->bindValue(':workId', $WorkId, PDO::PARAM_STR);
            $TeamPeopleStmt->execute();
            $MaxTeamRow = $TeamPeopleStmt->fetch();

            if ($MaxTeamRow && $PeopleNum < (int) $MaxTeamRow['peoplenum']) {
                Database::Rollback();
                Response::Error(
                    '人数は配下のチーム「' . ($MaxTeamRow['teamname'] ?? '')
                    . '」の人数（' . number_format((int) $MaxTeamRow['peoplenum']) . '）以上を指定してください'
                );
            }
        }
    }

   /* ---------------------------------------------
    *  8. 画像の処理（保存・削除の判定）
    *    - DB Commit 前にファイル書込/削除は行わず、Commit 後に実行するための
    *      保留リストを構築する。
    *    - 検証・保留リストの作成は ImageList（他画面と共通）で行う。
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
        if (!empty($OrgId)) {
            $OldImgStmt = $Pdo->prepare("SELECT imgPath, imgList FROM OrganizationInfo WHERE orgId = :orgId AND workId = :workId");
            $OldImgStmt->bindValue(':orgId', $OrgId, PDO::PARAM_STR);
            $OldImgStmt->bindValue(':workId', $WorkId, PDO::PARAM_STR);
            $OldImgStmt->execute();
            $OldImgRow = $OldImgStmt->fetch();
            if ($OldImgRow) {
                $OldImages = ImageList::Parse($OldImgRow['imglist'] ?? null, $OldImgRow['imgpath'] ?? null);
            }
        }

        /* 3. 検証・DB登録値・保留リストの作成 */
        $ImagePrepared = ImageList::Prepare($Images, $OldImages, 'Organizations', 'org');
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
    if (empty($OrgId)) {
        $Sql = "
            INSERT INTO OrganizationInfo (
                workId, orgName, foundedDate, peopleNum, purpose, activity, note,
                baseBuildId, leaderId, imgPath, imgList, registDate, updateDate, registUser, updateUser
            ) VALUES (
                :workId, :orgName, :foundedDate, :peopleNum, :purpose, :activity, :note,
                :baseBuildId, :leaderId, :imgPath, :imgList, CURRENT_TIMESTAMP(3), CURRENT_TIMESTAMP(3), :registUser, :updateUser
            )
            RETURNING orgId, updateDate
        ";

        $Stmt = $Pdo->prepare($Sql);
        $Stmt->execute([
            ':workId'       => $WorkId,
            ':orgName'      => $OrgName,
            ':foundedDate'  => $FoundedDate,
            ':peopleNum'    => $PeopleNum,
            ':purpose'      => $Purpose,
            ':activity'     => $Activity,
            ':note'         => $Note,
            ':baseBuildId'  => $BaseBuildId,
            ':leaderId'     => $LeaderId,
            ':imgPath'      => $ImgPath,
            ':imgList'      => $ImgList,
            ':registUser'   => $UserId,
            ':updateUser'   => $UserId,
        ]);

        $InsertResult = $Stmt->fetch();
        $NewOrgId = $InsertResult['orgid'];
        $NewUpdateDate = $InsertResult['updatedate'];

        // 更新履歴の詳細メッセージを生成（登録）
        $Detail = HistoryDetail::Register($OrgName);

   /* ---------------------------------------------
    *  10. 更新処理
    * --------------------------------------------- */
    } else {
        /* 差分履歴用：更新前の表示値を取得（FK列は名称へ解決するため JOIN。取得キーは小文字） */
        $OldRowStmt = $Pdo->prepare("
            SELECT
                o.orgName, o.foundedDate, o.peopleNum, o.purpose, o.activity, o.note,
                o.baseBuildId, b.buildName, o.leaderId, c.charaName
            FROM OrganizationInfo o
            LEFT JOIN BuildInfo b     ON o.baseBuildId = b.buildId
            LEFT JOIN CharacterInfo c ON o.leaderId    = c.charaId
            WHERE o.orgId = :orgId AND o.workId = :workId
        ");
        $OldRowStmt->bindValue(':orgId', $OrgId, PDO::PARAM_STR);
        $OldRowStmt->bindValue(':workId', $WorkId, PDO::PARAM_STR);
        $OldRowStmt->execute();
        $OldRow = $OldRowStmt->fetch() ?: [];

        $UpdateImgPath = $IsImageUpdated ? ", imgPath = :imgPath, imgList = :imgList" : "";

        $Sql = "
            UPDATE OrganizationInfo SET
                orgName = :orgName,
                foundedDate = :foundedDate,
                peopleNum = :peopleNum,
                purpose = :purpose,
                activity = :activity,
                note = :note,
                baseBuildId = :baseBuildId,
                leaderId = :leaderId,
                updateDate = CURRENT_TIMESTAMP(3),
                updateUser = :updateUser
                {$UpdateImgPath}
            WHERE orgId = :orgId AND workId = :workId AND updateDate = :updateDate
            RETURNING updateDate
        ";

        $Stmt = $Pdo->prepare($Sql);
        $Stmt->bindValue(':orgId', $OrgId);
        $Stmt->bindValue(':workId', $WorkId);
        $Stmt->bindValue(':updateDate', $UpdateDate, PDO::PARAM_STR);
        $Stmt->bindValue(':orgName', $OrgName);
        $Stmt->bindValue(':foundedDate', $FoundedDate);
        $Stmt->bindValue(':peopleNum', $PeopleNum);
        $Stmt->bindValue(':purpose', $Purpose);
        $Stmt->bindValue(':activity', $Activity);
        $Stmt->bindValue(':note', $Note);
        $Stmt->bindValue(':baseBuildId', $BaseBuildId);
        $Stmt->bindValue(':leaderId', $LeaderId);
        $Stmt->bindValue(':updateUser', $UserId);

        if ($IsImageUpdated) {
            $Stmt->bindValue(':imgPath', $ImgPath, $ImgPath === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $Stmt->bindValue(':imgList', $ImgList, $ImgList === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        }

        $Stmt->execute();
        $UpdateResult = $Stmt->fetch();

        if (!$UpdateResult) {
            Database::Rollback();
            Response::ConflictError();
        }

        $NewOrgId = $OrgId;
        $NewUpdateDate = $UpdateResult['updatedate'];

        /* 更新履歴の詳細メッセージを生成（更新・列単位差分）
         * FK列は新IDが変わった場合のみ名称を再解決する（未変更は旧名称を再利用）。 */
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
        $NewFkName = function ($Table, $IdCol, $NameCol, $NewId, $OldId, $OldName) use ($ResolveName) {
            if ((string) ($NewId ?? '') === (string) ($OldId ?? '')) {
                return $OldName;
            }
            return $ResolveName($Table, $IdCol, $NameCol, $NewId);
        };

        $Labels = [
            'orgName'     => '組織名',
            'foundedDate' => '設立年月日',
            'peopleNum'   => '人員数',
            'purpose'     => '目的',
            'activity'    => '活動内容',
            'baseBuildId' => '拠点建物',
            'leaderId'    => 'リーダー',
            'note'        => '備考',
        ];
        $OldDisplay = [
            'orgName'     => $OldRow['orgname']     ?? null,
            'foundedDate' => $OldRow['foundeddate'] ?? null,
            'peopleNum'   => $OldRow['peoplenum']   ?? null,
            'purpose'     => $OldRow['purpose']     ?? null,
            'activity'    => $OldRow['activity']    ?? null,
            'baseBuildId' => $OldRow['buildname']   ?? null,
            'leaderId'    => $OldRow['charaname']   ?? null,
            'note'        => $OldRow['note']        ?? null,
        ];
        $NewDisplay = [
            'orgName'     => $OrgName,
            'foundedDate' => $FoundedDate,
            'peopleNum'   => $PeopleNum,
            'purpose'     => $Purpose,
            'activity'    => $Activity,
            'baseBuildId' => $NewFkName('BuildInfo',     'buildId', 'buildName', $BaseBuildId, $OldRow['basebuildid'] ?? null, $OldRow['buildname'] ?? null),
            'leaderId'    => $NewFkName('CharacterInfo', 'charaId', 'charaName', $LeaderId,    $OldRow['leaderid']    ?? null, $OldRow['charaname'] ?? null),
            'note'        => $Note,
        ];
        $Detail = HistoryDetail::Update($OrgName, $OldDisplay, $NewDisplay, $Labels);
    }

   /* ---------------------------------------------
    *  11.作品情報更新
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
    *  12.更新履歴登録
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
        'OrgId' => $NewOrgId,
        'UpdateDate' => $NewUpdateDate,
        'Message' => '組織を' . $Operation . 'しました'
    ]);

} catch (Exception $E) {
    Database::Rollback();
    Logger::Error($FunctionName, '保存処理エラー', $UserId, $E->getMessage());
    Response::Error('組織の保存に失敗しました');
}
