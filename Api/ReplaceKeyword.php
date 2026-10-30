<?php
/**
 * ReplaceKeyword.php
 * キーワード横断置換API
 *
 * 入力(POST JSON):
 *   - WorkId: 作品ID (必須)
 *   - Keyword: 検索キーワード (必須、空白不可)
 *   - ReplaceWord: 置換後文字列 (必須、空文字許可)
 *   - CaseSensitive: 大文字小文字を区別するか (bool)
 *   - MenuIds: 検索対象画面IDの配列 (必須、1件以上)
 *   - UpdateDate: WorkInfoの更新年月日 (楽観ロック用、必須)
 *
 * 権限:
 *   - 作成者 (CreaterList.isCreator) または管理者 (Session.AuthFlg) のみ実行可能
 *
 * 処理フロー:
 *   1. 権限チェック
 *   2. トランザクション開始
 *   3. WorkInfoの楽観ロック (updateDate照合 + 更新)
 *   4. GetSearchKeyword.php と同一のSQLで対象レコードを取得
 *   5. PHP側で対象列の文字列を置換し、UPDATE発行
 *      - 親テーブル(storyinfo/plotinfo)が定義されている場合は親のupdateDateも更新
 *   6. UpdateHistoryに記録
 *   7. コミット
 */

require_once __DIR__ . '/../Common/Database.php';
require_once __DIR__ . '/../Common/Logger.php';
require_once __DIR__ . '/../Common/Response.php';
require_once __DIR__ . '/../Common/Auth.php';

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
    $ReplaceWord = isset($Input['ReplaceWord']) ? (string)$Input['ReplaceWord'] : '';
    $CaseSensitive = !empty($Input['CaseSensitive']);
    $MenuIds = $Input['MenuIds'] ?? [];
    $UpdateDate = $Input['UpdateDate'] ?? null;
    $UserId = $AuthUserId;

    Logger::Info($FunctionName, '置換処理開始', $UserId);

    if (empty($WorkId)) {
        Response::Error('作品IDが指定されていません');
    }
    if ($Keyword === '') {
        Response::Error('キーワードを入力してください');
    }
    if (empty($MenuIds) || !is_array($MenuIds)) {
        Response::Error('置換対象画面を1つ以上選択してください');
    }
    if (empty($UpdateDate)) {
        Response::Error('更新年月日が指定されていません');
    }

    $Pdo = Database::GetConnection();

    // 権限チェック (作成者または管理者)
    $PermStmt = $Pdo->prepare('
        SELECT isCreator FROM CreaterList
        WHERE workId = :workId AND userId = :userId
    ');
    $PermStmt->execute([':workId' => $WorkId, ':userId' => $UserId]);
    $PermResult = $PermStmt->fetch();
    $IsCreator = $PermResult ? (bool)$PermResult['iscreator'] : false;
    if (!$IsCreator && !$IsAuthAdmin) {
        Response::Error('置換を実行する権限がありません', 403);
    }

    // 画面定義の読込
    $ConfigPath = __DIR__ . '/../../Front/Config/Screens.json';
    if (!is_file($ConfigPath)) {
        Logger::Error($FunctionName, '画面定義ファイルが見つかりません', $UserId, $ConfigPath);
        Response::Error('画面定義ファイルが見つかりません');
    }
    $ConfigJson = file_get_contents($ConfigPath);
    $Config = json_decode($ConfigJson, true);
    if (!is_array($Config) || empty($Config['Screens'])) {
        Logger::Error($FunctionName, '画面定義ファイルの形式が不正です', $UserId, '');
        Response::Error('画面定義の読込に失敗しました');
    }

    // 指定された MenuIds に該当する画面定義のみ抽出
    $RequestedMenuIds = array_map('strval', $MenuIds);
    $TargetScreens = array_values(array_filter($Config['Screens'], function ($Screen) use ($RequestedMenuIds) {
        return isset($Screen['MenuId']) && in_array((string)$Screen['MenuId'], $RequestedMenuIds, true);
    }));
    if (empty($TargetScreens)) {
        Response::Error('指定された画面が画面定義に存在しません');
    }

    // ILIKE 用ワイルドカードのエスケープ (GetSearchKeyword.php と同じロジック)
    $EscapedKeyword = str_replace(['/', '%', '_'], ['//', '/%', '/_'], $Keyword);
    $LikePattern = '%' . $EscapedKeyword . '%';

    Database::BeginTransaction();

    // WorkInfo 楽観ロック + 更新
    $WorkLockSql = "
        UPDATE WorkInfo SET
            updateDate = CURRENT_TIMESTAMP(3),
            updateUser = :updateUser
        WHERE workId = :workId
          AND updateDate = :updateDate
        RETURNING updateDate
    ";
    $WorkLockStmt = $Pdo->prepare($WorkLockSql);
    $WorkLockStmt->execute([
        ':workId' => $WorkId,
        ':updateUser' => $UserId,
        ':updateDate' => $UpdateDate,
    ]);
    $WorkLockResult = $WorkLockStmt->fetch();
    if (!$WorkLockResult) {
        Database::Rollback();
        Response::ConflictError();
    }

    $ReplaceCount = 0;
    // 親テーブル(storyinfo/plotinfo)の更新済みID追跡 (二重UPDATE回避)
    $UpdatedParents = [];

    foreach ($TargetScreens as $Screen) {
        $MenuId = (string)$Screen['MenuId'];
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

            // 識別子検証
            if (!IsValidIdentifier($TableName) || !IsValidIdentifier($RowIdColumn)) {
                continue;
            }
            if ($WorkIdColumn !== '' && !IsValidIdentifier($WorkIdColumn)) {
                continue;
            }
            if ($ParentTable !== '' && !IsValidIdentifier($ParentTable)) {
                continue;
            }
            if ($ParentJoinColumn !== '' && !IsValidIdentifier($ParentJoinColumn)) {
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
                if (!IsValidIdentifier($ColName)) {
                    continue;
                }
                $ValidColumns[] = $ColName;
                $ColumnWhere[] = "t.$ColName ILIKE :keyword ESCAPE '/'";
            }
            if (empty($ColumnWhere)) {
                continue;
            }

            // SELECT 列リスト
            $SelectCols = ["t.$RowIdColumn AS rowid"];
            foreach ($ValidColumns as $ColName) {
                $SelectCols[] = "t.$ColName AS col_$ColName";
            }

            // 親テーブル経由 or WorkIdColumn 直接
            if ($ParentTable !== '' && $ParentJoinColumn !== '') {
                $ParentScreenDef = FindParentTableDef($Config['Screens'], $ParentTable);
                $ParentWorkIdColumn = $ParentScreenDef['WorkIdColumn'] ?? 'workid';
                if (!IsValidIdentifier($ParentWorkIdColumn)) {
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

            $SearchStmt = $Pdo->prepare($Sql);
            $SearchStmt->execute([
                ':workid' => $WorkId,
                ':keyword' => $LikePattern,
            ]);
            $Rows = $SearchStmt->fetchAll();

            // 各行ごとに対象列を置換してUPDATE
            foreach ($Rows as $Row) {
                $RowIdValue = $Row['rowid'] ?? null;
                $ParentIdValue = array_key_exists('parentid', $Row) ? $Row['parentid'] : null;
                if ($RowIdValue === null) {
                    continue;
                }
                $SetClauses = [];
                $Params = [];
                $RowMatchCount = 0;
                foreach ($ValidColumns as $ColName) {
                    $ColKey = 'col_' . strtolower($ColName);
                    if (!array_key_exists($ColKey, $Row) || $Row[$ColKey] === null) {
                        continue;
                    }
                    $CellValue = (string)$Row[$ColKey];
                    if ($CellValue === '') {
                        continue;
                    }
                    // 該当しない列(他列でヒット)はスキップ
                    if ($CaseSensitive) {
                        if (strpos($CellValue, $Keyword) === false) {
                            continue;
                        }
                        $NewValue = str_replace($Keyword, $ReplaceWord, $CellValue);
                    } else {
                        if (stripos($CellValue, $Keyword) === false) {
                            continue;
                        }
                        // 置換後文字列に含まれる '$' などをエスケープせず安全に置換するため preg_replace_callback を使用
                        $Pattern = '/' . preg_quote($Keyword, '/') . '/iu';
                        $NewValue = preg_replace_callback(
                            $Pattern,
                            function ($M) use ($ReplaceWord) { return $ReplaceWord; },
                            $CellValue
                        );
                    }
                    if ($NewValue === $CellValue) {
                        continue;
                    }
                    $SetClauses[] = "$ColName = :col_$ColName";
                    $Params[":col_$ColName"] = $NewValue;
                    $RowMatchCount++;
                }
                if (empty($SetClauses)) {
                    continue;
                }
                $SetClauses[] = "updateDate = CURRENT_TIMESTAMP(3)";
                $SetClauses[] = "updateUser = :updateUser";
                $Params[':updateUser'] = $UserId;
                $Params[':rowid'] = $RowIdValue;
                $UpdateSql = "UPDATE $TableName SET "
                           . implode(', ', $SetClauses)
                           . " WHERE $RowIdColumn = :rowid";
                $UpdateStmt = $Pdo->prepare($UpdateSql);
                $UpdateStmt->execute($Params);
                $ReplaceCount += $RowMatchCount;

                // 親テーブル (storyinfo/plotinfo 等) の updateDate も更新 (重複回避)
                if ($ParentTable !== '' && $ParentJoinColumn !== '' && $ParentIdValue !== null) {
                    $ParentKey = $ParentTable . ':' . $ParentIdValue;
                    if (!isset($UpdatedParents[$ParentKey])) {
                        $ParentUpdateSql = "UPDATE $ParentTable SET "
                                         . "updateDate = CURRENT_TIMESTAMP(3), "
                                         . "updateUser = :updateUser "
                                         . "WHERE $ParentJoinColumn = :parentid";
                        $ParentUpdateStmt = $Pdo->prepare($ParentUpdateSql);
                        $ParentUpdateStmt->execute([
                            ':updateUser' => $UserId,
                            ':parentid' => $ParentIdValue,
                        ]);
                        $UpdatedParents[$ParentKey] = true;
                    }
                }
            }
        }
    }

    // 更新履歴に1件記録 (置換が0件でも操作した事実は残す)
    $HistorySql = "
        INSERT INTO UpdateHistory (
            workId, functionName, operation, registDate, updateDate, registUser, updateUser
        ) VALUES (
            :workId, :functionName, :operation, CURRENT_TIMESTAMP(3), CURRENT_TIMESTAMP(3), :registUser, :updateUser
        )
    ";
    $HistoryStmt = $Pdo->prepare($HistorySql);
    $HistoryStmt->execute([
        ':workId' => $WorkId,
        ':functionName' => $FunctionName,
        ':operation' => 'キーワード置換',
        ':registUser' => $UserId,
        ':updateUser' => $UserId,
    ]);

    Database::Commit();

    Logger::Info($FunctionName, '置換処理終了', $UserId);

    Response::Success([
        'Count' => $ReplaceCount,
    ]);

} catch (Exception $E) {
    Database::Rollback();
    Logger::Error($FunctionName, '置換処理エラー', $UserId, $E->getMessage());
    Response::Error('キーワード置換に失敗しました');
}

/**
 * SQL 識別子として安全な文字列か判定
 */
function IsValidIdentifier($Name)
{
    if (!is_string($Name) || $Name === '') {
        return false;
    }
    return preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $Name) === 1;
}

/**
 * 画面定義配列から指定テーブルの定義を検索し、最初に見つかったものを返す。
 */
function FindParentTableDef($Screens, $TableName)
{
    foreach ($Screens as $Screen) {
        if (!isset($Screen['Tables']) || !is_array($Screen['Tables'])) {
            continue;
        }
        foreach ($Screen['Tables'] as $Table) {
            if (isset($Table['TableName']) && (string)$Table['TableName'] === $TableName) {
                return $Table;
            }
        }
    }
    return null;
}
