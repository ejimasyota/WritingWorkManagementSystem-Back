<?php
/**
 * Login.php
 * ログインAPI
 */

require_once __DIR__ . '/../Common/Database.php';
require_once __DIR__ . '/../Common/Logger.php';
require_once __DIR__ . '/../Common/Response.php';
require_once __DIR__ . '/../Common/Csrf.php';

// セッションCookieのセキュリティ設定
session_set_cookie_params([
    'httponly'  => true,     // JavaScriptからのCookieアクセスを禁止
    'secure'   => ($_SERVER['HTTPS'] ?? '') === 'on', // HTTPS時のみCookieを送信
    'samesite' => 'Strict', // クロスサイトリクエストでのCookie送信を禁止
]);

// セッション開始
session_start();

// POSTメソッドのみ許可
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::Error('不正なリクエストです', 405);
}

$FunctionName = 'ログイン';
$Input = Response::GetJsonInput();
$UserId = $Input['UserId'] ?? '';
$Password = $Input['Password'] ?? '';

/**
 * ログイン試行回数に応じたロック時間（分）を取得
 * @param int $TryLoginCount ログイン試行回数
 * @return int ロック時間（分）。ロック不要の場合は0
 */
function GetLockMinutes(int $TryLoginCount): int
{
    if ($TryLoginCount >= 60) return 60;
    if ($TryLoginCount >= 30) return 30;
    if ($TryLoginCount >= 10) return 10;
    if ($TryLoginCount >= 5) return 5;
    return 0;
}

/**
 * 次のロック閾値を取得
 * @param int $TryLoginCount 現在のログイン試行回数
 * @return int 次のロック閾値
 *
 * 本関数は呼び出し側で `GetLockMinutes($TryLoginCount) === 0`（＝ count < 5）
 * の場合のみ参照されるため、実際に返る値は 5 に限られる。
 * 上位の分岐は将来的な閾値追加時の意図表明として残す。
 */
function GetNextLockThreshold(int $TryLoginCount): int
{
    if ($TryLoginCount < 5) return 5;
    if ($TryLoginCount < 10) return 10;
    if ($TryLoginCount < 30) return 30;
    return 60;
}

