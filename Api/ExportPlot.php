<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../Common/Database.php';
require_once __DIR__ . '/../Common/Logger.php';
require_once __DIR__ . '/../Common/Response.php';
require_once __DIR__ . '/../Common/Auth.php';

use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\IOFactory;

$FunctionName = 'プロットWord出力';
$UserId = '';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        Response::Error('不正なリクエストです', 405);
        return;
    }
    $Input = Response::GetJsonInput();
    $WorkId = $AuthWorkId ?? '';
    $PlotId = $Input['PlotId'] ?? null;
    $UserId = $AuthUserId;
    $SortOrder = $Input['SortOrder'] ?? 'asc';
    $Font = $Input['Font'] ?? 'ＭＳ ゴシック';
    $NewLine = $Input['NewLine'] ?? false;
    $Cover = $Input['Cover'] ?? true;

    Logger::Info($FunctionName, 'Word出力処理開始', $UserId);

    if (empty($WorkId)) {
        Response::Error('作品IDが指定されていません');
    }
    if (empty($PlotId)) {
        Response::Error('プロットIDが指定されていません');
    }

    $Pdo = Database::GetConnection();

    $WorkStmt = $Pdo->prepare('SELECT workTitle FROM WorkInfo WHERE workId = :workId');
    $WorkStmt->execute([':workId' => $WorkId]);
    $WorkRow = $WorkStmt->fetch();
    $WorkTitle = $WorkRow ? $WorkRow['worktitle'] : '作品';

    $PlotInfoStmt = $Pdo->prepare('SELECT plotName FROM PlotInfo WHERE plotId = :plotId AND workId = :workId');
    $PlotInfoStmt->execute([
        ':plotId' => $PlotId,
        ':workId' => $WorkId
    ]);
    $PlotInfo = $PlotInfoStmt->fetch();
    if (!$PlotInfo) {
        Response::Error('指定されたプロットが見つかりません');
    }
    $PlotName = $PlotInfo['plotname'];

    $OrderDirection = $SortOrder === 'desc' ? 'DESC' : 'ASC';
    $PlotStmt = $Pdo->prepare("SELECT indexNum, structureType, content FROM PlotRows WHERE plotId = :plotId ORDER BY indexNum $OrderDirection");
    $PlotStmt->execute([':plotId' => $PlotId]);
    $Plots = $PlotStmt->fetchAll();

    $StructureLabels = ['起', '承', '転', '結'];

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
        $section->addText($WorkTitle . '　' . $PlotName, $CoverTitleStyle, ['alignment' => 'center']);
        $section->addTextBreak(2);
        $section->addText('プロット資料', $CoverSubStyle, ['alignment' => 'center']);
        $section->addTextBreak(4);
        $section->addText(date('Y年m月d日'), $CoverDateStyle, ['alignment' => 'center']);
        $section->addPageBreak();
    }

    foreach ($Plots as $Plot) {
        $structureLabel = isset($Plot['structuretype']) && $Plot['structuretype'] !== null
            ? ($StructureLabels[$Plot['structuretype'] - 1] ?? '')
            : '';
        $text = $Plot['indexnum'] . '. ';
        if ($structureLabel) {
            $text .= '[' . $structureLabel . '] ';
        }
        $section->addText($text, $FontStyleBold);
        $content = $Plot['content'] ?? '';
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
    $tempBase = tempnam(sys_get_temp_dir(), 'plot');
    $tempFile = $tempBase . '.docx';
    $writer = IOFactory::createWriter($phpWord, 'Word2007');
    $writer->save($tempFile);

    $fileContent = file_get_contents($tempFile);
    $base64Content = base64_encode($fileContent);
    unlink($tempFile);
    @unlink($tempBase);

    $fileName = $WorkTitle . '_' . $PlotName . '_プロット.docx';

    Logger::Info($FunctionName, 'Word出力処理終了', $UserId);

    Response::Success([
        'FileData' => $base64Content,
        'FileName' => $fileName,
        'MimeType' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
    ]);

} catch (Exception $E) {
    Logger::Error($FunctionName, 'Word出力処理エラー', $UserId, $E->getMessage());
    Response::Error('Word出力に失敗しました');
}
