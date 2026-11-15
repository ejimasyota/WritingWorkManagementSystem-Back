<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../Common/Database.php';
require_once __DIR__ . '/../Common/NarrativeText.php';
require_once __DIR__ . '/../Common/HtmlDocument.php';
require_once __DIR__ . '/../Common/Logger.php';
require_once __DIR__ . '/../Common/Response.php';
require_once __DIR__ . '/../Common/Auth.php';

use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\IOFactory;

$FunctionName = 'ストーリー出力';
$FormatLabel = 'Word';
$UserId = '';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        Response::Error('不正なリクエストです', 405);
        return;
    }
    $Input = Response::GetJsonInput();
    $WorkId = (string)($Input['WorkId'] ?? $AuthWorkId ?? '');
    $StoryId = $Input['StoryId'] ?? null;
    $UserId = $AuthUserId;
    $SortOrder = $Input['SortOrder'] ?? 'asc';
    $Font = $Input['Font'] ?? 'ＭＳ ゴシック';
    $NewLine = $Input['NewLine'] ?? false;
    $Cover = $Input['Cover'] ?? true;
    $ExportType = $Input['ExportType'] ?? 'word';
    if (!in_array($ExportType, ['word', 'html'], true)) {
        Response::Error('出力形式が不正です');
    }

    $FormatLabel = $ExportType === 'html' ? 'HTML' : 'Word';
    Logger::Info($FunctionName, $FormatLabel . '出力処理開始', $UserId);

    if (empty($WorkId)) {
        Response::Error('作品IDが指定されていません');
    }
    if (empty($StoryId)) {
        Response::Error('ストーリーIDが指定されていません');
    }

    $Pdo = Database::GetConnection();
    require_once __DIR__.'/../Common/WorkDocumentAccess.php';
    WorkDocumentAccess::Check($Pdo,$WorkId,(string)$AuthUserId,$IsAuthAdmin);

    $WorkStmt = $Pdo->prepare('SELECT workTitle FROM WorkInfo WHERE workId = :workId');
    $WorkStmt->execute([':workId' => $WorkId]);
    $WorkRow = $WorkStmt->fetch();
    $WorkTitle = $WorkRow ? $WorkRow['worktitle'] : '作品';

    $StoryInfoStmt = $Pdo->prepare('SELECT storyName FROM StoryInfo WHERE storyId = :storyId AND workId = :workId');
    $StoryInfoStmt->execute([
        ':storyId' => $StoryId,
        ':workId' => $WorkId
    ]);
    $StoryInfo = $StoryInfoStmt->fetch();
    if (!$StoryInfo) {
        Response::Error('指定されたストーリーが見つかりません');
    }
    $StoryName = $StoryInfo['storyname'];

    $OrderDirection = $SortOrder === 'desc' ? 'DESC' : 'ASC';
    $StoryStmt = $Pdo->prepare("
        SELECT sr.indexNum, COALESCE(c.charaName, sr.narrator) AS narrator, sr.actionLine, sr.content
        FROM StoryRows sr
        LEFT JOIN CharacterInfo c ON c.charaId = sr.narratorCharaId
        WHERE sr.storyId = :storyId
        ORDER BY sr.indexNum $OrderDirection
    ");
    $StoryStmt->execute([':storyId' => $StoryId]);
    $Stories = $StoryStmt->fetchAll();

    if ($ExportType === 'html') {
        $Rows = array_map(static fn(array $Row): array => [
            'Heading' => NarrativeText::StoryHeading($Row),
            'Content' => $Row['content'] ?? ''
        ], $Stories);
        Logger::Info($FunctionName, 'HTML出力処理終了', $UserId);
        Response::Success(HtmlDocument::Narrative($WorkTitle, $StoryName, 'ストーリー', $Rows,
            (string) $Font, (bool) $NewLine, (bool) $Cover));
    }

    /* 出力する文字列のXMLエスケープを有効にする
     * 既定は無効のため、利用者が登録した名称・本文等に WordprocessingML のタグが含まれると、
     * 文書が破損したり、外部参照などの要素を注入されたりする */
    \PhpOffice\PhpWord\Settings::setOutputEscapingEnabled(true);
    $phpWord = new PhpWord();
    $phpWord->setDefaultFontName($Font);
    $section = $phpWord->addSection();

    $FontStyle = ['name' => $Font];
    $FontStyleBold = ['name' => $Font, 'bold' => true];

    if ($Cover) {
        $CoverTitleStyle = ['bold' => true, 'size' => 28, 'name' => $Font];
        $CoverSubStyle = ['size' => 14, 'name' => $Font];
        $CoverDateStyle = ['size' => 12, 'name' => $Font];
        $section->addTextBreak(8);
        $section->addText($WorkTitle . '　' . $StoryName, $CoverTitleStyle, ['alignment' => 'center']);
        $section->addTextBreak(2);
        $section->addText('ストーリー資料', $CoverSubStyle, ['alignment' => 'center']);
        $section->addTextBreak(4);
        $section->addText(date('Y年m月d日'), $CoverDateStyle, ['alignment' => 'center']);
        $section->addPageBreak();
    }

    foreach ($Stories as $Story) {
        $text = NarrativeText::StoryHeading($Story);
        $section->addText($text, $FontStyleBold);
        $content = $Story['content'] ?? '';
        if ($NewLine && strpos($content, "\n") !== false) {
            $lines = explode("\n", $content);
            foreach ($lines as $line) {
                $section->addText($line, $FontStyle);
            }
        } else {
            $section->addText($content, $FontStyle);
        }
        $section->addTextBreak();
    }

    // tempnam() が作成した空ファイルも削除するため、元のパスを保持しておく
    $tempBase = tempnam(sys_get_temp_dir(), 'story');
    $tempFile = $tempBase . '.docx';
    $writer = IOFactory::createWriter($phpWord, 'Word2007');
    $writer->save($tempFile);

    $fileContent = file_get_contents($tempFile);
    $base64Content = base64_encode($fileContent);
    unlink($tempFile);
    @unlink($tempBase);

    $fileName = $WorkTitle . '_' . $StoryName . '_ストーリー.docx';

    Logger::Info($FunctionName, 'Word出力処理終了', $UserId);

    Response::Success([
        'FileData' => $base64Content,
        'FileName' => $fileName,
        'MimeType' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
    ]);

} catch (Exception $E) {
    Logger::Error($FunctionName, $FormatLabel . '出力処理エラー', $UserId, $E->getMessage());
    Response::Error($FormatLabel . '出力に失敗しました');
}
