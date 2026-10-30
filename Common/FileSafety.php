<?php
/**
 * FileSafety.php
 * ファイル操作の安全化ヘルパー
 *
 * DB由来のパスであっても、将来別の入り口から任意パスが流入する可能性を考え、
 * Uploads/ 配下限定の realpath 封じ込めを行った上で unlink する。
 */

class FileSafety
{
    /** アップロード画像1件あたりの上限バイト数（5MB） */
    public const MAX_IMAGE_SIZE = 5 * 1024 * 1024;

    /**
     * アップロード画像のサイズが上限以内かを判定する。
     *
     * Base64 デコード後のバイト列に対して判定するため、各 API では
     * MIME 検証（finfo）の直後に呼び出すこと。
     *
     * @param string $BinaryData デコード済みの画像バイナリ
     * @return bool 上限以内の場合 true
     */
    public static function IsAllowedImageSize(string $BinaryData): bool
    {
        return strlen($BinaryData) <= self::MAX_IMAGE_SIZE;
    }

    /**
     * 画像サイズ超過時のエラーメッセージを返す。
     * 画面側の文言と揃えるため、メッセージ生成もここへ集約する。
     *
     * @return string エラーメッセージ
     */
    public static function GetImageSizeErrorMessage(): string
    {
        return '画像サイズは' . (self::MAX_IMAGE_SIZE / 1024 / 1024) . 'MB以下にしてください';
    }

    /**
     * 指定パスが Back/../../Uploads/ 配下にある場合のみ unlink する。
     * それ以外は何もしない。
     *
     * @param string $Path 削除対象の物理パス（絶対でも相対でも可）
     * @return bool 実際に削除できた場合 true
     */
    public static function SafeUnlinkInUploads(string $Path): bool
    {
        if ($Path === '' || !file_exists($Path)) {
            return false;
        }

        $UploadsRoot = realpath(__DIR__ . '/../../Uploads');
        if ($UploadsRoot === false) {
            return false;
        }

        $Real = realpath($Path);
        if ($Real === false) {
            return false;
        }

        // 対象が Uploads/ 配下でなければ削除しない
        $UploadsRootNorm = rtrim($UploadsRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (strncmp($Real, $UploadsRootNorm, strlen($UploadsRootNorm)) !== 0) {
            return false;
        }

        return @unlink($Real);
    }
}
