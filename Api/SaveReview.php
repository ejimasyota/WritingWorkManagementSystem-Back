<?php
/**
 * SaveReview.php
 * 作品レビュー登録・更新API
 */

require_once __DIR__ . '/../Common/Database.php';
require_once __DIR__ . '/../Common/Logger.php';
require_once __DIR__ . '/../Common/Response.php';
require_once __DIR__ . '/../Common/Auth.php';
require_once __DIR__ . '/../Common/HistoryDetail.php';
// コード区分値 → 名称変換
require_once __DIR__ . '/../Common/CodeLabel.php';

/**
 * 作品レビューを登録・更新する
 */
function SaveReview(): void
{
    global $AuthUserId;
    global $AuthWorkId;
    // POSTメソッドのみ許可
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        Response::Error('不正なリクエストです', 405);
        return;
    }
    $FunctionName = '作品レビュー';
    $UserId = '';

    try {
        /** リクエストデータを取得 */
        $Input = Response::GetJsonInput();
        $ReviewId = $Input['ReviewId'] ?? null;
        $WorkId = $AuthWorkId ?? '';
        $ReviewContent = $Input['ReviewContent'] ?? '';
        $Evaluation = $Input['Evaluation'] ?? 0;
        $UserId = $AuthUserId;
        $UpdateDate = $Input['UpdateDate'] ?? null;

        $Operation = empty($ReviewId) ? '登録' : '更新';
        // 更新履歴の詳細メッセージ（登録/更新分岐でそれぞれ生成）
        $Detail = null;
        Logger::Info($FunctionName, $Operation . '処理開始', $UserId);

        /** 入力チェック */
        if (empty($WorkId)) {
            Response::Error('作品IDが指定されていません');
            return;
        }

        if (empty($ReviewContent)) {
            Response::Error('レビュー内容を入力してください');
            return;
        }

        if (empty($Evaluation) || $Evaluation < 1 || $Evaluation > 4) {
            Response::Error('評価を選択してください');
            return;
        }

        /** DB接続 */
        $Pdo = Database::GetConnection();
        Database::BeginTransaction();

        /** レビュアー名は画面からの入力を受け付けず、ログインユーザーのIDを設定する */
        $ReviewerName = !empty($UserId) ? $UserId : null;

        if (empty($ReviewId)) {
            /** 新規登録 */
            $Sql = "
                INSERT INTO ReviewInfo (
                    workId, reviewerName, reviewContent, evaluation,
                    registDate, updateDate, registUser, updateUser
                ) VALUES (
                    :workId, :reviewerName, :reviewContent, :evaluation,
                    CURRENT_TIMESTAMP(3), CURRENT_TIMESTAMP(3), :registUser, :updateUser
                )
                RETURNING reviewId, updateDate
            ";

            $Stmt = $Pdo->prepare($Sql);
            $Stmt->execute([
                ':workId' => $WorkId,
                ':reviewerName' => $ReviewerName,
                ':reviewContent' => $ReviewContent,
                ':evaluation' => $Evaluation,
                ':registUser' => $UserId,
                ':updateUser' => $UserId
            ]);

            $Result = $Stmt->fetch();
            $NewReviewId = $Result['reviewid'];
            $NewUpdateDate = $Result['updatedate'];

            // 更新履歴の詳細メッセージを生成（登録。レビュアー名を名称として使用）
            $Detail = HistoryDetail::Register($ReviewerName);

        } else {
            /** 更新 - 自分が登録したレビューのみ更新可能 */
            // 差分履歴用：更新前の表示値を取得（FK列なし。評価は区分値のため差分対象外）
            $OldRowStmt = $Pdo->prepare("
                SELECT reviewerName, reviewContent, evaluation
                FROM ReviewInfo
                WHERE reviewId = :reviewId AND workId = :workId
            ");
            $OldRowStmt->bindValue(':reviewId', $ReviewId, PDO::PARAM_STR);
            $OldRowStmt->bindValue(':workId', $WorkId, PDO::PARAM_STR);
            $OldRowStmt->execute();
            $OldRow = $OldRowStmt->fetch() ?: [];

            $Sql = "
                UPDATE ReviewInfo SET
                    reviewerName = :reviewerName,
                    reviewContent = :reviewContent,
                    evaluation = :evaluation,
                    updateDate = CURRENT_TIMESTAMP(3),
                    updateUser = :updateUser
                WHERE reviewId = :reviewId AND workId = :workId AND updateDate = :updateDate AND registUser = :registUser
                RETURNING updateDate
            ";

            $Stmt = $Pdo->prepare($Sql);
            $Stmt->execute([
                ':reviewId' => $ReviewId,
                ':workId' => $WorkId,
                ':reviewerName' => $ReviewerName,
                ':reviewContent' => $ReviewContent,
                ':evaluation' => $Evaluation,
                ':updateUser' => $UserId,
                ':updateDate' => $UpdateDate,
                ':registUser' => $UserId
            ]);
            $UpdateResult = $Stmt->fetch();

            if (!$UpdateResult) {
                Database::Rollback();
                Response::ConflictError();
            }

            $NewReviewId = $ReviewId;
            $NewUpdateDate = $UpdateResult['updatedate'];

            /* 更新履歴の詳細メッセージを生成（更新・列単位差分。FK列なし）
             * レビュアー名は画面からの入力を受け付けず常にログインユーザーのIDとなるため、
             * 差分の対象からは除く */
            $Labels = [
                'evaluation'    => '評価',
                'reviewContent' => 'レビュー内容',
            ];
            $OldDisplay = [
                'evaluation'    => CodeLabel::Get('Review', $OldRow['evaluation'] ?? null),
                'reviewContent' => $OldRow['reviewcontent'] ?? null,
            ];
            $NewDisplay = [
                'evaluation'    => CodeLabel::Get('Review', $Evaluation),
                'reviewContent' => $ReviewContent,
            ];
            $Detail = HistoryDetail::Update($ReviewerName, $OldDisplay, $NewDisplay, $Labels);
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
            'ReviewId' => $NewReviewId,
            'UpdateDate' => $NewUpdateDate,
            'Message' => '作品レビューを' . $Operation . 'しました'
        ]);

    } catch (Exception $E) {
        Database::Rollback();
        Logger::Error($FunctionName, '保存処理エラー', $UserId, $E->getMessage());
        Response::Error('作品レビューの保存に失敗しました');
    }
}

/** API実行 */
SaveReview();
