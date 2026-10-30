<?php
/**
 * Response.php
 * APIレスポンスを管理する共通クラス
 */

/**
 * レスポンスクラス
 */
class Response
{
    /**
     * 成功レスポンスを返却
     * @param mixed $Data レスポンスデータ
     * @param string $Message メッセージ
     */
    public static function Success($Data = null, string $Message = ''): void
    {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'Success' => true,
            'Message' => $Message,
            'Data' => $Data
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * エラーレスポンスを返却
     * @param string $Message エラーメッセージ
     * @param int $StatusCode HTTPステータスコード
     * @param mixed $Data レスポンスデータ
     */
    public static function Error(string $Message, int $StatusCode = 400, $Data = null): void
    {
        http_response_code($StatusCode);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'Success' => false,
            'Message' => $Message,
            'Data' => $Data
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * 排他制御エラーレスポンスを返却
     * 他ユーザーによる更新が検出された場合に使用
     */
    public static function ConflictError(): void
    {
        self::Error("他ユーザーからの更新がありました\n画面をリフレッシュし、再度実行してください", 409);
    }

    /**
     * JSONリクエストボディを取得
     * @return array リクエストデータ
     */
    public static function GetJsonInput(): array
    {
        $Input = file_get_contents('php://input');
        $Data = json_decode($Input, true);
        return $Data ?? [];
    }
}
