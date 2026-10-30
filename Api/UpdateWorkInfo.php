<?php
/* ==========================================================
 * 作品情報更新
 * ----------------------------------------------------------
 * ・作品情報の更新を行うAPI
 * ----------------------------------------------------------
 * 更新履歴：
 * 2026-01-16 作成
 * ========================================================== */

/* ==========================================================
 * 1. 共通モジュール読み込み
 * ========================================================== */
// 1. データベース接続クラス
require_once __DIR__ . '/../Common/Database.php';
// 2. ログ出力クラス
require_once __DIR__ . '/../Common/Logger.php';
// 3. レスポンスクラス
require_once __DIR__ . '/../Common/Response.php';
// 4. ユーザー認証
require_once __DIR__ . '/../Common/Auth.php';
// 5. 日付ユーティリティ（日付パース・年齢計算の共通処理）
require_once __DIR__ . '/../Common/DateUtil.php';
// 6. 更新履歴 詳細メッセージ生成
require_once __DIR__ . '/../Common/HistoryDetail.php';
// 7. コード区分値 → 名称変換
require_once __DIR__ . '/../Common/CodeLabel.php';

/* ==========================================================
 * 2. リクエストチェック
 * ========================================================== */ 
/* ------------------------------
 * 1. POSTメソッド以外の場合
 * ------------------------------*/
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    // 1. エラー返却
    Response::Error('不正なリクエストです', 405);
}

/* ==========================================================
 * 3. グローバル定義
 * ========================================================== */
// 1. 機能名
$FunctionName = '作品情報更新';
// 2. 作品ID
$WorkId = $AuthWorkId ?? '';
// 3. ユーザーID
$UserId = $AuthUserId;

/* ==========================================================
 * 4. リクエスト取得
 * ========================================================== */
// 1. 画面からのリクエストを取得
$Input = Response::GetJsonInput();
// 2. 作品名
$WorkTitle = $Input['WorkTitle'] ?? '';
// 3. ジャンル
$Genre = $Input['Genre'] ?? 0;
// 4. 物語開始年月日
$SettingDate = $Input['SettingDate'] ?? '';
// 5. モチーフ
$Motif = $Input['Motif'] ?? null;
// 6. テーマ
$Theme = $Input['Theme'] ?? '';
// 7. 概要
$Description = $Input['Description'] ?? '';
// 8. 更新年月日
$UpdateDate = $Input['UpdateDate'] ?? null;

/* ==========================================================
 * 5. 処理部
 * ========================================================== */
