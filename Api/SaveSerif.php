<?php
/**
 * SaveSerif.php
 * セリフ登録・更新API
 */

require_once __DIR__ . '/../Common/Database.php';
require_once __DIR__ . '/../Common/Logger.php';
require_once __DIR__ . '/../Common/Response.php';
require_once __DIR__ . '/../Common/Auth.php';
// 更新履歴 詳細メッセージ生成
require_once __DIR__ . '/../Common/HistoryDetail.php';

// POSTメソッドのみ許可
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::Error('不正なリクエストです', 405);
}

$FunctionName = 'セリフ設定';
$UserId = '';

try {
    /** リクエストデータを取得 */
    $Input = Response::GetJsonInput();
    $SerifId = $Input['SerifId'] ?? null;
    $WorkId = $AuthWorkId ?? '';
    $CharaId = $Input['CharaId'] ?? null;
    $Content = $Input['Content'] ?? '';
    $Situation = $Input['Situation'] ?? null;
    $Monologue = !empty($Input['Monologue']);
    $UserId = $AuthUserId;
    $UpdateDate = $Input['UpdateDate'] ?? null;

    $Operation = empty($SerifId) ? '登録' : '更新';
    // 更新履歴の詳細メッセージ（登録/更新分岐でそれぞれ生成）
    $Detail = null;
    Logger::Info($FunctionName, $Operation . '処理開始', $UserId);

    /** 入力チェック */
    if (empty($WorkId)) {
        Response::Error('作品IDが指定されていません');
    }

    if (empty($Content)) {
        Response::Error('セリフ内容を入力してください');
    }

    /** DB接続 */
    $Pdo = Database::GetConnection();
    Database::BeginTransaction();

    /** 空文字をNULLに変換 */
    $SerifId = !empty($SerifId) ? $SerifId : null;
    $CharaId = !empty($CharaId) ? $CharaId : null;
    $Situation = !empty($Situation) ? $Situation : null;

    /** キャラ名解決ヘルパ（FK charaId → CharacterInfo.charaName） */
    $ResolveCharaName = function ($Id) use ($Pdo, $WorkId) {
        if ($Id === null || $Id === '') {
            return null;
        }
        $S = $Pdo->prepare("SELECT charaName AS nm FROM CharacterInfo WHERE charaId = :id AND workId = :workId");
        $S->bindValue(':id', $Id, PDO::PARAM_STR);
        $S->bindValue(':workId', $WorkId, PDO::PARAM_STR);
        $S->execute();
        $R = $S->fetch();
        return $R ? $R['nm'] : null;
    };

    if (empty($SerifId)) {
        /** 新規登録 */
        $Sql = "
            INSERT INTO SerifInfo (
                workId, charaId, content, situation, monologue,
                registDate, updateDate, registUser, updateUser
            ) VALUES (
                :workId, :charaId, :content, :situation, :monologue,
                CURRENT_TIMESTAMP(3), CURRENT_TIMESTAMP(3), :registUser, :updateUser
            )
            RETURNING serifId, updateDate
        ";

        $Stmt = $Pdo->prepare($Sql);
        $Stmt->execute([
            ':workId' => $WorkId,
            ':charaId' => $CharaId,
            ':content' => $Content,
            ':situation' => $Situation,
            ':monologue' => $Monologue ? 't' : 'f',
            ':registUser' => $UserId,
            ':updateUser' => $UserId
        ]);

        $Result = $Stmt->fetch();
        $NewSerifId = $Result['serifid'];
        $NewUpdateDate = $Result['updatedate'];

        // 更新履歴の詳細メッセージを生成（登録。名称はセリフ内容）
        $Detail = HistoryDetail::Register($Content);

    } else {
        /** 差分履歴用：更新前の表示値を取得（FK charaId は名称へ解決） */
        $OldRowStmt = $Pdo->prepare("
            SELECT s.charaId, c.charaName, s.content, s.situation, s.monologue
            FROM SerifInfo s
            LEFT JOIN CharacterInfo c ON s.charaId = c.charaId
            WHERE s.serifId = :serifId AND s.workId = :workId
        ");
        $OldRowStmt->bindValue(':serifId', $SerifId, PDO::PARAM_INT);
        $OldRowStmt->bindValue(':workId',  $WorkId,  PDO::PARAM_STR);
        $OldRowStmt->execute();
        $OldRow = $OldRowStmt->fetch() ?: [];

        /** 更新 */
        $Sql = "
            UPDATE SerifInfo SET
                charaId = :charaId,
                content = :content,
                situation = :situation,
                monologue = :monologue,
                updateDate = CURRENT_TIMESTAMP(3),
                updateUser = :updateUser
            WHERE serifId = :serifId AND workId = :workId AND updateDate = :updateDate
            RETURNING updateDate
        ";

        $Stmt = $Pdo->prepare($Sql);
        $Stmt->execute([
            ':serifId' => $SerifId,
            ':workId' => $WorkId,
            ':charaId' => $CharaId,
            ':content' => $Content,
            ':situation' => $Situation,
            ':monologue' => $Monologue ? 't' : 'f',
            ':updateUser' => $UserId,
            ':updateDate' => $UpdateDate
        ]);

        $UpdateResult = $Stmt->fetch();
        if (!$UpdateResult) {
            Database::Rollback();
            Response::ConflictError();
        }

        $NewSerifId = $SerifId;
        $NewUpdateDate = $UpdateResult['updatedate'];

        /** 更新履歴の詳細メッセージを生成（更新・列単位差分） */
        // 差分対象の列 → 和名（PK/workId/監査列は対象外。FKは概念名）
        $Labels = [
            'charaId'   => 'キャラクター',
            'content'   => '内容',
            'situation' => '状況',
            'monologue' => 'モノローグ',
        ];
        // 更新前の表示値（FKは旧名称）
        $OldDisplay = [
            'charaId'   => $OldRow['charaname'] ?? null,
            'content'   => $OldRow['content']   ?? null,
            'situation' => $OldRow['situation'] ?? null,
            'monologue' => !empty($OldRow['monologue']) ? 'あり' : 'なし',
        ];
        // 更新後の表示値（FKは新id→名称解決。新id==旧idは旧名称を再利用）
        $NewCharaName = ((string) ($CharaId ?? '') === (string) ($OldRow['charaid'] ?? ''))
            ? ($OldRow['charaname'] ?? null)
            : $ResolveCharaName($CharaId);
        $NewDisplay = [
            'charaId'   => $NewCharaName,
            'content'   => $Content,
            'situation' => $Situation,
            'monologue' => $Monologue ? 'あり' : 'なし',
        ];
        $Detail = HistoryDetail::Update($Content, $OldDisplay, $NewDisplay, $Labels);
    }

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

    /** 更新履歴を登録（変更が無い更新は履歴に残す情報が無いため登録しない） */
    $HistorySql = "
        INSERT INTO UpdateHistory (
            workId, functionName, operation, detail, registDate, updateDate, registUser, updateUser
        ) VALUES (
            :workId, :functionName, :operation, :detail, CURRENT_TIMESTAMP(3), CURRENT_TIMESTAMP(3), :registUser, :updateUser
        )
    ";
    $HistoryStmt = $Pdo->prepare($HistorySql);
    if (HistoryDetail::HasChanges($Detail)) {
        $HistoryStmt->execute([
            ':workId' => $WorkId,
            ':functionName' => $FunctionName,
            ':operation' => $Operation,
            ':detail' => $Detail,
            ':registUser' => $UserId,
            ':updateUser' => $UserId
        ]);
    }

    Database::Commit();

    Logger::Info($FunctionName, $Operation . '処理終了', $UserId);

    Response::Success([
        'SerifId' => $NewSerifId,
        'UpdateDate' => $NewUpdateDate,
        'Message' => 'セリフを' . $Operation . 'しました'
    ]);

} catch (Exception $E) {
    Database::Rollback();
    Logger::Error($FunctionName, '保存処理エラー', $UserId, $E->getMessage());
    Response::Error('セリフの保存に失敗しました');
}
