<?php
/**
 * ChapterOrder.php
 * チャプターの並び順 正規化 共通ユーティリティ
 *
 * チャプターカードの並び順（ChapterInfo.indexNum）は、以下の仕様を満たす必要がある。
 *   ・関連年表なしカードは、ユーザーがドラッグ&ドロップで決めた位置を保持する
 *   ・関連年表ありカード同士の相対順序は、年表の発生時期順（eventDate, timeId）が常に正
 *
 * そのため「年表ありカードが占めているスロット位置はそのままに、
 * そこへ収まるカードの並びだけを年表順へ差し替える」正規化を行う。
 * 年表なしカードのスロット位置は一切動かさないため、ユーザー操作の結果は失われない。
 *
 * 【呼び出しタイミング】
 *   ・チャプターの登録／更新／削除／並び替え（Save/Delete/UpdateChapterOrder）
 *   ・年表の登録／更新／削除（SaveTimeline/DeleteTimeline）
 *     └ 年表の発生時期が変われば年表ありカードの正しい順序も変わるため。
 *        年表削除時は FK が ON DELETE SET NULL で紐づけを解除するため、
 *        解除されたカードは「年表なしカード」として現在位置に留まる。
 *
 * 【updateDate を更新しない理由】
 *   indexNum は年表側の変更からも派生的に決まる値であり、
 *   ここで updateDate を進めると画面を開いたままのユーザーが
 *   意図せず楽観ロックエラー（競合）になるため、indexNum のみを更新する。
 */

require_once __DIR__ . '/Database.php';

class ChapterOrder
{
    /** アドバイザリロックのキー（第1引数：機能識別子） */
    const ADVISORY_LOCK_KEY = 1002;

    /** 年表・チャプターの行を更新する前に、作品単位の順序ロックを取得する。 */
    public static function Lock(PDO $Pdo, string $WorkId): void
    {
        $Stmt = $Pdo->prepare("SELECT pg_advisory_xact_lock(" . self::ADVISORY_LOCK_KEY . ", hashtext(:workId))");
        $Stmt->execute([':workId' => $WorkId]);
    }

