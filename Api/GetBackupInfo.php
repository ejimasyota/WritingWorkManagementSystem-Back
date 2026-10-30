<?php
/**
 * GetBackupInfo.php
 * 作品バックアップCSV出力API
 */

require_once __DIR__ . '/../Common/Database.php';
require_once __DIR__ . '/../Common/Logger.php';
require_once __DIR__ . '/../Common/Response.php';
require_once __DIR__ . '/../Common/Auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::Error('不正なリクエストです', 405);
}

$FunctionName = '作品バックアップCSV出力';
$Input = Response::GetJsonInput();
// Main.html (作品一覧画面) から呼び出されるためリクエストの WorkId を使用
$WorkId = $Input['WorkId'] ?? '';
$UserId = $AuthUserId;

define('BACKUP_NULL_TOKEN', '__NULL__');
define('BACKUP_ESCAPED_NULL_TOKEN', '\\__NULL__');

function QuoteIdentifier($Name)
{
    return '"' . str_replace('"', '""', $Name) . '"';
}

function GetTableColumns($Pdo, $TableName)
{
    $Stmt = $Pdo->prepare(
        'SELECT column_name
         FROM information_schema.columns
         WHERE table_schema = :schema
           AND table_name = :tableName
         ORDER BY ordinal_position'
    );
    $Stmt->execute(array(
        ':schema' => 'public',
        ':tableName' => strtolower($TableName)
    ));

    $Rows = $Stmt->fetchAll();
    $Columns = array();
    foreach ($Rows as $Row) {
        $Columns[] = $Row['column_name'];
    }
    return $Columns;
}

function GetPrimaryKeys($Pdo, $TableName)
{
    $Stmt = $Pdo->prepare(
        "SELECT kcu.column_name
         FROM information_schema.table_constraints tc
         INNER JOIN information_schema.key_column_usage kcu
             ON tc.constraint_name = kcu.constraint_name
            AND tc.table_schema = kcu.table_schema
         WHERE tc.table_schema = :schema
           AND tc.table_name = :tableName
           AND tc.constraint_type = 'PRIMARY KEY'
         ORDER BY kcu.ordinal_position"
    );
    $Stmt->execute(array(
        ':schema' => 'public',
        ':tableName' => strtolower($TableName)
    ));

    $Rows = $Stmt->fetchAll();
    $Keys = array();
    foreach ($Rows as $Row) {
        $Keys[] = $Row['column_name'];
    }
    return $Keys;
}

function EncodeCsvValue($Value)
{
    if ($Value === null) {
        return BACKUP_NULL_TOKEN;
    }
    if ($Value === BACKUP_NULL_TOKEN) {
        return BACKUP_ESCAPED_NULL_TOKEN;
    }
    if (is_bool($Value)) {
        return $Value ? 'true' : 'false';
    }
    return (string)$Value;
}

function SanitizeFileName($FileName)
{
    return preg_replace('/[\\\\\/:*?"<>|]+/', '_', $FileName);
}

function GetBackupSignKey()
{
    // .env は putenv で環境変数へ反映されないため getenv では取得できない。
    // Env::Get で .env の実値を確実に読み込む（BACKUP_SIGN_KEY を署名鍵として使用）。
    $EnvKey = Env::Get('BACKUP_SIGN_KEY');
    if (is_string($EnvKey) && trim($EnvKey) !== '') {
        return trim($EnvKey);
    }

    $DbHost = Env::Get('DB_HOST');
    $DbName = Env::Get('DB_NAME');
    $DbUser = Env::Get('DB_USER');
    $DbPass = Env::Get('DB_PASS');
    return hash('sha256', (string)$DbHost . '|' . (string)$DbName . '|' . (string)$DbUser . '|' . (string)$DbPass);
}

function BuildBackupSignature($Payload)
{
    return hash_hmac('sha256', $Payload, GetBackupSignKey());
}

