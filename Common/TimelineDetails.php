<?php
/** 年表詳細の一括保存。呼び出し元が親年表と同じトランザクションを管理する。 */
require_once __DIR__ . '/DateUtil.php';
require_once __DIR__ . '/RelationJson.php';
require_once __DIR__ . '/HistoryDetail.php';

final class TimelineDetails
{
    /** DB更新前に形式・必須項目・親との期間の整合性を検証する。 */
    public static function Validate($Details, $Snapshot, string $ParentStart, ?string $ParentEnd): array
    {
        if (!is_array($Details) || !array_is_list($Details) || !is_array($Snapshot) || !array_is_list($Snapshot)) {
            throw new InvalidArgumentException('年表詳細の形式が不正です');
        }
        $Result = [];
        $Ids = [];
        foreach ($Details as $Row) {
            if (!is_array($Row)) throw new InvalidArgumentException('年表詳細の形式が不正です');
            foreach (['Title', 'StartDate', 'StartTime', 'EndDate', 'EndTime', 'Content'] as $Key) {
                if (isset($Row[$Key]) && !is_string($Row[$Key])) {
                    throw new InvalidArgumentException('年表詳細の入力値が不正です');
                }
                $Row[$Key] = trim($Row[$Key] ?? '');
            }
            if ($Row['Title'] === '' || mb_strlen($Row['Title']) > 30) {
                throw new InvalidArgumentException('出来事タイトルを30桁以内で入力してください');
            }
            if ($Row['StartDate'] === '' || $Row['Content'] === '') {
                throw new InvalidArgumentException('開始年月日と内容を入力してください');
            }
            foreach (['StartTime', 'EndTime'] as $Key) {
                if ($Row[$Key] !== '' && !preg_match('/\A(?:[01][0-9]|2[0-3]):[0-5][0-9]\z/', $Row[$Key])) {
                    throw new InvalidArgumentException('時分はHH:MM形式で入力してください');
                }
            }
            if ($Row['EndDate'] === '') $Row['EndTime'] = '';
            $Compare = DateUtil::Compare($Row['EndDate'], $Row['StartDate']);
            if ($Row['EndDate'] !== '' && ($Compare < 0 || ($Compare === 0 && $Row['StartTime'] !== ''
                && $Row['EndTime'] !== '' && $Row['EndTime'] < $Row['StartTime']))) {
                throw new InvalidArgumentException('終了年月日は開始年月日以降を設定してください');
            }
            if (DateUtil::Compare($Row['StartDate'], $ParentStart) < 0) {
                throw new InvalidArgumentException('開始年月日は年表の発生時期以降を設定してください');
            }
            if ($ParentEnd && DateUtil::Compare($Row['EndDate'] ?: $Row['StartDate'], $ParentEnd) > 0) {
                throw new InvalidArgumentException('終了年月日は年表の終了時期以前を設定してください');
            }
            $Id = (string) ($Row['TimeEventId'] ?? '');
            if ($Id !== '' && (!preg_match('/\A[1-9][0-9]{0,18}\z/', $Id) || isset($Ids[$Id]))) {
                throw new InvalidArgumentException('年表詳細IDが不正または重複しています');
            }
            if ($Id !== '') $Ids[$Id] = true;
            $Result[] = $Row;
        }
        return $Result;
    }

