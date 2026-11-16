<?php
require_once __DIR__ . '/../Common/CharacterAffiliation.php';
function AffiliationCheck(bool $Condition, string $Message): void {
    if (!$Condition) throw new RuntimeException($Message);
}
function AffiliationReject(callable $Action): void {
    try { $Action(); } catch (InvalidArgumentException $Error) { return; }
    throw new RuntimeException('不正な所属履歴を受理しました');
}
class AffiliationTestPdo extends PDO
{
    public array $Queries = [];
    public array $Masters = [
        'OrganizationInfo' => [['id' => '1', 'name' => '組織']],
        'TeamInfo' => [['id' => '2', 'name' => 'チーム', 'orgid' => '1']],
        'ClassInfo' => [['id' => '3', 'name' => '階級', 'orgid' => '1']],
    ];
    public function __construct() {}
    public function prepare(string $Sql, array $Options = []): PDOStatement|false {
        $this->Queries[] = $Sql;
        preg_match('/FROM (\w+)/', $Sql, $Match);
        return new AffiliationTestStatement($this->Masters[$Match[1]]);
    }
}
class AffiliationTestStatement extends PDOStatement
{
    public function __construct(private array $Rows) {}
    public function execute(?array $Params = null): bool {
        AffiliationCheck(($Params[':workId'] ?? '') === 'work', '参照先を作品で制限する');
        return true;
    }
    public function fetchAll(int $Mode = PDO::FETCH_DEFAULT, mixed ...$Args): array { return $this->Rows; }
}
$Db = new AffiliationTestPdo();
$Row = ['OrgId' => '1', 'TeamId' => '2', 'ClassId' => '3', 'OrgName' => '偽の組織名', 'JoinedDate' => '2000年', 'LeftDate' => '2005年', 'Note' => '<備考>'];
$Value = ['Affiliations' => [$Row, array_replace($Row, ['JoinedDate' => '2010-02', 'LeftDate' => '']), array_replace($Row, ['JoinedDate' => '1990年', 'LeftDate' => ''])]];
$Rows = CharacterAffiliation::Prepare($Db, 'work', $Value, null);
AffiliationCheck($Rows[0]['JoinedDate'] === '2010-02' && $Rows[2]['JoinedDate'] === '1990年', '最新加入順');
AffiliationCheck($Rows[0]['OrgName'] === '組織' && $Rows[0]['TeamName'] === 'チーム', '名称はマスタから解決する');
AffiliationCheck(count($Db->Queries) === 3 && count(array_filter($Db->Queries, fn($Sql) => str_contains($Sql, 'FOR SHARE'))) === 3, '行数に依存しない問い合わせと削除競合の防止');
$Migrated = ['Affiliations' => [array_replace($Row, ['JoinedDate' => '', 'LeftDate' => ''])]];
AffiliationCheck(CharacterAffiliation::Parse($Migrated)[0]['JoinedDate'] === '', '日付未設定の移行行は読み込める');
AffiliationCheck(CharacterAffiliation::Prepare($Db, 'work', $Migrated, $Migrated)[0]['JoinedDate'] === '', '加入年月日は任意');
AffiliationCheck(CharacterAffiliation::Prepare($Db, 'work', ['Affiliations' => [array_replace($Row, ['JoinedDate' => '   '])]], null)[0]['JoinedDate'] === '', '空白の加入年月日は未設定として保存する');
$Encoded = CharacterAffiliation::Encode($Rows);
AffiliationCheck(CharacterAffiliation::Parse($Encoded) === $Rows, 'JSONを損失なく再読込する');
AffiliationCheck(str_contains(CharacterAffiliation::FormatLines($Encoded), '<備考>'), '帳票と更新履歴に備考を含む');
foreach (['{', '{}', '{"Affiliations":{}}'] as $Invalid) AffiliationReject(fn() => CharacterAffiliation::Parse($Invalid));
foreach ([['OrgId' => 'x'], ['OrgId' => ['1']], ['JoinedDate' => '不明'], ['LeftDate' => '1999年']] as $Invalid)
    AffiliationReject(fn() => CharacterAffiliation::Parse(['Affiliations' => [array_replace($Row, $Invalid)]]));
AffiliationReject(fn() => CharacterAffiliation::Prepare($Db, 'work', ['Affiliations' => [array_replace($Row, ['OrgId' => '99'])]], null));
AffiliationReject(fn() => CharacterAffiliation::Prepare($Db, 'work', ['Affiliations' => [array_replace($Row, ['OrgId' => ''])]], null));
// 既存の削除済み所属は残すが、新規の削除済みIDは拒否する。
$Db->Masters['OrganizationInfo'] = [];
$Historical = CharacterAffiliation::Prepare($Db, 'work', $Encoded, $Encoded);
AffiliationCheck($Historical[0]['OrgName'] === '組織', '所属先削除後も履歴の名称を失わない');
AffiliationReject(fn() => CharacterAffiliation::Prepare($Db, 'work', $Value, null));
$Cleared = CharacterAffiliation::Prepare($Db, 'work', ['Affiliations' => []], $Encoded);
AffiliationCheck($Cleared === [], '全件削除を保存できる');
echo "Character affiliation passed (sorting, validation, work scope, hierarchy, name resolution, deleted masters, history/report text).\n";
