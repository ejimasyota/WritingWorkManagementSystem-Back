<?php
/** 旧ImportStoryの解析処理を、仮取込と保存取込で共有する。 */
/** 取込ファイルの上限バイト数（画面側 StoryPlotModule.js の STORY_IMPORT_MAX_SIZE と同じ値とすること） */
const STORY_IMPORT_MAX_FILE_SIZE = 2 * 1024 * 1024;
/** docx 内の本文XMLの上限バイト数（圧縮率の極端なファイルによる展開時のメモリ枯渇を防ぐ） */
const STORY_IMPORT_MAX_XML_SIZE = 50 * 1024 * 1024;
/** 語り手（storyrows.narrator）の最大文字数 */
const STORY_IMPORT_NARRATOR_MAX_LENGTH = 255;

/**
 * 取込処理の利用者向けエラー
 * メッセージはそのままエラーダイアログへ表示する
 */
class StoryImportException extends Exception
{
}

/**
 * ファイル名から取込形式（txt / docx）を判定する
 * @param mixed $FileName ファイル名
 * @return string|null 取込形式（対象外の場合は null）
 */
function GetStoryImportType($FileName): ?string
{
    if (!is_string($FileName) || $FileName === '') {
        return null;
    }
    $Extension = strtolower(pathinfo($FileName, PATHINFO_EXTENSION));
    return in_array($Extension, ['txt', 'docx'], true) ? $Extension : null;
}

/**
 * テキストファイルの内容を行の配列へ変換する
 *   Windows のメモ帳等で保存された Shift_JIS にも対応するため、UTF-8 として不正な場合は Shift_JIS とみなす
 * @param string $Binary ファイルの内容
 * @return array<int,string> 行の配列
 */
function ReadStoryImportText(string $Binary): array
{
    // 1. UTF-8 の BOM を除去する
    if (strncmp($Binary, "\xEF\xBB\xBF", 3) === 0) {
        $Binary = substr($Binary, 3);
    }
    // 2. 文字コードを UTF-8 へ揃える
    if (!mb_check_encoding($Binary, 'UTF-8')) {
        if (!mb_check_encoding($Binary, 'SJIS-win')) {
            throw new StoryImportException('ファイルの文字コードを判別できませんでした。UTF-8またはShift_JISで保存してください');
        }
        $Binary = mb_convert_encoding($Binary, 'UTF-8', 'SJIS-win');
    }
    // 3. 改行で行へ分割する
    return explode("\n", str_replace(["\r\n", "\r"], "\n", $Binary));
}

/**
 * Wordファイル（.docx）の本文を行の配列へ変換する
 *   本文XML（word/document.xml）の段落（w:p）を1行とし、段落内の改行（w:br / w:cr）でも行を分ける
 * @param string $Binary ファイルの内容
 * @return array<int,string> 行の配列
 */
