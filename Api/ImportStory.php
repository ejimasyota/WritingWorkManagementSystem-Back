<?php
/**
 * ImportStory.php
 * ストーリー行取込API
 *
 * テキストファイル（.txt）またはWordファイル（.docx）の内容を、1行を1レコードとして
 * ストーリー行へ取り込む。処理はデリートインサートとし、既存の行は全て削除する。
 *
 * 行の判定（行全体が以下の形式に一致する場合のみ。空行は取り込まない）
 *   名前「内容」 … 名前に一致するキャラが存在すればそのキャラ、存在しなければ自由入力の語り手
 *   名前（内容） … 上記に加えてモノローグ
 *   上記以外     … ナレーション（語り手なし）
 * 内容の整形（「」・() の付与）は、画面から登録した行と同じ状態となるよう SaveStory.php に揃える。
 */

require_once __DIR__ . '/../Common/Database.php';
require_once __DIR__ . '/../Common/Logger.php';
require_once __DIR__ . '/../Common/Response.php';
require_once __DIR__ . '/../Common/Auth.php';
// 更新履歴 詳細メッセージ生成
require_once __DIR__ . '/../Common/HistoryDetail.php';

// POSTメソッドのみ許可
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::Error('不正なリクエストです', 405);
}

require_once __DIR__.'/../Common/StoryImport.php';
$FunctionName = 'ストーリー作成';
$UserId = '';

