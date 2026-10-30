<?php
/* ==========================================================
 * チーム情報更新
 * ----------------------------------------------------------
 * ・チーム情報を登録・更新する
 * ----------------------------------------------------------
 * 更新履歴：
 * 2026-01-11 作成
 * ========================================================== */

/* ==========================================================
 * 1. 共通モジュール読み込み
 * ========================================================== */
require_once __DIR__ . '/../Common/Database.php';
require_once __DIR__ . '/../Common/Logger.php';
require_once __DIR__ . '/../Common/Response.php';
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
$FunctionName = 'チーム設定';
$UserId = '';

/* ==========================================================
 * 4. 処理
 * ========================================================== */
try {

   /* ---------------------------------------------
    * 1. リクエスト取得
    * --------------------------------------------- */
    $Input = Response::GetJsonInput();

   /* ---------------------------------------------
    * 2. リクエスト内容定義
    * --------------------------------------------- */
    $TeamId      = $Input['TeamId'] ?? null;
    $WorkId      = $AuthWorkId ?? '';
    $TeamName    = $Input['TeamName'] ?? '';
    $OrgId       = $Input['OrgId'] ?? null;
    $Description = $Input['Description'] ?? null;
    $LeaderId    = $Input['LeaderId'] ?? null;
    $PeopleNum   = $Input['PeopleNum'] ?? null;
    $FoundedDate = $Input['FoundedDate'] ?? null;
    $UserId = $AuthUserId;
    // 画像一覧（未送信の場合は画像を更新しない）
    $Images      = $Input['Images'] ?? null;
    $UpdateDate  = $Input['UpdateDate'] ?? null;

   /* ---------------------------------------------
    * 3. 操作設定
    * --------------------------------------------- */
    $Operation = empty($TeamId) ? '登録' : '更新';
    // 更新履歴の詳細メッセージ（登録/更新分岐でそれぞれ生成）
    $Detail = null;

   /* ---------------------------------------------
    * 4. 操作ログ出力
    * --------------------------------------------- */
    Logger::Info($FunctionName, $Operation . '処理開始', $UserId);

   /* ---------------------------------------------
    * 5. バリデーション
    * --------------------------------------------- */
    if (empty($WorkId)) {
        Response::Error('作品IDが指定されていません');
    }
    if (empty($UserId)) {
        Response::Error('ユーザーIDが指定されていません');
    }

   /* ---------------------------------------------
    * 6. DB接続
    * --------------------------------------------- */
    $Pdo = Database::GetConnection();
    Database::BeginTransaction();

   /* ---------------------------------------------
    * 7. 空文字をNULLに変換
    * --------------------------------------------- */
    $TeamId      = !empty($TeamId) ? $TeamId : null;
    $OrgId       = !empty($OrgId) ? $OrgId : null;
    $Description = !empty($Description) ? $Description : null;
    $LeaderId    = !empty($LeaderId) ? $LeaderId : null;
    $PeopleNum   = !empty($PeopleNum) ? $PeopleNum : null;
    $FoundedDate = !empty($FoundedDate) ? $FoundedDate : null;

    // 人数は所属組織との整合性チェック（7-3）で比較するため BIGINT で保持する。数値以外は受け付けない
    if ($PeopleNum !== null) {
        if (!preg_match('/\A[0-9]{1,15}\z/', trim((string) $PeopleNum))) {
            Database::Rollback();
            Response::Error('人数は0以上の整数（15桁以内）で入力してください');
        }
        $PeopleNum = (int) trim((string) $PeopleNum);
    }

   /* ---------------------------------------------
    * 7-1. 関連ID（所属組織・リーダー）の検証
    *   他作品のIDを保存されると、作品条件の無い結合経由で
    *   他作品の名称が参照できてしまうため、結成日の有無に関わらず
    *   同一作品内に存在することを要求する。
    *   形式不正のIDは型変換エラーとなるため、クエリ前に不正として扱う。
    * --------------------------------------------- */
    // 1. 検証対象（項目名 => [テーブル名, ID列名, 値, IDの形式]）
    $RelationChecks = [
        '所属組織' => ['OrganizationInfo', 'orgId',   $OrgId,    '/\A[1-9][0-9]{0,18}\z/'],
        'リーダー' => ['CharacterInfo',    'charaId', $LeaderId, '/\A[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}\z/'],
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
    * 7-2. 所属組織との整合チェック
    *   チームは所属組織より後に結成されるため、
    *   結成日が組織の設立日より過去となる登録は認めない
    * --------------------------------------------- */
    if ($OrgId !== null && $FoundedDate !== null) {
        $OrgDateStmt = $Pdo->prepare("
            SELECT orgName, foundedDate
            FROM OrganizationInfo
            WHERE orgId = :orgId AND workId = :workId
        ");
        $OrgDateStmt->bindValue(':orgId',  $OrgId,  PDO::PARAM_INT);
        $OrgDateStmt->bindValue(':workId', $WorkId, PDO::PARAM_STR);
        $OrgDateStmt->execute();
        $OrgDateRow = $OrgDateStmt->fetch() ?: [];
        $OrgFoundedDate = $OrgDateRow['foundeddate'] ?? null;

        $Compared = DateUtil::Compare($FoundedDate, $OrgFoundedDate);
        if ($Compared !== null && $Compared < 0) {
            Database::Rollback();
            Response::Error(
                '結成日は所属組織「' . ($OrgDateRow['orgname'] ?? '')
                . '」の設立日（' . $OrgFoundedDate . '）以降を指定してください'
            );
        }
    }

   /* ---------------------------------------------
    * 7-3. 所属組織との人数の整合チェック
    *   チームの人数は所属組織の人数以下とする（チームどうしの合計ではなく1件ずつ比較する。
    *   1人が複数のチームに所属し得るため、合計では判定できない）。
    *   人数または所属組織を変更した場合のみ判定する。
    *   組織の保存（SaveOrganization.php）と同時に実行されると互いの変更を見ずに通過し得るため、
    *   同じ作品の組織・チームの人数の判定はアドバイザリロック（キー1004）で直列化する。
    * --------------------------------------------- */
    if ($OrgId !== null && $PeopleNum !== null) {
        $LockStmt = $Pdo->prepare("SELECT pg_advisory_xact_lock(1004, hashtext(:workId))");
        $LockStmt->execute([':workId' => $WorkId]);

        // 1. 人数・所属組織の変更有無（新規登録は変更ありとみなす）
        $IsPeopleRelationChanged = true;
        if ($TeamId !== null) {
            $OldPeopleStmt = $Pdo->prepare("SELECT peopleNum, orgId FROM TeamInfo WHERE teamId = :teamId AND workId = :workId");
            $OldPeopleStmt->bindValue(':teamId', $TeamId, PDO::PARAM_INT);
            $OldPeopleStmt->bindValue(':workId', $WorkId, PDO::PARAM_STR);
            $OldPeopleStmt->execute();
            $OldPeopleRow = $OldPeopleStmt->fetch();
            if ($OldPeopleRow) {
                $IsPeopleRelationChanged =
                    (string) ($OldPeopleRow['peoplenum'] ?? '') !== (string) $PeopleNum
                    || (string) ($OldPeopleRow['orgid'] ?? '') !== (string) $OrgId;
            }
        }

        // 2. 所属組織の人数と比較する（組織の人数が未入力の場合は比較しない）
        if ($IsPeopleRelationChanged) {
            $OrgPeopleStmt = $Pdo->prepare("SELECT orgName, peopleNum FROM OrganizationInfo WHERE orgId = :orgId AND workId = :workId");
            $OrgPeopleStmt->bindValue(':orgId',  $OrgId,  PDO::PARAM_INT);
            $OrgPeopleStmt->bindValue(':workId', $WorkId, PDO::PARAM_STR);
            $OrgPeopleStmt->execute();
            $OrgPeopleRow = $OrgPeopleStmt->fetch();

            if ($OrgPeopleRow && $OrgPeopleRow['peoplenum'] !== null && $PeopleNum > (int) $OrgPeopleRow['peoplenum']) {
                Database::Rollback();
                Response::Error(
                    '人数は所属組織「' . ($OrgPeopleRow['orgname'] ?? '')
                    . '」の人数（' . number_format((int) $OrgPeopleRow['peoplenum']) . '）以下を指定してください'
                );
            }
        }
    }

   /* ---------------------------------------------
    * 8. 画像の処理（保存・削除の判定）
    *   - DBトランザクション成功前にファイルを物理書込/削除しないため、
    *     ここではメモリ上に「新規書込データ」「削除対象パス」を積み、
    *     Commit 後の 13-2. で実際のファイル操作を行う。
    *   - 検証・保留リストの作成は ImageList（他画面と共通）で行う。
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
        if (!empty($TeamId)) {
            $OldImgStmt = $Pdo->prepare("SELECT imgPath, imgList FROM TeamInfo WHERE teamId = :teamId AND workId = :workId");
            $OldImgStmt->bindValue(':teamId', $TeamId, PDO::PARAM_STR);
            $OldImgStmt->bindValue(':workId', $WorkId, PDO::PARAM_STR);
            $OldImgStmt->execute();
            $OldImgRow = $OldImgStmt->fetch();
            if ($OldImgRow) {
                $OldImages = ImageList::Parse($OldImgRow['imglist'] ?? null, $OldImgRow['imgpath'] ?? null);
            }
        }

        /* 3. 検証・DB登録値・保留リストの作成 */
        $ImagePrepared = ImageList::Prepare($Images, $OldImages, 'Teams', 'team');
        if (isset($ImagePrepared['Error'])) {
            Database::Rollback();
            Response::Error($ImagePrepared['Error']);
        }
        $ImgPath = $ImagePrepared['ImgPath'];
        $ImgList = $ImagePrepared['ImgList'];
    }

   /* ---------------------------------------------
    * 9. 新規登録処理
    * --------------------------------------------- */
    if (empty($TeamId)) {

        $Sql = "
            INSERT INTO TeamInfo (
                workId, teamName, orgId, description, leaderId, peopleNum, foundedDate, imgPath, imgList,
                registDate, updateDate, registUser, updateUser
            ) VALUES (
                :workId, :teamName, :orgId, :description, :leaderId, :peopleNum, :foundedDate, :imgPath, :imgList,
                CURRENT_TIMESTAMP(3), CURRENT_TIMESTAMP(3),
                :registUser, :updateUser
            )
            RETURNING teamId, updateDate
        ";

        $Stmt = $Pdo->prepare($Sql);
        $Stmt->bindValue(':workId',      $WorkId,      PDO::PARAM_STR);
        $Stmt->bindValue(':teamName',    $TeamName,    PDO::PARAM_STR);
        $Stmt->bindValue(':orgId',       $OrgId,       $OrgId === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $Stmt->bindValue(':description', $Description,$Description === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $Stmt->bindValue(':leaderId',    $LeaderId,    $LeaderId === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $Stmt->bindValue(':peopleNum',   $PeopleNum,   $PeopleNum === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $Stmt->bindValue(':foundedDate', $FoundedDate,  $FoundedDate === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $Stmt->bindValue(':imgPath',     $ImgPath,     $ImgPath === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $Stmt->bindValue(':imgList',     $ImgList,     $ImgList === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $Stmt->bindValue(':registUser',  $UserId,      PDO::PARAM_STR);
        $Stmt->bindValue(':updateUser',  $UserId,      PDO::PARAM_STR);

        $Stmt->execute();
        $InsertResult = $Stmt->fetch();
        $NewTeamId = $InsertResult['teamid'];
        $NewUpdateDate = $InsertResult['updatedate'];

        // 更新履歴の詳細メッセージを生成（登録）
        $Detail = HistoryDetail::Register($TeamName);

   /* ---------------------------------------------
    * 10. 更新処理
    * --------------------------------------------- */
    } else {

        /* 差分履歴用：更新前の表示値を取得（FK列は名称へ解決するため JOIN。取得キーは小文字） */
        $OldRowStmt = $Pdo->prepare("
            SELECT
                t.teamName, t.orgId, o.orgName, t.description,
                t.leaderId, c.charaName, t.peopleNum, t.foundedDate
            FROM TeamInfo t
            LEFT JOIN OrganizationInfo o ON t.orgId    = o.orgId
            LEFT JOIN CharacterInfo c    ON t.leaderId = c.charaId
            WHERE t.teamId = :teamId AND t.workId = :workId
        ");
        $OldRowStmt->bindValue(':teamId', $TeamId, PDO::PARAM_STR);
        $OldRowStmt->bindValue(':workId', $WorkId, PDO::PARAM_STR);
        $OldRowStmt->execute();
        $OldRow = $OldRowStmt->fetch() ?: [];

        $UpdateImgPath = $IsImageUpdated ? ", imgPath = :imgPath, imgList = :imgList" : "";

        $Sql = "
            UPDATE TeamInfo SET
                teamName = :teamName,
                orgId = :orgId,
                description = :description,
                leaderId = :leaderId,
                peopleNum = :peopleNum,
                foundedDate = :foundedDate,
                updateDate = CURRENT_TIMESTAMP(3),
                updateUser = :updateUser
                {$UpdateImgPath}
            WHERE teamId = :teamId AND workId = :workId AND updateDate = :updateDate
            RETURNING updateDate
        ";

        $Stmt = $Pdo->prepare($Sql);
        $Stmt->bindValue(':teamId',      $TeamId,      PDO::PARAM_STR);
        $Stmt->bindValue(':workId',      $WorkId,      PDO::PARAM_STR);
        $Stmt->bindValue(':updateDate',  $UpdateDate,  PDO::PARAM_STR);
        $Stmt->bindValue(':teamName',    $TeamName,    PDO::PARAM_STR);
        $Stmt->bindValue(':orgId',       $OrgId,       $OrgId === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $Stmt->bindValue(':description', $Description,$Description === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $Stmt->bindValue(':leaderId',    $LeaderId,    $LeaderId === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $Stmt->bindValue(':peopleNum',   $PeopleNum,   $PeopleNum === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $Stmt->bindValue(':foundedDate', $FoundedDate,  $FoundedDate === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $Stmt->bindValue(':updateUser',  $UserId,      PDO::PARAM_STR);

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

        $NewTeamId = $TeamId;
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
            'teamName'    => 'チーム名',
            'orgId'       => '組織',
            'description' => '説明',
            'leaderId'    => 'リーダー',
            'peopleNum'   => '人員数',
            'foundedDate' => '設立年月日',
        ];
        $OldDisplay = [
            'teamName'    => $OldRow['teamname']    ?? null,
            'orgId'       => $OldRow['orgname']     ?? null,
            'description' => $OldRow['description'] ?? null,
            'leaderId'    => $OldRow['charaname']   ?? null,
            'peopleNum'   => $OldRow['peoplenum']   ?? null,
            'foundedDate' => $OldRow['foundeddate'] ?? null,
        ];
        $NewDisplay = [
            'teamName'    => $TeamName,
            'orgId'       => $NewFkName('OrganizationInfo', 'orgId',   'orgName',   $OrgId,    $OldRow['orgid']    ?? null, $OldRow['orgname']   ?? null),
            'description' => $Description,
            'leaderId'    => $NewFkName('CharacterInfo',    'charaId', 'charaName', $LeaderId, $OldRow['leaderid'] ?? null, $OldRow['charaname'] ?? null),
            'peopleNum'   => $PeopleNum,
            'foundedDate' => $FoundedDate,
        ];
        $Detail = HistoryDetail::Update($TeamName, $OldDisplay, $NewDisplay, $Labels);
    }

   /* ---------------------------------------------
    * 11. 作品情報更新
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
    * 12. 更新履歴登録
    * --------------------------------------------- */
    $HistoryStmt = $Pdo->prepare("
        INSERT INTO UpdateHistory (
            workId, functionName, operation, detail,
            registDate, updateDate, registUser, updateUser
        ) VALUES (
            :workId, :functionName, :operation, :detail,
            CURRENT_TIMESTAMP(3), CURRENT_TIMESTAMP(3),
            :registUser, :updateUser
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

   /* ---------------------------------------------
    * 13. コミット
    * --------------------------------------------- */
    Database::Commit();

   /* ---------------------------------------------
    * 13-2. Commit 後のファイル操作
    *   Commit 成功後に画像を物理書込・旧画像を物理削除する。
    *   ファイル操作失敗時は DB 状態を巻き戻せないため、ログ記録に留める。
    * --------------------------------------------- */
    ImageList::ApplyFiles($ImagePrepared, $FunctionName, $UserId);

   /* ---------------------------------------------
    * 14. 操作ログ
    * --------------------------------------------- */
    Logger::Info($FunctionName, $Operation . '処理終了', $UserId);

   /* ---------------------------------------------
    * 15. 成功レスポンス
    * --------------------------------------------- */
    Response::Success([
        'TeamId'  => $NewTeamId,
        'UpdateDate' => $NewUpdateDate,
        'Message' => 'チームを' . $Operation . 'しました'
    ]);

} catch (Exception $E) {
    Database::Rollback();
    Logger::Error($FunctionName, '保存処理エラー', $UserId, $E->getMessage());
    Response::Error('チームの保存に失敗しました');
}
