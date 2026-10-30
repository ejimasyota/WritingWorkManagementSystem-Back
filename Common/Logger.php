<?php
/**
 * Logger.php
 * ログ出力を管理する共通クラス
 */

/**
 * ログ出力クラス
 */
class Logger
{
    /** @var string 情報ログディレクトリ */
    private const INFO_LOG_DIR = __DIR__ . '/../Log/Info/';

    /** @var string エラーログディレクトリ */
    private const ERROR_LOG_DIR = __DIR__ . '/../Log/Error/';

    /** @var int ログ1項目あたりの最大文字数 */
    private const MAX_FIELD_LENGTH = 500;

    /**
     * ログへ連結する値を無害化する
     *
     * 1行1レコードの形式のため、改行や制御文字を含む値をそのまま連結すると
     * 偽のログ行を注入できてしまう（ログインジェクション）。
     * User-Agent のようなリクエスト由来の値だけでなく、ユーザーIDや
     * 例外メッセージなど外部入力が混ざり得る項目はすべてここを通す。
     *
     * @param string $Value 対象の値
     * @return string 無害化した値
     */
    private static function Sanitize(string $Value): string
    {
        // 1. 改行（CR/LF）とタブを半角スペースへ置換する
        $Sanitized = str_replace(["\r\n", "\r", "\n", "\t"], ' ', $Value);
        // 2. 残りの制御文字（NULL・エスケープシーケンス等）を除去する
        $Sanitized = preg_replace('/[\x00-\x1F\x7F]/u', '', $Sanitized);
        // 3. preg_replace は不正なUTF-8でnullを返すため、その場合は除去前の値を用いる
        if ($Sanitized === null) {
            $Sanitized = $Value;
        }
        // 4. 1項目が長大になりログが読めなくなるのを防ぐため上限で切り詰める
        if (mb_strlen($Sanitized) > self::MAX_FIELD_LENGTH) {
            $Sanitized = mb_substr($Sanitized, 0, self::MAX_FIELD_LENGTH) . '…(以下略)';
        }
        return $Sanitized;
    }

    /**
     * 情報ログを出力
     * @param string $FunctionName 機能名
     * @param string $Operation 操作内容
     * @param string $UserId ユーザーID
     */
    public static function Info(string $FunctionName, string $Operation, string $UserId = ''): void
    {
        $LogDir = self::INFO_LOG_DIR;
        self::EnsureDirectoryExists($LogDir);

        $FileName = '創作管理システム_' . date('Ymd') . '.log';
        $FilePath = $LogDir . $FileName;

        $Timestamp = date('Y-m-d H:i:s');
        // 外部入力が混ざり得る項目はすべて無害化してから連結する
        $UserAgent = self::Sanitize($_SERVER['HTTP_USER_AGENT'] ?? '');
        $FunctionName = self::Sanitize($FunctionName);
        $Operation = self::Sanitize($Operation);
        $UserId = self::Sanitize($UserId);
        $UserInfo = $UserId !== '' ? " | ユーザーID : {$UserId}" : '';
        $Message = "[{$Timestamp}] {$FunctionName} | {$Operation}{$UserInfo} | UA : {$UserAgent}\n";

        file_put_contents($FilePath, $Message, FILE_APPEND | LOCK_EX);
    }

    /**
     * エラーログを出力
     * @param string $FunctionName 機能名
     * @param string $Operation 操作内容
     * @param string $UserId ユーザーID
     * @param string $ErrorMessage エラーメッセージ
     */
    public static function Error(string $FunctionName, string $Operation, string $UserId, string $ErrorMessage): void
    {
        $LogDir = self::ERROR_LOG_DIR;
        self::EnsureDirectoryExists($LogDir);

        $FileName = '創作管理システム_' . date('Ymd') . '.log';
        $FilePath = $LogDir . $FileName;

        $Timestamp = date('Y-m-d H:i:s');
        // 外部入力が混ざり得る項目はすべて無害化してから連結する
        $UserAgent = self::Sanitize($_SERVER['HTTP_USER_AGENT'] ?? '');
        $FunctionName = self::Sanitize($FunctionName);
        $Operation = self::Sanitize($Operation);
        $UserId = self::Sanitize($UserId);
        $ErrorMessage = self::Sanitize($ErrorMessage);
        $Message = "[{$Timestamp}] {$FunctionName} | {$Operation} | ユーザーID : {$UserId} | error : {$ErrorMessage} | UA : {$UserAgent}\n";

        file_put_contents($FilePath, $Message, FILE_APPEND | LOCK_EX);
    }

    /**
     * ディレクトリの存在を確認し、存在しない場合は作成
     * @param string $DirPath ディレクトリパス
     */
    private static function EnsureDirectoryExists(string $DirPath): void
    {
        if (!is_dir($DirPath)) {
            mkdir($DirPath, 0755, true);
        }
    }
}