function ReadStoryImportDocx(string $Binary): array
{
    $ReadErrorMessage = 'Wordファイルの読み込みに失敗しました。ファイルが破損しているか、.docx形式ではありません';

    // 1. ZipArchive はファイルパスから開くため、一時ファイルへ書き出す
    $TempPath = tempnam(sys_get_temp_dir(), 'StoryImport');
    if ($TempPath === false || file_put_contents($TempPath, $Binary) === false) {
        throw new Exception('一時ファイルの作成に失敗しました');
    }

    $Zip = new ZipArchive();
    $IsOpened = false;
    try {
        // 2. 本文XMLを取り出す（展開後のサイズを先に確認する）
        if ($Zip->open($TempPath) !== true) {
            throw new StoryImportException($ReadErrorMessage);
        }
        $IsOpened = true;
        $Stat = $Zip->statName('word/document.xml');
        if ($Stat === false) {
            throw new StoryImportException($ReadErrorMessage);
        }
        if ($Stat['size'] > STORY_IMPORT_MAX_XML_SIZE) {
            throw new StoryImportException('Wordファイルの本文が大きすぎるため取り込めません');
        }
        $Xml = $Zip->getFromName('word/document.xml');
        if ($Xml === false) {
            throw new StoryImportException($ReadErrorMessage);
        }

        // 3. XMLを解析する（外部リソースは読み込まない）
        $Dom = new DOMDocument();
        $PreviousUseErrors = libxml_use_internal_errors(true);
        $IsLoaded = $Dom->loadXML($Xml, LIBXML_NONET | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($PreviousUseErrors);
        if (!$IsLoaded) {
            throw new StoryImportException($ReadErrorMessage);
        }

        // 4. 段落ごとに文字列を組み立てる
        $XPath = new DOMXPath($Dom);
        $XPath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
        $Lines = [];
        foreach ($XPath->query('//w:body//w:p') as $Paragraph) {
            $Text = '';
            foreach ($XPath->query('.//w:t | .//w:tab | .//w:br | .//w:cr', $Paragraph) as $Node) {
                if ($Node->localName === 't') {
                    $Text .= $Node->textContent;
                } elseif ($Node->localName === 'tab') {
                    $Text .= "\t";
                } else {
                    $Text .= "\n";
                }
            }
            foreach (explode("\n", $Text) as $Line) {
                $Lines[] = $Line;
            }
        }
        return $Lines;
    } finally {
        if ($IsOpened) {
            $Zip->close();
        }
        @unlink($TempPath);
    }
}

/**
 * 前後の空白（全角スペースを含む）を除去する
 *   trim() の第2引数は1バイト単位で扱われ、全角スペース（E3 80 80）を指定すると
 *   ひらがな・カタカナ等の先頭バイト（E3）まで削られて不正な UTF-8 となるため、正規表現で除去する
 * @param string $Text 対象の文字列（UTF-8）
 * @return string 前後の空白を除去した文字列
 */
function TrimStoryImportText(string $Text): string
{
    return (string) preg_replace('/^[\s　]+|[\s　]+$/u', '', $Text);
}

/**
 * 語り手付きの内容を SaveStory.php と同じ形式へ整形する
 * @param string $Content   内容（かっこの内側）
 * @param bool   $Monologue モノローグか
 * @return string 整形後の内容
 */
function FormatStoryImportContent(string $Content, bool $Monologue): string
{
    $Content = preg_replace('/^[「(（]/u', '', trim($Content));
    $Content = preg_replace('/[」)）]$/u', '', $Content);
    return $Monologue ? '(' . $Content . ')' : '「' . $Content . '」';
}

/**
 * 1行を登録用のストーリー行へ変換する
 * @param string $Line       行（前後の空白は除去済み）
 * @param array  $CharaIdMap キャラ名 => キャラID
 * @return array{Narrator:?string,NarratorCharaId:?string,Monologue:bool,Content:string}
 */
function ParseStoryImportLine(string $Line, array $CharaIdMap): array
{
    // 1. 名前「内容」または 名前（内容）の形式か判定する
    //    かっこの内側に同じ種類のかっこを含む行（例：太郎「はい」と花子「いいえ」）は1人の発言とみなさない
    $IsMatched = preg_match('/^([^「」（）()]+?)(?:「([^「」]*)」|[（(]([^（）()]*)[）)])$/u', $Line, $Matches) === 1;
    if ($IsMatched) {
        $Name = TrimStoryImportText($Matches[1]);
        $Monologue = isset($Matches[3]) && $Matches[3] !== '';
        $Inner = $Monologue ? $Matches[3] : $Matches[2];
        // 名前が語り手の桁数を超える場合や、かっこの中が空の場合は通常の文とみなす
        if ($Name !== '' && mb_strlen($Name) <= STORY_IMPORT_NARRATOR_MAX_LENGTH && TrimStoryImportText($Inner) !== '') {
            $CharaId = $CharaIdMap[$Name] ?? null;
            return [
                'Narrator' => $CharaId === null ? $Name : null,
                'NarratorCharaId' => $CharaId,
                'Monologue' => $Monologue,
                'Content' => FormatStoryImportContent($Inner, $Monologue),
            ];
        }
    }
    // 2. 上記以外はナレーションとする
    return [
        'Narrator' => null,
        'NarratorCharaId' => null,
        'Monologue' => false,
        'Content' => $Line,
    ];
}

