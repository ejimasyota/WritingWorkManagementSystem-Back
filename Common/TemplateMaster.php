<?php
/**
 * TemplateMaster.php
 * テンプレートマスタ参照 共通ユーティリティ
 *
 * 作品のジャンルに対応するストーリー・プロットの雛形を取得する。
 * ストーリー作成／プロット作成ダイアログの「テンプレートから作成」と、
 * そのボタンの表示可否の判定（一覧取得API）で共通に用いる。
 */

class TemplateMaster
{
    /** 区分 : ストーリー */
    public const DIVISION_STORY = 1;
    /** 区分 : プロット */
    public const DIVISION_PLOT = 2;

    /**
     * 作品のジャンルに対応するテンプレートのJSON文字列を取得する
     *
     * @param PDO    $Pdo          DB接続
     * @param string $WorkId       作品ID
     * @param int    $DivisionType 区分（DIVISION_STORY / DIVISION_PLOT）
     * @return string|null テンプレート内容のJSON文字列。該当が無い場合は null
     */
    public static function GetTemplateText(PDO $Pdo, string $WorkId, int $DivisionType): ?string
    {
        $Stmt = $Pdo->prepare("
            SELECT t.templateText
            FROM WorkInfo w
            INNER JOIN TemplateMasterInfo t
                ON t.genre = w.genre AND t.divisionType = :divisionType
            WHERE w.workId = :workId
        ");
        $Stmt->execute([
            ':workId' => $WorkId,
            ':divisionType' => $DivisionType,
        ]);
        $Row = $Stmt->fetch();
        return $Row ? $Row['templatetext'] : null;
    }

    /**
     * 作品のジャンルに対応するテンプレートが存在するかを判定する
     * 「テンプレートから作成」ボタンの表示可否に用いる。
     *
     * @param PDO    $Pdo          DB接続
     * @param string $WorkId       作品ID
     * @param int    $DivisionType 区分（DIVISION_STORY / DIVISION_PLOT）
     * @return bool 存在する場合 true
     */
    public static function Exists(PDO $Pdo, string $WorkId, int $DivisionType): bool
    {
        return self::GetTemplateText($Pdo, $WorkId, $DivisionType) !== null;
    }

    /**
     * テンプレートのJSON文字列を行の配列へ変換する
     *
     * @param string|null $TemplateText テンプレート内容のJSON文字列
     * @return array<int,array> テンプレート行の配列（解釈できない場合は空配列）
     */
    public static function ParseRows(?string $TemplateText): array
    {
        if (empty($TemplateText)) {
            return [];
        }
        $Decoded = json_decode($TemplateText, true);
        if (!is_array($Decoded) || empty($Decoded['Rows']) || !is_array($Decoded['Rows'])) {
            return [];
        }
        return $Decoded['Rows'];
    }

    /**
     * テンプレートからストーリー行を一括登録する
     * ストーリー作成と同一トランザクション内で呼び出すこと。
     *
     * @param PDO    $Pdo     DB接続
     * @param string $WorkId  作品ID
     * @param mixed  $StoryId 登録先のストーリーID
     * @param string $UserId  登録者
     * @return int 登録した行数
     */
    public static function ApplyStoryRows(PDO $Pdo, string $WorkId, $StoryId, string $UserId): int
    {
        $Rows = self::ParseRows(self::GetTemplateText($Pdo, $WorkId, self::DIVISION_STORY));
        if (empty($Rows)) {
            return 0;
        }

        $Stmt = $Pdo->prepare("
            INSERT INTO StoryRows (
                storyId, indexNum, narrator, narratorCharaId, monologue, content,
                registDate, updateDate, registUser, updateUser
            ) VALUES (
                :storyId, :indexNum, :narrator, NULL, :monologue, :content,
                CURRENT_TIMESTAMP(3), CURRENT_TIMESTAMP(3), :registUser, :updateUser
            )
        ");

        /* 並び順はテンプレートの指定順を保ちつつ、1からの連番へ振り直す
         * （storyrows は storyId × indexNum が一意のため、重複・欠番を避ける） */
        usort($Rows, static fn($A, $B) => ((int) ($A['IndexNum'] ?? 0)) <=> ((int) ($B['IndexNum'] ?? 0)));

        $Count = 0;
        foreach ($Rows as $Row) {
            $Content = trim((string) ($Row['Content'] ?? ''));
            if ($Content === '') {
                continue;
            }
            $Count++;
            /* 語り手は作品を跨いで使用するテンプレートのため、キャラIDは持たない。
             * 自由入力の指定が無い行は語り手を未設定とする。 */
            $Narrator = !empty($Row['FreeInput']) ? trim((string) ($Row['Narrator'] ?? '')) : null;
            $Stmt->execute([
                ':storyId' => $StoryId,
                ':indexNum' => $Count,
                ':narrator' => ($Narrator !== null && $Narrator !== '') ? $Narrator : null,
                ':monologue' => !empty($Row['Monologue']) ? 't' : 'f',
                ':content' => $Content,
                ':registUser' => $UserId,
                ':updateUser' => $UserId,
            ]);
        }
        return $Count;
    }

    /**
     * テンプレートからプロット行を一括登録する
     * プロット作成と同一トランザクション内で呼び出すこと。
     *
     * @param PDO    $Pdo    DB接続
     * @param string $WorkId 作品ID
     * @param mixed  $PlotId 登録先のプロットID
     * @param string $UserId 登録者
     * @return int 登録した行数
     */
    public static function ApplyPlotRows(PDO $Pdo, string $WorkId, $PlotId, string $UserId): int
    {
        $Rows = self::ParseRows(self::GetTemplateText($Pdo, $WorkId, self::DIVISION_PLOT));
        if (empty($Rows)) {
            return 0;
        }

        $Stmt = $Pdo->prepare("
            INSERT INTO PlotRows (
                plotId, indexNum, structureType, content,
                registDate, updateDate, registUser, updateUser
            ) VALUES (
                :plotId, :indexNum, :structureType, :content,
                CURRENT_TIMESTAMP(3), CURRENT_TIMESTAMP(3), :registUser, :updateUser
            )
        ");

        /* 起承転結 ➝ 並び順 の昇順へ整えたうえで、
         * plotrows の一意制約（plotId, indexNum, structureType）に合わせ、
         * 起承転結の区分ごとに1からの連番を振る */
        usort($Rows, static fn($A, $B) =>
            (((int) ($A['StructureType'] ?? 0)) <=> ((int) ($B['StructureType'] ?? 0)))
                ?: (((int) ($A['IndexNum'] ?? 0)) <=> ((int) ($B['IndexNum'] ?? 0))));

        $CountByStructure = [];
        $Count = 0;
        foreach ($Rows as $Row) {
            $Content = trim((string) ($Row['Content'] ?? ''));
            $StructureType = (int) ($Row['StructureType'] ?? 0);
            /* 起承転結が未設定の行はプロット行として成立しないため取り込まない */
            if ($Content === '' || $StructureType < 1) {
                continue;
            }
            $CountByStructure[$StructureType] = ($CountByStructure[$StructureType] ?? 0) + 1;
            $Stmt->execute([
                ':plotId' => $PlotId,
                ':indexNum' => $CountByStructure[$StructureType],
                ':structureType' => $StructureType,
                ':content' => $Content,
                ':registUser' => $UserId,
                ':updateUser' => $UserId,
            ]);
            $Count++;
        }
        return $Count;
    }
}
