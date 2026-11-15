<?php
require_once __DIR__ . '/DateUtil.php';

/** キャラの所属履歴。単一所属列は一覧・既存参照用に最新行と同期する。 */
class CharacterAffiliation
{
    private const MASTERS = [
        ['OrganizationInfo', 'OrgId', 'OrgName'],
        ['TeamInfo', 'TeamId', 'TeamName'],
        ['ClassInfo', 'ClassId', 'ClassName'],
    ];

    public static function Parse($Value): array
    {
        if ($Value === null || $Value === '') return [];
        if (is_string($Value)) {
            try {
                $Decoded = json_decode($Value, false, 512, JSON_THROW_ON_ERROR);
                if (!($Decoded instanceof stdClass) || !is_array($Decoded->Affiliations ?? null))
                    throw new InvalidArgumentException('所属履歴の形式が不正です');
                $Value = ['Affiliations' => array_map(static fn($Row) => $Row instanceof stdClass ? get_object_vars($Row) : $Row, $Decoded->Affiliations)];
            }
            catch (JsonException $Error) { throw new InvalidArgumentException('所属履歴の形式が不正です'); }
        }
        if (!is_array($Value) || !isset($Value['Affiliations']) || !is_array($Value['Affiliations']) || !array_is_list($Value['Affiliations']))
            throw new InvalidArgumentException('所属履歴の形式が不正です');
        $Rows = [];
        foreach ($Value['Affiliations'] as $Input) {
            if (!is_array($Input)) throw new InvalidArgumentException('所属履歴の行が不正です');
            $Row = [];
            foreach (['OrgId', 'OrgName', 'TeamId', 'TeamName', 'ClassId', 'ClassName', 'JoinedDate', 'LeftDate', 'Note'] as $Key) {
                if (isset($Input[$Key]) && !is_scalar($Input[$Key])) throw new InvalidArgumentException('所属履歴の項目が不正です');
                $Row[$Key] = trim((string)($Input[$Key] ?? ''));
            }
            foreach (['OrgId', 'TeamId', 'ClassId'] as $Key) {
                if ($Row[$Key] !== '' && !preg_match('/^[1-9][0-9]{0,18}$/D', $Row[$Key]))
                    throw new InvalidArgumentException('所属先IDが不正です');
            }
            if ($Row['OrgId'] === '' && $Row['TeamId'] === '' && $Row['ClassId'] === '')
                throw new InvalidArgumentException('所属を選択してください');
            foreach (['JoinedDate', 'LeftDate'] as $Key) {
                if ($Row[$Key] !== '' && (mb_strlen($Row[$Key]) > 20 || DateUtil::Parse($Row[$Key]) === null))
                    throw new InvalidArgumentException('所属履歴の年月日が不正です');
            }
            if (DateUtil::Compare($Row['LeftDate'], $Row['JoinedDate']) === -1)
                throw new InvalidArgumentException('脱退年月日は加入年月日以降を指定してください');
            $Rows[] = $Row;
        }
        usort($Rows, static function ($Left, $Right) {
            $A = DateUtil::Parse($Left['JoinedDate']);
            $B = DateUtil::Parse($Right['JoinedDate']);
            return $A === null ? ($B === null ? 0 : 1) : ($B === null ? -1 : $B <=> $A);
        });
        return $Rows;
    }

    /** 同一作品のマスタを一括取得し、改ざんされたID・組織との不整合を拒否する。 */
    public static function Prepare(PDO $Pdo, string $WorkId, $Value, $Previous): array
    {
        $Rows = self::Parse($Value);
        // 読込・帳票では移行データの未設定日を許容し、新規・更新保存では必須にする。
        foreach ($Rows as $Row) {
            if ($Row['JoinedDate'] === '') throw new InvalidArgumentException('加入年月日を入力してください');
        }
        $Old = self::Parse($Previous);
        $Maps = [];
        foreach (self::MASTERS as [$Table, $Id, $Name]) {
            $Columns = $Id === 'OrgId' ? '' : ', orgId';
            $Stmt = $Pdo->prepare("SELECT $Id AS id, $Name AS name $Columns FROM $Table WHERE workId = :workId FOR SHARE");
            $Stmt->execute([':workId' => $WorkId]);
            foreach ($Stmt->fetchAll() as $Master) $Maps[$Id][(string)$Master['id']] = $Master;
        }
        foreach ($Rows as &$Row) {
            foreach (self::MASTERS as [$Table, $Id, $Name]) {
                if ($Row[$Id] === '') { $Row[$Name] = ''; continue; }
                $Master = $Maps[$Id][$Row[$Id]] ?? null;
                $PreviousRow = null;
                foreach ($Old as $Candidate) {
                    if ($Candidate['OrgId'] === $Row['OrgId'] && $Candidate['TeamId'] === $Row['TeamId'] && $Candidate['ClassId'] === $Row['ClassId']) {
                        $PreviousRow = $Candidate; break;
                    }
                }
                if ($Master === null) {
                    // 削除済みの既存履歴は名称を保持する。新しい架空IDは保存できない。
                    if ($PreviousRow === null) throw new InvalidArgumentException('所属先が存在しません。画面を再表示してください');
                    $Row[$Name] = $PreviousRow[$Name];
                } else {
                    if ($Id !== 'OrgId' && (string)($Master['orgid'] ?? '') !== $Row['OrgId'] && $PreviousRow === null)
                        throw new InvalidArgumentException('チーム・階級は選択した組織に属するものを指定してください');
                    $Row[$Name] = $Master['name'];
                }
            }
        }
        unset($Row);
        return $Rows;
    }

    public static function Encode(array $Rows): string
    {
        return json_encode(['Affiliations' => $Rows], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    public static function FormatLines($Value): string
    {
        return implode("\n", array_map(static function ($Row) {
            $Name = implode(' / ', array_filter([$Row['OrgName'], $Row['TeamName'], $Row['ClassName']], static fn($Text) => $Text !== ''));
            return $Name . '（加入：' . ($Row['JoinedDate'] ?: '未設定') . '／脱退：' . ($Row['LeftDate'] ?: '未設定') . '）'
                . ($Row['Note'] !== '' ? ' ' . $Row['Note'] : '');
        }, self::Parse($Value)));
    }
}
