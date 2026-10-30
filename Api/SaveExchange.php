<?php
/**
 * SaveExchange.php
 * 掛け合い登録・更新API
 *
 * Mode = 'info' … 掛け合い（見出し）の登録・更新
 * Mode = 'row'  … 掛け合い行の登録・更新
 *
 * 行の並び順（indexNum）は (exchangeId, indexNum) の一意制約と競合しないよう、
 * ストーリー行と同じく負値を経由して入れ替える。
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

$FunctionName = '掛け合い';
$UserId = '';

try {
    $Input = Response::GetJsonInput();
    $Mode = $Input['Mode'] ?? 'row';
    $WorkId = $AuthWorkId ?? '';
    $UserId = $AuthUserId;
    $ExchangeId = $Input['ExchangeId'] ?? null;
    $ExchangeRowId = $Input['ExchangeRowId'] ?? null;
    $ExchangeName = trim($Input['ExchangeName'] ?? '');
    $Description = $Input['Description'] ?? null;
    $CharaId = $Input['CharaId'] ?? null;
    $Speaker = $Input['Speaker'] ?? null;
    $SerifId = $Input['SerifId'] ?? null;
    $Content = $Input['Content'] ?? null;
    $Monologue = !empty($Input['Monologue']);
    $Feeling = $Input['Feeling'] ?? null;
    $IndexNum = isset($Input['IndexNum']) && $Input['IndexNum'] !== '' ? (int)$Input['IndexNum'] : null;
    $UpdateDate = $Input['UpdateDate'] ?? null;

    $Operation = $Mode === 'info'
        ? (empty($ExchangeId) ? '登録' : '更新')
        : (empty($ExchangeRowId) ? '登録' : '更新');
    // 更新履歴の詳細メッセージ（登録/更新分岐でそれぞれ生成）
    $Detail = null;
    Logger::Info($FunctionName, $Operation . '処理開始', $UserId);

    if (empty($WorkId)) {
        Response::Error('作品IDが指定されていません');
    }

    $Pdo = Database::GetConnection();
    Database::BeginTransaction();

    $NewExchangeId = $ExchangeId;
    $NewExchangeRowId = $ExchangeRowId;
    $NewUpdateDate = null;

    if ($Mode === 'info') {
        /* ==========================================================
         * 掛け合い（見出し）の登録・更新
         * ========================================================== */
        if ($ExchangeName === '') {
            Database::Rollback();
            Response::Error('掛け合い名を入力してください');
        }
        $Description = ($Description !== null && trim((string)$Description) !== '') ? $Description : null;

        if (empty($ExchangeId)) {
            $Stmt = $Pdo->prepare("
                INSERT INTO ExchangeInfo (
                    workId, exchangeName, description,
                    registDate, updateDate, registUser, updateUser
                ) VALUES (
                    :workId, :exchangeName, :description,
                    CURRENT_TIMESTAMP(3), CURRENT_TIMESTAMP(3), :registUser, :updateUser
                )
                RETURNING exchangeId, updateDate
            ");
            $Stmt->execute([
                ':workId' => $WorkId,
                ':exchangeName' => $ExchangeName,
                ':description' => $Description,
                ':registUser' => $UserId,
                ':updateUser' => $UserId
            ]);
            $Result = $Stmt->fetch();
            $NewExchangeId = $Result['exchangeid'];
            $NewUpdateDate = $Result['updatedate'];

            // 更新履歴の詳細メッセージを生成（登録）
            $Detail = HistoryDetail::Register($ExchangeName);
        } else {
            /* 差分履歴用：更新前の表示値を取得（FK列なし） */
            $OldRowStmt = $Pdo->prepare("
                SELECT exchangeName, description
                FROM ExchangeInfo
                WHERE exchangeId = :exchangeId AND workId = :workId
            ");
            $OldRowStmt->execute([':exchangeId' => $ExchangeId, ':workId' => $WorkId]);
            $OldRow = $OldRowStmt->fetch() ?: [];

            $Stmt = $Pdo->prepare("
                UPDATE ExchangeInfo SET
                    exchangeName = :exchangeName,
                    description = :description,
                    updateDate = CURRENT_TIMESTAMP(3),
                    updateUser = :updateUser
                WHERE exchangeId = :exchangeId AND workId = :workId AND updateDate = :updateDate
                RETURNING updateDate
            ");
            $Stmt->execute([
                ':exchangeId' => $ExchangeId,
                ':workId' => $WorkId,
                ':exchangeName' => $ExchangeName,
                ':description' => $Description,
                ':updateUser' => $UserId,
                ':updateDate' => $UpdateDate
            ]);
            $UpdateResult = $Stmt->fetch();
            if (!$UpdateResult) {
                Database::Rollback();
                Response::ConflictError();
            }
            $NewUpdateDate = $UpdateResult['updatedate'];

            // 更新履歴の詳細メッセージを生成（更新・列単位差分）
            $Labels = [
                'exchangeName' => '掛け合い名',
                'description'  => '概要',
            ];
            $OldDisplay = [
                'exchangeName' => $OldRow['exchangename'] ?? null,
                'description'  => $OldRow['description']  ?? null,
            ];
            $NewDisplay = [
                'exchangeName' => $ExchangeName,
                'description'  => $Description,
            ];
            $Detail = HistoryDetail::Update($ExchangeName, $OldDisplay, $NewDisplay, $Labels);
        }
    } else {
        /* ==========================================================
         * 掛け合い行の登録・更新
         * ========================================================== */
        if (empty($ExchangeId)) {
            Database::Rollback();
            Response::Error('掛け合いIDが指定されていません');
        }

        /* 対象の掛け合いが自作品のものであることを確認する */
        $OwnerStmt = $Pdo->prepare("
            SELECT exchangeName FROM ExchangeInfo WHERE exchangeId = :exchangeId AND workId = :workId
        ");
        $OwnerStmt->execute([':exchangeId' => $ExchangeId, ':workId' => $WorkId]);
        $OwnerRow = $OwnerStmt->fetch();
        if (!$OwnerRow) {
            Database::Rollback();
            Response::Error('対象の掛け合いが見つかりません');
        }

        /* 発言者：キャラを選択した場合は自由入力を持たせない */
        $CharaId = !empty($CharaId) ? $CharaId : null;
        $Speaker = ($Speaker !== null && trim((string)$Speaker) !== '') ? trim((string)$Speaker) : null;
        if ($CharaId !== null) {
            $Speaker = null;
            $CharaCheckStmt = $Pdo->prepare("SELECT 1 FROM CharacterInfo WHERE charaId = :charaId AND workId = :workId");
            $CharaCheckStmt->execute([':charaId' => $CharaId, ':workId' => $WorkId]);
            if (!$CharaCheckStmt->fetch()) {
                Database::Rollback();
                Response::Error('指定された発言者が見つかりません');
            }
        }

        /* セリフ：セリフを選択した場合は自由入力を持たせない */
        $SerifId = !empty($SerifId) ? $SerifId : null;
        $Content = ($Content !== null && trim((string)$Content) !== '') ? trim((string)$Content) : null;
        if ($SerifId !== null) {
            $Content = null;
            $SerifCheckStmt = $Pdo->prepare("SELECT 1 FROM SerifInfo WHERE serifId = :serifId AND workId = :workId");
            $SerifCheckStmt->execute([':serifId' => $SerifId, ':workId' => $WorkId]);
            if (!$SerifCheckStmt->fetch()) {
                Database::Rollback();
                Response::Error('指定されたセリフが見つかりません');
            }
        }

        /* モノローグ：セリフ設定のマスタは加工できないため、自由入力の場合のみ（ ）で囲んで登録する
         *   既に（ ）で囲まれている場合は外してから付け直し、二重に囲まれることを防ぐ */
        if ($Content === null) {
            $Monologue = false;
        } elseif ($Monologue) {
            $Inner = trim(preg_replace('/^（(.*)）$/us', '$1', $Content));
            $Content = $Inner !== '' ? '（' . $Inner . '）' : null;
        }
        if ($SerifId === null && $Content === null) {
            Database::Rollback();
            Response::Error('セリフを入力してください');
        }

        $Feeling = ($Feeling !== null && trim((string)$Feeling) !== '') ? $Feeling : null;

        // 並び順の同時実行競合を防ぐため、exchangeIdでアドバイザリロックを取得
        $LockStmt = $Pdo->prepare("SELECT pg_advisory_xact_lock(1002, hashtext(:exchangeId))");
        $LockStmt->execute([':exchangeId' => (string)$ExchangeId]);

        if (empty($ExchangeRowId)) {
            /* ---------- 新規登録 ---------- */
            $MaxIndexStmt = $Pdo->prepare("SELECT COALESCE(MAX(indexNum), 0) AS currentMax FROM ExchangeRows WHERE exchangeId = :exchangeId");
            $MaxIndexStmt->execute([':exchangeId' => $ExchangeId]);
            $CurrentMaxIndex = (int)$MaxIndexStmt->fetch()['currentmax'];

            if ($IndexNum === null) {
                $InsertIndexNum = $CurrentMaxIndex + 1;
            } else {
                if ($IndexNum < 1 || $IndexNum > $CurrentMaxIndex + 1) {
                    Database::Rollback();
                    Response::Error('存在しないインデックスが入力されました');
                }
                $InsertIndexNum = $IndexNum;
            }

            /* 挿入位置以降を後ろへずらす（一意制約を避けるため負値を経由する） */
            if ($InsertIndexNum <= $CurrentMaxIndex) {
                $ShiftTempStmt = $Pdo->prepare("
                    UPDATE ExchangeRows SET
                        indexNum = -1 * (indexNum + 1),
                        updateDate = CURRENT_TIMESTAMP(3),
                        updateUser = :updateUser
                    WHERE exchangeId = :exchangeId
                      AND indexNum >= :insertIndexNum
                ");
                $ShiftTempStmt->execute([
                    ':exchangeId' => $ExchangeId,
                    ':insertIndexNum' => $InsertIndexNum,
                    ':updateUser' => $UserId
                ]);

                $ShiftApplyStmt = $Pdo->prepare("
                    UPDATE ExchangeRows SET
                        indexNum = -1 * indexNum,
                        updateDate = CURRENT_TIMESTAMP(3),
                        updateUser = :updateUser
                    WHERE exchangeId = :exchangeId
                      AND indexNum < 0
                ");
                $ShiftApplyStmt->execute([
                    ':exchangeId' => $ExchangeId,
                    ':updateUser' => $UserId
                ]);
            }

            $Stmt = $Pdo->prepare("
                INSERT INTO ExchangeRows (
                    exchangeId, indexNum, charaId, speaker, serifId, content, monologue, feeling,
                    registDate, updateDate, registUser, updateUser
                ) VALUES (
                    :exchangeId, :indexNum, :charaId, :speaker, :serifId, :content, :monologue, :feeling,
                    CURRENT_TIMESTAMP(3), CURRENT_TIMESTAMP(3), :registUser, :updateUser
                )
                RETURNING exchangeRowId, updateDate
            ");
            $Stmt->execute([
                ':exchangeId' => $ExchangeId,
                ':indexNum' => $InsertIndexNum,
                ':charaId' => $CharaId,
                ':speaker' => $Speaker,
                ':serifId' => $SerifId,
                ':content' => $Content,
                ':monologue' => $Monologue ? 't' : 'f',
                ':feeling' => $Feeling,
                ':registUser' => $UserId,
                ':updateUser' => $UserId
            ]);
            $Result = $Stmt->fetch();
            $NewExchangeRowId = $Result['exchangerowid'];
            $NewUpdateDate = $Result['updatedate'];

            // 更新履歴の詳細メッセージを生成（登録。掛け合い名を名称として扱う）
            $Detail = HistoryDetail::Register($OwnerRow['exchangename'] ?? null);
        } else {
            /* ---------- 更新 ---------- */
            /* 楽観ロックチェック：並び順の入替でupdateDateが先に書き換わるため、
             * 入替前の時点で一致を判定する（ストーリー行と同じ扱い） */
            $CurrentRowStmt = $Pdo->prepare("
                SELECT r.exchangeRowId, r.indexNum, r.charaId, r.speaker, r.serifId, r.content, r.feeling,
                       c.charaName AS charaName, s.content AS serifContent
                FROM ExchangeRows r
                LEFT JOIN CharacterInfo c ON r.charaId = c.charaId
                LEFT JOIN SerifInfo     s ON r.serifId = s.serifId
                WHERE r.exchangeRowId = :exchangeRowId AND r.exchangeId = :exchangeId
                  AND r.updateDate = :updateDate
            ");
            $CurrentRowStmt->execute([
                ':exchangeRowId' => $ExchangeRowId,
                ':exchangeId' => $ExchangeId,
                ':updateDate' => $UpdateDate
            ]);
            $CurrentRow = $CurrentRowStmt->fetch();
            if (!$CurrentRow) {
                $ExistsStmt = $Pdo->prepare("SELECT 1 FROM ExchangeRows WHERE exchangeRowId = :exchangeRowId AND exchangeId = :exchangeId");
                $ExistsStmt->execute([
                    ':exchangeRowId' => $ExchangeRowId,
                    ':exchangeId' => $ExchangeId
                ]);
                if (!$ExistsStmt->fetch()) {
                    Database::Rollback();
                    Response::Error('対象の掛け合い行が見つかりません');
                }
                Database::Rollback();
                Response::ConflictError();
            }

            $CountStmt = $Pdo->prepare("SELECT COUNT(*) AS rowCount FROM ExchangeRows WHERE exchangeId = :exchangeId");
            $CountStmt->execute([':exchangeId' => $ExchangeId]);
            $RowCount = (int)$CountStmt->fetch()['rowcount'];

            $CurrentIndexNum = (int)$CurrentRow['indexnum'];
            $TargetIndexNum = $IndexNum ?? $CurrentIndexNum;
            if ($TargetIndexNum < 1 || $TargetIndexNum > $RowCount) {
                Database::Rollback();
                Response::Error('存在しないインデックスが入力されました');
            }

            if ($TargetIndexNum !== $CurrentIndexNum) {
                /* 対象行を一時的に退避（0は他行が取らない値） */
                $TempStmt = $Pdo->prepare("
                    UPDATE ExchangeRows SET
                        indexNum = 0,
                        updateDate = CURRENT_TIMESTAMP(3),
                        updateUser = :updateUser
                    WHERE exchangeRowId = :exchangeRowId AND exchangeId = :exchangeId
                ");
                $TempStmt->execute([
                    ':exchangeRowId' => $ExchangeRowId,
                    ':exchangeId' => $ExchangeId,
                    ':updateUser' => $UserId
                ]);

                if ($TargetIndexNum < $CurrentIndexNum) {
                    $ShiftTempStmt = $Pdo->prepare("
                        UPDATE ExchangeRows SET
                            indexNum = -1 * (indexNum + 1),
                            updateDate = CURRENT_TIMESTAMP(3),
                            updateUser = :updateUser
                        WHERE exchangeId = :exchangeId
                          AND indexNum >= :targetIndexNum
                          AND indexNum < :currentIndexNum
                    ");
                } else {
                    $ShiftTempStmt = $Pdo->prepare("
                        UPDATE ExchangeRows SET
                            indexNum = -1 * (indexNum - 1),
                            updateDate = CURRENT_TIMESTAMP(3),
                            updateUser = :updateUser
                        WHERE exchangeId = :exchangeId
                          AND indexNum > :currentIndexNum
                          AND indexNum <= :targetIndexNum
                    ");
                }
                $ShiftTempStmt->execute([
                    ':exchangeId' => $ExchangeId,
                    ':targetIndexNum' => $TargetIndexNum,
                    ':currentIndexNum' => $CurrentIndexNum,
                    ':updateUser' => $UserId
                ]);

                $ShiftApplyStmt = $Pdo->prepare("
                    UPDATE ExchangeRows SET
                        indexNum = -1 * indexNum,
                        updateDate = CURRENT_TIMESTAMP(3),
                        updateUser = :updateUser
                    WHERE exchangeId = :exchangeId
                      AND indexNum < 0
                ");
                $ShiftApplyStmt->execute([
                    ':exchangeId' => $ExchangeId,
                    ':updateUser' => $UserId
                ]);
            }

            /* 楽観ロックは冒頭で確認済みのため、本UPDATEではupdateDate判定を行わない */
            $Stmt = $Pdo->prepare("
                UPDATE ExchangeRows SET
                    indexNum = :indexNum,
                    charaId = :charaId,
                    speaker = :speaker,
                    serifId = :serifId,
                    content = :content,
                    monologue = :monologue,
                    feeling = :feeling,
                    updateDate = CURRENT_TIMESTAMP(3),
                    updateUser = :updateUser
                WHERE exchangeRowId = :exchangeRowId AND exchangeId = :exchangeId
                RETURNING updateDate
            ");
            $Stmt->execute([
                ':exchangeRowId' => $ExchangeRowId,
                ':exchangeId' => $ExchangeId,
                ':indexNum' => $TargetIndexNum,
                ':charaId' => $CharaId,
                ':speaker' => $Speaker,
                ':serifId' => $SerifId,
                ':content' => $Content,
                ':monologue' => $Monologue ? 't' : 'f',
                ':feeling' => $Feeling,
                ':updateUser' => $UserId
            ]);
            $NewUpdateDate = $Stmt->fetch()['updatedate'];

            /* 更新履歴の詳細メッセージを生成（更新・列単位差分。並び順は対象外）
             * 発言者・セリフはプルダウン選択と自由入力のどちらでも読める形へ寄せる */
            $Labels = [
                'speaker' => '発言者',
                'content' => 'セリフ',
                'feeling' => '心境',
            ];
            $OldDisplay = [
                'speaker' => $CurrentRow['charaname']    ?? ($CurrentRow['speaker'] ?? null),
                'content' => $CurrentRow['serifcontent'] ?? ($CurrentRow['content'] ?? null),
                'feeling' => $CurrentRow['feeling']      ?? null,
            ];
            /* 新しい発言者名・セリフ内容を解決する（未変更なら旧値を再利用） */
            $NewSpeakerDisplay = $Speaker;
            if ($CharaId !== null) {
                if ((string) $CharaId === (string) ($CurrentRow['charaid'] ?? '')) {
                    $NewSpeakerDisplay = $CurrentRow['charaname'] ?? null;
                } else {
                    $NmStmt = $Pdo->prepare("SELECT charaName AS nm FROM CharacterInfo WHERE charaId = :id AND workId = :workId");
                    $NmStmt->execute([':id' => $CharaId, ':workId' => $WorkId]);
                    $NmRow = $NmStmt->fetch();
                    $NewSpeakerDisplay = $NmRow ? $NmRow['nm'] : null;
                }
            }
            $NewContentDisplay = $Content;
            if ($SerifId !== null) {
                if ((string) $SerifId === (string) ($CurrentRow['serifid'] ?? '')) {
                    $NewContentDisplay = $CurrentRow['serifcontent'] ?? null;
                } else {
                    $ScStmt = $Pdo->prepare("SELECT content AS ct FROM SerifInfo WHERE serifId = :id AND workId = :workId");
                    $ScStmt->execute([':id' => $SerifId, ':workId' => $WorkId]);
                    $ScRow = $ScStmt->fetch();
                    $NewContentDisplay = $ScRow ? $ScRow['ct'] : null;
                }
            }
            $NewDisplay = [
                'speaker' => $NewSpeakerDisplay,
                'content' => $NewContentDisplay,
                'feeling' => $Feeling,
            ];
            $Detail = HistoryDetail::Update($OwnerRow['exchangename'] ?? null, $OldDisplay, $NewDisplay, $Labels);
        }

        /* 行を更新した場合は親の掛け合いの更新年月日も更新する */
        $TouchStmt = $Pdo->prepare("
            UPDATE ExchangeInfo SET
                updateDate = CURRENT_TIMESTAMP(3),
                updateUser = :updateUser
            WHERE exchangeId = :exchangeId AND workId = :workId
        ");
        $TouchStmt->execute([
            ':exchangeId' => $ExchangeId,
            ':workId' => $WorkId,
            ':updateUser' => $UserId
        ]);
    }

    /** 作品情報の更新年月日を更新 */
    $UpdateWorkStmt = $Pdo->prepare("
        UPDATE WorkInfo SET
            updateDate = CURRENT_TIMESTAMP(3),
            updateUser = :updateUser
        WHERE workId = :workId
    ");
    $UpdateWorkStmt->execute([
        ':workId' => $WorkId,
        ':updateUser' => $UserId
    ]);

    /** 更新履歴を登録（変更が無い更新は履歴に残す情報が無いため登録しない） */
    $HistoryStmt = $Pdo->prepare("
        INSERT INTO UpdateHistory (
            workId, functionName, operation, detail, registDate, updateDate, registUser, updateUser
        ) VALUES (
            :workId, :functionName, :operation, :detail, CURRENT_TIMESTAMP(3), CURRENT_TIMESTAMP(3), :registUser, :updateUser
        )
    ");
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
        'ExchangeId' => $NewExchangeId,
        'ExchangeRowId' => $NewExchangeRowId,
        'UpdateDate' => $NewUpdateDate,
        'Message' => '掛け合いを' . $Operation . 'しました'
    ]);

} catch (Exception $E) {
    Database::Rollback();
    Logger::Error($FunctionName, '保存処理エラー', $UserId, $E->getMessage());
    Response::Error('掛け合いの保存に失敗しました');
}