try {
    /* ------------------------------
     * 1. 事前処理
     * ------------------------------*/
    Logger::Info($FunctionName, '更新処理開始', $UserId);

    /* ------------------------------
     * 2. バリデーションチェック
     * ------------------------------*/
    /* 1. 作品ID: 必須チェック */
    if (empty($WorkId)) {
        // 1. エラー返却
        Response::Error('作品IDが指定されていません');
    }
    /* 2. ユーザーID: 必須チェック */
    if (empty($UserId)) {
        // 1. エラー返却
        Response::Error('ユーザーIDが指定されていません');
    }
    /* 3. 作品名: 必須チェック */
    if (empty($WorkTitle)) {
        // 1. エラー返却
        Response::Error('作品名が指定されていません');
    }


    /* ---------------------------------------------
     * 3. DB接続
     * --------------------------------------------- */
    // 1. DB接続
    $Db = Database::GetConnection();
    // 2. トランザクション開始
    Database::BeginTransaction();

    /* ---------------------------------------------
     * 3-2. 差分履歴用：更新前の表示値を取得
     *      作品共通情報（WorkInfo）と設定情報（SettingInfo）を
     *      workId で LEFT JOIN し、更新前の値をまとめて取得する。
     *      取得キーは PDO 既定の小文字。
     * --------------------------------------------- */
    $OldRowStmt = $Db->prepare('
        SELECT
            w.workTitle, w.genre, w.description,
            s.settingDate, s.motif, s.theme
        FROM WorkInfo w
        LEFT JOIN SettingInfo s ON w.workId = s.workId
        WHERE w.workId = :workId
    ');
    $OldRowStmt->bindValue(':workId', $WorkId, PDO::PARAM_STR);
    $OldRowStmt->execute();
    $OldRow = $OldRowStmt->fetch() ?: [];

    /* ---------------------------------------------
     * 4. 作品情報更新
     * --------------------------------------------- */
    // 1. クエリ定義
    $Stmt = $Db->prepare('
        UPDATE WorkInfo
        SET workTitle = :workTitle,
            genre = :genre,
            description = :description,
            updateDate = CURRENT_TIMESTAMP(3),
            updateUser = :updateUser
        WHERE workId = :workId
          AND updateDate = :updateDate
    ');

    // 2. 実行
    $Stmt->execute([
        ':workTitle' => $WorkTitle,
        ':genre' => $Genre,
        ':description' => $Description !== '' ? $Description : null,
        ':updateUser' => $UserId,
        ':workId' => $WorkId,
        ':updateDate' => $UpdateDate
    ]);

    // 3. 更新件数が0件の場合（楽観ロック）
    if ($Stmt->rowCount() === 0) {
        // 1. ロールバック
        Database::Rollback();
        // 2. エラー返却
        Response::ConflictError();
    }

    /* ---------------------------------------------
     * 4-2. 更新履歴の詳細メッセージを生成（更新・列単位差分）
     *      ジャンル・モチーフはコード値のため、画面と同一の
     *      区分→名称マップ（CodeLabel）で可読な名称に変換してから差分判定する。
     *      設定日・テーマ・概要・作品名は値そのもので差分判定する。
     * --------------------------------------------- */
    // 1. 差分対象の列 → 和名（この範囲・順序で差分判定する）
    $Labels = [
        'workTitle'   => '作品名',
        'genre'       => 'ジャンル',
        'settingDate' => '物語開始年月日',
        'motif'       => 'モチーフ',
        'theme'       => 'テーマ',
        'description' => '概要',
    ];

    // 2. 更新前の表示値（コード値は名称へ変換）
    $OldDisplay = [
        'workTitle'   => $OldRow['worktitle']   ?? null,
        'genre'       => CodeLabel::Get('Genre', $OldRow['genre'] ?? null),
        'settingDate' => $OldRow['settingdate'] ?? null,
        'motif'       => CodeLabel::Get('Motif', $OldRow['motif'] ?? null),
        'theme'       => $OldRow['theme']       ?? null,
        'description' => $OldRow['description'] ?? null,
    ];

    // 3. 更新後の表示値（DBへ保存する値に合わせる。コード値は名称へ変換）
    $NewDisplay = [
        'workTitle'   => $WorkTitle,
        'genre'       => CodeLabel::Get('Genre', $Genre),
        'settingDate' => $SettingDate ?: null,
        'motif'       => CodeLabel::Get('Motif', $Motif ?: null),
        'theme'       => $Theme ?: null,
        'description' => $Description !== '' ? $Description : null,
    ];

    // 7. 詳細メッセージを生成
    $Detail = HistoryDetail::Update($WorkTitle, $OldDisplay, $NewDisplay, $Labels);

    /* ---------------------------------------------
     * 5. 作品設定情報更新
     * --------------------------------------------- */
    // 1. クエリ定義
    $SettingStmt = $Db->prepare('
        INSERT INTO SettingInfo (workId, settingDate, motif, theme, registUser, updateUser)
        VALUES (:workId, :settingDate, :motif, :theme, :registUser, :updateUser)
        ON CONFLICT (workId) DO UPDATE SET
            settingDate = EXCLUDED.settingDate,
            motif = EXCLUDED.motif,
            theme = EXCLUDED.theme,
            updateDate = CURRENT_TIMESTAMP(3),
            updateUser = EXCLUDED.updateUser
    ');

    // 2. 実行
    $SettingStmt->execute([
        ':workId' => $WorkId,
        ':settingDate' => $SettingDate ?: null,
        ':motif' => $Motif ?: null,
        ':theme' => $Theme ?: null,
        ':registUser' => $UserId,
        ':updateUser' => $UserId
    ]);

    /* ---------------------------------------------
     * 6. 舞台設定日に基づきキャラクターの年齢を更新
     * --------------------------------------------- */
    if (!empty($SettingDate)) {
        /* 1. 生年月日が存在するキャラクター一覧を取得（没年月日も取得） */
        // 1. クエリを定義
        $CharaStmt = $Db->prepare('
            SELECT
                charaId AS "CharaId",
                birthDate AS "BirthDate",
                deathDate AS "DeathDate"
            FROM CharacterInfo
            WHERE workId = :workId AND birthDate IS NOT NULL AND birthDate != \'\'
        ');
        // 2. 実行
        $CharaStmt->execute([':workId' => $WorkId]);
        // 3. 結果セットを取得
        $Characters = $CharaStmt->fetchAll();

        // 舞台設定日を解釈できる場合のみ年齢を再計算
        // （日付パース・年齢算出はフロントと同一解釈の DateUtil に共通化）
        if (DateUtil::Parse($SettingDate) !== null) {
            $AgeUpdateStmt = $Db->prepare('
                UPDATE CharacterInfo
                SET age = :age,
                    updateDate = CURRENT_TIMESTAMP(3),
                    updateUser = :updateUser
                WHERE charaId = :charaId
            ');

            foreach ($Characters as $Chara) {
                // 没年月日が登録されているキャラクターは年齢を再計算しない
                if (!empty($Chara['DeathDate'])) continue;

                // 生年月日が解釈できない場合はスキップ（既存挙動を踏襲）
                if (DateUtil::Parse($Chara['BirthDate']) === null) continue;

                // 舞台設定日が生年月日より前の場合は null（＝年齢クリア）、それ以外は算出年齢を登録
                $Age = DateUtil::CalcAge($SettingDate, $Chara['BirthDate']);

                $AgeUpdateStmt->execute([
                    ':age' => $Age === null ? null : (string) $Age,
                    ':updateUser' => $UserId,
                    ':charaId' => $Chara['CharaId']
                ]);
            }
        }
    }

    // 更新履歴に登録（変更が無い更新は履歴に残す情報が無いため登録しない）
    $HistoryStmt = $Db->prepare('
        INSERT INTO UpdateHistory (workId, functionName, operation, detail, registUser, updateUser)
        VALUES (:workId, :functionName, :operation, :detail, :registUser, :updateUser)
    ');

    if (HistoryDetail::HasChanges($Detail)) {
        $HistoryStmt->execute([
            ':workId' => $WorkId,
            ':functionName' => '作品共通情報',
            ':operation' => '更新',
            ':detail' => $Detail,
            ':registUser' => $UserId,
            ':updateUser' => $UserId
        ]);
    }

    Database::Commit();

    Logger::Info($FunctionName, '更新処理終了', $UserId);

    Response::Success(null, '作品情報を更新しました');

} catch (Exception $E) {
    Database::Rollback();
    Logger::Error($FunctionName, '更新処理エラー', $UserId, $E->getMessage());
    Response::Error('更新処理中にエラーが発生しました', 500);
}
