<?php
/**
 * GetOrganizationList.php
 * 組織一覧取得API
 */

require_once __DIR__ . '/../Common/Database.php';
require_once __DIR__ . '/../Common/Logger.php';
require_once __DIR__ . '/../Common/Response.php';
require_once __DIR__ . '/../Common/Auth.php';

// GETメソッドのみ許可
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    Response::Error('不正なリクエストです', 405);
}

/* ==========================================================
 * 共通定義
 * ========================================================== */
$FunctionName = '組織設定';
$UserId = '';

try {
    /* ---------------------------------------------
     * 1. リクエストデータ取得
     * --------------------------------------------- */
    // 1. 作品ID
    $WorkId = $AuthWorkId ?? '';
    // 2. ユーザーID
    $UserId = $AuthUserId;

    Logger::Info($FunctionName, '一覧取得処理開始', $UserId);

    /* ---------------------------------------------
     * 2. 入力チェック
     * --------------------------------------------- */
    if (empty($WorkId)) {
        Response::Error('作品IDが指定されていません');
    }

    /* ---------------------------------------------
     * 3. DB接続
     * --------------------------------------------- */
    $Pdo = Database::GetConnection();

    /* ---------------------------------------------
     * 4. 組織一覧取得
     * --------------------------------------------- */
    $Sql = "
        SELECT
            o.orgId        AS \"OrgId\",
            o.workId       AS \"WorkId\",
            o.orgName      AS \"OrgName\",
            o.foundedDate  AS \"FoundedDate\",
            o.peopleNum    AS \"PeopleNum\",
            o.purpose      AS \"Purpose\",
            o.activity     AS \"Activity\",
            o.baseBuildId  AS \"BaseBuildId\",
            b.buildName    AS \"BuildName\",
            o.note         AS \"Note\",
            o.leaderId     AS \"LeaderId\",
            c.charaName    AS \"LeaderName\",
            o.imgPath      AS \"ImgPath\",
            o.imgList      AS \"ImgList\",
            o.registDate   AS \"RegistDate\",
            o.updateDate   AS \"UpdateDate\",
            o.registUser   AS \"RegistUser\",
            o.updateUser   AS \"UpdateUser\"
        FROM OrganizationInfo o
        LEFT JOIN BuildInfo b ON o.baseBuildId = b.buildId
        LEFT JOIN CharacterInfo c ON o.leaderId = c.charaId
        WHERE o.workId = :workId
        ORDER BY o.orgName ASC
    ";

    $Stmt = $Pdo->prepare($Sql);
    $Stmt->execute([':workId' => $WorkId]);
    $OrganizationList = $Stmt->fetchAll();

    /* ---------------------------------------------
     * 5. 建物一覧取得（プルダウン用）
     * --------------------------------------------- */
    $BuildSql = "
        SELECT
            buildId   AS \"BuildId\",
            buildName AS \"BuildName\"
        FROM BuildInfo
        WHERE workId = :workId
        ORDER BY buildName ASC
    ";

    $BuildStmt = $Pdo->prepare($BuildSql);
    $BuildStmt->execute([':workId' => $WorkId]);
    $BuildList = $BuildStmt->fetchAll();

    /* ---------------------------------------------
     * 5-2. キャラクター一覧取得（リーダープルダウン用）
     * --------------------------------------------- */
    $CharaSql = "
        SELECT
            charaId   AS \"CharaId\",
            charaName AS \"CharaName\"
        FROM CharacterInfo
        WHERE workId = :workId
        ORDER BY charaName ASC
    ";

    $CharaStmt = $Pdo->prepare($CharaSql);
    $CharaStmt->execute([':workId' => $WorkId]);
    $CharaList = $CharaStmt->fetchAll();

    /* ---------------------------------------------
     * 5-3. チーム一覧取得（設立日と結成日の整合チェック用）
     *   結成日は「2025年」「2025年01月」など粒度が混在するためSQLでは比較できず、
     *   画面側で判定するため結成日を持つチームのみを返す
     * --------------------------------------------- */
    $TeamSql = "
        SELECT
            orgId       AS \"OrgId\",
            teamName    AS \"TeamName\",
            foundedDate AS \"FoundedDate\"
        FROM TeamInfo
        WHERE workId = :workId AND orgId IS NOT NULL AND foundedDate IS NOT NULL
        ORDER BY teamName ASC
    ";

    $TeamStmt = $Pdo->prepare($TeamSql);
    $TeamStmt->execute([':workId' => $WorkId]);
    $TeamList = $TeamStmt->fetchAll();

    Logger::Info($FunctionName, '一覧取得処理終了', $UserId);

    /* ---------------------------------------------
     * 6. 成功レスポンス
     * --------------------------------------------- */
    Response::Success([
        'OrganizationList' => $OrganizationList,
        'BuildList'        => $BuildList,
        'CharaList'        => $CharaList,
        'TeamList'         => $TeamList,
        'TotalCount'       => count($OrganizationList)
    ]);

} catch (Exception $E) {
    /* ---------------------------------------------
     * 7. 例外処理
     * --------------------------------------------- */
    Logger::Error($FunctionName, '一覧取得処理エラー', $UserId, $E->getMessage());
    Response::Error('組織一覧の取得に失敗しました');
}
