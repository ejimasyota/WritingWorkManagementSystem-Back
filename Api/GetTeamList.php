<?php
/**
 * GetTeamList.php
 * チーム一覧取得API
 */

require_once __DIR__ . '/../Common/Database.php';
require_once __DIR__ . '/../Common/Logger.php';
require_once __DIR__ . '/../Common/Response.php';
require_once __DIR__ . '/../Common/Auth.php';

/* ==========================================================
 * GETメソッドのみ許可
 * ========================================================== */
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    Response::Error('不正なリクエストです', 405);
}

/* ==========================================================
 * 共通定義
 * ========================================================== */
$FunctionName = 'チーム設定';
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
     * 4. チーム一覧取得
     * --------------------------------------------- */
    $Sql = "
        SELECT
            t.teamId      AS \"TeamId\",
            t.workId      AS \"WorkId\",
            t.teamName    AS \"TeamName\",
            t.orgId       AS \"OrgId\",
            o.orgName     AS \"OrgName\",
            t.description AS \"Description\",
            t.leaderId    AS \"LeaderId\",
            c.charaName   AS \"LeaderName\",
            t.peopleNum   AS \"PeopleNum\",
            t.foundedDate AS \"FoundedDate\",
            o.foundedDate AS \"OrgFoundedDate\",
            t.imgPath     AS \"ImgPath\",
            t.imgList     AS \"ImgList\",
            t.registDate  AS \"RegistDate\",
            t.updateDate  AS \"UpdateDate\",
            t.registUser  AS \"RegistUser\",
            t.updateUser  AS \"UpdateUser\"
        FROM TeamInfo t
        LEFT JOIN OrganizationInfo o
               ON t.orgId = o.orgId
        LEFT JOIN CharacterInfo c
               ON t.leaderId = c.charaId
        WHERE t.workId = :workId
        ORDER BY t.teamName ASC
    ";

    $Stmt = $Pdo->prepare($Sql);
    $Stmt->execute([':workId' => $WorkId]);
    $TeamList = $Stmt->fetchAll();

    /* ---------------------------------------------
     * 5. 組織一覧取得（プルダウン用）
     * --------------------------------------------- */
    $OrgSql = "
        SELECT
            orgId       AS \"OrgId\",
            orgName     AS \"OrgName\",
            foundedDate AS \"FoundedDate\"
        FROM OrganizationInfo
        WHERE workId = :workId
        ORDER BY orgName ASC
    ";

    $OrgStmt = $Pdo->prepare($OrgSql);
    $OrgStmt->execute([':workId' => $WorkId]);
    $OrganizationList = $OrgStmt->fetchAll();

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

    Logger::Info($FunctionName, '一覧取得処理終了', $UserId);

    /* ---------------------------------------------
     * 6. 成功レスポンス
     * --------------------------------------------- */
    Response::Success([
        'TeamList'         => $TeamList,
        'OrganizationList' => $OrganizationList,
        'CharaList'        => $CharaList,
        'TotalCount'       => count($TeamList)
    ]);

} catch (Exception $E) {
    /* ---------------------------------------------
     * 7. 例外処理
     * --------------------------------------------- */
    Logger::Error($FunctionName, '一覧取得処理エラー', $UserId, $E->getMessage());
    Response::Error('チーム一覧の取得に失敗しました');
}