try {
    Logger::Info($FunctionName, 'ログイン処理開始', $UserId);

    // 入力チェック
    if (empty($UserId) || empty($Password)) {
        Response::Error('ユーザーIDとパスワードを入力してください');
    }

    $Db = Database::GetConnection();

    // ユーザー情報を取得（ロック判定もDB側で実施）
    $Stmt = $Db->prepare('
        SELECT
            userId,
            passwordHash,
            accountLockFlg,
            tryLoginCount,
            loginLockDate,
            (tryLoginCount >= 5 AND loginLockDate > current_timestamp(3)) AS isloginlocked,
            GREATEST(0, EXTRACT(EPOCH FROM (loginLockDate - current_timestamp(3))) * 1000)::BIGINT AS remainingms
        FROM UserInfo
        WHERE userId = :userId
    ');
    $Stmt->execute([':userId' => $UserId]);
    $User = $Stmt->fetch();

    if (!$User) {
        Logger::Info($FunctionName, 'ログイン失敗：ユーザーが存在しません', $UserId);
        // ユーザー列挙防止のため、存在しない場合もパスワード不一致と同じメッセージ・タイミングにする
        password_verify($Password, '$2y$10$abcdefghijklmnopqrstuu1234567890abcdefghijklmnopqrstuv');
        Response::Error('ユーザーIDまたはパスワードが違います');
    }

    // アカウントロックチェック（永久凍結）
    if ($User['accountlockflg']) {
        Logger::Info($FunctionName, 'ログイン失敗：アカウントロック', $UserId);
        Response::Error('凍結されたアカウントによるログインです');
    }

    // ログインロックチェック（時限ロック - DB側で判定済み）
    if ($User['isloginlocked']) {
        $RemainingMs = (int)$User['remainingms'];

        Logger::Info($FunctionName, 'ログイン失敗：ログインロック中', $UserId);
        Response::Error('ログインがロックされています', 200, [
            'IsLocked' => true,
            'RemainingMs' => $RemainingMs
        ]);
    }

    // パスワード検証
    if (!password_verify($Password, $User['passwordhash'])) {
        // ログイン試行回数をカウントアップ
        $TryLoginCount = (int)$User['trylogincount'] + 1;

        // ロック時間を取得
        $LockMinutes = GetLockMinutes($TryLoginCount);

        if ($LockMinutes > 0) {
            // ロック閾値に達した場合：ロック解除年月日を設定（DB側で時刻計算）
            $UpdateStmt = $Db->prepare("
                UPDATE UserInfo
                SET tryLoginCount = :count,
                    loginLockDate = current_timestamp(3) + INTERVAL '{$LockMinutes} minutes',
                    updateDate = current_timestamp(3)
                WHERE userId = :userId
                RETURNING EXTRACT(EPOCH FROM (loginLockDate - current_timestamp(3))) * 1000 AS remainingms
            ");
            $UpdateStmt->execute([
                ':count' => $TryLoginCount,
                ':userId' => $UserId
            ]);
            $UpdateResult = $UpdateStmt->fetch();
            $RemainingMs = (int)$UpdateResult['remainingms'];

            Logger::Info($FunctionName, "ログイン失敗：パスワード不一致（試行{$TryLoginCount}回、{$LockMinutes}分ロック）", $UserId);
            Response::Error("ユーザーIDまたはパスワードが違います。ログインが{$LockMinutes}分間ロックされました。", 200, [
                'IsLocked' => true,
                'RemainingMs' => $RemainingMs,
                'TryLoginCount' => $TryLoginCount
            ]);
        } else {
            // ロック閾値未達：試行回数のみ更新
            $UpdateStmt = $Db->prepare('
                UPDATE UserInfo
                SET tryLoginCount = :count,
                    updateDate = current_timestamp(3)
                WHERE userId = :userId
            ');
            $UpdateStmt->execute([
                ':count' => $TryLoginCount,
                ':userId' => $UserId
            ]);

            $NextLockAt = GetNextLockThreshold($TryLoginCount);
            $Remaining = $NextLockAt - $TryLoginCount;

            Logger::Info($FunctionName, "ログイン失敗：パスワード不一致（試行{$TryLoginCount}回）", $UserId);
            Response::Error("ユーザーIDまたはパスワードが違います。\nあと{$Remaining}回失敗するとログインがロックされます。", 200, [
                'IsLocked' => false,
                'TryLoginCount' => $TryLoginCount,
                'RemainingAttempts' => $Remaining
            ]);
        }
    }

    // ログイン成功：ログイン試行回数をリセット、ロック解除年月日に現在時刻を登録
    $UpdateStmt = $Db->prepare('
        UPDATE UserInfo
        SET tryLoginCount = 0,
            loginLockDate = current_timestamp(3),
            updateDate = current_timestamp(3)
        WHERE userId = :userId
    ');
    $UpdateStmt->execute([':userId' => $UserId]);

    // セッション固定攻撃対策：ログイン成功時にセッションIDを再生成
    session_regenerate_id(true);

    // 前のセッション情報をクリアしてから登録（管理者フラグの引き継ぎ防止）
    $_SESSION = [];
    $_SESSION['UserId'] = $UserId;
    $_SESSION['LastActivity'] = time();

    // 管理者チェック
    $AdminStmt = $Db->prepare('SELECT userId FROM AdminInfo WHERE userId = :userId');
    $AdminStmt->execute([':userId' => $UserId]);
    $IsAdmin = (bool)$AdminStmt->fetch();

    if ($IsAdmin) {
        $_SESSION['AuthFlg'] = true;
    }

    Logger::Info($FunctionName, 'ログイン処理終了', $UserId);

    Response::Success([
        'IsAdmin' => $IsAdmin
    ], 'ログインに成功しました');

} catch (Exception $E) {
    Logger::Error($FunctionName, 'ログイン処理エラー', $UserId, $E->getMessage());
    Response::Error('ログイン処理中にエラーが発生しました', 500);
}
