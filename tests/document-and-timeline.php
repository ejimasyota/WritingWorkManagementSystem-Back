<?php
/** DB接続なしでHTML出力と年表詳細の保存・競合・ロールバックを確認する。 */
require_once __DIR__ . '/../Common/HtmlDocument.php';
require_once __DIR__ . '/../Common/TimelineDetails.php';
require_once __DIR__ . '/../Common/SearchScreens.php';
function Check(bool $Condition, string $Label): void { if (!$Condition) throw new RuntimeException($Label); }

$Output = HtmlDocument::Narrative('<作品>', '名前', 'ストーリー', [
    ['Heading'=>'1. <script>alert(1)</script>', 'Content'=>"<img src=x>\n次の行"]
], '</style><script>alert(2)</script>', true, true);
$Html = base64_decode($Output['FileData'], true);
Check(strpos($Html, '<script>') === false && strpos($Html, '<img src=x>') === false, 'HTMLエスケープ');
Check(strpos($Html, '&lt;script&gt;') !== false && strpos($Html, '<br>') !== false, '本文と改行');
Check($Output['MimeType'] === 'text/html; charset=UTF-8' && str_ends_with($Output['FileName'], '.html'), 'ダウンロード形式');
$Plain = base64_decode(HtmlDocument::Narrative('作品','名前','プロット', [['Heading'=>'1. [起]','Content'=>"a\nb"]], 'serif',false,false)['FileData']);
Check(strpos($Plain,'class="cover"') === false && strpos($Plain,'a b') !== false, '表紙・改行指定');

class TimelineTestStatement extends PDOStatement
{
    private array $Result = [];
    private int $Count = 0;
    public function __construct(private TimelineTestPdo $Db, private string $Sql) {}
    public function execute(?array $Params = null): bool
    {
        $this->Result = []; $this->Count = 0;
        if (str_starts_with($this->Sql, 'SELECT * FROM TimelineEventInfo')) {
            $this->Result = array_values($this->Db->Rows); return true;
        }
        if (str_contains($this->Sql, 'INSERT INTO UpdateHistory')) { $this->Db->History[] = $Params; return true; }
        if (str_contains($this->Sql, 'INSERT INTO TimelineEventInfo')) {
            if (($Params[':title'] ?? '') === 'DB失敗') throw new RuntimeException('保存時のDBエラー');
            $Id = (string) ++$this->Db->Sequence;
            $Row = ['timeeventid'=>$Id,'updatedate'=>'new'];
            foreach ($Params as $Key => $Value) $Row[strtolower(substr($Key,1))] = $Value;
            $this->Db->Rows[$Id] = $Row; $this->Result = [$Row]; $this->Count = 1; return true;
        }
        $Id = (string) ($Params[':timeEventId'] ?? '');
        if (str_starts_with($this->Sql, 'UPDATE TimelineEventInfo')) {
            if (!isset($this->Db->Rows[$Id]) || $this->Db->Rows[$Id]['updatedate'] !== $Params[':originalUpdateDate']) return true;
            foreach ($Params as $Key => $Value) $this->Db->Rows[$Id][strtolower(substr($Key,1))] = $Value;
            $this->Db->Rows[$Id]['updatedate'] = 'new'; $this->Result = [$this->Db->Rows[$Id]]; $this->Count = 1; return true;
        }
        if (str_starts_with($this->Sql, 'DELETE FROM TimelineEventInfo')) {
            if (isset($this->Db->Rows[$Id]) && $this->Db->Rows[$Id]['updatedate'] === $Params[':updateDate']) {
                unset($this->Db->Rows[$Id]); $this->Count = 1;
            }
            return true;
        }
        throw new RuntimeException('未定義SQL: ' . $this->Sql);
    }
    public function fetchAll(int $Mode = PDO::FETCH_DEFAULT, mixed ...$Args): array { return $this->Result; }
    public function fetch(int $Mode = PDO::FETCH_DEFAULT, int $Orientation = PDO::FETCH_ORI_NEXT, int $Offset = 0): mixed { return array_shift($this->Result) ?? false; }
    public function rowCount(): int { return $this->Count; }
}
class TimelineTestPdo extends PDO
{
    public array $Rows = [];
    public array $History = [];
    public array $Statements = [];
    public int $Sequence = 10;
    public string $ParentTitle = '保存前';
    private ?array $Before = null;
    public function __construct() {}
    public function prepare(string $Query, array $Options = []): PDOStatement|false {  $this->Statements[] = $Query; return new TimelineTestStatement($this, $Query); }
    public function inTransaction(): bool { return $this->Before !== null; }
    public function beginTransaction(): bool { $this->Before = [$this->Rows,$this->History,$this->ParentTitle]; return true; }
    public function commit(): bool { $this->Before = null; return true; }
    public function rollBack(): bool { [$this->Rows,$this->History,$this->ParentTitle] = $this->Before; $this->Before = null; return true; }
}
$Input = ['Title'=>'出来事','StartDate'=>'2000年5月1日','StartTime'=>'10:00','EndDate'=>'2000年5月1日','EndTime'=>'11:00','Content'=>'内容'];
$Details = TimelineDetails::Validate([$Input], [], '2000年', '2001年');
foreach ([['EndTime'=>'09:00'], ['StartDate'=>'1999年'], ['Title'=>str_repeat('あ',31)], ['StartTime'=>'24:00']] as $Invalid) {
    try { TimelineDetails::Validate([array_merge($Input,$Invalid)], [], '2000年','2001年'); throw new RuntimeException('不正値を受理'); }
    catch (InvalidArgumentException $Error) {}
}
$Db = new TimelineTestPdo(); $Db->beginTransaction(); $Db->ParentTitle = '保存後';
$Saved = TimelineDetails::Save($Db,'work','1','user',$Details,[]);
Check(count($Saved) === 1 && $Db->inTransaction(), '詳細保存は親トランザクションを確定しない');
$Db->commit();
$ExistingId = $Saved[0]['TimeEventId'];
$Snapshot = [['TimeEventId'=>$ExistingId,'UpdateDate'=>'new']];
$WriteCount = static fn(): int => count(array_filter($Db->Statements, static fn(string $Sql): bool =>
    preg_match('/^(?:INSERT INTO|UPDATE|DELETE FROM) TimelineEventInfo/', $Sql) === 1));
