<?php
/**
 * DeleteWeapon.php
 * 武器削除API
 */

require_once __DIR__ . '/../Common/Database.php';
require_once __DIR__ . '/../Common/Logger.php';
require_once __DIR__ . '/../Common/Response.php';
require_once __DIR__ . '/../Common/Auth.php';
// 更新履歴 詳細メッセージ生成
require_once __DIR__ . '/../Common/HistoryDetail.php';
// キャラクターの能力・武器・技（JSON保持）の共通処理
require_once __DIR__ . '/../Common/CharacterSkill.php';
// 画像一覧の共通処理（削除時の画像ファイルの物理削除）
require_once __DIR__ . '/../Common/ImageList.php';

// POSTメソッドのみ許可
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::Error('不正なリクエストです', 405);
}

$FunctionName = '武器設定';
$UserId = '';

try {
    /** リクエストデータを取得 */
    $Input = Response::GetJsonInput();
    $WeaponId = $Input['WeaponId'] ?? null;
    $WorkId = $AuthWorkId ?? '';
    $UserId = $AuthUserId;
    $UpdateDate = $Input['UpdateDate'] ?? null;

    Logger::Info($FunctionName, '削除処理開始', $UserId);

    /** 入力チェック */
    if (empty($WeaponId)) {
        Response::Error('武器IDが指定されていません');
    }

    if (empty($WorkId)) {
        Response::Error('作品IDが指定されていません');
    }

    /** DB接続 */
    $Pdo = Database::GetConnection();
    Database::BeginTransaction();

    /** 削除対象の武器が存在するか確認 */
    $CheckSql = "SELECT weaponId, weaponName, imgPath, imgList FROM WeaponInfo WHERE weaponId = :weaponId AND workId = :workId";
    $CheckStmt = $Pdo->prepare($CheckSql);
    $CheckStmt->execute([':weaponId' => $WeaponId, ':workId' => $WorkId]);
    $WeaponInfo = $CheckStmt->fetch();

    if (!$WeaponInfo) {
        Database::Rollback();
        Response::Error('削除対象の武器が見つかりません');
    }

    // 更新履歴の詳細メッセージを生成（削除。主キーから名称変換した値を使用）
    $Detail = HistoryDetail::Delete($WeaponInfo['weaponname'] ?? null);

    /** コミット後に削除する画像のパス（画像一覧の全画像と代表画像）
     *  行の削除後は取得できないため、削除前に収集しておく。 */
    $DeleteImgPaths = ImageList::CollectPaths($WeaponInfo['imglist'] ?? null, $WeaponInfo['imgpath'] ?? null);

    /** 武器を削除 */
    $Sql = "DELETE FROM WeaponInfo WHERE weaponId = :weaponId AND workId = :workId AND updateDate = :updateDate";
    $Stmt = $Pdo->prepare($Sql);
    $Stmt->execute([
        ':weaponId' => $WeaponId,
        ':workId' => $WorkId,
        ':updateDate' => $UpdateDate
    ]);
    if ($Stmt->rowCount() === 0) {
        Database::Rollback();
        Response::ConflictError();
    }

    /** キャラクターの武器一覧（JSON）から削除した武器を除去
     *  武器はJSON保持のため外部キーによる ON DELETE SET NULL が効かない。 */
    CharacterSkill::RemoveId($Pdo, $WorkId, CharacterSkill::WEAPON, $WeaponId);

    /** 作品情報の更新年月日を更新 */
    $UpdateWorkSql = "
        UPDATE WorkInfo SET
            updateDate = CURRENT_TIMESTAMP(3),
            updateUser = :updateUser
        WHERE workId = :workId
    ";
    $UpdateWorkStmt = $Pdo->prepare($UpdateWorkSql);
    $UpdateWorkStmt->execute([
        ':workId' => $WorkId,
        ':updateUser' => $UserId
    ]);

    /** 更新履歴を登録 */
    $HistorySql = "
        INSERT INTO UpdateHistory (
            workId, functionName, operation, detail, registDate, updateDate, registUser, updateUser
        ) VALUES (
            :workId, :functionName, :operation, :detail, CURRENT_TIMESTAMP(3), CURRENT_TIMESTAMP(3), :registUser, :updateUser
        )
    ";
    $HistoryStmt = $Pdo->prepare($HistorySql);
    $HistoryStmt->execute([
        ':workId' => $WorkId,
        ':functionName' => $FunctionName,
        ':operation' => '削除',
        ':detail' => $Detail,
        ':registUser' => $UserId,
        ':updateUser' => $UserId
    ]);

    Database::Commit();

    /** Commit 後の画像の物理削除
     *  - ファイル操作失敗は DB 状態を巻き戻せないため、削除できなくても処理を継続する。
     *  - Uploads/ 配下以外のパスは FileSafety 側で削除しない。 */
    ImageList::DeleteFiles($DeleteImgPaths);

    Logger::Info($FunctionName, '削除処理終了', $UserId);

    Response::Success([
        'Message' => '武器を削除しました'
    ]);

} catch (Exception $E) {
    Database::Rollback();
    Logger::Error($FunctionName, '削除処理エラー', $UserId, $E->getMessage());
    Response::Error('武器の削除に失敗しました');
}