try {
    $Input = Response::GetJsonInput();
    $WorkId = $AuthWorkId ?? '';
    $UserId = $AuthUserId;
    $StoryId = $Input['StoryId'] ?? null;
    $FileName = $Input['FileName'] ?? '';
    $FileData = $Input['FileData'] ?? '';

    Logger::Info($FunctionName, '取込処理開始', $UserId);

    /* ---------- 入力チェック ---------- */
    if (empty($WorkId)) {
        Response::Error('作品IDが指定されていません');
    }
    if (empty($StoryId) && ($Input['Preview'] ?? false) !== true) {
        Response::Error('ストーリーIDが指定されていません');
    }
    // 画面側の拡張子チェックは改ざん可能なため、API側でも同じ判定を行う
    $ImportType = GetStoryImportType($FileName);
    if ($ImportType === null) {
        Response::Error('テキストファイル（.txt）またはWordファイル（.docx）以外は選択できません');
    }
    $Binary = is_string($FileData) ? base64_decode($FileData, true) : false;
    if ($Binary === false) {
        Response::Error('ファイルの読み込みに失敗しました');
    }
    if (strlen($Binary) > STORY_IMPORT_MAX_FILE_SIZE) {
        Response::Error('ファイルサイズは' . (STORY_IMPORT_MAX_FILE_SIZE / 1024 / 1024) . 'MB以下にしてください');
    }
    if ($Binary === '') {
        Response::Error('ファイルの内容が空です');
    }

    /* ---------- ファイルの内容を行へ変換する ---------- */
    $RawLines = [];
    try {
        $RawLines = $ImportType === 'docx'
            ? ReadStoryImportDocx($Binary)
            : ReadStoryImportText($Binary);
    } catch (StoryImportException $E) {
        Response::Error($E->getMessage());
    }
    $Lines = [];
    foreach ($RawLines as $RawLine) {
        // 全角スペースのみの行も空行として扱う
        $Line = TrimStoryImportText((string) $RawLine);
        if ($Line !== '') {
            $Lines[] = $Line;
        }
    }
    if (empty($Lines)) {
        Response::Error('取り込める行がありません。ファイルの内容を確認してください');
    }

    $Pdo = Database::GetConnection();
    /* ---------- 語り手の解決用に、キャラ名 => キャラID の対応を作る ----------
     * キャラ名は一意ではないため、同名のキャラが複数存在する場合は最初に登録されたキャラを採用する */
    $CharaStmt = $Pdo->prepare("
        SELECT charaId, charaName
        FROM CharacterInfo
        WHERE workId = :workId
        ORDER BY registDate ASC, charaId ASC
    ");
    $CharaStmt->execute([':workId' => $WorkId]);
    $CharaIdMap = [];
    foreach ($CharaStmt->fetchAll() as $CharaRow) {
        $CharaName = (string) $CharaRow['charaname'];
        if (!isset($CharaIdMap[$CharaName])) {
            $CharaIdMap[$CharaName] = $CharaRow['charaid'];
        }
    }

    if (($Input['Preview'] ?? false) === true) {
        require_once __DIR__.'/../Common/WorkDocumentAccess.php';
        WorkDocumentAccess::Check($Pdo,(string)$WorkId,(string)$UserId,$IsAuthAdmin,true);
        Response::Success(['Rows'=>array_map(static fn($Line)=>ParseStoryImportLine($Line,$CharaIdMap),$Lines)]);
    }
    Database::BeginTransaction();

    // 対象ストーリーが現在の作品に属することを検証（他作品への越境登録・改ざんを防止）
    $OwnStmt = $Pdo->prepare("SELECT storyName FROM StoryInfo WHERE storyId = :storyId AND workId = :workId");
    $OwnStmt->execute([':storyId' => $StoryId, ':workId' => $WorkId]);
    $OwnRow = $OwnStmt->fetch();
    if (!$OwnRow) {
        Database::Rollback();
        Response::Error('対象のストーリーが見つかりません', 403);
    }

    /* ---------- デリートインサート ---------- */
    // 行追加・並び替えとの同時実行競合を防ぐため、SaveStory.php と同じキーでアドバイザリロックを取得
    $LockStmt = $Pdo->prepare("SELECT pg_advisory_xact_lock(1001, hashtext(:storyId))");
    $LockStmt->execute([':storyId' => (string) $StoryId]);

    $DeleteStmt = $Pdo->prepare("DELETE FROM StoryRows WHERE storyId = :storyId");
    $DeleteStmt->execute([':storyId' => $StoryId]);

    $InsertStmt = $Pdo->prepare("
        INSERT INTO StoryRows (
            storyId, indexNum, narrator, narratorCharaId, monologue, content,
            registDate, updateDate, registUser, updateUser
        ) VALUES (
            :storyId, :indexNum, :narrator, :narratorCharaId, :monologue, :content,
            CURRENT_TIMESTAMP(3), CURRENT_TIMESTAMP(3), :registUser, :updateUser
        )
    ");
    foreach ($Lines as $Index => $Line) {
        $Row = ParseStoryImportLine($Line, $CharaIdMap);
        $InsertStmt->execute([
            ':storyId' => $StoryId,
            ':indexNum' => $Index + 1,
            ':narrator' => $Row['Narrator'],
            ':narratorCharaId' => $Row['NarratorCharaId'],
            ':monologue' => $Row['Monologue'] ? 't' : 'f',
            ':content' => $Row['Content'],
            ':registUser' => $UserId,
            ':updateUser' => $UserId
        ]);
    }
    $RowCount = count($Lines);

    $UpdateWorkStmt = $Pdo->prepare("
        UPDATE WorkInfo SET
            updateDate = CURRENT_TIMESTAMP(3),
            updateUser = :updateUser
        WHERE workId = :workId
    ");
    $UpdateWorkStmt->execute([
        ':workId' => $WorkId,
        ':updateUser' => $UserId
    ]);

    // 更新履歴の詳細メッセージを生成（取込。ストーリー名と取込件数を名称として扱う）
    $HistoryStmt = $Pdo->prepare("
        INSERT INTO UpdateHistory (
            workId, functionName, operation, detail, registDate, updateDate, registUser, updateUser
        ) VALUES (
            :workId, :functionName, :operation, :detail, CURRENT_TIMESTAMP(3), CURRENT_TIMESTAMP(3), :registUser, :updateUser
        )
    ");
    $HistoryStmt->execute([
        ':workId' => $WorkId,
        ':functionName' => $FunctionName,
        ':operation' => '取込',
        ':detail' => HistoryDetail::Register(($OwnRow['storyname'] ?? '') . '（ファイル取込：' . $RowCount . '行）'),
        ':registUser' => $UserId,
        ':updateUser' => $UserId
    ]);

    Database::Commit();

    Logger::Info($FunctionName, '取込処理終了', $UserId);

    Response::Success([
        'StoryId' => $StoryId,
        'RowCount' => $RowCount,
        'Message' => 'ストーリー行を取り込みました'
    ]);

} catch (Exception $E) {
    Database::Rollback();
    Logger::Error($FunctionName, '取込処理エラー', $UserId, $E->getMessage());
    Response::Error('ストーリー行の取込に失敗しました');
}