    /**
     * 読込時の全詳細のID・更新日時を照合した後、追加・更新・削除する。
     * 削除行もSnapshotへ残すことで、別利用者の変更を削除で上書きしない。
     * 親の行ロックは呼び出し元が先に取得し、ロック順序を親→詳細へ統一する。
     */
    public static function Save(PDO $Pdo, string $WorkId, $TimeId, string $UserId, array $Details, array $Snapshot): array
    {
        if (!$Pdo->inTransaction()) throw new LogicException('年表詳細はトランザクション内で保存してください');
        $Stmt = $Pdo->prepare('SELECT * FROM TimelineEventInfo WHERE workId = :workId AND timeId = :timeId ORDER BY timeEventId FOR UPDATE');
        $Stmt->execute([':workId' => $WorkId, ':timeId' => $TimeId]);
        $Existing = [];
        foreach ($Stmt->fetchAll() as $Row) $Existing[(string) $Row['timeeventid']] = $Row;
        $Seen = [];
        foreach ($Snapshot as $Row) {
            if (!is_array($Row)) throw new InvalidArgumentException('年表詳細の読込情報が不正です');
            $Id = (string) ($Row['TimeEventId'] ?? '');
            if (isset($Seen[$Id]) || !isset($Existing[$Id])
                || (string) $Existing[$Id]['updatedate'] !== (string) ($Row['UpdateDate'] ?? '')) {
                throw new RuntimeException('他ユーザーによって年表詳細が変更されています。再度開いてください', 409);
            }
            $Seen[$Id] = true;
        }
        if (count($Seen) !== count($Existing)) {
            throw new RuntimeException('他ユーザーによって年表詳細が変更されています。再度開いてください', 409);
        }
        $Saved = [];
        foreach ($Details as $Row) {
            $Id = (string) ($Row['TimeEventId'] ?? '');
            if ($Id !== '' && (!isset($Existing[$Id])
                || (string) ($Row['UpdateDate'] ?? '') !== (string) $Existing[$Id]['updatedate'])) {
                throw new RuntimeException('他ユーザーによって年表詳細が変更されています。再度開いてください', 409);
            }
            $Relations = [];
            foreach ([['Chara', 'CharacterInfo'], ['Stage', 'StageInfo'], ['Build', 'BuildInfo']] as [$Kind, $Table]) {
                $Text = RelationJson::Encode($Row[$Kind . 'Info'] ?? null, $Kind . 'Info', $Kind . 'Id');
                $Error = RelationJson::ValidateIds($Pdo, $WorkId, $Text, $Kind . 'Info', $Kind . 'Id', $Table,
                    lcfirst($Kind) . 'Id', '関連項目');
                if ($Error !== null) throw new InvalidArgumentException($Error);
                $Relations[strtolower($Kind) . 'Text'] = $Text;
            }
            // 内容が同じ詳細の版は進めない。親だけの変更で詳細の競合を増やさないため。
            if ($Id !== '') {
                $Unchanged = true;
                foreach (['Title', 'StartDate', 'StartTime', 'EndDate', 'EndTime', 'Content'] as $Key) {
                    if ((string) ($Existing[$Id][strtolower($Key)] ?? '') !== (string) $Row[$Key]) $Unchanged = false;
                }
                foreach ($Relations as $Key => $Value) {
                    if ((string) ($Existing[$Id][strtolower($Key)] ?? '') !== (string) ($Value ?? '')) $Unchanged = false;
                }
                if ($Unchanged) {
                    $Saved[] = ['TimeEventId' => $Id, 'UpdateDate' => $Existing[$Id]['updatedate']];
                    unset($Existing[$Id]);
                    continue;
                }
            }
            $Values = [':workId' => $WorkId, ':timeId' => $TimeId, ':updateUser' => $UserId];
            foreach (['Title', 'StartDate', 'StartTime', 'EndDate', 'EndTime', 'Content'] as $Key) {
                $Values[':' . lcfirst($Key)] = $Row[$Key] !== '' ? $Row[$Key] : null;
            }
            foreach ($Relations as $Key => $Value) $Values[':' . $Key] = $Value;
            if ($Id === '') {
                $Sql = 'INSERT INTO TimelineEventInfo (workId,timeId,title,startDate,startTime,endDate,endTime,content,
                    charaText,stageText,buildText,registUser,updateUser,registDate,updateDate)
                    VALUES (:workId,:timeId,:title,:startDate,:startTime,:endDate,:endTime,:content,
                    :charaText,:stageText,:buildText,:registUser,:updateUser,CURRENT_TIMESTAMP(3),CURRENT_TIMESTAMP(3))
                    RETURNING timeEventId,updateDate';
                $Values[':registUser'] = $UserId;
            } else {
                $Sql = 'UPDATE TimelineEventInfo SET title=:title,startDate=:startDate,startTime=:startTime,
                    endDate=:endDate,endTime=:endTime,content=:content,charaText=:charaText,stageText=:stageText,
                    buildText=:buildText,updateUser=:updateUser,updateDate=CURRENT_TIMESTAMP(3)
                    WHERE workId=:workId AND timeId=:timeId AND timeEventId=:timeEventId AND updateDate=:originalUpdateDate
                    RETURNING timeEventId,updateDate';
                $Values[':timeEventId'] = $Id;
                $Values[':originalUpdateDate'] = $Row['UpdateDate'];
            }
            $Stmt = $Pdo->prepare($Sql);
            $Stmt->execute($Values);
            $Result = $Stmt->fetch();
            if (!$Result) throw new RuntimeException('年表詳細の保存が競合しました', 409);
            $Saved[] = ['TimeEventId' => $Result['timeeventid'], 'UpdateDate' => $Result['updatedate']];
            $Labels = ['Title'=>'出来事タイトル','StartDate'=>'開始年月日','StartTime'=>'開始時分',
                'EndDate'=>'終了年月日','EndTime'=>'終了時分','Content'=>'内容',
                'CharaText'=>'関連キャラ','StageText'=>'関連舞台','BuildText'=>'関連建物'];
            $OldDisplay = [];
            $NewDisplay = [];
            foreach ($Labels as $Key => $Label) {
                $OldDisplay[$Key] = $Id !== '' ? ($Existing[$Id][strtolower($Key)] ?? null) : null;
                $NewDisplay[$Key] = $Row[$Key] ?? $Relations[lcfirst($Key)] ?? null;
            }
            // 更新履歴は関連IDではなく、同一作品内で解決した名称を記録する。
            if ($Id !== '') {
                foreach ([['Chara', 'CharacterInfo', 'charaName'], ['Stage', 'StageInfo', 'stageName'], ['Build', 'BuildInfo', 'buildName']] as [$Kind, $Table, $NameColumn]) {
                    $Key = $Kind . 'Text';
                    $Map = RelationJson::BuildNameMap($Pdo, $WorkId, [$OldDisplay[$Key], $NewDisplay[$Key]],
                        $Kind . 'Info', $Kind . 'Id', $Table, lcfirst($Kind) . 'Id', $NameColumn);
                    $OldDisplay[$Key] = RelationJson::ToDisplayText($OldDisplay[$Key], $Map, $Kind . 'Info', $Kind . 'Id');
                    $NewDisplay[$Key] = RelationJson::ToDisplayText($NewDisplay[$Key], $Map, $Kind . 'Info', $Kind . 'Id');
                }
            }
            self::History($Pdo, $WorkId, $UserId, $Id === '' ? '登録' : '更新',
                $Id === '' ? HistoryDetail::Register($Row['Title'])
                    : HistoryDetail::Update($Row['Title'], $OldDisplay, $NewDisplay, $Labels));
            if ($Id !== '') unset($Existing[$Id]);
        }
        foreach ($Existing as $Id => $Row) {
            $Stmt = $Pdo->prepare('DELETE FROM TimelineEventInfo WHERE workId=:workId AND timeId=:timeId AND timeEventId=:timeEventId AND updateDate=:updateDate');
            $Stmt->execute([':workId' => $WorkId, ':timeId' => $TimeId, ':timeEventId' => $Id, ':updateDate' => $Row['updatedate']]);
            if ($Stmt->rowCount() !== 1) throw new RuntimeException('年表詳細の削除が競合しました', 409);
            self::History($Pdo, $WorkId, $UserId, '削除', HistoryDetail::Delete($Row['title']));
        }
        return $Saved;
    }

    /** 子の変更履歴も親と同一のトランザクションで記録する。 */
    private static function History(PDO $Pdo, string $WorkId, string $UserId, string $Operation, ?string $Detail): void
    {
        if (!HistoryDetail::HasChanges($Detail)) return;
        $Stmt = $Pdo->prepare("INSERT INTO UpdateHistory (workId,functionName,operation,detail,registDate,updateDate,registUser,updateUser)
            VALUES (:workId,'年表詳細',:operation,:detail,CURRENT_TIMESTAMP(3),CURRENT_TIMESTAMP(3),:registUser,:updateUser)");
        $Stmt->execute([':workId' => $WorkId, ':operation' => $Operation, ':detail' => $Detail, ':registUser' => $UserId, ':updateUser' => $UserId]);
    }
}