    /**
     * 指定作品のチャプターの並び順を正規化する
     *
     * 呼び出し元のトランザクション内で実行すること（コミット・ロールバックは行わない）。
     *
     * @param PDO    $Pdo    DB接続
     * @param string $WorkId 作品ID
     * @return void
     */
    public static function Normalize(PDO $Pdo, string $WorkId): void
    {
        if ($WorkId === '') {
            return;
        }

        /** 並び順の同時更新による競合を防ぐため、作品単位でアドバイザリロックを取得 */
        self::Lock($Pdo, $WorkId);

        /** 現在の並び順でチャプターを取得（年表の発生時期も併せて取得） */
        $Stmt = $Pdo->prepare("
            SELECT
                ch.chapterId,
                ch.timeId,
                ch.indexNum,
                t.eventDate
            FROM ChapterInfo ch
            LEFT JOIN TimelineInfo t ON ch.timeId = t.timeId
            WHERE ch.workId = :workId
            ORDER BY ch.indexNum ASC, ch.chapterId ASC
        ");
        $Stmt->execute([':workId' => $WorkId]);
        $Rows = $Stmt->fetchAll();

        if (empty($Rows)) {
            return;
        }

        /** 年表ありカードが占めているスロット位置と、その中身を抽出 */
        $LinkedSlots = [];
        $LinkedRows  = [];
        foreach ($Rows as $Index => $Row) {
            if (!empty($Row['timeid'])) {
                $LinkedSlots[] = $Index;
                $LinkedRows[]  = $Row;
            }
        }

        /** 年表ありカードを年表順（発生時期 → 年表ID）へ並べ替える */
        usort($LinkedRows, function ($A, $B) {
            $DateA = self::DateKey($A['eventdate'] ?? null);
            $DateB = self::DateKey($B['eventdate'] ?? null);
            if ($DateA !== $DateB) {
                return strcmp($DateA, $DateB);
            }
            return (int) $A['timeid'] <=> (int) $B['timeid'];
        });

        /** 年表ありカードのスロットへ、年表順に並べ替えた結果を戻す */
        foreach ($LinkedSlots as $SlotIndex => $Slot) {
            $Rows[$Slot] = $LinkedRows[$SlotIndex];
        }

        /** 先頭から連番を振り直す（変更のある行のみ UPDATE） */
        $UpdateStmt = $Pdo->prepare("
            UPDATE ChapterInfo SET indexNum = :indexNum
            WHERE chapterId = :chapterId AND workId = :workId
        ");
        foreach ($Rows as $Index => $Row) {
            $NewIndex = $Index + 1;
            if ((int) $Row['indexnum'] === $NewIndex) {
                continue;
            }
            $UpdateStmt->execute([
                ':indexNum'  => $NewIndex,
                ':chapterId' => $Row['chapterid'],
                ':workId'    => $WorkId,
            ]);
        }
    }

    /**
     * 年表ありカード同士の並び替えが行われていないか検証する
     *
     * 「関連年表ありカード ➝ 関連年表ありカード」の並び替えは不可のため、
     * 要求された並び順における年表ありカードの並びが、
     * 年表順（発生時期 → 年表ID）と一致するかどうかを判定する。
     *
     * @param PDO    $Pdo             DB接続
     * @param string $WorkId          作品ID
     * @param array  $OrderedChapterIds 要求された並び順のチャプターIDリスト
     * @return bool 並び替え可能な場合 true
     */
    public static function IsValidOrder(PDO $Pdo, string $WorkId, array $OrderedChapterIds): bool
    {
        $Stmt = $Pdo->prepare("
            SELECT ch.chapterId, ch.timeId, t.eventDate
            FROM ChapterInfo ch
            LEFT JOIN TimelineInfo t ON ch.timeId = t.timeId
            WHERE ch.workId = :workId
        ");
        $Stmt->execute([':workId' => $WorkId]);

        $Map = [];
        foreach ($Stmt->fetchAll() as $Row) {
            $Map[(string) $Row['chapterid']] = $Row;
        }

        /** 要求順における年表ありカードの並び */
        $RequestedLinked = [];
        foreach ($OrderedChapterIds as $ChapterId) {
            $Row = $Map[(string) $ChapterId] ?? null;
            if ($Row !== null && !empty($Row['timeid'])) {
                $RequestedLinked[] = $Row;
            }
        }

        /** 年表ありカードが1件以下なら相対順序の概念が無いため常に可 */
        if (count($RequestedLinked) <= 1) {
            return true;
        }

        /** 正しい並び（年表順） */
        $ExpectedLinked = $RequestedLinked;
        usort($ExpectedLinked, function ($A, $B) {
            $DateA = self::DateKey($A['eventdate'] ?? null);
            $DateB = self::DateKey($B['eventdate'] ?? null);
            if ($DateA !== $DateB) {
                return strcmp($DateA, $DateB);
            }
            return (int) $A['timeid'] <=> (int) $B['timeid'];
        });

        $RequestedIds = array_map(fn($R) => (string) $R['chapterid'], $RequestedLinked);
        $ExpectedIds  = array_map(fn($R) => (string) $R['chapterid'], $ExpectedLinked);

        return $RequestedIds === $ExpectedIds;
    }

    /**
     * 発生時期の比較キーを生成する
     * 数字以外を除去し、8桁（YYYYMMDD想定）へゼロ埋めして文字列比較できるようにする。
     * 未設定は最大値扱いとし、末尾へ寄せる。
     *
     * @param string|null $DateStr
     * @return string
     */
    private static function DateKey(?string $DateStr): string
    {
        $Digits = preg_replace('/[^0-9]/', '', (string) $DateStr);
        if ($Digits === '') {
            return '99999999';
        }
        return str_pad(substr($Digits, 0, 8), 8, '0', STR_PAD_RIGHT);
    }
}
