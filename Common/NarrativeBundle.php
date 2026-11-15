<?php
require_once __DIR__ . '/WorkDocumentAccess.php';
/**
 * 見出しと全行の一括保存。トランザクションの開始・確定は呼出側が担当する。
 * テーブル・列は固定の種別だけから構成し、リクエスト文字列をSQL識別子へ使用しない。
 */
final class NarrativeBundle
{
    public static function Kind(string $Kind): string
    {
        if (!in_array($Kind, ['Story','Plot','Exchange'], true)) throw new InvalidArgumentException('区分が不正です');
        return $Kind;
    }
    public static function Normalize(string $Kind, array $Input): array
    {
        self::Kind($Kind);
        $Name = trim((string)($Input[$Kind.'Name'] ?? ''));
        if ($Name === '' || mb_strlen($Name) > 100) throw new InvalidArgumentException('名称は1～100文字で入力してください');
        if (!isset($Input['Rows']) || !is_array($Input['Rows']) || !isset($Input['RowsSnapshot']) || !is_array($Input['RowsSnapshot'])) throw new InvalidArgumentException('明細の保存情報が不正です');
        $Production = (int)($Input['ProductionType'] ?? 0);
        if ($Production < 0 || $Production > 5) throw new InvalidArgumentException('制作区分が不正です');
        $Rows = [];
        $Indexes = [];
        $Ids = [];
        foreach ($Input['Rows'] as $Row) {
            if (!is_array($Row)) throw new InvalidArgumentException('行情報が不正です');
            $ActionLine = $Kind !== 'Plot' && !empty($Row['ActionLine']);
            if ($ActionLine) {
                foreach (['Narrator','NarratorCharaId','Speaker','CharaId','SerifId','Feeling'] as $Key) $Row[$Key] = null;
                $Row['Monologue'] = false;
            }
            $Content = trim((string)($Row['Content'] ?? ''));
            $SerifId = $Kind === 'Exchange' ? ($Row['SerifId'] ?? null) : null;
            if ($Content === '' && empty($SerifId)) throw new InvalidArgumentException('行の内容を入力してください');
            $Id = $Row[$Kind.'RowId'] ?? null;
            if ($Id !== null && $Id !== '') {
                if (isset($Ids[(string)$Id]) || empty($Row['UpdateDate'])) throw new InvalidArgumentException('行IDまたは更新日時が不正です');
                $Ids[(string)$Id] = true;
            }
            $Values = ['Content'=>$Content, $Kind.'RowId'=>$Id, 'UpdateDate'=>$Row['UpdateDate'] ?? null];
            $Group = 0;
            if ($Kind === 'Plot') {
                $Group = (int)($Row['StructureType'] ?? 0);
                if (($Group < 1 || $Group > 4) && !($Group === 0 && $Id)) throw new InvalidArgumentException('起承転結を指定してください');
                $Values['StructureType'] = $Group;
            } else {
                $Values['ActionLine'] = $ActionLine ? 't' : 'f';
                $CharacterKey = $Kind === 'Story' ? 'NarratorCharaId' : 'CharaId';
                $NameKey = $Kind === 'Story' ? 'Narrator' : 'Speaker';
                $Values[$CharacterKey] = empty($Row[$CharacterKey]) ? null : $Row[$CharacterKey];
                $Values[$NameKey] = $Values[$CharacterKey] ? null : (trim((string)($Row[$NameKey] ?? '')) ?: null);
                if (mb_strlen($Values[$NameKey] ?? '') > 255) throw new InvalidArgumentException('話者は255文字以内で入力してください');
                $Values['Monologue'] = !empty($Row['Monologue']) ? 't' : 'f';
                if ($Kind === 'Exchange') {
                    $Values['SerifId'] = empty($SerifId) ? null : $SerifId;
                    $Values['Feeling'] = trim((string)($Row['Feeling'] ?? '')) ?: null;

                }
                // 旧保存APIと同じく、話者を設定した本文だけをセリフ／独白の括弧で整える。
                if ($Kind === 'Story' && ($Values[$CharacterKey] || $Values[$NameKey])) {
                    $Text = preg_replace('/^[「(（]/u', '', $Content);
                    $Text = preg_replace('/[」)）]$/u', '', $Text);
                    $Values['Content'] = !empty($Row['Monologue']) ? '('.$Text.')' : '「'.$Text.'」';
                }
            }
            if ($Kind === 'Exchange') {
                if ($Values['SerifId']) { $Values['Content'] = null; $Values['Monologue'] = 'f'; }
                elseif (!empty($Row['Monologue'])) {
                    $Inner = trim(preg_replace('/^（(.*)）$/us', '$1', $Content));
                    if ($Inner === '') throw new InvalidArgumentException('セリフを入力してください');
                    $Values['Content'] = '（'.$Inner.'）';
                }
            }
            $Indexes[$Group] = ($Indexes[$Group] ?? 0) + 1;
            $Values['IndexNum'] = $Indexes[$Group];
            $Rows[] = $Values;
        }
        $Values = ['Name'=>$Name, 'ProductionType'=>$Production ?: null, 'PlotId'=>empty($Input['PlotId']) ? null : $Input['PlotId'], 'Rows'=>$Rows];
        if ($Kind === 'Exchange') $Values['Description'] = (string)($Input['Description'] ?? '');
        return $Values;
    }
    private static function Reference(PDO $Pdo, string $Table, string $Column, $Id, string $WorkId): void
    {
        if (!$Id) return;
        $Stmt = $Pdo->prepare("SELECT 1 FROM $Table WHERE $Column = :id AND workId = :workId");
        $Stmt->execute([':id'=>$Id, ':workId'=>$WorkId]);
        if (!$Stmt->fetch()) throw new InvalidArgumentException('関連項目が現在の作品に存在しません');
    }
    /** 版の比較は削除した行も含むスナップショット全件に対して行う。 */
    public static function CheckSnapshot(string $Kind, array $Existing, array $Snapshot): void
    {
        $Actual = [];
        foreach ($Existing as $Row) $Actual[(string)$Row[strtolower($Kind.'RowId')]] = (string)$Row['updatedate'];
        $Expected = [];
        foreach ($Snapshot as $Row) {
            if (!is_array($Row)) throw new InvalidArgumentException('明細の更新情報が不正です');
            $Id = (string)($Row[$Kind.'RowId'] ?? '');
            if ($Id === '' || isset($Expected[$Id]) || empty($Row['UpdateDate'])) throw new InvalidArgumentException('明細の更新情報が不正です');
            $Expected[$Id] = (string)$Row['UpdateDate'];
        }
        ksort($Actual); ksort($Expected);
        if ($Actual !== $Expected) throw new RuntimeException('他ユーザーからの更新があります。画面を開き直してください', 409);
    }
    public static function Save(PDO $Pdo, string $Kind, string $WorkId, string $UserId, array $Input): array
    {
        self::Kind($Kind);
        if (!$Pdo->inTransaction()) throw new LogicException('一括保存にはトランザクションが必要です');
        $Values = self::Normalize($Kind, $Input);
        $IdColumn = $Kind.'Id'; $RowIdColumn = $Kind.'RowId'; $NameColumn = $Kind.'Name';
        $Id = $Input[$IdColumn] ?? null;
        $Existing = [];
        if ($Id) {
            // 旧個別行APIと同じアドバイザリロックを先に取得し、並び替えとの競合を防ぐ。
            $Lock = $Pdo->prepare('SELECT pg_advisory_xact_lock('.($Kind === 'Story' ? 1001 : 1002).', hashtext(:id))');
            $Lock->execute([':id'=>(string)$Id]);
            $Stmt = $Pdo->prepare("SELECT * FROM {$Kind}Info WHERE $IdColumn = :id AND workId = :workId FOR UPDATE");
            $Stmt->execute([':id'=>$Id, ':workId'=>$WorkId]);
            $Parent = $Stmt->fetch();
            if (!$Parent) throw new RuntimeException('対象が見つかりません', 404);
            if (empty($Input['UpdateDate']) || (string)$Parent['updatedate'] !== (string)$Input['UpdateDate']) throw new RuntimeException('他ユーザーからの更新があります。画面を開き直してください',409);
            $Stmt = $Pdo->prepare("SELECT * FROM {$Kind}Rows WHERE $IdColumn = :id ORDER BY $RowIdColumn FOR UPDATE");
            $Stmt->execute([':id'=>$Id]); $Existing = $Stmt->fetchAll();
        }
        self::CheckSnapshot($Kind,$Existing,$Input['RowsSnapshot']);
        if ($Kind === 'Story' && $Values['PlotId']) {
            $Stmt = $Pdo->prepare('SELECT productionType FROM PlotInfo WHERE plotId = :id AND workId = :workId');
            $Stmt->execute([':id'=>$Values['PlotId'], ':workId'=>$WorkId]); $Plot = $Stmt->fetch();
            if (!$Plot || (int)$Plot['productiontype'] !== (int)$Values['ProductionType']) throw new InvalidArgumentException('関連プロットと制作区分が一致していません');
        }
        foreach ($Values['Rows'] as &$Row) {
            if ($Kind === 'Plot') continue;
            self::Reference($Pdo,'CharacterInfo','charaId',$Row[$Kind === 'Story' ? 'NarratorCharaId' : 'CharaId'],$WorkId);
            if ($Kind === 'Exchange' && $Row['SerifId']) {
                $Stmt = $Pdo->prepare('SELECT content, charaId FROM SerifInfo WHERE serifId = :id AND workId = :workId');
                $Stmt->execute([':id'=>$Row['SerifId'],':workId'=>$WorkId]); $Serif = $Stmt->fetch();
                if (!$Serif) throw new InvalidArgumentException('セリフが現在の作品に存在しません');

            }
        }
        unset($Row);
        $ParentValues = [$NameColumn=>$Values['Name']];
        // 概要は既存の掛け合いだけが保持する。ストーリー・プロットには列を要求しない。
        if ($Kind === 'Exchange') $ParentValues['Description'] = $Values['Description'];
        if ($Kind !== 'Exchange') $ParentValues['ProductionType'] = $Values['ProductionType'];
        if ($Kind === 'Story') $ParentValues['PlotId'] = $Values['PlotId'];
        $Params = [':workId'=>$WorkId,':user'=>$UserId];
        foreach ($ParentValues as $Key=>$Value) $Params[':'.$Key] = $Value;
        if ($Id) {
            $Params[':id'] = $Id;
            $Set = implode(', ',array_map(static fn($Key)=>"$Key = :$Key",array_keys($ParentValues)));
            $Sql = "UPDATE {$Kind}Info SET $Set, updateDate = CURRENT_TIMESTAMP(3), updateUser = :user WHERE $IdColumn = :id AND workId = :workId RETURNING $IdColumn, updateDate";
        } else {
            $Columns = implode(', ',array_keys($ParentValues));
            $Bind = implode(', ',array_map(static fn($Key)=>':'.$Key,array_keys($ParentValues)));
            $Sql = "INSERT INTO {$Kind}Info (workId,$Columns,registUser,updateUser) VALUES (:workId,$Bind,:user,:user) RETURNING $IdColumn,updateDate";
        }
        $Stmt = $Pdo->prepare($Sql); $Stmt->execute($Params); $Saved = $Stmt->fetch();
        $Id = $Saved[strtolower($IdColumn)];
        $Known = [];
        foreach ($Existing as $Row) $Known[(string)$Row[strtolower($RowIdColumn)]] = $Row;
        $Retained = [];
        foreach ($Values['Rows'] as $Row) if (!empty($Row[$RowIdColumn])) {
            $RowId = (string)$Row[$RowIdColumn];
            if (!isset($Known[$RowId]) || (string)$Known[$RowId]['updatedate'] !== (string)$Row['UpdateDate']) throw new RuntimeException('行の更新情報が一致しません',409);
            if ($Kind === 'Plot' && (int)$Row['StructureType'] === 0 && (int)($Known[$RowId]['structuretype'] ?? 0) !== 0) throw new InvalidArgumentException('起承転結を指定してください');
            $Retained[$RowId] = true;
        }
        foreach ($Known as $RowId=>$Row) if (!isset($Retained[$RowId])) {
            $Stmt = $Pdo->prepare("DELETE FROM {$Kind}Rows WHERE $RowIdColumn = :rowId AND $IdColumn = :id");
            $Stmt->execute([':rowId'=>$RowId,':id'=>$Id]);
        }
        // 一意制約を維持しながら並び順を入れ替える。既存の行IDは維持する。
        $Stmt = $Pdo->prepare("UPDATE {$Kind}Rows SET indexNum = -indexNum WHERE $IdColumn = :id");
        $Stmt->execute([':id'=>$Id]);
        foreach ($Values['Rows'] as $Row) {
            $RowId = $Row[$RowIdColumn]; unset($Row[$RowIdColumn],$Row['UpdateDate']);
            $Params = [':id'=>$Id, ':user'=>$UserId];
            foreach ($Row as $Key=>$Value) $Params[':'.$Key] = $Value;
            if ($RowId) {
                $Params[':rowId'] = $RowId;
                $Set = implode(', ',array_map(static fn($Key)=>"$Key = :$Key",array_keys($Row)));
                $Sql = "UPDATE {$Kind}Rows SET $Set,updateDate = CURRENT_TIMESTAMP(3),updateUser = :user WHERE $RowIdColumn = :rowId AND $IdColumn = :id";
            } else {
                $Columns = implode(', ',array_keys($Row)); $Bind = implode(', ',array_map(static fn($Key)=>':'.$Key,array_keys($Row)));
                $Sql = "INSERT INTO {$Kind}Rows ($IdColumn,$Columns,registUser,updateUser) VALUES (:id,$Bind,:user,:user)";
            }
            $Pdo->prepare($Sql)->execute($Params);
        }
        $Pdo->prepare('UPDATE WorkInfo SET updateDate = CURRENT_TIMESTAMP(3),updateUser = :user WHERE workId = :workId')->execute([':user'=>$UserId,':workId'=>$WorkId]);
        $History = $Pdo->prepare('INSERT INTO UpdateHistory (workId,functionName,operation,detail,registDate,updateDate,registUser,updateUser) VALUES (:workId,:screen,:operation,:detail,CURRENT_TIMESTAMP(3),CURRENT_TIMESTAMP(3),:user,:user)');
        $History->execute([':workId'=>$WorkId,':screen'=>['Story'=>'ストーリー作成','Plot'=>'プロット作成','Exchange'=>'掛け合い'][$Kind],':operation'=>empty($Input[$IdColumn])?'登録':'更新',':detail'=>$Values['Name'].'（'.count($Values['Rows']).'行）',':user'=>$UserId]);
        return [$IdColumn=>$Id,'UpdateDate'=>$Saved['updatedate']];
    }
}
