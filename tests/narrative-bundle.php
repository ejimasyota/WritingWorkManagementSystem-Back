<?php
/** 実DBへ接続せず、一括保存の原子性・版チェック・旧本文整形を検証する。 */
require_once __DIR__.'/../Common/NarrativeBundle.php';
require_once __DIR__.'/../Common/StoryImport.php';
require_once __DIR__.'/../Common/NarrativeText.php';
function AssertNarrative(bool $Condition, string $Message): void { if (!$Condition) throw new RuntimeException($Message); }
class NarrativeTestPdo extends PDO
{
    public array $Parents = [];
    public array $Rows = [];
    public array $History = [];
    public int $Sequence = 10;
    public bool $Creator = true;
    private ?array $Before = null;
    public function __construct() {}
    public function prepare(string $Sql, array $Options = []): PDOStatement|false { return new NarrativeTestStatement($this,$Sql); }
    public function beginTransaction(): bool { $this->Before=[$this->Parents,$this->Rows,$this->History];return true; }
    public function inTransaction(): bool { return $this->Before!==null; }
    public function commit(): bool { $this->Before=null;return true; }
    public function rollBack(): bool { [$this->Parents,$this->Rows,$this->History]=$this->Before;$this->Before=null;return true; }
}
class NarrativeTestStatement extends PDOStatement
{
    private array $Result=[];
    public function __construct(private NarrativeTestPdo $Db,private string $Sql){}
    public function execute(?array $Params=null): bool
    {
        $this->Result=[];$Sql=$this->Sql;$P=$Params??[];
        if(str_starts_with($Sql,'SELECT w.projectLockFlg')){$this->Result=[['projectlockflg'=>false,'userlockflg'=>false,'iscreator'=>$this->Db->Creator]];return true;}
        if(str_contains($Sql,'pg_advisory_xact_lock'))return true;
        if(str_starts_with($Sql,'SELECT 1 FROM CharacterInfo')){$this->Result=$P[':id']==='chara'?[['ok'=>1]]:[];return true;}
        if(str_starts_with($Sql,'SELECT content, charaId')){$this->Result=$P[':id']==='serif'?[['content'=>'セリフ','charaid'=>'chara']]:[];return true;}
        if(str_starts_with($Sql,'SELECT productionType')){$this->Result=[['productiontype'=>1]];return true;}
        if(str_starts_with($Sql,'UPDATE WorkInfo'))return true;
        if(str_starts_with($Sql,'INSERT INTO UpdateHistory')){$this->Db->History[]=$P;return true;}
        if(!preg_match('/\b(Story|Plot|Exchange)(Info|Rows)\b/',$Sql,$Match))throw new RuntimeException('未定義SQL:'.$Sql);
        if ($Match[1] !== 'Exchange' && $Match[2] === 'Info' && stripos($Sql, 'description') !== false) throw new LogicException('ストーリー・プロットの概要列を参照しています');
        [$All,$Kind,$Type]=$Match;$IdColumn=strtolower($Kind.($Type==='Info'?'Id':'RowId'));
        $Id=(string)($P[$Type==='Info'?':id':':rowId']??'');
        if(str_starts_with($Sql,'SELECT *')){
            $this->Result=$Type==='Info'?(isset($this->Db->Parents[$Kind][$Id])?[$this->Db->Parents[$Kind][$Id]]:[]):array_values(array_filter($this->Db->Rows[$Kind]??[],static fn($Row)=>(string)$Row[strtolower($Kind.'Id')]===(string)$P[':id']));return true;
        }
        if(str_starts_with($Sql,'DELETE')){unset($this->Db->Rows[$Kind][$Id]);return true;}
        if(str_contains($Sql,'SET indexNum = -indexNum')){foreach($this->Db->Rows[$Kind]??[] as $Key=>$Row)if((string)$Row[strtolower($Kind.'Id')]===(string)$P[':id'])$this->Db->Rows[$Kind][$Key]['indexnum']=-$Row['indexnum'];return true;}
        if(($P[':Content']??'')==='DB失敗')throw new RuntimeException('途中のDB失敗');
        if(str_starts_with($Sql,'INSERT'))$Id=(string)++$this->Db->Sequence;
        $Row=[$IdColumn=>$Id,'updatedate'=>'v'.++$this->Db->Sequence];
        foreach($P as $Key=>$Value)if(!in_array($Key,[':id',':rowId',':user'],true))$Row[strtolower(substr($Key,1))]=$Value;
        if($Type==='Rows'){$Row[strtolower($Kind.'Id')]=$P[':id'];$this->Db->Rows[$Kind][$Id]=$Row;}
        else $this->Db->Parents[$Kind][$Id]=$Row;
        $this->Result=[$Row];return true;
    }
    public function fetch(int $Mode=PDO::FETCH_DEFAULT,int $Orientation=PDO::FETCH_ORI_NEXT,int $Offset=0):mixed{return array_shift($this->Result)??false;}
    public function fetchAll(int $Mode=PDO::FETCH_DEFAULT,mixed ...$Args):array{return $this->Result;}
}
foreach(['Story','Plot','Exchange'] as $Kind){
    $Db=new NarrativeTestPdo();
    $Input=[$Kind.'Name'=>'作品内文書','Description'=>'概要','ProductionType'=>1,'Rows'=>[['Content'=>'本文','StructureType'=>1]],'RowsSnapshot'=>[]];
    $Db->beginTransaction();$Saved=NarrativeBundle::Save($Db,$Kind,'work','user',$Input);
    AssertNarrative($Db->inTransaction(),'親呼出側のトランザクションを維持');$Db->commit();
    AssertNarrative(count($Db->Rows[$Kind])===1 && count($Db->History)===1,'親・行・履歴を保存');
    $Parent=array_values($Db->Parents[$Kind])[0];
    AssertNarrative($Kind === 'Exchange' ? ($Parent['description'] ?? null)==='概要' : !array_key_exists('description',$Parent),'概要は掛け合いだけで保持');
    $Row=array_values($Db->Rows[$Kind])[0];$RowId=$Row[strtolower($Kind.'RowId')];
    $Input[$Kind.'Id']=$Saved[$Kind.'Id'];$Input['UpdateDate']=$Saved['UpdateDate'];
    $Input['RowsSnapshot']=[[$Kind.'RowId'=>$RowId,'UpdateDate'=>$Row['updatedate']]];
    $Input['Rows']=[['Content'=>'変更','StructureType'=>1,$Kind.'RowId'=>$RowId,'UpdateDate'=>$Row['updatedate']]];
    $Before=[$Db->Parents,$Db->Rows,$Db->History];
    $Broken=$Input;$Broken['Rows'][]=['Content'=>'DB失敗','StructureType'=>1];
    $Db->beginTransaction();try{NarrativeBundle::Save($Db,$Kind,'work','user',$Broken);throw new LogicException('失敗しなかった');}catch(RuntimeException $Error){$Db->rollBack();}
    AssertNarrative([$Db->Parents,$Db->Rows,$Db->History]===$Before,'途中失敗は親・行・履歴すべてを元に戻す');
    $Conflict=$Input;$Conflict['RowsSnapshot'][0]['UpdateDate']='古い版';
    $Db->beginTransaction();try{NarrativeBundle::Save($Db,$Kind,'work','user',$Conflict);throw new LogicException('競合を見逃した');}catch(RuntimeException $Error){AssertNarrative($Error->getCode()===409,'明細版の競合');$Db->rollBack();}
    $Db->beginTransaction();$Saved=NarrativeBundle::Save($Db,$Kind,'work','user',$Input);$Db->commit();
    AssertNarrative(isset($Db->Rows[$Kind][$RowId]) && $Db->Rows[$Kind][$RowId]['content']==='変更','更新でも明細IDを保持');
    $Current=$Db->Rows[$Kind][$RowId];$Input['UpdateDate']=$Saved['UpdateDate'];$Input['RowsSnapshot']=[[$Kind.'RowId'=>$RowId,'UpdateDate'=>$Current['updatedate']]];$Input['Rows']=[];
    $Db->beginTransaction();NarrativeBundle::Save($Db,$Kind,'work','user',$Input);$Db->commit();AssertNarrative(!$Db->Rows[$Kind],'削除した明細も一括保存');
}
$Story=['StoryName'=>'文書','Rows'=>[['Content'=>'内容','Narrator'=>'自由入力','Monologue'=>true]],'RowsSnapshot'=>[]];
AssertNarrative(NarrativeBundle::Normalize('Story',$Story)['Rows'][0]['Content']===FormatStoryImportContent('内容',true),'語り手・独白は旧整形と同じ');
$Exchange=['ExchangeName'=>'会話','Rows'=>[['Content'=>'（思い）','Monologue'=>true]],'RowsSnapshot'=>[]];
AssertNarrative(NarrativeBundle::Normalize('Exchange',$Exchange)['Rows'][0]['Content']==='（思い）','掛け合いの独白は全角括弧で二重にしない');
$Exchange['Rows']=[['SerifId'=>'serif','Content'=>'改ざん','Monologue'=>true]];
$Normalized=NarrativeBundle::Normalize('Exchange',$Exchange)['Rows'][0];AssertNarrative($Normalized['Content']===null && $Normalized['Monologue']==='f','マスタセリフ選択時は自由入力と独白を保存しない');
$Db=new NarrativeTestPdo();$Db->Creator=false;
try{WorkDocumentAccess::Check($Db,'work','user',false,true);throw new LogicException('助手の変更を許可');}catch(RuntimeException $Error){AssertNarrative($Error->getCode()===403,'変更権限はサーバーでも検証');}
AssertNarrative(ParseStoryImportLine('太郎「はい」',['太郎'=>'chara'])['NarratorCharaId']==='chara','取込の旧キャラ解決を維持');
foreach (['Story','Exchange'] as $Kind) {
    $Input=[$Kind.'Name'=>'描写','Rows'=>[['ActionLine'=>true,'Content'=>'（走る）','Narrator'=>'話者','NarratorCharaId'=>'chara','Speaker'=>'話者','CharaId'=>'chara','SerifId'=>'serif','Feeling'=>'感情','Monologue'=>true]],'RowsSnapshot'=>[]];
    $Normalized=NarrativeBundle::Normalize($Kind,$Input)['Rows'][0];
    AssertNarrative($Normalized['ActionLine']==='t' && $Normalized['Content']==='（走る）' && $Normalized['Monologue']==='f','動作描写は本文を加工しない');
    AssertNarrative($Kind==='Story' ? $Normalized['Narrator']===null && $Normalized['NarratorCharaId']===null : $Normalized['Speaker']===null && $Normalized['CharaId']===null && $Normalized['SerifId']===null && $Normalized['Feeling']===null,'非表示の入力値を動作描写へ保存しない');
    $Db=new NarrativeTestPdo();$Db->beginTransaction();NarrativeBundle::Save($Db,$Kind,'work','user',$Input);$Db->commit();$SavedRow=array_values($Db->Rows[$Kind])[0];AssertNarrative($SavedRow['actionline']==='t','動作描写の区分をDBへ渡す');
}
AssertNarrative(NarrativeText::Monologue('言葉',true)==='（言葉）' && NarrativeText::Monologue('（言葉）',true)==='（言葉）','マスタの独白を資料へ出力しても二重括弧にしない');
AssertNarrative(NarrativeText::StoryHeading(['indexnum'=>1,'actionline'=>true,'narrator'=>'無視'])==='1.','動作描写の帳票に語り手を出さない');
$ActionCells=NarrativeText::ExchangeCells(['ActionLine'=>true,'Speaker'=>'無視','Feeling'=>'無視','Content'=>'歩く'],2);
AssertNarrative($ActionCells===['2',[''],'歩く',['']],'動作描写の帳票では発言者と心境を未設定表示にせず空欄にする');
echo "文書一括保存・競合・ロールバック・旧本文整形の検証に成功しました。\n";
