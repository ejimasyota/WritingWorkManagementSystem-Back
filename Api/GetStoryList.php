<?php
/**
 * GetStoryList.php
 * ストーリー一覧取得API
 */

require_once __DIR__ . '/../Common/Database.php';
require_once __DIR__ . '/../Common/Logger.php';
require_once __DIR__ . '/../Common/Response.php';
require_once __DIR__ . '/../Common/Auth.php';
// テンプレートマスタ参照（「テンプレートから作成」ボタンの表示可否の判定）
require_once __DIR__ . '/../Common/TemplateMaster.php';

// GETメソッドのみ許可
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    Response::Error('不正なリクエストです', 405);
}

$FunctionName = 'ストーリー作成';
$UserId = '';

try {
    $WorkId = $AuthWorkId ?? '';
    $StoryId = $_GET['StoryId'] ?? null;
    $UserId = $AuthUserId;

    Logger::Info($FunctionName, '一覧取得処理開始', $UserId);

    if (empty($WorkId)) {
        Response::Error('作品IDが指定されていません');
    }

    $Pdo = Database::GetConnection();

    $Sql = "
        SELECT
            s.storyId AS \"StoryId\",
            s.storyName AS \"StoryName\",
            COALESCE(s.productionType, 0) AS \"ProductionType\",
            s.plotId AS \"PlotId\",
            p.plotName AS \"PlotName\",
            s.workId AS \"WorkId\",
            s.registDate AS \"RegistDate\",
            s.updateDate AS \"UpdateDate\",
            s.registUser AS \"RegistUser\",
            s.updateUser AS \"UpdateUser\",
            COALESCE(r.rowCount, 0) AS \"RowCount\"
        FROM StoryInfo s
        LEFT JOIN PlotInfo p ON p.plotId = s.plotId
        LEFT JOIN (
            SELECT storyId, COUNT(*) AS rowCount
            FROM StoryRows
            GROUP BY storyId
        ) r ON r.storyId = s.storyId
        WHERE s.workId = :workId
        ORDER BY s.updateDate DESC, s.storyId DESC
    ";
    $Stmt = $Pdo->prepare($Sql);
    $Stmt->execute([':workId' => $WorkId]);
    $StoryList = $Stmt->fetchAll();

    $CharaSql = "
        SELECT
            charaId AS \"CharaId\",
            charaName AS \"CharaName\"
        FROM CharacterInfo
        WHERE workId = :workId
        ORDER BY charaName ASC
    ";
    $CharaStmt = $Pdo->prepare($CharaSql);
    $CharaStmt->execute([':workId' => $WorkId]);
    $CharacterList = $CharaStmt->fetchAll();

    $PlotStmt = $Pdo->prepare("
        SELECT
            plotId AS \"PlotId\",
            plotName AS \"PlotName\",
            COALESCE(productionType, 0) AS \"ProductionType\"
        FROM PlotInfo
        WHERE workId = :workId
        ORDER BY plotName ASC, plotId ASC
    ");
    $PlotStmt->execute([':workId' => $WorkId]);
    $PlotList = $PlotStmt->fetchAll();

    $SelectedStory = null;
    $StoryRowList = [];
    if (!empty($StoryId)) {
        $SelectedStoryStmt = $Pdo->prepare("
            SELECT
                s.storyId AS \"StoryId\",
                s.storyName AS \"StoryName\",
                COALESCE(s.productionType, 0) AS \"ProductionType\",
                s.plotId AS \"PlotId\",
                p.plotName AS \"PlotName\",
                s.workId AS \"WorkId\",
                s.registDate AS \"RegistDate\",
                s.updateDate AS \"UpdateDate\",
                s.registUser AS \"RegistUser\",
                s.updateUser AS \"UpdateUser\"
            FROM StoryInfo s
            LEFT JOIN PlotInfo p ON p.plotId = s.plotId
            WHERE s.storyId = :storyId AND s.workId = :workId
        ");
        $SelectedStoryStmt->execute([
            ':storyId' => $StoryId,
            ':workId' => $WorkId
        ]);
        $SelectedStory = $SelectedStoryStmt->fetch();

        if (!$SelectedStory) {
            Response::Error('指定されたストーリーが見つかりません');
        }

        $RowStmt = $Pdo->prepare("
            SELECT
                sr.storyRowId AS \"StoryRowId\",
                sr.storyId AS \"StoryId\",
                sr.indexNum AS \"IndexNum\",
                sr.narrator AS \"Narrator\",
                sr.narratorCharaId AS \"NarratorCharaId\",
                c.charaName AS \"NarratorCharaName\",
                COALESCE(c.charaName, sr.narrator) AS \"NarratorDisplay\",
                sr.monologue AS \"Monologue\",
                sr.actionLine AS \"ActionLine\",
                sr.content AS \"Content\",
                sr.registDate AS \"RegistDate\",
                sr.updateDate AS \"UpdateDate\",
                sr.registUser AS \"RegistUser\",
                sr.updateUser AS \"UpdateUser\"
            FROM StoryRows sr
            LEFT JOIN CharacterInfo c ON c.charaId = sr.narratorCharaId
            WHERE sr.storyId = :storyId
            ORDER BY sr.indexNum ASC, sr.storyRowId ASC
        ");
        $RowStmt->execute([':storyId' => $StoryId]);
        $StoryRowList = $RowStmt->fetchAll();
    }

    Logger::Info($FunctionName, '一覧取得処理終了', $UserId);

    Response::Success([
        'StoryList' => $StoryList,
        'SelectedStory' => $SelectedStory,
        'StoryRowList' => $StoryRowList,
        'CharacterList' => $CharacterList,
        'PlotList' => $PlotList,
        /* 作品のジャンルに対応するテンプレートが無い場合、
         * ストーリー作成ダイアログの「テンプレートから作成」は表示しない */
        'TemplateRows' => TemplateMaster::ParseRows(TemplateMaster::GetTemplateText($Pdo, $WorkId, TemplateMaster::DIVISION_STORY)),
        'HasTemplate' => TemplateMaster::Exists($Pdo, $WorkId, TemplateMaster::DIVISION_STORY),
        'TotalCount' => count($StoryList)
    ]);

} catch (Exception $E) {
    Logger::Error($FunctionName, '一覧取得処理エラー', $UserId, $E->getMessage());
    Response::Error('ストーリー一覧の取得に失敗しました');
}
