<?php
/* ==========================================================
 * 作品バックアップ復元処理
 * ----------------------------------------------------------
 * ・バックアップCSVから作品情報の復元を行うAPI
 * ・基本的には作成者しか利用を行わないため、排他の制御は不要
 * ----------------------------------------------------------
 * 更新履歴：
 * 2026-01-10 作成
 * ========================================================== */

/* ==========================================================
 * 1. 共通モジュール読み込み
 * ========================================================== */
// 1. データベース接続クラス
require_once __DIR__ . '/../Common/Database.php';
// 2. ログ出力クラス
require_once __DIR__ . '/../Common/Logger.php';
// 3. レスポンスクラス
require_once __DIR__ . '/../Common/Response.php';
// 4. ユーザー認証
require_once __DIR__ . '/../Common/Auth.php';

/* ==========================================================
 * 2. リクエストチェック
 * ========================================================== */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::Error('不正なリクエストです', 405);
}

/* ==========================================================
 * 3. グローバル定義
 * ========================================================== */
// 1. 機能名
$FunctionName = '作品バックアップ復元';
// 2. リクエストボディ
$Input = Response::GetJsonInput();
// 3. 作品ID
$WorkId = $Input['WorkId'] ?? '';
// 4. ユーザーID
$UserId = $AuthUserId;
// 5. CSVファイル情報
$FileData = $Input['FileData'] ?? '';
// 6. CSVファイル名
$FileName = $Input['FileName'] ?? '';

/* ==========================================================
 * 4. 定数
 * ========================================================== */
// 1. NULLチェック用
define('RESTORE_NULL_TOKEN', '__NULL__');
// 2. 文字列としてのNULLチェック用
define('RESTORE_ESCAPED_NULL_TOKEN', '\\__NULL__');

/* ==========================================================
 * 5. ヘルパー関数群
 * ========================================================== */
/**
 * ファイル名の拡張子がCSVであるかを判定する関数
 *  - 画面側（Main.js の HandleBackupRestore）と同じ判定をAPI側でも行う
 *  - リクエストは改ざん可能なため、画面側のチェックのみに依存しない
 */
function IsCsvFileName($FileName)
{
    /* ------------------------------
     * 1. 型・空判定
     * ------------------------------*/
    if (!is_string($FileName)) {
        return false;
    }

    $Trimmed = trim($FileName);
    if ($Trimmed === '') {
        return false;
    }

    /* ------------------------------
     * 2. パス区切りを含む場合は不正とする
     * ------------------------------*/
    if (strpbrk($Trimmed, "/\\") !== false) {
        return false;
    }

    /* ------------------------------
     * 3. 拡張子の判定（大文字小文字は区別しない）
     * ------------------------------*/
    $Extension = strtolower((string)pathinfo($Trimmed, PATHINFO_EXTENSION));

    /* ------------------------------
     * 4. 戻り値の設定
     * ------------------------------*/
    return $Extension === 'csv';
}

/**
 * テーブル名・カラム名を安全にクォートする関数(SQLインジェクション対策)
 */
function QuoteIdentifier($Name)
{
    // 1. 内部の " を "" に変換し、全体を " で囲んで返す
    return '"' . str_replace('"', '""', $Name) . '"';
}

/**
 * バックアップ署名用の秘密鍵を取得する関数(バックアップ改ざん検知用)
 *  - 環境変数BACKUP_SIGN_KEYが設定されていればそれを使用(基本的にはこっちを使う)
 *  - 未設定の場合はDB接続情報からハッシュを生成して代用(フォールバック)
 *  - 署名(HMAC)の鍵として使用される
 */