$BeforeWrites = $WriteCount();
$Db->beginTransaction();
$Unchanged = array_merge($Details[0], ['TimeEventId'=>$ExistingId,'UpdateDate'=>'new']);
TimelineDetails::Save($Db,'work','1','user',[$Unchanged],$Snapshot); $Db->commit();
Check($WriteCount() === $BeforeWrites, '未変更の詳細は更新日時を進めない');
$Db->beginTransaction();
try {
    TimelineDetails::Save($Db,'work','1','user',[],[['TimeEventId'=>$ExistingId,'UpdateDate'=>'stale']]);
    throw new RuntimeException('削除対象の競合を見落とした');
} catch (RuntimeException $Error) { Check($Error->getCode() === 409, '削除対象も元の版を照合'); $Db->rollBack(); }
$Db->beginTransaction();
TimelineDetails::Save($Db,'work','1','user',[array_merge($Unchanged,['Title'=>'更新した詳細'])],$Snapshot); $Db->commit();
$History = json_decode($Db->History[array_key_last($Db->History)][':detail'],true);
Check($History['Changes'][0]['Old'] === '出来事' && $History['Changes'][0]['New'] === '更新した詳細','詳細の変更履歴');

$Db->beginTransaction();
try { TimelineDetails::Save($Db,'work','1','user',[],[]); throw new RuntimeException('別利用者の追加を見落とした'); }
catch (RuntimeException $Error) { Check($Error->getCode() === 409,'詳細一覧の競合'); $Db->rollBack(); }
$Before = $Db->Rows;
$Db->beginTransaction(); $Db->ParentTitle = '失敗する親変更';
try {
    TimelineDetails::Save($Db,'work','1','user',[$Details[0],array_merge($Details[0],['Title'=>'DB失敗'])],$Snapshot);
    throw new RuntimeException('DBエラー未検出');
} catch (RuntimeException $Error) { $Db->rollBack(); }
Check($Db->Rows === $Before && $Db->ParentTitle === '保存後', '途中失敗で親と詳細を共にロールバック');
$Db->beginTransaction(); TimelineDetails::Save($Db,'work','1','user',[],$Snapshot); $Db->commit();
Check(count($Db->Rows) === 0,'削除を親と同時に保存');
$Config = SearchScreens::Load();
$Selected = SearchScreens::Select($Config['Screens'],['MenuStory','MenuTerm','Unknown']);
Check(count($Selected) === 2, '検索対象は許可定義へ限定する');
Check(SearchScreens::IsValidIdentifier('storyrows') && !SearchScreens::IsValidIdentifier('storyrows;DROP TABLE WorkInfo'), 'SQL識別子の検証');
echo "HTML出力・年表詳細・検索定義の検証に成功しました。\n";
