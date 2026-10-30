<?php
/**
 * SaveChapter.php
 * チャプター登録・更新API
 */

require_once __DIR__ . '/../Common/Database.php';
require_once __DIR__ . '/../Common/Logger.php';
require_once __DIR__ . '/../Common/Response.php';
require_once __DIR__ . '/../Common/Auth.php';
// 更新履歴 詳細メッセージ生成
require_once __DIR__ . '/../Common/HistoryDetail.php';
// チャプターの並び順 正規化
require_once __DIR__ . '/../Common/ChapterOrder.php';
// 関連要素（キャラ）のJSON保持 共通処理
require_once __DIR__ . '/../Common/RelationJson.php';

// POSTメソッドのみ許可
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::Error('不正なリクエストです', 405);
}

/**
 * チャプターを登録・更新する
 */
function SaveChapter(): void
{
    global $AuthUserId;
    global $AuthWorkId;
    $FunctionName = 'チャプター設定';
    $UserId = '';

    try {
        /** リクエストデータを取得 */
        $Input = Response::GetJsonInput();
        $ChapterId = $Input['ChapterId'] ?? null;
        $WorkId = $AuthWorkId ?? '';
        $ChapterName = trim((string) ($Input['ChapterName'] ?? ''));
        $IndexNumInput = $Input['IndexNum'] ?? null;
        $Description = $Input['Description'] ?? null;
        $TimeId = $Input['TimeId'] ?? null;
        $CharaInfoInput = $Input['CharaInfo'] ?? null;
        $UserId = $AuthUserId;
        $UpdateDate = $Input['UpdateDate'] ?? null;

        $Operation = empty($ChapterId) ? '登録' : '更新';
        // 更新履歴の詳細メッセージ（登録/更新分岐でそれぞれ生成）
        $Detail = null;
        Logger::Info($FunctionName, $Operation . '処理開始', $UserId);

        /** 入力チェック */
        if (empty($WorkId)) {
            Response::Error('作品IDが指定されていません');
            return;
        }

        if ($ChapterName === '') {
            Response::Error('チャプター名を入力してください');
            return;
        }

        if (mb_strlen($ChapterName) > 30) {
            Response::Error('チャプター名は30桁以内で入力してください');
            return;
        }

        /*
         * 並び順（手入力）
         *   未指定の場合は従来どおり、登録時は末尾へ追加し、更新時は現在の位置を維持する。
         */
        $TargetIndexNum = null;
        if ($IndexNumInput !== null && $IndexNumInput !== '') {
            if (!is_numeric($IndexNumInput) || (int) $IndexNumInput < 1) {
                Response::Error('並び順は1以上の数値で入力してください');
                return;
            }
            $TargetIndexNum = (int) $IndexNumInput;
        }

        /*
         * 関連キャラの正規化
         *   JSON保存形式: {"CharaInfo":[{"CharaId":"<uuid>"}]}
         *   表示名は取得時にマスタから解決するため、IDのみを保持する。
         *   正規化は年表詳細と共通の RelationJson で行う（重複は取り除く）。
         */
        $CharaText = RelationJson::Encode($CharaInfoInput, 'CharaInfo', 'CharaId');

        $Description = ($Description !== null && $Description !== '') ? $Description : null;
        $TimeId = !empty($TimeId) ? $TimeId : null;

        /** DB接続 */
        $Pdo = Database::GetConnection();
        Database::BeginTransaction();

        /*
         * 関連年表・関連キャラの検証
         *   他作品のIDを保存されると、作品条件の無い結合経由で他作品の名称が参照できてしまい、
         *   形式不正のIDは一覧取得・Word出力の失敗につながるため、保存前に検証する。
         */
        if ($TimeId !== null) {
            $TimeExists = false;
            if (is_scalar($TimeId) && preg_match('/\A[1-9][0-9]{0,18}\z/', (string) $TimeId)) {
                $TimeCheckStmt = $Pdo->prepare("SELECT 1 FROM TimelineInfo WHERE timeId = :timeId AND workId = :workId");
                $TimeCheckStmt->bindValue(':timeId', (string) $TimeId, PDO::PARAM_STR);
                $TimeCheckStmt->bindValue(':workId', $WorkId, PDO::PARAM_STR);
                $TimeCheckStmt->execute();
                $TimeExists = $TimeCheckStmt->fetchColumn() !== false;
            }
            if (!$TimeExists) {
                Database::Rollback();
                Response::Error('関連年表が存在しません。画面を再表示してください');
                return;
            }
        }
        // 件数上限（RelationJson::MAX_IDS_PER_KIND）・IDの形式・同一作品内の存在を確認する
        $CharaError = RelationJson::ValidateIds($Pdo, $WorkId, $CharaText, 'CharaInfo', 'CharaId', 'CharacterInfo', 'charaId', '関連キャラ');
        if ($CharaError !== null) {
            Database::Rollback();
            Response::Error($CharaError);
            return;
        }

        /** 更新履歴の関連キャラ表示用（IDから名称を解決する）
         *  保存済みの値に形式不正のIDが含まれていても失敗しないよう、RelationJson で解決する */
        $ResolveCharaNames = function (?string $Json) use ($Pdo, $WorkId): ?string {
            $Map = RelationJson::BuildNameMap(
                $Pdo, $WorkId, [$Json],
                'CharaInfo', 'CharaId', 'CharacterInfo', 'charaId', 'charaName'
            );
            // 保存順を維持したまま名称へ変換（マスタに存在しないIDは除外）
            return RelationJson::ToDisplayText($Json, $Map, 'CharaInfo', 'CharaId');
        };

        if (empty($ChapterId)) {
            /** 新規登録：並び順は末尾へ追加する */
            $MaxStmt = $Pdo->prepare("SELECT COALESCE(MAX(indexNum), 0) AS maxindex FROM ChapterInfo WHERE workId = :workId");
            $MaxStmt->execute([':workId' => $WorkId]);
            $MaxRow = $MaxStmt->fetch();
            $NewIndexNum = (int) ($MaxRow['maxindex'] ?? 0) + 1;

            $Sql = "
                INSERT INTO ChapterInfo (
                    workId, chapterName, description, timeId, charaText, indexNum,
                    registDate, updateDate, registUser, updateUser
                ) VALUES (
                    :workId, :chapterName, :description, :timeId, :charaText, :indexNum,
                    CURRENT_TIMESTAMP(3), CURRENT_TIMESTAMP(3), :registUser, :updateUser
                )
                RETURNING chapterId, updateDate
            ";

            $Stmt = $Pdo->prepare($Sql);
            $Stmt->execute([
                ':workId' => $WorkId,
                ':chapterName' => $ChapterName,
                ':description' => $Description,
                ':timeId' => $TimeId,
                ':charaText' => $CharaText,
                ':indexNum' => $NewIndexNum,
                ':registUser' => $UserId,
                ':updateUser' => $UserId
            ]);

            $Result = $Stmt->fetch();
            $NewChapterId = $Result['chapterid'];
            $NewUpdateDate = $Result['updatedate'];
            // 更新履歴の詳細メッセージを生成（登録）
            $Detail = HistoryDetail::Register($ChapterName);

        } else {
            /** 差分履歴用：更新前の表示値を取得（FKの関連年表は名称へ解決） */
            $OldRowStmt = $Pdo->prepare("
                SELECT ch.chapterName, ch.description, ch.timeId, ch.charaText, t.title AS timelinetitle
                FROM ChapterInfo ch
                LEFT JOIN TimelineInfo t ON ch.timeId = t.timeId
                WHERE ch.chapterId = :chapterId AND ch.workId = :workId
            ");
            $OldRowStmt->execute([':chapterId' => $ChapterId, ':workId' => $WorkId]);
            $OldRow = $OldRowStmt->fetch() ?: [];

            /** 更新 */
            $Sql = "
                UPDATE ChapterInfo SET
                    chapterName = :chapterName,
                    description = :description,
                    timeId = :timeId,
                    charaText = :charaText,
                    updateDate = CURRENT_TIMESTAMP(3),
                    updateUser = :updateUser
                WHERE chapterId = :chapterId AND workId = :workId AND updateDate = :updateDate
                RETURNING updateDate
            ";

            $Stmt = $Pdo->prepare($Sql);
            $Stmt->execute([
                ':chapterId' => $ChapterId,
                ':workId' => $WorkId,
                ':chapterName' => $ChapterName,
                ':description' => $Description,
                ':timeId' => $TimeId,
                ':charaText' => $CharaText,
                ':updateUser' => $UserId,
                ':updateDate' => $UpdateDate
            ]);

            $UpdateResult = $Stmt->fetch();
            if (!$UpdateResult) {
                Database::Rollback();
                Response::ConflictError();
                return;
            }

            $NewChapterId = $ChapterId;
            $NewUpdateDate = $UpdateResult['updatedate'];

            /** 更新履歴の詳細メッセージを生成（更新・列単位差分） */
            // 関連年表（FK）の新名称解決（変更が無ければ旧名称を再利用、null時null）
            $NewTimelineTitle = null;
            if ($TimeId !== null) {
                if ((string) $TimeId === (string) ($OldRow['timeid'] ?? '')) {
                    $NewTimelineTitle = $OldRow['timelinetitle'] ?? null;
                } else {
                    $TimeStmt = $Pdo->prepare("SELECT title AS nm FROM TimelineInfo WHERE timeId = :id AND workId = :workId");
                    $TimeStmt->bindValue(':id', $TimeId);
                    $TimeStmt->bindValue(':workId', $WorkId, PDO::PARAM_STR);
                    $TimeStmt->execute();
                    $TimeRow = $TimeStmt->fetch();
                    $NewTimelineTitle = $TimeRow ? $TimeRow['nm'] : null;
                }
            }
            $Labels = [
                'chapterName' => 'チャプター名',
                'description' => '概要',
                'timeId'      => '関連年表',
                'charaText'   => '関連キャラ',
            ];
            $OldDisplay = [
                'chapterName' => $OldRow['chaptername']   ?? null,
                'description' => $OldRow['description']   ?? null,
                'timeId'      => $OldRow['timelinetitle'] ?? null,
                'charaText'   => $ResolveCharaNames($OldRow['charatext'] ?? null),
            ];
            $NewDisplay = [
                'chapterName' => $ChapterName,
                'description' => $Description,
                'timeId'      => $NewTimelineTitle,
                'charaText'   => $ResolveCharaNames($CharaText),
            ];
            $Detail = HistoryDetail::Update($ChapterName, $OldDisplay, $NewDisplay, $Labels);
        }

        /*
         * 並び順の指定がある場合は、指定位置へ移動する
         *   「関連年表ありカード ➝ 関連年表ありカード」の並び替えは仕様上不可のため、
         *   UpdateChapterOrder.php（ドラッグ&ドロップ）と同一の検証を行う。
         */
        if ($TargetIndexNum !== null) {
            /** 並び順の同時実行競合を防ぐため、workIdでアドバイザリロックを取得 */
            $LockStmt = $Pdo->prepare("SELECT pg_advisory_xact_lock(" . ChapterOrder::ADVISORY_LOCK_KEY . ", hashtext(:workId))");
            $LockStmt->execute([':workId' => (string) $WorkId]);

            /** 現在の並び順のチャプターIDを取得（登録時は本APIで追加した行も含まれる） */
            $OrderStmt = $Pdo->prepare("
                SELECT chapterId
                FROM ChapterInfo
                WHERE workId = :workId
                ORDER BY indexNum ASC, chapterId ASC
            ");
            $OrderStmt->execute([':workId' => $WorkId]);
            $OrderedIds = array_map(fn($R) => (string) $R['chapterid'], $OrderStmt->fetchAll());

            /** 対象を一旦取り除き、指定位置へ挿入し直す */
            $OrderedIds = array_values(array_filter(
                $OrderedIds,
                fn($Id) => $Id !== (string) $NewChapterId
            ));
            $Position = min($TargetIndexNum, count($OrderedIds) + 1) - 1;
            array_splice($OrderedIds, $Position, 0, [(string) $NewChapterId]);

            /** 年表ありカード同士の並び替えになっていないか検証 */
            if (!ChapterOrder::IsValidOrder($Pdo, $WorkId, $OrderedIds)) {
                Database::Rollback();
                Response::Error('関連年表が設定されているカード同士の並び替えはできません');
                return;
            }

            /** 一時的に負値へ退避してから確定値を設定する（並び順の衝突回避のため） */
            $OrderUpdateStmt = $Pdo->prepare("
                UPDATE ChapterInfo SET indexNum = :indexNum
                WHERE chapterId = :chapterId AND workId = :workId
            ");
            foreach ($OrderedIds as $Index => $Id) {
                $OrderUpdateStmt->execute([
                    ':indexNum' => -1 * ($Index + 1),
                    ':chapterId' => $Id,
                    ':workId' => $WorkId
                ]);
            }
            foreach ($OrderedIds as $Index => $Id) {
                $OrderUpdateStmt->execute([
                    ':indexNum' => $Index + 1,
                    ':chapterId' => $Id,
                    ':workId' => $WorkId
                ]);
            }
        }

        /** 並び順を正規化（年表ありカード同士の相対順序を年表順へ揃える） */
        ChapterOrder::Normalize($Pdo, $WorkId);

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
            'ChapterId' => $NewChapterId,
            'UpdateDate' => $NewUpdateDate,
            'Message' => 'チャプターを' . $Operation . 'しました'
        ]);

    } catch (Exception $E) {
        Database::Rollback();
        Logger::Error($FunctionName, '保存処理エラー', $UserId, $E->getMessage());
        Response::Error('チャプターの保存に失敗しました');
    }
}

/** API実行 */
SaveChapter();
