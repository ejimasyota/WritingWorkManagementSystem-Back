<?php
/** 横断検索・置換・画面選択で同じ許可定義を参照する。 */
final class SearchScreens
{
    public static function Load(): array
    {
        $Path = __DIR__ . '/../Config/Screens.json';
        if (!is_readable($Path)) throw new RuntimeException('画面定義ファイルが見つかりません');
        $Config = json_decode(file_get_contents($Path), true);
        if (!is_array($Config) || empty($Config['Screens']) || !is_array($Config['Screens'])) {
            throw new RuntimeException('画面定義の読込に失敗しました');
        }
        return $Config;
    }

    /** クライアントから受け付けたIDを、サーバーで許可した画面定義へ限定する。 */
    public static function Select(array $Screens, array $MenuIds): array
    {
        $Requested = array_map('strval', $MenuIds);
        return array_values(array_filter($Screens, static fn(array $Screen): bool =>
            isset($Screen['MenuId']) && in_array((string) $Screen['MenuId'], $Requested, true)));
    }

    /** テーブル名・列名は値としてバインドできないため、許可定義でも識別子の形式を確認する。 */
    public static function IsValidIdentifier($Name): bool
    {
        return is_string($Name) && $Name !== '' && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $Name) === 1;
    }

    public static function FindParentTableDef(array $Screens, string $TableName): ?array
    {
        foreach ($Screens as $Screen) {
            foreach ($Screen['Tables'] ?? [] as $Table) {
                if (isset($Table['TableName']) && (string) $Table['TableName'] === $TableName) return $Table;
            }
        }
        return null;
    }
}
