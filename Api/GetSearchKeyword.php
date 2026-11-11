<?php
/**
 * GetSearchKeyword.php
 * キーワード横断検索API
 *
 * 入力(POST JSON):
 *   - WorkId: 作品ID (必須)
 *   - Keyword: 検索キーワード (必須、空白不可)
 *   - MenuIds: 検索対象画面IDの配列 (必須、1件以上)
 *
 * 検索対象列の定義は Back/Config/Screens.json を参照。
 * 列名・テーブル名は Screens.json で許可されたもの以外は採用しないため
 * SQLインジェクション耐性を保つ。
 */

require_once __DIR__ . '/../Common/Database.php';
require_once __DIR__ . '/../Common/Logger.php';
require_once __DIR__ . '/../Common/Response.php';
require_once __DIR__ . '/../Common/Auth.php';
require_once __DIR__ . '/../Common/SearchScreens.php';
require_once __DIR__ . '/../Common/EntryTable.php';

// POSTメソッドのみ許可
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::Error('不正なリクエストです', 405);
}

$FunctionName = 'キーワード検索';
$UserId = '';

try {
    $Input = Response::GetJsonInput();
    $WorkId = $AuthWorkId ?? '';
    $Keyword = isset($Input['Keyword']) ? trim((string)$Input['Keyword']) : '';
    $MenuIds = $Input['MenuIds'] ?? [];
    $UserId = $AuthUserId;

    Logger::Info($FunctionName, '検索処理開始', $UserId);

    if (empty($WorkId)) {
        Response::Error('作品IDが指定されていません');
    }
    if ($Keyword === '') {
        Response::Error('キーワードを入力してください');
    }
    if (empty($MenuIds) || !is_array($MenuIds)) {
        Response::Error('検索対象画面を1つ以上選択してください');
    }

    // 検索・置換で共有する、バックエンド内の許可定義を使用する。
    $Config = SearchScreens::Load();
    $TargetScreens = SearchScreens::Select($Config['Screens'], $MenuIds);
    if (empty($TargetScreens)) {
        Response::Error('指定された画面が画面定義に存在しません');
    }

    // ILIKE 用ワイルドカードのエスケープ
    // ESCAPE 文字には '/' を採用（SQL リテラルでの再エスケープ不要のため）
    // 順序重要: '/' を先に置換しないと、'/%' などのエスケープ後文字列がさらに変換されてしまう
    $EscapedKeyword = str_replace(['/', '%', '_'], ['//', '/%', '/_'], $Keyword);
    $LikePattern = '%' . $EscapedKeyword . '%';

    $Pdo = Database::GetConnection();
    $Results = [];

    foreach ($TargetScreens as $Screen) {
        $MenuId = (string)$Screen['MenuId'];
        $ScreenName = (string)($Screen['ScreenName'] ?? '');
        $Tables = isset($Screen['Tables']) && is_array($Screen['Tables']) ? $Screen['Tables'] : [];

        foreach ($Tables as $Table) {
            $TableName = isset($Table['TableName']) ? (string)$Table['TableName'] : '';
            $RowIdColumn = isset($Table['RowIdColumn']) ? (string)$Table['RowIdColumn'] : '';
            $WorkIdColumn = isset($Table['WorkIdColumn']) ? (string)$Table['WorkIdColumn'] : '';
            $ParentTable = isset($Table['ParentTable']) ? (string)$Table['ParentTable'] : '';
            $ParentJoinColumn = isset($Table['ParentJoinColumn']) ? (string)$Table['ParentJoinColumn'] : '';
            $SearchableColumns = isset($Table['SearchableColumns']) && is_array($Table['SearchableColumns'])
                ? $Table['SearchableColumns']
                : [];

            // テーブル名・列名は識別子のためホワイトリスト的に検証
            if (!SearchScreens::IsValidIdentifier($TableName) || !SearchScreens::IsValidIdentifier($RowIdColumn)) {
                continue;
            }
            if ($WorkIdColumn !== '' && !SearchScreens::IsValidIdentifier($WorkIdColumn)) {
                continue;
            }
            if ($ParentTable !== '' && !SearchScreens::IsValidIdentifier($ParentTable)) {
                continue;
            }
            if ($ParentJoinColumn !== '' && !SearchScreens::IsValidIdentifier($ParentJoinColumn)) {
                continue;
            }
            if (empty($SearchableColumns)) {
                continue;
            }

            // 列名検証 + ILIKE 条件構築
            $ColumnWhere = [];
            $ValidColumns = [];
            foreach ($SearchableColumns as $Col) {
                $ColName = isset($Col['Column']) ? (string)$Col['Column'] : '';
                $ColLabel = isset($Col['Label']) ? (string)$Col['Label'] : $ColName;
                if (!SearchScreens::IsValidIdentifier($ColName)) {
                    continue;
                }
                // 表形式項目（JSON保持）の列は EntryTable の定義名を保持する
                $EntryEntity = isset($Col['EntryEntity']) ? (string)$Col['EntryEntity'] : '';
                $EntryKey = isset($Col['EntryKey']) ? (string)$Col['EntryKey'] : '';
                $ValidColumns[] = [
                    'Column' => $ColName,
                    'Label' => $ColLabel,
                    'EntryEntity' => $EntryEntity,
                    'EntryKey' => $EntryKey,
                ];
                $ColumnWhere[] = "t.$ColName ILIKE :keyword ESCAPE '/'";
            }
            if (empty($ColumnWhere)) {
                continue;
            }

            // SELECT 列リスト（エイリアスは小文字で統一: PDO::FETCH_ASSOC で
            // PostgreSQL の引用なし識別子は小文字になるため）
            $SelectCols = [];
            $SelectCols[] = "t.$RowIdColumn AS rowid";
            foreach ($ValidColumns as $Vc) {
                $SelectCols[] = "t.{$Vc['Column']} AS col_{$Vc['Column']}";
            }

            // 親テーブル経由の WorkId フィルタ
            if ($ParentTable !== '' && $ParentJoinColumn !== '') {
                $ParentScreenDef = SearchScreens::FindParentTableDef($Config['Screens'], $ParentTable);
                $ParentWorkIdColumn = $ParentScreenDef['WorkIdColumn'] ?? 'workid';
                if (!SearchScreens::IsValidIdentifier($ParentWorkIdColumn)) {
                    $ParentWorkIdColumn = 'workid';
                }
                $SelectCols[] = "t.$ParentJoinColumn AS parentid";
                $Sql = "SELECT " . implode(', ', $SelectCols)
                     . " FROM $TableName t"
                     . " INNER JOIN $ParentTable p ON p.$ParentJoinColumn = t.$ParentJoinColumn"
                     . " WHERE p.$ParentWorkIdColumn = :workid"
                     . " AND (" . implode(' OR ', $ColumnWhere) . ")";
            } else {
                if ($WorkIdColumn === '') {
                    continue;
                }
                $Sql = "SELECT " . implode(', ', $SelectCols)
                     . " FROM $TableName t"
                     . " WHERE t.$WorkIdColumn = :workid"
                     . " AND (" . implode(' OR ', $ColumnWhere) . ")";
            }

            $Stmt = $Pdo->prepare($Sql);
            $Stmt->execute([
                ':workid' => $WorkId,
                ':keyword' => $LikePattern,
            ]);
            $Rows = $Stmt->fetchAll();

            foreach ($Rows as $Row) {
                $RowIdValue = $Row['rowid'] ?? null;
                $ParentIdValue = array_key_exists('parentid', $Row) ? $Row['parentid'] : null;
                foreach ($ValidColumns as $Vc) {
                    $ColKey = 'col_' . strtolower($Vc['Column']);
                    if (!array_key_exists($ColKey, $Row) || $Row[$ColKey] === null) {
                        continue;
                    }
                    $CellValue = (string)$Row[$ColKey];
                    // 表形式項目（JSON保持）は表示用の文字列へ整形する
                    // （JSONのキー名のみに一致した行は整形後の再確認で除外される）
                    if ($Vc['EntryEntity'] !== '' && $Vc['EntryKey'] !== '') {
                        $CellValue = implode("\n", EntryTable::FormatLines($Vc['EntryEntity'], $Vc['EntryKey'], $CellValue));
                    }
                    // 大文字小文字を無視した部分一致を再確認
                    if ($CellValue === '' || stripos($CellValue, $Keyword) === false) {
                        continue;
                    }
                    $Results[] = [
                        'MenuId' => $MenuId,
                        'ScreenName' => $ScreenName,
                        'TableName' => $TableName,
                        'RowIdColumn' => $RowIdColumn,
                        'RowId' => (string)$RowIdValue,
                        'ParentIdColumn' => $ParentJoinColumn !== '' ? $ParentJoinColumn : null,
                        'ParentId' => $ParentIdValue !== null ? (string)$ParentIdValue : null,
                        'MatchedColumn' => $Vc['Column'],
                        'MatchedColumnLabel' => $Vc['Label'],
                        'Content' => $CellValue,
                    ];
                }
            }
        }
    }

    Logger::Info($FunctionName, '検索処理終了', $UserId);

    Response::Success([
        'Results' => $Results,
        'TotalCount' => count($Results),
    ]);

} catch (Exception $E) {
    Logger::Error($FunctionName, '検索処理エラー', $UserId, $E->getMessage());
    Response::Error('キーワード検索に失敗しました');
}