function GetBackupSignKey()
{
    /* ------------------------------
     * 1. 定義
     * ------------------------------*/
    // 1. .env から専用キーを取得（putenv 未使用のため getenv では読めず、Env::Get で実値を読む）
    $EnvKey = Env::Get('BACKUP_SIGN_KEY');

    /* ------------------------------
     * 2. 専用キーが存在する場合
     * ------------------------------*/
    if (is_string($EnvKey) && trim($EnvKey) !== '') {
        // 1. そのキーを使用する
        return trim($EnvKey);
    }

    /* ------------------------------
     * 3. フォールバック用定義
     * ------------------------------*/
    // 1. DBホスト
    $DbHost = Env::Get('DB_HOST');
    // 2. DB名
    $DbName = Env::Get('DB_NAME');
    // 3. ユーザー
    $DbUser = Env::Get('DB_USER');
    // 4. パスワード
    $DbPass = Env::Get('DB_PASS');

    /* ------------------------------
     * 4. 疑似的なキーを作成して返す
     * ------------------------------*/
    return hash('sha256',
        (string)$DbHost . '|' .
        (string)$DbName . '|' .
        (string)$DbUser . '|' .
        (string)$DbPass
    );
}

/**
 * バックアップ署名を生成する関数
 *  - 指定されたデータを元にHMAC-SHA256で署名を生成
 *  - 秘密鍵はGetBackupSignKey()から取得
 *  - 復元時に同じ計算を行い、改ざん検知に使用する
 */
function BuildBackupSignature($Payload)
{
    // 1. HMAC-SHA256で署名生成
    return hash_hmac('sha256', $Payload, GetBackupSignKey());
}

/**
 * 指定テーブルの主キーカラム一覧を取得する関数
 */
function GetPrimaryKeys($Pdo, $TableName)
{
    /* ------------------------------
     * 1. クエリ定義
     * ------------------------------*/
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

    /* ------------------------------
     * 2. クエリ実行
     * ------------------------------*/
    $Stmt->execute(array(
        ':schema' => 'public',
        ':tableName' => strtolower($TableName)
    ));

    /* ------------------------------
     * 3. 結果セットの取得
     * ------------------------------*/
    // 1. 全行を取得
    $Rows = $Stmt->fetchAll();
    // 2. 結果受け取り用の配列を定義
    $Keys = array();

    /* ------------------------------
     * 4. 結果の詰め替え
     * ------------------------------*/
    foreach ($Rows as $Row) {
        // 1. 取得した行を配列に変換
        $Keys[] = $Row['column_name'];
    }

    /* ------------------------------
     * 5. 戻り値の設定
     * ------------------------------*/
    return $Keys;
}

/**
 * 指定テーブルのカラム情報（カラム名とデータ型）を取得する関数
 */
function GetTableColumnMeta($Pdo, $TableName)
{
    /* ------------------------------
     * 1. クエリ定義
     * ------------------------------*/
    $Stmt = $Pdo->prepare(
        'SELECT column_name, data_type
         FROM information_schema.columns
         WHERE table_schema = :schema
           AND table_name = :tableName
         ORDER BY ordinal_position'
    );

    /* ------------------------------
     * 2. クエリ実行
     * ------------------------------*/
    $Stmt->execute(array(
        ':schema' => 'public',
        ':tableName' => strtolower($TableName)
    ));

    /* ------------------------------
     * 3. 結果セットの取得
     * ------------------------------*/
    // 1. 全行取得
    $Rows = $Stmt->fetchAll();
    // 2. 結果受け取り用の配列を定義
    $Meta = array();

    /* ------------------------------
     * 4. 結果の詰め替え
     * ------------------------------*/
    foreach ($Rows as $Row) {
        // 1. カラム名をキー、データ型を値として格納
        $Meta[$Row['column_name']] = $Row['data_type'];
    }

    /* ------------------------------
     * 5. 戻り値の設定
     * ------------------------------*/
    return $Meta;
}

function DecodeCsvValue($Value)
{
    if ($Value === null) {
        return null;
    }
    if ($Value === RESTORE_NULL_TOKEN) {
        return null;
    }
    if ($Value === RESTORE_ESCAPED_NULL_TOKEN) {
        return RESTORE_NULL_TOKEN;
    }
    return $Value;
}