try {
    if ($WorkId === '') {
        Response::Error('作品IDが指定されていません');
    }
    if ($UserId === '') {
        Response::Error('ユーザーIDが指定されていません');
    }

    Logger::Info($FunctionName, 'バックアップ出力処理開始', $UserId);

    $Pdo = Database::GetConnection();

    $CheckStmt = $Pdo->prepare(
        'SELECT workTitle
         FROM WorkInfo
         WHERE workId = :workId
           AND registUser = :userId'
    );
    $CheckStmt->execute(array(
        ':workId' => $WorkId,
        ':userId' => $UserId
    ));
    $Work = $CheckStmt->fetch();

    if (!$Work) {
        Response::Error('バックアップ出力の権限がありません', 403);
    }

    $TargetTables = array(
        'workinfo', 'createrlist', 'updatehistory', 'settinginfo',
        'calendarinfo', 'buildinfo', 'stageinfo', 'organizationinfo', 'classinfo',
        'teaminfo', 'raceinfo', 'powerinfo', 'weaponinfo', 'moveinfo',
        'iteminfo', 'characterinfo', 'relationobjectinfo', 'relationinfo',
        'timelineinfo', 'storyinfo', 'storyrows', 'plotinfo', 'plotrows', 'clueinfo', 'terminfo',
        'serifinfo', 'exchangeinfo', 'exchangerows',
        'conversationinfo', 'biographyinfo', 'scheduleinfo',
        'workmemoinfo', 'reviewinfo', 'taginfo'
    );

    $Stream = fopen('php://temp', 'w+');
    if ($Stream === false) {
        throw new Exception('CSV生成に失敗しました');
    }

    $CreatedAt = date('c');
    $SignaturePayload = $WorkId . '|' . $CreatedAt;
    $Signature = BuildBackupSignature($SignaturePayload);

    fputcsv($Stream, array('#META', 'WorkId', $WorkId));
    fputcsv($Stream, array('#META', 'CreatedAt', $CreatedAt));
    fputcsv($Stream, array('#META', 'Signature', $Signature));

    foreach ($TargetTables as $TableName) {
        $Columns = GetTableColumns($Pdo, $TableName);
        if (empty($Columns)) {
            continue;
        }

        $SelectColumns = array();
        foreach ($Columns as $Col) {
            $SelectColumns[] = QuoteIdentifier($Col);
        }

        $PrimaryKeys = GetPrimaryKeys($Pdo, $TableName);
        $OrderBy = '';
        if (!empty($PrimaryKeys)) {
            $OrderCols = array();
            foreach ($PrimaryKeys as $Pk) {
                $OrderCols[] = QuoteIdentifier($Pk);
            }
            $OrderBy = ' ORDER BY ' . implode(', ', $OrderCols);
        }

        if (in_array('workid', $Columns, true)) {
            $Sql = 'SELECT ' . implode(', ', $SelectColumns)
                . ' FROM ' . QuoteIdentifier($TableName)
                . ' WHERE "workid" = :workId'
                . $OrderBy;
            $Stmt = $Pdo->prepare($Sql);
            $Stmt->execute(array(':workId' => $WorkId));
            $Rows = $Stmt->fetchAll(PDO::FETCH_ASSOC);
        } elseif ($TableName === 'storyrows') {
            $StoryRowSelectColumns = array();
            foreach ($Columns as $Col) {
                $StoryRowSelectColumns[] = 'sr.' . QuoteIdentifier($Col) . ' AS ' . QuoteIdentifier($Col);
            }
            $StoryRowOrderBy = '';
            if (!empty($PrimaryKeys)) {
                $StoryRowOrderCols = array();
                foreach ($PrimaryKeys as $Pk) {
                    $StoryRowOrderCols[] = 'sr.' . QuoteIdentifier($Pk);
                }
                $StoryRowOrderBy = ' ORDER BY ' . implode(', ', $StoryRowOrderCols);
            }
            $Sql = 'SELECT ' . implode(', ', $StoryRowSelectColumns)
                . ' FROM "storyrows" sr'
                . ' INNER JOIN "storyinfo" si ON si."storyid" = sr."storyid"'
                . ' WHERE si."workid" = :workId'
                . $StoryRowOrderBy;
            $Stmt = $Pdo->prepare($Sql);
            $Stmt->execute(array(':workId' => $WorkId));
            $Rows = $Stmt->fetchAll(PDO::FETCH_ASSOC);
        } elseif ($TableName === 'plotrows') {
            $PlotRowSelectColumns = array();
            foreach ($Columns as $Col) {
                $PlotRowSelectColumns[] = 'pr.' . QuoteIdentifier($Col) . ' AS ' . QuoteIdentifier($Col);
            }
            $PlotRowOrderBy = '';
            if (!empty($PrimaryKeys)) {
                $PlotRowOrderCols = array();
                foreach ($PrimaryKeys as $Pk) {
                    $PlotRowOrderCols[] = 'pr.' . QuoteIdentifier($Pk);
                }
                $PlotRowOrderBy = ' ORDER BY ' . implode(', ', $PlotRowOrderCols);
            }
            $Sql = 'SELECT ' . implode(', ', $PlotRowSelectColumns)
                . ' FROM "plotrows" pr'
                . ' INNER JOIN "plotinfo" pi ON pi."plotid" = pr."plotid"'
                . ' WHERE pi."workid" = :workId'
                . $PlotRowOrderBy;
            $Stmt = $Pdo->prepare($Sql);
            $Stmt->execute(array(':workId' => $WorkId));
            $Rows = $Stmt->fetchAll(PDO::FETCH_ASSOC);
        } elseif ($TableName === 'exchangerows') {
            /* 掛け合い行はworkid列を持たないため、親の掛け合いを経由して作品で絞り込む
             * （ストーリー行・プロット行と同じ扱い） */
            $ExchangeRowSelectColumns = array();
            foreach ($Columns as $Col) {
                $ExchangeRowSelectColumns[] = 'er.' . QuoteIdentifier($Col) . ' AS ' . QuoteIdentifier($Col);
            }
            $ExchangeRowOrderBy = '';
            if (!empty($PrimaryKeys)) {
                $ExchangeRowOrderCols = array();
                foreach ($PrimaryKeys as $Pk) {
                    $ExchangeRowOrderCols[] = 'er.' . QuoteIdentifier($Pk);
                }
                $ExchangeRowOrderBy = ' ORDER BY ' . implode(', ', $ExchangeRowOrderCols);
            }
            $Sql = 'SELECT ' . implode(', ', $ExchangeRowSelectColumns)
                . ' FROM "exchangerows" er'
                . ' INNER JOIN "exchangeinfo" ei ON ei."exchangeid" = er."exchangeid"'
                . ' WHERE ei."workid" = :workId'
                . $ExchangeRowOrderBy;
            $Stmt = $Pdo->prepare($Sql);
            $Stmt->execute(array(':workId' => $WorkId));
            $Rows = $Stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            continue;
        }
        fputcsv($Stream, array('#TABLE', $TableName));
        fputcsv($Stream, $Columns);

        foreach ($Rows as $Row) {
            $CsvRow = array();
            foreach ($Columns as $ColumnName) {
                $CsvRow[] = EncodeCsvValue(isset($Row[$ColumnName]) ? $Row[$ColumnName] : null);
            }
            fputcsv($Stream, $CsvRow);
        }
    }

    rewind($Stream);
    $CsvContent = stream_get_contents($Stream);
    fclose($Stream);

    if ($CsvContent === false) {
        throw new Exception('CSV生成に失敗しました');
    }

    $WorkTitle = isset($Work['worktitle']) ? $Work['worktitle'] : 'Work';
    // 出力毎にファイル名を一意化して既存バックアップの上書きを防止する。
    // 秒までの日時に加えミリ秒を付与し、同一秒での連続出力でも衝突しないようにする。
    $Now = microtime(true);
    $DatePart = date('Ymd_His', (int)$Now) . sprintf('_%03d', (int)(($Now - floor($Now)) * 1000));
    $FileName = SanitizeFileName($WorkTitle . '_Backup_' . $DatePart . '.csv');

    Logger::Info($FunctionName, 'バックアップ出力処理終了', $UserId);

    Response::Success(array(
        'FileData' => base64_encode($CsvContent),
        'FileName' => $FileName,
        'MimeType' => 'text/csv;charset=utf-8'
    ));
} catch (Exception $E) {
    Logger::Error($FunctionName, 'バックアップ出力エラー', $UserId, $E->getMessage());
    Response::Error('バックアップ出力に失敗しました', 500);
}

