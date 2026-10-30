<?php
/* ==========================================================
 * DB接続ユーティリティ
 * ----------------------------------------------------------
 * ・DB接続に関する機能を提供する
 * ----------------------------------------------------------
 * 更新履歴：
 * 2026-01-16 作成
 * ========================================================== */

// .env の読み込みは Env クラスへ集約している
require_once __DIR__ . '/Env.php';

/**
 * データベース接続クラス
 */
class Database
{
    /* ==========================================================
     * フィールド
     * ========================================================== */
    /**
     * @var PDO|null PDOインスタンス 
     */
    private static ?PDO $Connection = null;

    /**
     * データベース接続を取得
     * @return PDO PDOインスタンス
     * @throws Exception 接続エラー時
     */
    public static function GetConnection(): PDO
    {
        /* ==========================================================
         * 接続処理
         * ========================================================== */
        if (self::$Connection === null) {
            /* ------------------------------
             * 1. 事前定義
             * ------------------------------*/
            // 1. envファイルを読み込み
            $EnvPath = __DIR__ . '/../Config/.env';

            /* ------------------------------
             * 2. envファイルが存在しない場合
             * ------------------------------*/
            if (!file_exists($EnvPath)) {
                // 1. 例外をスロー
                throw new Exception('環境設定ファイルが見つかりません');
            }

            /* ------------------------------
             * 3. 事前処理
             * ------------------------------*/
            // 1. .env の解釈は Env クラスへ集約している（独自パースは持たない）

            // 接続文字列を構築
            $Dsn = sprintf(
                'pgsql:host=%s;port=%s;dbname=%s',
                Env::Get('DB_HOST', '127.0.0.1'),
                Env::Get('DB_PORT', '5432'),
                Env::Get('DB_NAME', 'WorkManagement')
            );

            try {
                self::$Connection = new PDO(
                    $Dsn,
                    Env::Get('DB_USER', ''),
                    Env::Get('DB_PASS', ''),
                    [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                        PDO::ATTR_EMULATE_PREPARES => false
                    ]
                );
            } catch (PDOException $E) {
                // PDO のメッセージには接続先（ホスト・DB名・ユーザー名）が含まれ得るため、
                // 例外メッセージへは連結せずサーバ側ログにのみ記録する（元の例外は previous として保持する）
                if (class_exists('Logger')) {
                    Logger::Error('データベース接続', '接続失敗', '', $E->getMessage());
                }
                throw new Exception('データベース接続に失敗しました', 0, $E);
            }
        }

        return self::$Connection;
    }

    /**
     * トランザクションを開始
     */
    public static function BeginTransaction(): void
    {
        // 1. トランザクションを開始
        self::GetConnection()->beginTransaction();
    }

    /**
     * トランザクションをコミット
     */
    public static function Commit(): void
    {
        // 1. トランザクションをコミット
        self::GetConnection()->commit();
    }

    /**
     * トランザクションをロールバック
     */
    public static function Rollback(): void
    {
        if (self::$Connection && self::$Connection->inTransaction()) {
            self::$Connection->rollBack();
        }
    }
}
