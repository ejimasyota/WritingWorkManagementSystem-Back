<?php
/**
 * PasswordPolicy.php
 * パスワードポリシー共通処理
 *
 * 登録・変更時に共通で参照する強度要件を集約する。
 *   - 最小長 8 文字
 *   - 半角英字 (a-z / A-Z) と 数字 (0-9) の両方を含む
 *   - 制御文字・空白のみは不可
 */

class PasswordPolicy
{
    /** 最小長 */
    public const MIN_LENGTH = 8;

    /** 最大長（BCrypt の実効上限 72 バイトに合わせる） */
    public const MAX_LENGTH = 72;

    /**
     * パスワード強度を検証する
     * 問題がなければ null、あればエラーメッセージ文字列を返す。
     */
    public static function Validate(string $Password): ?string
    {
        if ($Password === '') {
            return 'パスワードを入力してください';
        }
        // バイト長で判定（BCrypt の 72 バイト制限のため）
        if (strlen($Password) < self::MIN_LENGTH) {
            return 'パスワードは' . self::MIN_LENGTH . '文字以上で入力してください';
        }
        if (strlen($Password) > self::MAX_LENGTH) {
            return 'パスワードは' . self::MAX_LENGTH . '文字以下で入力してください';
        }
        if (!preg_match('/[A-Za-z]/', $Password)) {
            return 'パスワードには半角英字を1文字以上含めてください';
        }
        if (!preg_match('/[0-9]/', $Password)) {
            return 'パスワードには数字を1文字以上含めてください';
        }
        // 制御文字を禁止
        if (preg_match('/[\x00-\x1F\x7F]/', $Password)) {
            return 'パスワードに使用できない文字が含まれています';
        }
        return null;
    }
}
