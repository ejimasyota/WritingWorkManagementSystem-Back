<?php
/** 作品資料・ストーリー・プロットで共有するHTML出力処理。DB操作やレスポンス送信は行わない。 */
final class HtmlDocument
{
    /** 登録値を本文・属性へ安全に出力する。 */
    public static function Escape($Value): string
    {
        return htmlspecialchars((string) ($Value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** 出力API共通のダウンロード形式へ変換する。 */
    public static function File(string $Html, string $FileName): array
    {
        return ['FileData' => base64_encode($Html), 'FileName' => $FileName . '.html',
            'MimeType' => 'text/html; charset=UTF-8'];
    }

    /** 見出しと本文から、単独で閲覧・印刷できる文書を作成する。本文は必ずエスケープする。 */
    public static function Narrative(string $WorkTitle, string $Name, string $Kind, array $Rows,
        string $Font, bool $NewLine, bool $Cover): array
    {
        $Escape = [self::class, 'Escape'];
        // CSSへ任意の宣言を挿入できないよう、フォント名はCSS文字列として符号化する。
        $CssFont = json_encode($Font, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $Html = '<!DOCTYPE html><html lang="ja"><head><meta charset="UTF-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . $Escape($WorkTitle . ' ' . $Name) . '</title><style>'
            . 'body{font-family:' . $CssFont . ',sans-serif;line-height:1.8;max-width:960px;margin:auto;padding:32px}'
            . 'section{break-inside:avoid;margin-block:32px}h1,h2{overflow-wrap:anywhere}'
            . 'p{overflow-wrap:anywhere}.cover{text-align:center;padding-block:15vh;break-after:page}'
            . '@media print{body{padding:0}.cover{height:80vh}}</style></head><body>';
        if ($Cover) {
            $Html .= '<header class="cover"><h1>' . $Escape($WorkTitle . ' ' . $Name)
                . '</h1><p>' . $Escape($Kind . '資料') . '</p><p>' . date('Y年m月d日') . '</p></header>';
        } else {
            $Html .= '<h1>' . $Escape($Name) . '</h1>';
        }
        foreach ($Rows as $Row) {
            $Text = str_replace(["\r\n", "\r"], "\n", (string) ($Row['Content'] ?? ''));
            $Content = $NewLine ? nl2br($Escape($Text), false) : $Escape(str_replace("\n", ' ', $Text));
            $Html .= '<section><h2>' . $Escape($Row['Heading']) . '</h2><p>' . $Content . '</p></section>';
        }
        return self::File($Html . '</body></html>', $WorkTitle . '_' . $Name . '_' . $Kind);
    }
}
