<?php
/**
 * Env.php
 * 環境設定ファイル（Back/Config/.env）の読み込みヘルパ
 *
 * .env を 1 度だけパースしてキャッシュし、Env::Get(key, default) で値を取得する。
 * 秘匿値（DB/API キー等）と併せて、環境ごとに変えたい設定値の参照に使用する。
 */
class Env
{
    /** @var array<string,string>|null */
    private static $Config = null;

    private static function Load(): void
    {
        if (self::$Config !== null) {
            return;
        }
        self::$Config = [];
        $EnvPath = __DIR__ . '/../Config/.env';
        if (!file_exists($EnvPath)) {
            return;
        }
        $Lines = explode("\n", file_get_contents($EnvPath));
        foreach ($Lines as $Line) {
            $Line = trim($Line);
            if ($Line === '' || strpos($Line, '#') === 0) {
                continue;
            }
            $Parts = explode('=', $Line, 2);
            if (count($Parts) === 2) {
                self::$Config[trim($Parts[0])] = trim($Parts[1]);
            }
        }
    }

    /**
     * .env の値を取得する
     * @param string $Key キー
     * @param mixed $Default 未設定時の既定値
     * @return mixed
     */
    public static function Get(string $Key, $Default = null)
    {
        self::Load();
        return array_key_exists($Key, self::$Config) ? self::$Config[$Key] : $Default;
    }
}
