<?php
/**
 * SetCurrentWork.php
 * 現在開いている作品IDをサーバーサイドセッションに保存するAPI
 *
 * 用途: 作品一覧画面で作品を選択して入る際に呼び出す。
 *       権限確認後、$_SESSION['CurrentWorkId'] に WorkId を保存する。
 *       以降の API は $AuthWorkId（Auth.php 経由）から取得する。
 */

require_once __DIR__ . '/../Common/Database.php';
require_once __DIR__ . '/../Common/Logger.php';
require_once __DIR__ . '/../Common/Response.php';
require_once __DIR__ . '/../Common/Auth.php';

// POSTメソッドのみ許可
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::Error('不正なリクエストです', 405);
}

$FunctionName = '作品セッション設定';
$Input = Response::GetJsonInput();
$WorkId = $Input['WorkId'] ?? '';
$UserId = $AuthUserId;

try {
    Logger::Info($FunctionName, '設定処理開始', $UserId);

    if (empty($WorkId)) {
        Response::Error('作品IDが指定されていません');
    }

    $Db = Database::GetConnection();

    /** 作成者・参加者または管理者のみアクセス可能（BAN状態は除外） */
    $PermStmt = $Db->prepare('
        SELECT isCreator, userLockFlg
        FROM CreaterList
        WHERE workId = :workId AND userId = :userId
    ');
    $PermStmt->execute([':workId' => $WorkId, ':userId' => $UserId]);
    $Permission = $PermStmt->fetch();

    if (!$Permission && !$IsAuthAdmin) {
        Response::Error('参加中の作品ではありません', 403);
    }

    // 管理者でない場合、参加者のロック状態を確認
    if ($Permission && !$IsAuthAdmin && !empty($Permission['userlockflg'])) {
        Response::Error('この作品へのアクセスが制限されています', 403);
    }

    /** 作品自体の存在確認・停止フラグ確認 */
    $WorkStmt = $Db->prepare('
        SELECT projectLockFlg
        FROM WorkInfo
        WHERE workId = :workId
    ');
    $WorkStmt->execute([':workId' => $WorkId]);
    $Work = $WorkStmt->fetch();

    if (!$Work) {
        Response::Error('作品が存在しません', 404);
    }

    // プロジェクトが凍結されている場合は管理者以外アクセス不可
    if (!empty($Work['projectlockflg']) && !$IsAuthAdmin) {
        Response::Error('この作品は凍結されています', 403);
    }

    /** セッションに保存 */
    $_SESSION['CurrentWorkId'] = $WorkId;

    Logger::Info($FunctionName, '設定処理終了', $UserId);
    Response::Success(['WorkId' => $WorkId]);

} catch (Exception $E) {
    Logger::Error($FunctionName, '設定処理エラー', $UserId, $E->getMessage());
    Response::Error('作品セッションの設定中にエラーが発生しました', 500);
}