function NormalizeValue($Value, $DataType)
{
    if ($Value === null) {
        return null;
    }

    $Trimmed = is_string($Value) ? trim($Value) : $Value;
    if ($Trimmed === '') {
        return null;
    }

    switch ($DataType) {
        case 'boolean':
            if (is_bool($Trimmed)) {
                return $Trimmed;
            }
            $Lower = strtolower((string)$Trimmed);
            if (in_array($Lower, array('1', 'true', 't', 'yes'), true)) {
                return true;
            }
            if (in_array($Lower, array('0', 'false', 'f', 'no'), true)) {
                return false;
            }
            return null;

        case 'smallint':
        case 'integer':
        case 'bigint':
            // 数値として解釈できない値（型を数値へ変更する前のバックアップの「約100人」等）は、
            // (int) で 0 に変換されて誤った値で復元されるのを防ぐため NULL とする
            if (!is_numeric($Trimmed)) {
                return null;
            }
            return (int)$Trimmed;

        case 'real':
        case 'double precision':
        case 'numeric':
            return (float)$Trimmed;

        default:
            return $Trimmed;
    }
}

/**
 * workId 列を持たない子テーブルと、所属の判定に用いる親テーブルの対応を返す関数
 *  - 子テーブルの行は、親（ストーリー・プロット・掛け合い）が復元対象の作品に属する場合のみ復元する
 *  - 親テーブルは $TargetTables で子テーブルより先に復元されるため、CSV内の親も判定対象となる
 */
function GetRestoreParentMap()
{
    return array(
        'storyrows'    => array('column' => 'storyid',    'parent' => 'storyinfo'),
        'plotrows'     => array('column' => 'plotid',     'parent' => 'plotinfo'),
        'exchangerows' => array('column' => 'exchangeid', 'parent' => 'exchangeinfo'),
    );
}

/**
 * 親テーブルの行が指定作品に属するかを判定する関数（子テーブルの復元用）
 */
function IsRestoreParentOwned($Pdo, $ParentTable, $ParentColumn, $ParentId, $WorkId)
{
    if ($ParentId === null) {
        return false;
    }
    $Stmt = $Pdo->prepare(
        'SELECT 1 FROM ' . QuoteIdentifier($ParentTable)
        . ' WHERE ' . QuoteIdentifier($ParentColumn) . ' = :parentId AND workid = :workId'
    );
    $Stmt->bindValue(':parentId', $ParentId, is_int($ParentId) ? PDO::PARAM_INT : PDO::PARAM_STR);
    $Stmt->bindValue(':workId', $WorkId, PDO::PARAM_STR);
    $Stmt->execute();
    $Owned = $Stmt->fetch() !== false;
    $Stmt->closeCursor();
    return $Owned;
}

/**
 * 画像パス（imgPath / imgList）を保持する8テーブルを返す関数
 */
function GetRestoreImageTables()
{
    return array(
        'characterinfo', 'organizationinfo', 'teaminfo', 'stageinfo',
        'buildinfo', 'weaponinfo', 'iteminfo', 'workmemoinfo'
    );
}

/**
 * 復元する画像パスを検証する関数
 *  - 画像パスは、データ削除時・画像の差し替え時に ImageList::DeleteFiles で物理削除される。
 *    CSVは改ざんし得るため、任意のパスを復元できると、他作品の画像など Uploads 配下の任意のファイルを
 *    後から削除させることができてしまう。
 *  - そのため、画像の保存先の形式（Uploads/<種別>/<ファイル名>.<画像拡張子>）であること、
 *    かつ他作品が保持している画像でないことを確認する。
 * @return bool 復元してよい場合 true
 */
