<?php
/**
 * Auth.php
 * 認証共通処理
 *
 * 各APIの先頭でrequire_onceすることで、
 * セッションの認証チェックと無操作タイムアウト判定を一元的に行う。
 *
 * 提供する変数：
 *   $AuthUserId  - 認証済みユーザーID（$_SESSION['UserId']）
 *   $IsAuthAdmin - 管理者フラグ（$_SESSION['AuthFlg'] === true）
 *   $AuthWorkId  - 現在開いている作品ID（$_SESSION['CurrentWorkId']、未選択時は null）
 *
 * セッションタイムアウト仕様：
 *   $_SESSION['LastActivity'] を最終操作時刻として保持し、
 *   現在時刻との差が SESSION_TIMEOUT_SEC を超えていればセッションを破棄して401を返す。
 *   通過時は LastActivity を現在時刻で更新する。
 */

require_once __DIR__ . '/Response.php';
require_once __DIR__ . '/Csrf.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Env.php';
require_once __DIR__ . '/Logger.php';

// セッションタイムアウト時間（秒）。.env の SESSION_TIMEOUT_SEC で上書き可能（未設定時は3600=1時間）
define('SESSION_TIMEOUT_SEC', (int) Env::Get('SESSION_TIMEOUT_SEC', 3600));

// セッションCookieのセキュリティ設定
session_set_cookie_params([
    'httponly'  => true,     // JavaScriptからのCookieアクセスを禁止
    'secure'   => ($_SERVER['HTTPS'] ?? '') === 'on', // HTTPS時のみCookieを送信
    'samesite' => 'Strict', // クロスサイトリクエストでのCookie送信を禁止
]);

// セッション開始
session_start();

// 未ログインチェック
if (!isset($_SESSION['UserId'])) {
    Response::Error('認証が必要です', 401);
}

// 無操作タイムアウトチェック
if (isset($_SESSION['LastActivity']) && (time() - $_SESSION['LastActivity']) > SESSION_TIMEOUT_SEC) {
    // セッションを破棄
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $Params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $Params['path'],
            $Params['domain'],
            $Params['secure'],
            $Params['httponly']
        );
    }
    session_destroy();
    Response::Error('セッションがタイムアウトしました', 401);
}

// 最終操作時刻を更新
$_SESSION['LastActivity'] = time();

// 認証済みユーザー情報を提供
$AuthUserId = $_SESSION['UserId'];
$IsAuthAdmin = !empty($_SESSION['AuthFlg']) && $_SESSION['AuthFlg'] === true;
$AuthWorkId = isset($_SESSION['CurrentWorkId']) && !empty($_SESSION['CurrentWorkId'])
    ? $_SESSION['CurrentWorkId']
    : null;

/*
 * 作品参加権限の再検証
 * SetCurrentWork 時に検証済みだが、管理者による参加削除・BAN が反映されず
 * セッション有効期間中は権限が残り続けるのを防ぐため、各取得処理の冒頭で毎回再検証する。
 * これにより、参加取消・BAN が遅延なく（次回リクエストで即時）反映される。
 * CreaterList を workId+userId で引く単一行検索のみのため、性能影響は軽微。
 * 管理者はこの検証をスキップする（全作品にアクセス可能）。
 */
if ($AuthWorkId !== null && !$IsAuthAdmin) {
    try {
        $PermPdo = Database::GetConnection();
        // 作品の凍結（projectLockFlg）も作品選択時（SetCurrentWork.php）だけでなく毎回確認する。
        // 作品選択時のみの確認では、凍結前に作品を開いていた参加者が凍結後も読み書きできてしまうため。
        $PermStmt = $PermPdo->prepare('
            SELECT c.userLockFlg, w.projectLockFlg
            FROM CreaterList c
            INNER JOIN WorkInfo w ON w.workId = c.workId
            WHERE c.workId = :workId AND c.userId = :userId
        ');
        $PermStmt->execute([':workId' => $AuthWorkId, ':userId' => $AuthUserId]);
        $PermRow = $PermStmt->fetch();

        if (!$PermRow || !empty($PermRow['userlockflg'])) {
            // 参加が取り消された or BAN された → CurrentWorkId を破棄
            unset($_SESSION['CurrentWorkId']);
            // Data.Code でフロントが「作品アクセス権喪失」を識別し Main.html へ遷移する
            Response::Error(
                '作品へのアクセス権が失われました。作品一覧に戻ってください',
                403,
                ['Code' => 'WORK_ACCESS_LOST']
            );
        }
        if (!empty($PermRow['projectlockflg'])) {
            // 作品が凍結された → 参加取消と同じく CurrentWorkId を破棄して作品一覧へ戻す
            unset($_SESSION['CurrentWorkId']);
            Response::Error(
                'この作品は凍結されています。作品一覧に戻ってください',
                403,
                ['Code' => 'WORK_ACCESS_LOST']
            );
        }
    } catch (Exception $E) {
        /*
         * 権限を確認できない状態のまま処理を継続すると、参加取消・BAN 済みのユーザーが
         * 引き続き作品データへアクセスできてしまう（フェイルオープン）。
         * 検証できなかった場合はアクセスを許可しない（フェイルクローズ）方針とする。
         * 返却する 503 はフロントの共通エラー処理でダイアログ表示されるだけで、
         * 再認証・再送信を伴わないため、リトライループは発生しない。
         */
        Logger::Error('認証共通処理', '作品参加権限の再検証に失敗', (string)$AuthUserId, $E->getMessage());
        Response::Error('権限の確認に失敗しました。時間をおいて再度お試しください', 503);
    }
}
