<?php
/** セリフマスタと資料出力で同じ独白表示を使用する。既存の全角括弧を二重にしない。 */
final class NarrativeText
{
    public static function Monologue(?string $Content, bool $Monologue): string
    {
        $Text = (string)$Content;
        return $Monologue ? '（'.preg_replace('/^（(.*)）$/us', '$1', $Text).'）' : $Text;
    }
    public static function StoryHeading(array $Row): string
    {
        $Prefix = $Row['indexnum'].'.';
        if (!empty($Row['actionline'])) return $Prefix;
        return $Prefix.' '.(!empty($Row['narrator']) ? '語り手: '.$Row['narrator'] : 'ナレーション');
    }
    /** 資料のWord/HTMLで同じ列を出力する。[''] は未設定表示ではなく明示的な空欄。 */
    public static function ExchangeCells(array $Row, int $Index): array
    {
        $Action = !empty($Row['ActionLine']);
        return [(string)$Index, $Action ? [''] : ($Row['Speaker'] ?? null), $Row['Content'] ?? '', $Action ? [''] : ($Row['Feeling'] ?? null)];
    }
}