function IsRestorableImagePath($Pdo, $WorkId, $Path)
{
    if (!is_string($Path)
        || preg_match('#\A/Uploads/(Characters|Organizations|Teams|Stages|Buildings|Weapons|Items|WorkMemos)/[A-Za-z0-9_-]+\.(png|jpe?g|gif|webp)\z#', $Path) !== 1) {
        return false;
    }

    // imgList はJSON文字列のため、パスをJSONの文字列表現（引用符付き）で部分一致させる。
    // LIKE の特殊文字（\ % _）はエスケープする（PostgreSQL の LIKE の既定のエスケープ文字は \）
    $JsonPath = json_encode($Path, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $LikePath = '%' . str_replace(array('\\', '%', '_'), array('\\\\', '\\%', '\\_'), $JsonPath) . '%';

    // 列情報は画像ごとに参照するため、テーブル単位で保持して再取得を避ける
    static $MetaCache = array();
    foreach (GetRestoreImageTables() as $Table) {
        if (!isset($MetaCache[$Table])) {
            $MetaCache[$Table] = GetTableColumnMeta($Pdo, $Table);
        }
        $TableMeta = $MetaCache[$Table];
        if (!isset($TableMeta['imgpath'])) {
            continue;
        }
        $Condition = isset($TableMeta['imglist'])
            ? '(imgpath = :path OR imglist LIKE :likePath)'
            : 'imgpath = :path';
        $Stmt = $Pdo->prepare(
            'SELECT 1 FROM ' . QuoteIdentifier($Table)
            . ' WHERE workid <> :workId AND ' . $Condition . ' LIMIT 1'
        );
        $Stmt->bindValue(':workId', $WorkId, PDO::PARAM_STR);
        $Stmt->bindValue(':path', $Path, PDO::PARAM_STR);
        if (isset($TableMeta['imglist'])) {
            $Stmt->bindValue(':likePath', $LikePath, PDO::PARAM_STR);
        }
        $Stmt->execute();
        $UsedByOtherWork = $Stmt->fetch() !== false;
        $Stmt->closeCursor();
        if ($UsedByOtherWork) {
            return false;
        }
    }
    return true;
}

/**
 * 復元する行の画像パス（imgpath / imglist）を検証する関数
 * @return bool 復元してよい場合 true
 */
function IsRestorableImageColumns($Pdo, $WorkId, array $Params)
{
    $ImgPath = $Params[':imgpath'] ?? null;
    if ($ImgPath !== null && !IsRestorableImagePath($Pdo, $WorkId, $ImgPath)) {
        return false;
    }

    $ImgList = $Params[':imglist'] ?? null;
    if ($ImgList !== null) {
        $Decoded = json_decode((string)$ImgList, true);
        if (!is_array($Decoded) || !isset($Decoded['Images']) || !is_array($Decoded['Images'])) {
            return false;
        }
        foreach ($Decoded['Images'] as $Image) {
            if (!is_array($Image) || !IsRestorableImagePath($Pdo, $WorkId, $Image['Path'] ?? null)) {
                return false;
            }
        }
    }
    return true;
}

/* ==========================================================
 * 6. 復元処理
 * ========================================================== */
try {
    if ($WorkId === '') {
        Response::Error('作品IDが指定されていません');
    }
    if ($UserId === '') {
        Response::Error('ユーザーIDが指定されていません');
    }
    if ($FileData === '') {
        Response::Error('復元対象のCSVデータが指定されていません');
    }
    // 画面側の拡張子チェックは改ざん可能なため、API側でも同じ判定を行う
    if (!IsCsvFileName($FileName)) {
        Response::Error('CSV以外のファイルは選択できません');
    }

    Logger::Info($FunctionName, '復元処理開始', $UserId);

    $Pdo = Database::GetConnection();

    $CheckStmt = $Pdo->prepare(
        'SELECT 1
         FROM WorkInfo
         WHERE workId = :workId
           AND registUser = :userId'
    );
    $CheckStmt->execute(array(
        ':workId' => $WorkId,
        ':userId' => $UserId
    ));
    if (!$CheckStmt->fetch()) {
        Response::Error('復元処理の権限がありません', 403);
    }

    if (strpos($FileData, 'base64,') !== false) {
        $Parts = explode('base64,', $FileData, 2);
        $FileData = isset($Parts[1]) ? $Parts[1] : '';
    }

    $Binary = base64_decode($FileData, true);
    if ($Binary === false) {
        Response::Error('CSVデータのデコードに失敗しました');
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

    $Parsed = array();
    $CurrentTable = null;
    $ExpectHeader = false;
    $CsvWorkId = null;
    $CsvCreatedAt = null;
    $CsvSignature = null;

    $Stream = fopen('php://temp', 'w+');
    fwrite($Stream, $Binary);
    rewind($Stream);

    while (($Row = fgetcsv($Stream)) !== false) {
        if ($Row === null || (count($Row) === 1 && trim((string)$Row[0]) === '')) {
            continue;
        }

        $First = trim((string)$Row[0]);

        if ($First === '#META') {
            if (count($Row) >= 3 && isset($Row[1])) {
                $MetaKey = trim((string)$Row[1]);
                $MetaVal = trim((string)$Row[2]);
                if ($MetaKey === 'WorkId') {
                    $CsvWorkId = $MetaVal;
                } elseif ($MetaKey === 'CreatedAt') {
                    $CsvCreatedAt = $MetaVal;
                } elseif ($MetaKey === 'Signature') {
                    $CsvSignature = $MetaVal;
                }
            }
            continue;
        }

        if ($First === '#TABLE') {
            $TableName = strtolower(trim((string)(isset($Row[1]) ? $Row[1] : '')));
            if (!in_array($TableName, $TargetTables, true)) {
                throw new Exception('バックアップCSVのテーブル情報が不正です');
            }

            if (!isset($Parsed[$TableName])) {
                $Parsed[$TableName] = array(
                    'columns' => array(),
                    'rows' => array()
                );
            }

            $CurrentTable = $TableName;
            $ExpectHeader = true;
            continue;
        }

        if ($ExpectHeader) {
            if ($CurrentTable === null) {
                throw new Exception('バックアップCSVの形式が不正です');
            }
            $HeaderCols = array();
            foreach ($Row as $Column) {
                $HeaderCols[] = strtolower(trim((string)$Column));
            }
            $Parsed[$CurrentTable]['columns'] = $HeaderCols;
            $ExpectHeader = false;
            continue;
        }

        if ($CurrentTable === null) {
            continue;
        }

        $Parsed[$CurrentTable]['rows'][] = $Row;
    }

    fclose($Stream);

    // #META必須化
    if ($CsvWorkId === null || $CsvWorkId === '') {
        Response::Error('バックアップCSVにWorkIdメタ情報がありません');
    }
    if ($CsvCreatedAt === null || $CsvCreatedAt === '') {
        Response::Error('バックアップCSVにCreatedAtメタ情報がありません');
    }
    if ($CsvSignature === null || $CsvSignature === '') {
        Response::Error('バックアップCSVに署名情報がありません');
    }

    if ($CsvWorkId !== $WorkId) {
        Response::Error('選択したCSVは対象作品のバックアップではありません');
    }

    $ExpectedSignature = BuildBackupSignature($CsvWorkId . '|' . $CsvCreatedAt);
    if (!hash_equals($ExpectedSignature, $CsvSignature)) {
        Response::Error('バックアップCSVの署名検証に失敗しました');
    }

    Database::BeginTransaction();

    foreach ($TargetTables as $TableName) {
        if (!isset($Parsed[$TableName])) {
            continue;
        }

        $Columns = $Parsed[$TableName]['columns'];
        $Rows = $Parsed[$TableName]['rows'];
        if (empty($Columns)) {
            continue;
        }

        $Meta = GetTableColumnMeta($Pdo, $TableName);
        if (empty($Meta)) {
            continue;
        }

        $ColumnMappings = array();
        foreach ($Columns as $Index => $ColumnName) {
            if (isset($Meta[$ColumnName])) {
                $ColumnMappings[] = array(
                    'index' => $Index,
                    'name' => $ColumnName
                );
            }
        }

        if (empty($ColumnMappings)) {
            continue;
        }

        $PrimaryKeys = GetPrimaryKeys($Pdo, $TableName);
        if (empty($PrimaryKeys)) {
            throw new Exception('主キー情報の取得に失敗しました: ' . $TableName);
        }

        /* 作品の所属の判定方法を決める
         *  - workid 列を持つテーブル：行の workid（復元対象の作品IDへ置き換え済み）
         *  - workid 列を持たない子テーブル：親テーブルの workid
         *  主キーはCSVの値をそのまま使うため、他作品の行の主キーを指定されると
         *  ON CONFLICT の更新で他作品の行を上書き・自作品へ付け替えできてしまう。これを防ぐ */
        $HasWorkIdColumn = isset($Meta['workid']);
        $ParentMap = GetRestoreParentMap();
        $ParentInfo = $ParentMap[$TableName] ?? null;
        if (!$HasWorkIdColumn && $ParentInfo === null) {
            throw new Exception('作品の所属を判定できないテーブルです: ' . $TableName);
        }
        if ($ParentInfo !== null && !in_array($ParentInfo['column'], $Columns, true)) {
            throw new Exception('親テーブルの列がありません: ' . $TableName);
        }
        $HasImageColumns = isset($Meta['imgpath']) || isset($Meta['imglist']);

        $InsertColumns = array();
        foreach ($ColumnMappings as $MapItem) {
            $InsertColumns[] = $MapItem['name'];
        }

        $InsertColQuoted = array();
        foreach ($InsertColumns as $Col) {
            $InsertColQuoted[] = QuoteIdentifier($Col);
        }
        $InsertColumnSql = implode(', ', $InsertColQuoted);

        $Placeholders = array();
        foreach ($InsertColumns as $Col) {
            $Placeholders[] = ':' . $Col;
        }
        $PlaceholderSql = implode(', ', $Placeholders);

        $ConflictCols = array();
        foreach ($PrimaryKeys as $Pk) {
            $ConflictCols[] = QuoteIdentifier($Pk);
        }
        $ConflictColumns = implode(', ', $ConflictCols);

        $UpdateTargets = array();
        foreach ($InsertColumns as $Col) {
            if (!in_array($Col, $PrimaryKeys, true)) {
                $UpdateTargets[] = $Col;
            }
        }

        if (empty($UpdateTargets)) {
            $UpdateSql = 'DO NOTHING';
        } else {
            $UpdateExpr = array();
            foreach ($UpdateTargets as $Col) {
                $UpdateExpr[] = QuoteIdentifier($Col) . ' = EXCLUDED.' . QuoteIdentifier($Col);
            }
            // 既存行（衝突した行）が復元対象の作品に属する場合のみ更新する。
            // 他作品の行と衝突した場合は更新されず、更新件数が0件となるため、後続で改ざんとして中止する
            if ($HasWorkIdColumn) {
                $OwnerCondition = QuoteIdentifier($TableName) . '.workid = EXCLUDED.workid';
            } else {
                $OwnerCondition = 'EXISTS (SELECT 1 FROM ' . QuoteIdentifier($ParentInfo['parent']) . ' p'
                    . ' WHERE p.' . QuoteIdentifier($ParentInfo['column'])
                    . ' = ' . QuoteIdentifier($TableName) . '.' . QuoteIdentifier($ParentInfo['column'])
                    . ' AND p.workid = :restore_owner_workid)';
            }
            $UpdateSql = 'DO UPDATE SET ' . implode(', ', $UpdateExpr) . ' WHERE ' . $OwnerCondition;
        }
        $IsUpsert = $UpdateSql !== 'DO NOTHING';

        $Sql = 'INSERT INTO ' . QuoteIdentifier($TableName)
            . ' (' . $InsertColumnSql . ')'
            . ' VALUES (' . $PlaceholderSql . ')'
            . ' ON CONFLICT (' . $ConflictColumns . ') '
            . $UpdateSql;

        $Stmt = $Pdo->prepare($Sql);

        foreach ($Rows as $Row) {
            $Params = array();
            foreach ($ColumnMappings as $Map) {
                $Index = $Map['index'];
                $ColumnName = $Map['name'];
                $Raw = array_key_exists($Index, $Row) ? $Row[$Index] : null;
                $Decoded = DecodeCsvValue($Raw);

                if ($ColumnName === 'workid') {
                    // 行ごとのworkid一致検証（CSV改ざん対策）
                    if ($Decoded !== null && trim((string)$Decoded) !== '' && trim((string)$Decoded) !== $CsvWorkId) {
                        throw new Exception('バックアップCSVのworkidが不正です: ' . $TableName);
                    }
                    $Normalized = $WorkId;
                } else {
                    $DataType = $Meta[$ColumnName];
                    $Normalized = NormalizeValue($Decoded, $DataType);
                }

                $Params[':' . $ColumnName] = $Normalized;
            }

            // どの型でも空文字はNULLに統一
            foreach ($Params as $ParamKey => $ParamValue) {
                if (is_string($ParamValue) && trim($ParamValue) === '') {
                    $Params[$ParamKey] = null;
                }
            }

            // 子テーブルの行は、親が復元対象の作品に属する場合のみ復元する（他作品の親への付け替えを防ぐ）
            if ($ParentInfo !== null) {
                $ParentId = $Params[':' . $ParentInfo['column']] ?? null;
                if (!IsRestoreParentOwned($Pdo, $ParentInfo['parent'], $ParentInfo['column'], $ParentId, $WorkId)) {
                    throw new Exception('バックアップCSVの親データが対象作品に属していません: ' . $TableName);
                }
                if ($IsUpsert) {
                    $Params[':restore_owner_workid'] = $WorkId;
                }
            }

            // 画像パスは、他作品の画像など任意のファイルを後から削除させる経路となるため検証する
            if ($HasImageColumns && !IsRestorableImageColumns($Pdo, $WorkId, $Params)) {
                throw new Exception('バックアップCSVの画像パスが不正です: ' . $TableName);
            }

            foreach ($Params as $ParamKey => $ParamValue) {
                if ($ParamValue === null) {
                    $Stmt->bindValue($ParamKey, null, PDO::PARAM_NULL);
                } elseif (is_bool($ParamValue)) {
                    $Stmt->bindValue($ParamKey, $ParamValue, PDO::PARAM_BOOL);
                } elseif (is_int($ParamValue)) {
                    $Stmt->bindValue($ParamKey, $ParamValue, PDO::PARAM_INT);
                } else {
                    $Stmt->bindValue($ParamKey, (string)$ParamValue, PDO::PARAM_STR);
                }
            }

            $Stmt->execute();
            // 登録・更新のいずれも行われなかった場合は、他作品の行と主キーが衝突している（CSVの改ざん）
            if ($IsUpsert && $Stmt->rowCount() === 0) {
                throw new Exception('バックアップCSVに他作品のデータが含まれています: ' . $TableName);
            }
            $Stmt->closeCursor();
        }
    }

    Database::Commit();

    Logger::Info($FunctionName, '復元処理終了', $UserId);
    Response::Success(null, 'バックアップの復元が完了しました');

/* ==========================================================
 * 7. 例外処理
 * ========================================================== */
} catch (Exception $E) {
    // 1. トランザクションをロールバック
    Database::Rollback();
    // 2. エラーLOG書き込み（詳細はログのみに記録し、クライアントには内部情報を返さない）
    Logger::Error($FunctionName, '復元処理エラー', $UserId, $E->getMessage());
    // 3. レスポンス返却（スキーマ等の内部情報漏洩を防ぐため固定文言）
    Response::Error('バックアップの復元に失敗しました', 500);
}
