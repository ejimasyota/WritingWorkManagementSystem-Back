<?php
/* ==========================================================
 * 作品資料Word出力
 * ----------------------------------------------------------
 * ・各テーブルの内容から作品資料を作成し出力を行うAPI
 * ----------------------------------------------------------
 * 更新履歴：
 * 2026-01-31 作成
 * 2026-02-30 セリフ一覧を出力内容に追加
 * 2026-04-14 機能追加分の項目を追加
 * 2026-08-12 キャラの二つ名を追加、好き嫌い・地雷・長所・短所を表形式に対応
 * 2026-08-31 キャラの武器を複数設定（JSON保持）に対応
 * 2026-09-14 年表一覧に年表詳細を追加
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
// 4. 認証クラス
require_once __DIR__ . '/../Common/Auth.php';
// 5. コード区分値
require_once __DIR__ . '/../Common/CodeLabel.php';
// 7. キャラクターの表形式項目の共通処理
require_once __DIR__ . '/../Common/EntryTable.php';
// 8. 関連要素のJSON保持 共通処理
require_once __DIR__ . '/../Common/RelationJson.php';
// 9. 画像一覧の共通処理
require_once __DIR__ . '/../Common/ImageList.php';
// 6. オートロード
require_once __DIR__ . '/../vendor/autoload.php';

/* ==========================================================
 * 2. 名前空間の解決
 * ========================================================== */
// 1. PHPWord
use PhpOffice\PhpWord\PhpWord;
// 2. ファイル読み書き用エントリーポイント
use PhpOffice\PhpWord\IOFactory;

/* ==========================================================
 * 3. 共通定義
 * ========================================================== */
// 1. 機能名
$FunctionName = '資料Word出力';
// 2. ユーザーID
$UserId = '';

/* ==========================================================
 * 4. 定数定義
 * ========================================================== */
// 1. 項目名と値の2列表のスタイル名
const DETAIL_TABLE_STYLE = 'DetailTable';
// 2. 項目名列の幅
const DETAIL_LABEL_WIDTH = 2560;
// 3. 値列の幅
const DETAIL_VALUE_WIDTH = 6800;
// 4. 出力する画像の長辺の長さ
const EXPORT_IMAGE_SIZE = 142;
// 5. デフォルトテキスト
const DEFAULT_TEXT = '未設定';
// 6. ソート指定リクエストのデフォルト
const SORT_REQUEST_DEFAULT = 'asc';
// 7. デフォルトフォント
const DEFAULT_FONT = 'ＭＳ ゴシック';

/* ==========================================================
 * 5. 共通ヘルパー
 * ========================================================== */

/**
 * 技情報取得処理
 */
function GetMoveInfo(PDO $Pdo, string $WorkId, string $OrderDirection): array {
    /* ------------------------------
     * 1. 取得処理
     * ------------------------------*/
    // 1. クエリを定義
    $Stmt = $Pdo->prepare("
        SELECT
            m.moveId AS \"MoveId\",
            m.moveName AS \"MoveName\",
            p.powerName AS \"PowerName\",
            m.description AS \"Description\",
            m.conditions AS \"Conditions\",
            m.risk AS \"Risk\"
        FROM MoveInfo m
        LEFT JOIN PowerInfo p ON m.powerId = p.powerId
        WHERE m.workId = :workId
        ORDER BY m.moveName $OrderDirection
    ");
    // 2. クエリを実行
    $Stmt->execute([':workId' => $WorkId]);
    // 3. 結果行をすべて取得
    $Moves = $Stmt->fetchAll(PDO::FETCH_ASSOC);

    /* ------------------------------
     * 2. 戻り値返却
     * ------------------------------*/
    // 1. 技情報を返却
    return $Moves;
}

/**
 * 能力・武器・技の名称対応表取得処理
 */
function GetAbilityNameMaps(array $Powers, array $Weapons, array $Moves): array {
    /* ------------------------------
     * 1. 事前処理
     * ------------------------------*/
    // 1. 能力名の対応表を初期化
    $PowerNameById = [];
    // 2. 武器名の対応表を初期化
    $WeaponNameById = [];
    // 3. 技名の対応表を初期化
    $MoveNameById = [];

    /* ------------------------------
     * 2. 能力名の対応表生成
     * ------------------------------*/
    foreach ($Powers as $PowerRow) {
        // 1. 能力IDと能力名の対応を保持
        $PowerNameById[(string)$PowerRow['PowerId']] = $PowerRow['PowerName'];
    }

    /* ------------------------------
     * 3. 武器名の対応表生成
     * ------------------------------*/
    foreach ($Weapons as $WeaponRow) {
        // 1. 武器IDと武器名の対応を保持
        $WeaponNameById[(string)$WeaponRow['WeaponId']] = $WeaponRow['WeaponName'];
    }

    /* ------------------------------
     * 4. 技名の対応表生成
     * ------------------------------*/
    foreach ($Moves as $MoveRow) {
        // 1. 技IDと技名の対応を保持
        $MoveNameById[(string)$MoveRow['MoveId']] = $MoveRow['MoveName'];
    }

    /* ------------------------------
     * 5. 戻り値返却
     * ------------------------------*/
    // 1. 名称対応表を返却
    return [
        'PowerNameById' => $PowerNameById,
        'WeaponNameById' => $WeaponNameById,
        'MoveNameById' => $MoveNameById
    ];
}

/**
 * NULL値を「未設定」に変換
 */
function DisplayValue($value, $default = DEFAULT_TEXT) {
    // 1. NULLまたは空であれば未設定と返す
    return ($value === null || $value === '') ? $default : $value;
}

/**
 * 値を表のセルへ出力する行の配列へ変換する
 *  ・NULL・空文字は「未設定」の1行とする
 *  ・配列（表形式で保持する項目の整形結果など）は空であれば「未設定」とする
 *  ・改行反映が指定されている場合は改行位置で行を分割する
 * @param  mixed $value   出力値（文字列・NULL・文字列の配列）
 * @param  bool  $newLine 改行を反映するか（画面の出力オプション）
 * @return array          出力する行の配列
 */
function ToDisplayLines($value, $newLine = false) {
    /* ------------------------------
     * 1. 配列の場合
     * ------------------------------*/
    if (is_array($value)) {
        // 1. 行の集合として扱う
        return empty($value) ? [DisplayValue(null)] : $value;
    }

    /* ------------------------------
     * 2. 未設定の場合
     * ------------------------------*/
    if ($value === null || trim((string) $value) === '') {
        // 1. 未設定として設定
        return [DisplayValue(null)];
    }

    /* ------------------------------
     * 3. 改行反映が指定されている場合
     * ------------------------------*/
    /* 1. 事前定義 */
    // 1. 改行CDをLF形式に統一
    $text = str_replace(["\r\n", "\r"], "\n", (string) $value);

    /* 2. 改行反映が指定されていない場合または改行CDが含まれていない場合 */
    if (!$newLine || strpos($text, "\n") === false) {
        // 1. 文字列を配列で返却
        return [$text];
    }

    /* ------------------------------
     * 4. 戻り値返却
     * ------------------------------*/
    return explode("\n", $text);
}

/**
 * 画像ディレクトリの絶対パスを返却する
 */
function ReturnImgPath(){
    // 1. 正規化された絶対パスを返却
    return realpath(__DIR__ . '/../../Uploads');
}

/**
 * IDのみを保持するJSON（能力・武器・技）を「名称、名称」形式へ整形する
 * 概要はそれぞれの一覧セクションに出力されるため、ここでは名称のみを並べる
 * @param  string      $key     JSONのルート要素名（Powers / Weapons / Moves）
 * @param  string      $idKey   各行が持つID項目名（PowerId / WeaponId / MoveId）
 * @param  string|null $value   DBの保持値
 * @param  array       $nameMap ID => 名称 の対応
 * @return string|null          整形結果
 */
function FormatSkillNames($key, $idKey, $value, array $nameMap) {
    /* ------------------------------
     * 1. 事前処理
     * ------------------------------*/
    // 1. 未設定またはJSONとして解釈できない場合はNULLとする
    $decoded = ($value === null || trim((string) $value) === '') ? null : json_decode((string) $value, true);
    
    /* ------------------------------
     * 2. デコード結果が非配列・該当キーが存在しない・非オブジェクト配列の場合
     * ------------------------------*/
    if (!is_array($decoded) || !isset($decoded[$key]) || !is_array($decoded[$key])) {
        // 1. NULLを返却
        return null;
    }

    /* ------------------------------
     * 3. 事前定義
     * ------------------------------*/
    // 1. 戻り値を保持する
    $names = [];

    /* ------------------------------
     * 4. 保持しているIDから名称を解決する
     * ------------------------------*/
    foreach ($decoded[$key] as $row) {
        /* 1. 非配列または該当キーが存在しない場合 */
        if (!is_array($row) || !isset($row[$idKey])) {
            // 1. 行をスキップ
            continue;
        }

        /* 2. 事前処理 */
        // 1. 行のIDを保持
        $id = (string) $row[$idKey];

        /* 3. 名前解決 */
        if (isset($nameMap[$id])) {
            // 1. IDに該当する名称を保持
            $names[] = $nameMap[$id];
        }
    }

    /* ------------------------------
     * 5. 戻り値返却
     * ------------------------------*/
    return empty($names) ? null : implode('、', $names);
}

/* ==========================================================
 * 6. Word出力ヘルパー
 * ========================================================== */
/**
 * 「項目名」「値」の2列表を出力する
 *  1件分の明細（キャラ1人・組織1つなど）を1つの表として罫線付きで出力する
 * @param mixed $section     出力先セクション
 * @param array $rows        [[項目名, 値], ...]（値は文字列・NULL・文字列の配列）
 * @param array $labelStyle  項目名の文字スタイル
 * @param array $valueStyle  値の文字スタイル
 * @param bool  $newLine     改行を反映するか
 */
function AddDetailTable($section, array $rows, array $labelStyle, array $valueStyle, $newLine = false) {
    /* ------------------------------
     * 1. 事前定義
     * ------------------------------*/
    // 1. テーブルスタイルを設定
    $table = $section->addTable(DETAIL_TABLE_STYLE);

    /* ------------------------------
     * 2. 表の作成
     * ------------------------------*/
    foreach ($rows as $row) {
        /* 1. 行の作成処理 */
        // 1. 行の追加
        $table->addRow();
        // 2. 項目名列の作成
        $labelCell = $table->addCell(DETAIL_LABEL_WIDTH, ['bgColor' => 'F2F2F2']);
        // 3. 項目名の設定
        $labelCell->addText($row[0], $labelStyle);
        // 4. 値の設定
        $valueCell = $table->addCell(DETAIL_VALUE_WIDTH);

        /* 2. 値の配列変換 */
        foreach (ToDisplayLines($row[1], $newLine) as $line) {
            // 1. 配列変換された値の設定
            $valueCell->addText($line, $valueStyle);
        }
    }
}

/**
 * 見出し行を持つ一覧表を出力する
 *  経歴・掛け合いのように同じ形の行が繰り返される内容に使用する
 * @param mixed $section     出力先セクション
 * @param array $headers     見出しの配列
 * @param array $widths      各列の幅（twips）の配列
 * @param array $rows        行の配列（各行は列数分の値の配列）
 * @param array $labelStyle  見出しの文字スタイル
 * @param array $valueStyle  値の文字スタイル
 * @param bool  $newLine     改行を反映するか
 */
function AddListTable($section, array $headers, array $widths, array $rows, array $labelStyle, array $valueStyle, $newLine = false) {
    /* ------------------------------
     * 1. 事前定義
     * ------------------------------*/
    // 1. テーブルスタイルを設定
    $table = $section->addTable(DETAIL_TABLE_STYLE);
    // 2. 見出し行の作成
    $table->addRow();

    /* ------------------------------
     * 2. 見出しの作成
     * ------------------------------*/
    foreach ($headers as $index => $header) {
        // 1. 見出し内容列を作成
        $table->addCell($widths[$index], ['bgColor' => 'F2F2F2'])->addText($header, $labelStyle);
    }

    /* ------------------------------
     * 3. 明細行の作成
     * ------------------------------*/
    foreach ($rows as $row) {
        /* 1. 事前処理 */
        // 1. 明細行の作成
        $table->addRow();

        /* 2. 明細行の値の設定 */
        foreach ($row as $index => $value) {
            /* 事前処理 */
            // 1. 列の作成
            $cell = $table->addCell($widths[$index]);

            /* 値の設定 */
            foreach (ToDisplayLines($value, $newLine) as $line) {
                // 1. 列の値を設定
                $cell->addText($line, $valueStyle);
            }
        }
    }
}

/**
 * 登録されている画像と説明文を出力する
 *  担当編集などへ資料として渡す際に画像も確認できるよう、明細の表の直後に縮小して出力する
 *  ・画像は長辺を約5cmに揃える
 *  ・サーバーに実ファイルが存在しない画像・Uploads 配下以外のパスは出力しない
 *  ・PHPWord が扱えない形式の画像は、省略した旨を出力する
 * @param mixed       $section    出力先セクション
 * @param string|null $imgList    画像一覧のJSON文字列
 * @param string|null $imgPath    代表画像のパス（画像一覧が未設定の移行前のデータ用）
 * @param array       $labelStyle 見出しの文字スタイル
 * @param array       $valueStyle 説明文の文字スタイル
 */
function AddImageList($section, $imgList, $imgPath, array $labelStyle, array $valueStyle) {
    /* ------------------------------
     * 1. 画像リストの配列変換
     * ------------------------------*/
    // 1. 画像一覧を連想配列を変換する
    $images = ImageList::Parse($imgList, $imgPath);

    /* ------------------------------
     * 2. 件数が0件の場合
     * ------------------------------*/
    if (count($images) === 0) {
        // 1. 処理終了
        return;
    }

    /* ------------------------------
     * 3. 絶対パスの取得
     * ------------------------------*/
    // 1. 画像ディレクトリの絶対パスを取得
    $uploadsRoot = ReturnImgPath();
    
    /* ------------------------------
     * 4. ディレクトリが存在しない場合
     * ------------------------------*/
    if ($uploadsRoot === false) {
        // 1. 処理終了
        return;
    }

    /* ------------------------------
     * 5. 事前処理
     * ------------------------------*/
    // 1. 絶対パス右側のスラッシュを除去
    $uploadsRootNorm = rtrim($uploadsRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

    /* ------------------------------
     * 6. セクションラベルの設定
     * ------------------------------*/
    // 1. セクションのラベルを設定
    $section->addText('画像', $labelStyle);

    /* ------------------------------
     * 7. 画像の配置処理
     * ------------------------------*/
    foreach ($images as $index => $image) {
        /* 1. 事前定義 */
        // 1. Uploads配下の実ファイルのみを対象とする
        $realPath = realpath(__DIR__ . '/../../' . ltrim($image['Path'], '/'));

        /* 2. 実ファイルが存在しない・通常ファイルでない・アップロードディレクトリ外にある場合 */
        if ($realPath === false || !is_file($realPath) || strncmp($realPath, $uploadsRootNorm, strlen($uploadsRootNorm)) !== 0) {
            // 1. 処理スキップ
            continue;
        }

        /* 3. 画像表示設定 */
        // 1. 画像の位置と説明文を決定
        $caption = ($index === 0 ? '代表画像' : ($index + 1) . '枚目') . ($image['Caption'] !== '' ? '：' . $image['Caption'] : '');
        // 2. 縦長・横長のいずれも長辺を揃える
        $size = @getimagesize($realPath);
        // 3. 画像の表示スタイルを設定
        $imageStyle = ($size !== false && $size[1] > $size[0]) ? ['height' => EXPORT_IMAGE_SIZE] : ['width' => EXPORT_IMAGE_SIZE];

        /* 4. 画像の挿入処理 */
        try {
            // 1. 画像を挿入
            $section->addImage($realPath, $imageStyle);

        /* 5. 例外処理 */
        } catch (Exception $e) {
            // 1. キャプションに出力できない旨を表示
            $section->addText($caption . '（出力できない形式の画像のため省略しました）', $valueStyle);
            // 2. 処理スキップ
            continue;
        }

        /* 6. キャプションの記載 */
        // 1. 画像のキャプションを挿入
        $section->addText($caption, $valueStyle);
    }
}

/* ==========================================================
 * 7. 処理用データクラス
 * ========================================================== */
/**
 * 作品情報
 */
final class ExportWorkInfo {
    // 1. 作品名
    public string $WorkTitle;
    // 2. ジャンル
    public ?int $Genre;
    // 3. 概要
    public string $Description;
    // 4. 作品設定日
    public string $SettingDate;
    // 5. テーマ
    public string $Theme;
}

/**
 * 帳票に出力するデータを保持するクラス
 */
class ExportItemData {
    // 1. 作品情報
    public ExportWorkInfo $WorkInfo;
    // 2. キャラ情報
    public array $CharaInfo = [];
    // 3. 経歴情報
    public array $BiographyInfo = [];
    // 4. 元号情報
    public array $CalendarInfo = [];
    // 5. 建物情報
    public array $BuildInfo = [];
    // 6. 舞台情報
    public array $StageInfo = [];
    // 7. 組織情報
    public array $OrgInfo = [];
    // 8. 階級情報
    public array $ClassInfo = [];
    // 9. チーム情報
    public array $TeamInfo = [];
    //10. 種族情報
    public array $RaceInfo = [];
    //11. タグ情報
    public array $TagInfo = [];
    //12. 能力一覧
    public array $PowerInfo = [];
    //13. 武器一覧
    public array $WeaponInfo = [];
    //14. 技一覧
    public array $MoveInfo = [];
    //15. 年表一覧
    public array $TimelineInfo = [];
    //16. 年表詳細一覧
    public array $TimelineEventInfo = [];
    //17. チャプター一覧
    public array $ChapterInfo = [];
    //18. 伏線一覧
    public array $ClueInfo = [];
    //19. 作中用語一覧
    public array $TermInfo = [];
    //20. セリフ一覧
    public array $SerifInfo = [];
    //21. 掛け合い一覧
    public array $ExchangeInfo = [];
    //22. 掛け合い行一覧
    public array $ExchangeRowInfo = [];
    //23. アイテム一覧
    public array $ItemInfo = [];
    //24. 作品作成用メモ一覧
    public array $WorkMemoInfo = [];
    //25. 能力・武器・技の名称対応表
    public array $AbilityNameMaps = [];
    //26. 年表詳細の関連名称対応表
    public array $TimelineEventNameMaps = [];
}

/* ==========================================================
 * 8. データ取得処理
 * ========================================================== */
/**
 * 作品情報取得処理
 */
function GetWorkInfo(PDO $Pdo, string $WorkId): ExportWorkInfo {
    /* ------------------------------
     * 1. 取得処理
     * ------------------------------*/
    // 1. クエリを定義
    $Stmt = $Pdo->prepare('
        SELECT
            W.workTitle AS "WorkTitle",
            W.genre AS "Genre",
            W.description AS "Description",
            S.settingDate AS "SettingDate",
            S.theme AS "Theme"
        FROM WorkInfo W
        LEFT JOIN SettingInfo S ON W.workId = S.workId
        WHERE W.workId = :workId
    ');
    // 2. クエリを実行
    $Stmt->execute([':workId' => $WorkId]);
    // 3. 結果行を取得
    $Work = $Stmt->fetch(PDO::FETCH_ASSOC);

    /* ------------------------------
     * 2. 事前処理
     * ------------------------------*/
    // 1. DTOを生成
    $Dto = new ExportWorkInfo();

    /* ------------------------------
     * 3. セット処理
     * ------------------------------*/
    // 1. 作品名を保持
    $Dto->WorkTitle = $Work['WorkTitle'];
    // 2. ジャンルを保持
    $Dto->Genre = isset($Work['Genre']) ? (int)$Work['Genre'] : null;
    // 3. 概要を保持
    $Dto->Description = $Work['Description'] ?? '';
    // 4. 作品設定日を保持
    $Dto->SettingDate = $Work['SettingDate'] ?? '';
    // 5. テーマを保持
    $Dto->Theme = $Work['Theme'] ?? '';

    /* ------------------------------
     * 4. 戻り値返却
     * ------------------------------*/
    // 1. DTOを返却
    return $Dto;
}

/**
 * キャラ情報取得処理
 */
function GetCharaInfo(PDO $Pdo, string $WorkId, string $OrderDirection): array {
    /* ------------------------------
     * 1. キャラ情報の取得
     * ------------------------------*/
    // 1. クエリを定義
    $CharaStmt = $Pdo->prepare("
        SELECT
            c.charaName AS \"CharaName\",
            c.furigana AS \"Furigana\",
            c.nickname AS \"NickName\",
            c.alias AS \"Alias\",
            c.gender AS \"Gender\",
            c.birthDate AS \"BirthDate\",
            c.age AS \"Age\",
            c.likes AS \"Likes\",
            c.dislikes AS \"Dislikes\",
            c.landmine AS \"Landmine\",
            c.strongPoint AS \"StrongPoint\",
            c.weakPoint AS \"WeakPoint\",
            c.height AS \"Height\",
            c.weight AS \"Weight\",
            c.bloodType AS \"BloodType\",
            c.raceName AS \"RaceName\",
            c.birthplaceName AS \"BirthplaceName\",
            c.powers AS \"Powers\",
            c.weapons AS \"Weapons\",
            c.moves AS \"Moves\",
            c.teamName AS \"TeamName\",
            c.className AS \"ClassName\",
            c.orgName AS \"OrgName\",
            c.roleInfo AS \"RoleInfo\",
            c.personality AS \"Personality\",
            c.biography AS \"Biography\",
            c.firstPerson AS \"FirstPerson\",
            c.secondPerson AS \"SecondPerson\",
            c.deathDate AS \"DeathDate\",
            c.habit AS \"Habit\",
            c.purpose AS \"Purpose\",
            c.verbalTic AS \"VerbalTic\",
            c.hobby AS \"Hobby\",
            c.causeOfDeath AS \"CauseOfDeath\",
            c.note AS \"Note\",
            c.model AS \"Model\",
            c.belief AS \"Belief\",
            c.appearanceRemarks AS \"AppearanceRemarks\",
            c.transition AS \"Transition\",
            c.pendingSetting AS \"PendingSetting\",
            c.tagText AS \"TagText\",
            c.imgPath AS \"ImgPath\",
            c.imgList AS \"ImgList\"
        FROM v_character_detail c
        WHERE c.workId = :workId
        ORDER BY c.charaName $OrderDirection
    ");
    // 2. クエリを実行
    $CharaStmt->execute([':workId' => $WorkId]);
    // 3. 結果行をすべて取得
    $Characters = $CharaStmt->fetchAll(PDO::FETCH_ASSOC);

    /* ------------------------------
     * 2. 各キャラのタグ情報を名称解決
     * ------------------------------*/
    /* 1. 事前定義 */
    // 1. タグIDを保持する配列
    $AllCharaTagIds = [];
    // 2. キャラクターごとのタグID一覧を保持
    $ParsedCharaTagsByIndex = [];

    /* 2. キャラ別のタグIDの保持 */
    foreach ($Characters as $Idx => $Row) {
        /* 事前処理 */
        // 1. キャラ別タグID配列を初期化
        $ParsedCharaTagsByIndex[$Idx] = [];

        /* タグ情報が存在しない場合 */
        if (empty($Row['TagText'])) {
            // 1. 処理スキップ
            continue;
        }

        /* デコード処理 */
        // 1. JSON文字列をデコード
        $Decoded = json_decode($Row['TagText'], true);

        /* 非配列・タグ情報が存在しない・タグ情報が非配列形式の場合 */
        if (!is_array($Decoded) || empty($Decoded['TagInfo']) || !is_array($Decoded['TagInfo'])) {
            // 1. 処理スキップ
            continue;
        }

        /* キャラ別タグIDの保持 */
        foreach ($Decoded['TagInfo'] as $T) {
            /* タグ情報が非配列形式・タグ情報が存在しない場合 */
            if (!is_array($T) || !isset($T['TagId'])) {
                // 1. 処理スキップ
                continue;
            }

            /* キャラ別タグIDの保持 */
            // 1. タグIDを取得
            $TagId = (int)$T['TagId'];
            // 2. キャラ別にタグIDの保持
            $ParsedCharaTagsByIndex[$Idx][] = $TagId;
            // 3. 全キャラクターのタグIDを重複なしで保持
            $AllCharaTagIds[$TagId] = true;
        }
    }

    /* 3. 名称解決前定義 */
    // 1. タグ情報を保持する配列
    $TagNameMapForChara = [];

    /* 4. タグ情報が存在する場合 */
    if (!empty($AllCharaTagIds)) {
        // 1. タグID一覧を取得
        $TagIdList = array_keys($AllCharaTagIds);
        // 2. プレースホルダーを生成
        $Placeholders = implode(',', array_fill(0, count($TagIdList), '?'));
        // 3. クエリを定義
        $TagSql = "SELECT tagId, tagName FROM TagInfo WHERE workId = ? AND tagId IN ($Placeholders)";
        // 4. クエリを準備
        $TagStmt = $Pdo->prepare($TagSql);
        // 5. クエリを実行
        $TagStmt->execute(array_merge([$WorkId], $TagIdList));

        /* タグIDとタグ名の対応を保持 */
        foreach ($TagStmt->fetchAll(PDO::FETCH_ASSOC) as $TagRow) {
            // 1. タグ名を保持
            $TagNameMapForChara[(int)$TagRow['tagid']] = $TagRow['tagname'];
        }
    }

    /* 5. キャラ別タグ名の設定 */
    foreach ($Characters as $Idx => &$Row) {
        // 1. タグ名一覧を初期化
        $Names = [];

        /* キャラ別のタグIDを処理 */
        foreach ($ParsedCharaTagsByIndex[$Idx] as $TagId) {
            /* タグ名が存在する場合 */
            if (isset($TagNameMapForChara[$TagId])) {
                // 1. タグ名を保持
                $Names[] = $TagNameMapForChara[$TagId];
            }
        }

        // 2. タグ名を設定
        $Row['TagNames'] = empty($Names) ? '' : implode('、', $Names);
    }
    // 6. 参照を解除
    unset($Row);

    /* ------------------------------
     * 3. 戻り値返却
     * ------------------------------*/
    // 1. キャラ情報を返却
    return $Characters;
}


/**
 * 経歴情報取得処理
 */
function GetBiographyInfo(PDO $Pdo, string $WorkId, string $OrderDirection): array {
    /* ------------------------------
     * 1. 取得処理
     * ------------------------------*/
    // 1. クエリを定義
    $Stmt = $Pdo->prepare("
        SELECT
            b.charaId AS \"CharaId\",
            c.charaName AS \"CharaName\",
            b.eventDate AS \"EventDate\",
            b.age AS \"Age\",
            b.content AS \"Content\"
        FROM BiographyInfo b
        LEFT JOIN CharacterInfo c ON b.charaId = c.charaId
        WHERE b.workId = :workId
        ORDER BY c.charaName $OrderDirection, b.eventDate $OrderDirection
    ");
    // 2. クエリを実行
    $Stmt->execute([':workId' => $WorkId]);
    // 3. 結果行をすべて取得
    $Biographies = $Stmt->fetchAll(PDO::FETCH_ASSOC);

    /* ------------------------------
     * 2. 戻り値返却
     * ------------------------------*/
    // 1. 経歴情報を返却
    return $Biographies;
}

/**
 * 元号情報取得処理
 */
function GetCalendarInfo(PDO $Pdo, string $WorkId, string $OrderDirection): array {
    /* ------------------------------
     * 1. 取得処理
     * ------------------------------*/
    // 1. クエリを定義
    $Stmt = $Pdo->prepare("
        SELECT
            c.eraName AS \"EraName\",
            c.startYear AS \"StartYear\",
            c.endYear AS \"EndYear\",
            c.description AS \"Description\"
        FROM CalendarInfo c
        WHERE c.workId = :workId
        ORDER BY c.startYear $OrderDirection
    ");
    // 2. クエリを実行
    $Stmt->execute([':workId' => $WorkId]);
    // 3. 結果行をすべて取得
    $Calendars = $Stmt->fetchAll(PDO::FETCH_ASSOC);

    /* ------------------------------
     * 2. 戻り値返却
     * ------------------------------*/
    // 1. 元号情報を返却
    return $Calendars;
}

/**
 * 建物情報取得処理
 */
function GetBuildInfo(PDO $Pdo, string $WorkId, string $OrderDirection): array {
    /* ------------------------------
     * 1. 取得処理
     * ------------------------------*/
    // 1. クエリを定義
    $Stmt = $Pdo->prepare("
        SELECT
            buildName AS \"BuildName\",
            buildDate AS \"BuildDate\",
            height AS \"Height\",
            area AS \"Area\",
            description AS \"Description\",
            imgPath AS \"ImgPath\",
            imgList AS \"ImgList\"
        FROM BuildInfo
        WHERE workId = :workId
        ORDER BY buildName $OrderDirection
    ");
    // 2. クエリを実行
    $Stmt->execute([':workId' => $WorkId]);
    // 3. 結果行をすべて取得
    $Buildings = $Stmt->fetchAll(PDO::FETCH_ASSOC);

    /* ------------------------------
     * 2. 戻り値返却
     * ------------------------------*/
    // 1. 建物情報を返却
    return $Buildings;
}

/**
 * 舞台情報取得処理
 */
function GetStageInfo(PDO $Pdo, string $WorkId, string $OrderDirection): array {
    /* ------------------------------
     * 1. 取得処理
     * ------------------------------*/
    // 1. クエリを定義
    $Stmt = $Pdo->prepare("
        WITH RECURSIVE StagePath AS (
            SELECT stageId, stageName::TEXT AS path
            FROM StageInfo
            WHERE workId = :workId AND parentStageId IS NULL
            UNION ALL
            SELECT c.stageId, sp.path || ' > ' || c.stageName
            FROM StageInfo c
            INNER JOIN StagePath sp ON c.parentStageId = sp.stageId
            WHERE c.workId = :childWorkId
        )
        SELECT
            s.stageName AS \"StageName\",
            s.stageType AS \"StageType\",
            sp.path AS \"ParentPath\",
            s.population AS \"Population\",
            s.areaSize AS \"AreaSize\",
            s.description AS \"Description\",
            s.imgPath AS \"ImgPath\",
            s.imgList AS \"ImgList\"
        FROM StageInfo s
        LEFT JOIN StagePath sp ON s.parentStageId = sp.stageId
        WHERE s.workId = :stageWorkId
        ORDER BY s.stageName $OrderDirection
    ");
    // 2. クエリを実行
    $Stmt->execute([
        ':workId' => $WorkId,
        ':childWorkId' => $WorkId,
        ':stageWorkId' => $WorkId
    ]);
    // 3. 結果行をすべて取得
    $Stages = $Stmt->fetchAll(PDO::FETCH_ASSOC);

    /* ------------------------------
     * 2. 戻り値返却
     * ------------------------------*/
    // 1. 舞台情報を返却
    return $Stages;
}

/**
 * 組織情報取得処理
 */
function GetOrgInfo(PDO $Pdo, string $WorkId, string $OrderDirection): array {
    /* ------------------------------
     * 1. 取得処理
     * ------------------------------*/
    // 1. クエリを定義
    $Stmt = $Pdo->prepare("
        SELECT
            o.orgName AS \"OrgName\",
            c.charaName AS \"CharaName\",
            o.foundedDate AS \"FoundedDate\",
            o.peopleNum AS \"PeopleNum\",
            o.purpose AS \"Purpose\",
            o.activity AS \"Activity\",
            b.buildName AS \"BuildName\",
            o.note AS \"Note\",
            o.imgPath AS \"ImgPath\",
            o.imgList AS \"ImgList\"
        FROM OrganizationInfo o
        LEFT JOIN BuildInfo b ON o.baseBuildId = b.buildId
        LEFT JOIN CharacterInfo c ON o.leaderid = c.charaId
        WHERE o.workId = :workId
        ORDER BY o.orgName $OrderDirection
    ");
    // 2. クエリを実行
    $Stmt->execute([':workId' => $WorkId]);
    // 3. 結果行をすべて取得
    $Organizations = $Stmt->fetchAll(PDO::FETCH_ASSOC);

    /* ------------------------------
     * 2. 戻り値返却
     * ------------------------------*/
    // 1. 組織情報を返却
    return $Organizations;
}

/**
 * 階級情報取得処理
 */
function GetClassInfo(PDO $Pdo, string $WorkId, string $OrderDirection): array {
    /* ------------------------------
     * 1. 取得処理
     * ------------------------------*/
    // 1. クエリを定義
    $Stmt = $Pdo->prepare("
        SELECT
            c.className AS \"ClassName\",
            o.orgName AS \"OrgName\",
            c.rankOrder AS \"RankOrder\",
            c.description AS \"Description\"
        FROM ClassInfo c
        LEFT JOIN OrganizationInfo o ON c.orgId = o.orgId
        WHERE c.workId = :workId
        ORDER BY c.className $OrderDirection
    ");
    // 2. クエリを実行
    $Stmt->execute([':workId' => $WorkId]);
    // 3. 結果行をすべて取得
    $Classes = $Stmt->fetchAll(PDO::FETCH_ASSOC);

    /* ------------------------------
     * 2. 戻り値返却
     * ------------------------------*/
    // 1. 階級情報を返却
    return $Classes;
}

/**
 * チーム情報取得処理
 */
function GetTeamInfo(PDO $Pdo, string $WorkId, string $OrderDirection): array {
    /* ------------------------------
     * 1. 取得処理
     * ------------------------------*/
    // 1. クエリを定義
    $Stmt = $Pdo->prepare("
        SELECT
            t.teamName AS \"TeamName\",
            c.charaName AS \"CharaName\",
            t.foundedDate AS \"FoundedDate\",
            t.peopleNum AS \"PeopleNum\",
            o.orgName AS \"OrgName\",
            t.description AS \"Description\",
            t.imgPath AS \"ImgPath\",
            t.imgList AS \"ImgList\"
        FROM TeamInfo t
        LEFT JOIN OrganizationInfo o ON t.orgId = o.orgId
        LEFT JOIN CharacterInfo c ON t.leaderid = c.charaId
        WHERE t.workId = :workId
        ORDER BY t.teamName $OrderDirection
    ");
    // 2. クエリを実行
    $Stmt->execute([':workId' => $WorkId]);
    // 3. 結果行をすべて取得
    $Teams = $Stmt->fetchAll(PDO::FETCH_ASSOC);

    /* ------------------------------
     * 2. 戻り値返却
     * ------------------------------*/
    // 1. チーム情報を返却
    return $Teams;
}

/**
 * 種族情報取得処理
 */
function GetRaceInfo(PDO $Pdo, string $WorkId, string $OrderDirection): array {
    /* ------------------------------
     * 1. 取得処理
     * ------------------------------*/
    // 1. クエリを定義
    $Stmt = $Pdo->prepare("
        SELECT
            raceName AS \"RaceName\",
            description AS \"Description\",
            physicalFeature AS \"PhysicalFeature\"
        FROM RaceInfo
        WHERE workId = :workId
        ORDER BY raceName $OrderDirection
    ");
    // 2. クエリを実行
    $Stmt->execute([':workId' => $WorkId]);
    // 3. 結果行をすべて取得
    $Races = $Stmt->fetchAll(PDO::FETCH_ASSOC);

    /* ------------------------------
     * 2. 戻り値返却
     * ------------------------------*/
    // 1. 種族情報を返却
    return $Races;
}

/**
 * タグマスタ情報取得処理
 */
function GetTagMasterInfo(PDO $Pdo, string $WorkId, string $OrderDirection): array {
    /* ------------------------------
     * 1. 取得処理
     * ------------------------------*/
    // 1. クエリを定義
    $Stmt = $Pdo->prepare("
        SELECT
            tagName AS \"TagName\",
            description AS \"Description\"
        FROM TagInfo
        WHERE workId = :workId
        ORDER BY tagName $OrderDirection
    ");
    // 2. クエリを実行
    $Stmt->execute([':workId' => $WorkId]);
    // 3. 結果行をすべて取得
    $Tags = $Stmt->fetchAll(PDO::FETCH_ASSOC);

    /* ------------------------------
     * 2. 戻り値返却
     * ------------------------------*/
    // 1. タグマスタ情報を返却
    return $Tags;
}

/**
 * 能力情報取得処理
 */
function GetPowerInfo(PDO $Pdo, string $WorkId, string $OrderDirection): array {
    /* ------------------------------
     * 1. 取得処理
     * ------------------------------*/
    // 1. クエリを定義
    $Stmt = $Pdo->prepare("
        SELECT
            p.powerId AS \"PowerId\",
            p.powerName AS \"PowerName\",
            p.description AS \"Description\",
            p.conditions AS \"Conditions\",
            p.risk AS \"Risk\"
        FROM PowerInfo p
        WHERE p.workId = :workId
        ORDER BY p.powerName $OrderDirection
    ");
    // 2. クエリを実行
    $Stmt->execute([':workId' => $WorkId]);
    // 3. 結果行をすべて取得
    $Powers = $Stmt->fetchAll(PDO::FETCH_ASSOC);

    /* ------------------------------
     * 2. 戻り値返却
     * ------------------------------*/
    // 1. 能力情報を返却
    return $Powers;
}

/**
 * 武器情報取得処理
 */
function GetWeaponInfo(PDO $Pdo, string $WorkId, string $OrderDirection): array {
    /* ------------------------------
     * 1. 取得処理
     * ------------------------------*/
    // 1. クエリを定義
    $Stmt = $Pdo->prepare("
        SELECT
            w.weaponId AS \"WeaponId\",
            w.weaponName AS \"WeaponName\",
            w.description AS \"Description\",
            w.imgPath AS \"ImgPath\",
            w.imgList AS \"ImgList\"
        FROM WeaponInfo w
        WHERE w.workId = :workId
        ORDER BY w.weaponName $OrderDirection
    ");
    // 2. クエリを実行
    $Stmt->execute([':workId' => $WorkId]);
    // 3. 結果行をすべて取得
    $Weapons = $Stmt->fetchAll(PDO::FETCH_ASSOC);

    /* ------------------------------
     * 2. 戻り値返却
     * ------------------------------*/
    // 1. 武器情報を返却
    return $Weapons;
}

/**
 * 年表情報取得処理
 */
function GetTimelineInfo(PDO $Pdo, string $WorkId, string $OrderDirection): array {
    /* ------------------------------
     * 1. 取得処理
     * ------------------------------*/
    // 1. クエリを定義
    $Stmt = $Pdo->prepare("
        SELECT
            t.timeId AS \"TimeId\",
            c.eraName AS \"EraName\",
            t.eventDate AS \"EventDate\",
            t.endDate AS \"EndDate\",
            t.title AS \"Title\",
            t.content AS \"Content\"
        FROM TimelineInfo t
        LEFT JOIN CalendarInfo c ON t.caleId = c.caleId
        WHERE t.workId = :workId
        ORDER BY t.eventDate $OrderDirection
    ");
    // 2. クエリを実行
    $Stmt->execute([':workId' => $WorkId]);
    // 3. 結果行をすべて取得
    $Timelines = $Stmt->fetchAll(PDO::FETCH_ASSOC);

    /* ------------------------------
     * 2. 戻り値返却
     * ------------------------------*/
    // 1. 年表情報を返却
    return $Timelines;
}

/**
 * 年表詳細情報取得処理
 */
function GetTimelineEventInfo(PDO $Pdo, string $WorkId): array {
    /* ------------------------------
     * 1. 取得処理
     * ------------------------------*/
    // 1. クエリを定義
    $Stmt = $Pdo->prepare("
        SELECT
            timeId AS \"TimeId\",
            title AS \"Title\",
            startDate AS \"StartDate\",
            startTime AS \"StartTime\",
            endDate AS \"EndDate\",
            endTime AS \"EndTime\",
            content AS \"Content\",
            charaText AS \"CharaText\",
            stageText AS \"StageText\",
            buildText AS \"BuildText\"
        FROM TimelineEventInfo
        WHERE workId = :workId
        ORDER BY timeId ASC, startDate ASC, startTime ASC NULLS FIRST, timeEventId ASC
    ");
    // 2. クエリを実行
    $Stmt->execute([':workId' => $WorkId]);
    // 3. 結果行をすべて取得
    $TimeEvents = $Stmt->fetchAll(PDO::FETCH_ASSOC);

    /* ------------------------------
     * 2. 関連情報の名称対応表取得
     * ------------------------------*/
    // 1. キャラ名の対応表を取得
    $TimeEventCharaNameMap = RelationJson::BuildNameMap(
        $Pdo, $WorkId, array_column($TimeEvents, 'CharaText'),
        'CharaInfo', 'CharaId', 'CharacterInfo', 'charaId', 'charaName'
    );
    // 2. 舞台名の対応表を取得
    $TimeEventStageNameMap = RelationJson::BuildNameMap(
        $Pdo, $WorkId, array_column($TimeEvents, 'StageText'),
        'StageInfo', 'StageId', 'StageInfo', 'stageId', 'stageName'
    );
    // 3. 建物名の対応表を取得
    $TimeEventBuildNameMap = RelationJson::BuildNameMap(
        $Pdo, $WorkId, array_column($TimeEvents, 'BuildText'),
        'BuildInfo', 'BuildId', 'BuildInfo', 'buildId', 'buildName'
    );

    /* ------------------------------
     * 3. 年表詳細のグループ化
     * ------------------------------*/
    // 1. 年表詳細の対応表を初期化
    $TimeEventMap = [];

    /* 年表IDごとに年表詳細をまとめる */
    foreach ($TimeEvents as $TimeEvent) {
        // 1. 年表IDを取得
        $TimeId = (string)$TimeEvent['TimeId'];
        // 2. 年表詳細を保持
        $TimeEventMap[$TimeId][] = $TimeEvent;
    }

    /* ------------------------------
     * 4. 戻り値返却
     * ------------------------------*/
    // 1. 年表詳細と名称対応表を返却
    return [
        'TimeEventMap' => $TimeEventMap,
        'CharaNameMap' => $TimeEventCharaNameMap,
        'StageNameMap' => $TimeEventStageNameMap,
        'BuildNameMap' => $TimeEventBuildNameMap
    ];
}

/**
 * チャプター情報取得処理
 */
function GetChapterInfo(PDO $Pdo, string $WorkId, string $OrderDirection): array {
    /* ------------------------------
     * 1. 取得処理
     * ------------------------------*/
    // 1. クエリを定義
    $Stmt = $Pdo->prepare("
        SELECT
            ch.chapterName AS \"ChapterName\",
            ch.description AS \"Description\",
            ch.charaText AS \"CharaText\",
            ch.indexNum AS \"IndexNum\",
            t.title AS \"TimelineTitle\"
        FROM ChapterInfo ch
        LEFT JOIN TimelineInfo t ON ch.timeId = t.timeId
        WHERE ch.workId = :workId
        ORDER BY ch.indexNum $OrderDirection
    ");
    // 2. クエリを実行
    $Stmt->execute([':workId' => $WorkId]);
    // 3. 結果行をすべて取得
    $Chapters = $Stmt->fetchAll(PDO::FETCH_ASSOC);

    /* ------------------------------
     * 2. 関連キャラIDの取得
     * ------------------------------*/
    // 1. キャラID一覧を初期化
    $ChapterCharaIds = [];

    foreach ($Chapters as $Row) {
        /* キャラ情報が存在しない場合 */
        if (empty($Row['CharaText'])) {
            // 1. 処理スキップ
            continue;
        }

        // 1. JSON文字列をデコード
        $Decoded = json_decode($Row['CharaText'], true);

        /* JSON形式が不正な場合 */
        if (!is_array($Decoded) ||
            empty($Decoded['CharaInfo']) ||
            !is_array($Decoded['CharaInfo'])) {
            // 1. 処理スキップ
            continue;
        }

        /* キャラIDを抽出 */
        foreach ($Decoded['CharaInfo'] as $C) {
            /* UUID形式が正しい場合 */
            if (
                is_array($C) &&
                isset($C['CharaId']) &&
                is_string($C['CharaId']) &&
                preg_match(
                    '/\A[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}\z/',
                    $C['CharaId']
                )
            ) {
                // 1. キャラIDを重複なしで保持
                $ChapterCharaIds[$C['CharaId']] = true;
            }
        }
    }

    /* ------------------------------
     * 3. キャラ名の名称解決
     * ------------------------------*/
    // 1. キャラ名対応表を初期化
    $ChapterCharaMap = [];

    /* キャラIDが存在する場合 */
    if (!empty($ChapterCharaIds)) {
        // 1. キャラID一覧を取得
        $IdList = array_keys($ChapterCharaIds);
        // 2. プレースホルダーを生成
        $Placeholders = implode(',', array_fill(0, count($IdList), '?'));
        // 3. クエリを定義
        $Sql = "SELECT charaId, charaName FROM CharacterInfo WHERE workId = ? AND charaId IN ($Placeholders)";
        // 4. クエリを準備
        $CharaStmt = $Pdo->prepare($Sql);
        // 5. クエリを実行
        $CharaStmt->execute(array_merge([$WorkId], $IdList));

        /* キャラ名の対応表を作成 */
        foreach ($CharaStmt->fetchAll(PDO::FETCH_ASSOC) as $CharaRow) {
            // 1. キャラIDとキャラ名を保持
            $ChapterCharaMap[(string)$CharaRow['charaid']] = $CharaRow['charaname'];
        }
    }

    /* ------------------------------
     * 4. チャプターへのキャラ名設定
     * ------------------------------*/
    foreach ($Chapters as &$Row) {
        // 1. キャラ名一覧を初期化
        $Names = [];
        // 2. JSON文字列をデコード
        $Decoded = !empty($Row['CharaText'])
            ? json_decode($Row['CharaText'], true)
            : null;

        /* キャラ情報が存在する場合 */
        if (
            is_array($Decoded) &&
            !empty($Decoded['CharaInfo']) &&
            is_array($Decoded['CharaInfo'])
        ) {
            /* キャラ名を取得 */
            foreach ($Decoded['CharaInfo'] as $C) {
                // 1. キャラIDを取得
                $CharaId = is_array($C)
                    ? (string)($C['CharaId'] ?? '')
                    : '';

                /* キャラ名が存在する場合 */
                if ($CharaId !== '' && isset($ChapterCharaMap[$CharaId])) {
                    // 1. キャラ名を保持
                    $Names[] = $ChapterCharaMap[$CharaId];
                }
            }
        }

        // 3. キャラ名一覧を設定
        $Row['CharaNames'] = empty($Names) ? null : implode('、', $Names);
        // 4. 元のJSON文字列を削除
        unset($Row['CharaText']);
    }
    // 5. 参照を解除
    unset($Row);

    /* ------------------------------
     * 5. 戻り値返却
     * ------------------------------*/
    // 1. チャプター情報を返却
    return $Chapters;
}

/**
 * 伏線情報取得処理
 */
function GetClueInfo(PDO $Pdo, string $WorkId, string $OrderDirection): array {
    /* ------------------------------
     * 1. 取得処理
     * ------------------------------*/
    // 1. クエリを定義
    $Stmt = $Pdo->prepare("
        SELECT
            title AS \"Title\",
            content AS \"Content\",
            recoveryStatus AS \"RecoveryStatus\"
        FROM ClueInfo
        WHERE workId = :workId
        ORDER BY title $OrderDirection
    ");
    // 2. クエリを実行
    $Stmt->execute([':workId' => $WorkId]);
    // 3. 結果行をすべて取得
    $Clues = $Stmt->fetchAll(PDO::FETCH_ASSOC);

    /* ------------------------------
     * 2. 戻り値返却
     * ------------------------------*/
    // 1. 伏線情報を返却
    return $Clues;
}

/**
 * 作中用語情報取得処理
 */
function GetTermInfo(PDO $Pdo, string $WorkId, string $OrderDirection): array {
    /* ------------------------------
     * 1. 取得処理
     * ------------------------------*/
    // 1. クエリを定義
    $Stmt = $Pdo->prepare("
        SELECT
            t.termName AS \"TermName\",
            t.description AS \"Description\"
        FROM TermInfo t
        WHERE t.workId = :workId
        ORDER BY t.termName $OrderDirection
    ");
    // 2. クエリを実行
    $Stmt->execute([':workId' => $WorkId]);
    // 3. 結果行をすべて取得
    $Terms = $Stmt->fetchAll(PDO::FETCH_ASSOC);

    /* ------------------------------
     * 2. 戻り値返却
     * ------------------------------*/
    // 1. 作中用語情報を返却
    return $Terms;
}

/**
 * セリフ情報取得処理
 */
function GetSerifInfo(PDO $Pdo, string $WorkId, string $OrderDirection): array {
    /* ------------------------------
     * 1. 取得処理
     * ------------------------------*/
    // 1. クエリを定義
    $Stmt = $Pdo->prepare("
        SELECT
            c.charaName AS \"CharaName\",
            s.content AS \"Content\",
            s.situation AS \"Situation\"
        FROM SerifInfo s
        LEFT JOIN CharacterInfo c ON s.charaId = c.charaId
        WHERE s.workId = :workId
        ORDER BY c.charaName $OrderDirection
    ");
    // 2. クエリを実行
    $Stmt->execute([':workId' => $WorkId]);
    // 3. 結果行をすべて取得
    $Serifs = $Stmt->fetchAll(PDO::FETCH_ASSOC);

    /* ------------------------------
     * 2. 戻り値返却
     * ------------------------------*/
    // 1. セリフ情報を返却
    return $Serifs;
}


/**
 * 掛け合い情報取得処理
 */
function GetExchangeInfo(PDO $Pdo, string $WorkId, string $OrderDirection): array {
    /* ------------------------------
     * 1. 取得処理
     * ------------------------------*/
    // 1. クエリを定義
    $Stmt = $Pdo->prepare("
        SELECT
            e.exchangeId AS \"ExchangeId\",
            e.exchangeName AS \"ExchangeName\",
            e.description AS \"Description\"
        FROM ExchangeInfo e
        WHERE e.workId = :workId
        ORDER BY e.exchangeName $OrderDirection
    ");
    // 2. クエリを実行
    $Stmt->execute([':workId' => $WorkId]);
    // 3. 結果行をすべて取得
    $Exchanges = $Stmt->fetchAll(PDO::FETCH_ASSOC);

    /* ------------------------------
     * 2. 戻り値返却
     * ------------------------------*/
    // 1. 掛け合い情報を返却
    return $Exchanges;
}

/**
 * 掛け合い行情報取得処理
 */
function GetExchangeRowInfo(PDO $Pdo, string $WorkId): array {
    /* ------------------------------
     * 1. 取得処理
     * ------------------------------*/
    // 1. クエリを定義
    $Stmt = $Pdo->prepare("
        SELECT
            r.exchangeId AS \"ExchangeId\",
            r.indexNum AS \"IndexNum\",
            COALESCE(c.charaName, r.speaker) AS \"Speaker\",
            COALESCE(s.content, r.content) AS \"Content\",
            r.feeling AS \"Feeling\"
        FROM ExchangeRows r
        INNER JOIN ExchangeInfo e ON r.exchangeId = e.exchangeId
        LEFT JOIN CharacterInfo c ON r.charaId = c.charaId
        LEFT JOIN SerifInfo s ON r.serifId = s.serifId
        WHERE e.workId = :workId
        ORDER BY r.exchangeId ASC, r.indexNum ASC
    ");
    // 2. クエリを実行
    $Stmt->execute([':workId' => $WorkId]);
    // 3. 結果行をすべて取得
    $ExchangeRows = $Stmt->fetchAll(PDO::FETCH_ASSOC);

    /* ------------------------------
     * 2. 掛け合い行のグループ化
     * ------------------------------*/
    // 1. 掛け合い行の対応表を初期化
    $ExchangeRowMap = [];

    /* 掛け合いIDごとに行をまとめる */
    foreach ($ExchangeRows as $ExchangeRow) {
        // 1. 掛け合いIDを取得
        $ExchangeId = (string)$ExchangeRow['ExchangeId'];
        // 2. 掛け合い行を保持
        $ExchangeRowMap[$ExchangeId][] = $ExchangeRow;
    }

    /* ------------------------------
     * 3. 戻り値返却
     * ------------------------------*/
    // 1. 掛け合い行情報を返却
    return $ExchangeRowMap;
}


/**
 * アイテム情報取得処理
 */
function GetItemInfo(PDO $Pdo, string $WorkId, string $OrderDirection): array {
    /* ------------------------------
     * 1. 取得処理
     * ------------------------------*/
    // 1. クエリを定義
    $Stmt = $Pdo->prepare("
        SELECT
            i.itemName AS \"ItemName\",
            i.description AS \"Description\",
            i.imgPath AS \"ImgPath\",
            i.imgList AS \"ImgList\"
        FROM ItemInfo i
        WHERE i.workId = :workId
        ORDER BY i.itemName $OrderDirection
    ");
    // 2. クエリを実行
    $Stmt->execute([':workId' => $WorkId]);
    // 3. 結果行をすべて取得
    $Items = $Stmt->fetchAll(PDO::FETCH_ASSOC);

    /* ------------------------------
     * 2. 戻り値返却
     * ------------------------------*/
    // 1. アイテム情報を返却
    return $Items;
}

/**
 * 作品作成用メモ情報取得処理
 */
function GetWorkMemoInfo(PDO $Pdo, string $WorkId, string $OrderDirection): array {
    /* ------------------------------
     * 1. 取得処理
     * ------------------------------*/
    // 1. クエリを定義
    $Stmt = $Pdo->prepare("
        SELECT
            m.title AS \"Title\",
            m.content AS \"Content\",
            m.imgPath AS \"ImgPath\",
            m.imgList AS \"ImgList\"
        FROM WorkMemoInfo m
        WHERE m.workId = :workId
        ORDER BY m.title $OrderDirection
    ");
    // 2. クエリを実行
    $Stmt->execute([':workId' => $WorkId]);
    // 3. 結果行をすべて取得
    $WorkMemos = $Stmt->fetchAll(PDO::FETCH_ASSOC);

    /* ------------------------------
     * 2. 戻り値返却
     * ------------------------------*/
    // 1. 作品作成用メモ情報を返却
    return $WorkMemos;
}

/* ==========================================================
 * 9. Word生成
 * ========================================================== */
/**
 * Word形式の作品資料生成処理
 *
 * @param ExportItemData $ExportData 帳票出力用データ
 * @param array $ExportItems 出力対象項目（空配列なら全件）
 * @param bool $NewLine 改行反映フラグ
 * @param string $FontName フォント名
 * @param bool $IncludeCover 表紙出力フラグ
 * @return array{FileData:string,FileName:string,MimeType:string}
 */
function ExportWordDocument(
    ExportItemData $ExportData,
    array $ExportItems,
    bool $NewLine,
    string $FontName,
    bool $IncludeCover
): array {
    /* ------------------------------
     * 1. DTOから出力対象を取得
     * ------------------------------*/
    // 1. 作品名を取得
    $WorkTitle = $ExportData->WorkInfo->WorkTitle;
    // 2. 各種一覧を取得（既存の帳票生成処理を流用）
    $Characters = $ExportData->CharaInfo;
    $Biographies = $ExportData->BiographyInfo;
    $Calendars = $ExportData->CalendarInfo;
    $Buildings = $ExportData->BuildInfo;
    $Stages = $ExportData->StageInfo;
    $Organizations = $ExportData->OrgInfo;
    $Classes = $ExportData->ClassInfo;
    $Teams = $ExportData->TeamInfo;
    $Races = $ExportData->RaceInfo;
    $Tags = $ExportData->TagInfo;
    $Powers = $ExportData->PowerInfo;
    $Weapons = $ExportData->WeaponInfo;
    $Items = $ExportData->ItemInfo;
    $Moves = $ExportData->MoveInfo;
    $Timelines = $ExportData->TimelineInfo;
    $Chapters = $ExportData->ChapterInfo;
    $Clues = $ExportData->ClueInfo;
    $Serifs = $ExportData->SerifInfo;
    $Exchanges = $ExportData->ExchangeInfo;
    $Terms = $ExportData->TermInfo;
    $WorkMemos = $ExportData->WorkMemoInfo;
    $TimeEventMap = $ExportData->TimelineEventInfo;
    $ExchangeRowMap = $ExportData->ExchangeRowInfo;

    // 3. IDから名称へ変換するための対応表を取得
    $PowerNameById = $ExportData->AbilityNameMaps['PowerNameById'] ?? [];
    $WeaponNameById = $ExportData->AbilityNameMaps['WeaponNameById'] ?? [];
    $MoveNameById = $ExportData->AbilityNameMaps['MoveNameById'] ?? [];
    $TimeEventCharaNameMap = $ExportData->TimelineEventNameMaps['CharaNameMap'] ?? [];
    $TimeEventStageNameMap = $ExportData->TimelineEventNameMaps['StageNameMap'] ?? [];
    $TimeEventBuildNameMap = $ExportData->TimelineEventNameMaps['BuildNameMap'] ?? [];

    /* ==========================================================
     * Word文書生成
     * ========================================================== */
    /* 出力する文字列のXMLエスケープを有効にする
     * 既定は無効のため、利用者が登録した名称・説明文等に WordprocessingML のタグが含まれると、
     * 文書が破損したり、外部参照などの要素を注入されたりする */
    \PhpOffice\PhpWord\Settings::setOutputEscapingEnabled(true);
    $phpWord = new \PhpOffice\PhpWord\PhpWord();

    $SectionTitleStyle = ['bold' => true, 'size' => 16, 'name' => $FontName];
    $SubItemStyle = ['size' => 11, 'name' => $FontName];
    $NoDataStyle = ['size' => 11, 'italic' => true, 'color' => '888888', 'name' => $FontName];
    // 表の項目名（見出し列）の文字スタイル
    $LabelStyle = ['bold' => true, 'size' => 11, 'name' => $FontName];

    /* 明細は罫線付きの表で出力するため、表のスタイルを定義しておく */
    $phpWord->addTableStyle(DETAIL_TABLE_STYLE, [
        'borderSize'  => 6,
        'borderColor' => 'AAAAAA',
        'cellMargin'  => 60,
    ]);

    $section = $phpWord->addSection();

    /** 表紙の出力 */
    if ($IncludeCover) {
        $section->addTextBreak(8);
        $CoverTitleStyle = ['bold' => true, 'size' => 28, 'name' => $FontName];
        $CoverSubStyle = ['size' => 14, 'name' => $FontName];
        $section->addText($WorkTitle, $CoverTitleStyle, ['alignment' => 'center']);
        $section->addTextBreak(2);
        $section->addText('作品資料', $CoverSubStyle, ['alignment' => 'center']);
        $section->addTextBreak(4);
        $section->addText(date('Y年m月d日'), $SubItemStyle, ['alignment' => 'center']);
        $section->addPageBreak();
    }

    /** 出力項目フィルタ関数 */
    $ShouldExport = function($key) use ($ExportItems) {
        if (empty($ExportItems)) return true;
        return in_array($key, $ExportItems);
    };

    /** ===== 【作品】 ===== */
    $section->addText('【作品】', $SectionTitleStyle);
    AddDetailTable($section, [
        ['作品名', DisplayValue($WorkTitle)],
        ['ストーリー開始時の年月日', $ExportData->WorkInfo->SettingDate],
        ['テーマ', $ExportData->WorkInfo->Theme],
        ['概要', $ExportData->WorkInfo->Description],
    ], $LabelStyle, $SubItemStyle, $NewLine);
    $section->addTextBreak();

    /** ===== 【キャラ一覧】 ===== */
    if ($ShouldExport('Characters')) {
        $section->addText('【キャラ一覧】', $SectionTitleStyle);
        if (empty($Characters)) {
            $section->addText('データがありません', $NoDataStyle);
        } else {
            foreach ($Characters as $Chara) {
                AddDetailTable($section, [
                    ['キャラ名', DisplayValue($Chara['CharaName'])],
                    ['フリガナ', $Chara['Furigana']],
                    ['ニックネーム', $Chara['NickName']],
                    ['二つ名', $Chara['Alias']],
                    ['性別', CodeLabel::Get('Gender', $Chara['Gender'])],
                    ['生年月日', $Chara['BirthDate']],
                    ['没年月日', $Chara['DeathDate']],
                    ['死因', $Chara['CauseOfDeath']],
                    ['年齢', $Chara['Age']],
                    ['身長', $Chara['Height']],
                    ['体重', $Chara['Weight']],
                    ['血液型', CodeLabel::Get('BloodType', $Chara['BloodType'])],
                    ['種族', $Chara['RaceName']],
                    ['タグ', $Chara['TagNames']],
                    ['能力', FormatSkillNames('Powers', 'PowerId', $Chara['Powers'], $PowerNameById)],
                    ['武器', FormatSkillNames('Weapons', 'WeaponId', $Chara['Weapons'], $WeaponNameById)],
                    ['技', FormatSkillNames('Moves', 'MoveId', $Chara['Moves'], $MoveNameById)],
                    ['属するチーム', $Chara['TeamName']],
                    ['階級', $Chara['ClassName']],
                    ['属する組織', $Chara['OrgName']],
                    ['好き', EntryTable::FormatLines('Character', 'Likes', $Chara['Likes'])],
                    ['嫌い', EntryTable::FormatLines('Character', 'Dislikes', $Chara['Dislikes'])],
                    ['地雷', EntryTable::FormatLines('Character', 'Landmine', $Chara['Landmine'])],
                    ['長所', EntryTable::FormatLines('Character', 'StrongPoint', $Chara['StrongPoint'])],
                    ['短所', EntryTable::FormatLines('Character', 'WeakPoint', $Chara['WeakPoint'])],
                    ['役割', $Chara['RoleInfo']],
                    ['癖', EntryTable::FormatLines('Character', 'Habit', $Chara['Habit'])],
                    ['口癖', $Chara['VerbalTic']],
                    ['趣味', EntryTable::FormatLines('Character', 'Hobby', $Chara['Hobby'])],
                    ['性格', $Chara['Personality']],
                    ['出身地', $Chara['BirthplaceName']],
                    ['生い立ち', $Chara['Biography']],
                    ['目的', $Chara['Purpose']],
                    ['思想・信念', $Chara['Belief']],
                    ['一人称', EntryTable::FormatLines('Character', 'FirstPerson', $Chara['FirstPerson'])],
                    ['二人称', EntryTable::FormatLines('Character', 'SecondPerson', $Chara['SecondPerson'])],
                    ['モデル', $Chara['Model']],
                    ['身体備考', $Chara['AppearanceRemarks']],
                    ['変遷', EntryTable::FormatLines('Character', 'Transition', $Chara['Transition'])],
                    ['検討中・未確定の設定', $Chara['PendingSetting']],
                    ['備考', $Chara['Note']],
                ], $LabelStyle, $SubItemStyle, $NewLine);
                AddImageList($section, $Chara['ImgList'], $Chara['ImgPath'], $LabelStyle, $SubItemStyle);
                $section->addTextBreak();
            }
        }
        $section->addTextBreak();
    }

    /** ===== 【経歴一覧】 ===== */
    if ($ShouldExport('Biographies')) {
        $section->addText('【経歴一覧】', $SectionTitleStyle);
        if (empty($Biographies)) {
            $section->addText('データがありません', $NoDataStyle);
        } else {
            /* キャラごとに見出しを付け、そのキャラの経歴を1つの表にまとめる */
            $BiographyRowsByChara = [];
            $BiographyNameByChara = [];
            foreach ($Biographies as $Bio) {
                $Key = (string) $Bio['CharaId'];
                $BiographyNameByChara[$Key] = $Bio['CharaName'];
                $BiographyRowsByChara[$Key][] = [
                    DisplayValue($Bio['EventDate']),
                    DisplayValue($Bio['Age']),
                    $Bio['Content'],
                ];
            }
            foreach ($BiographyRowsByChara as $Key => $Rows) {
                /* 経歴は一覧表のみのため、キャラ名は直前の2列表で出力する */
                AddDetailTable($section, [
                    ['キャラ名', DisplayValue($BiographyNameByChara[$Key])],
                ], $LabelStyle, $SubItemStyle, $NewLine);
                AddListTable(
                    $section,
                    ['日付', '年齢', '内容'],
                    [1900, 1160, 6300],
                    $Rows,
                    $LabelStyle,
                    $SubItemStyle,
                    $NewLine
                );
                $section->addTextBreak();
            }
        }
        $section->addTextBreak();
    }

    /** ===== 【元号一覧】 ===== */
    if ($ShouldExport('Calendars')) {
        $section->addText('【元号一覧】', $SectionTitleStyle);
        if (empty($Calendars)) {
            $section->addText('データがありません', $NoDataStyle);
        } else {
            foreach ($Calendars as $Cal) {
                AddDetailTable($section, [
                    ['元号名', DisplayValue($Cal['EraName'])],
                    ['開始年', $Cal['StartYear']],
                    ['終了年', $Cal['EndYear']],
                    ['概要', $Cal['Description']],
                ], $LabelStyle, $SubItemStyle, $NewLine);
                $section->addTextBreak();
            }
        }
        $section->addTextBreak();
    }

    /** ===== 【建物一覧】 ===== */
    if ($ShouldExport('Buildings')) {
        $section->addText('【建物一覧】', $SectionTitleStyle);
        if (empty($Buildings)) {
            $section->addText('データがありません', $NoDataStyle);
        } else {
            foreach ($Buildings as $Build) {
                AddDetailTable($section, [
                    ['建物名', DisplayValue($Build['BuildName'])],
                    ['建設日', $Build['BuildDate']],
                    ['高さ', $Build['Height']],
                    ['面積', $Build['Area']],
                    ['概要', $Build['Description']],
                ], $LabelStyle, $SubItemStyle, $NewLine);
                AddImageList($section, $Build['ImgList'], $Build['ImgPath'], $LabelStyle, $SubItemStyle);
                $section->addTextBreak();
            }
        }
        $section->addTextBreak();
    }

    /** ===== 【舞台一覧】 ===== */
    if ($ShouldExport('Stages')) {
        $section->addText('【舞台一覧】', $SectionTitleStyle);
        if (empty($Stages)) {
            $section->addText('データがありません', $NoDataStyle);
        } else {
            foreach ($Stages as $Stage) {
                AddDetailTable($section, [
                    ['舞台名', DisplayValue($Stage['StageName'])],
                    ['舞台区分', CodeLabel::Get('StageType', $Stage['StageType'])],
                    ['上位舞台', $Stage['ParentPath']],
                    ['人口', $Stage['Population']],
                    ['面積', $Stage['AreaSize']],
                    ['概要', $Stage['Description']],
                ], $LabelStyle, $SubItemStyle, $NewLine);
                AddImageList($section, $Stage['ImgList'], $Stage['ImgPath'], $LabelStyle, $SubItemStyle);
                $section->addTextBreak();
            }
        }
        $section->addTextBreak();
    }

    /** ===== 【組織一覧】 ===== */
    if ($ShouldExport('Organizations')) {
        $section->addText('【組織一覧】', $SectionTitleStyle);
        if (empty($Organizations)) {
            $section->addText('データがありません', $NoDataStyle);
        } else {
            foreach ($Organizations as $Org) {
                AddDetailTable($section, [
                    ['組織名', DisplayValue($Org['OrgName'])],
                    ['リーダー', $Org['CharaName']],
                    ['設立日', $Org['FoundedDate']],
                    ['人数', $Org['PeopleNum']],
                    ['目的', $Org['Purpose']],
                    ['活動内容', $Org['Activity']],
                    ['本拠地', $Org['BuildName']],
                    ['備考', $Org['Note']],
                ], $LabelStyle, $SubItemStyle, $NewLine);
                AddImageList($section, $Org['ImgList'], $Org['ImgPath'], $LabelStyle, $SubItemStyle);
                $section->addTextBreak();
            }
        }
        $section->addTextBreak();
    }

    /** ===== 【階級一覧】 ===== */
    if ($ShouldExport('Classes')) {
        $section->addText('【階級一覧】', $SectionTitleStyle);
        if (empty($Classes)) {
            $section->addText('データがありません', $NoDataStyle);
        } else {
            foreach ($Classes as $Class) {
                AddDetailTable($section, [
                    ['階級名', DisplayValue($Class['ClassName'])],
                    ['所属組織', $Class['OrgName']],
                    ['順位', $Class['RankOrder']],
                    ['概要', $Class['Description']],
                ], $LabelStyle, $SubItemStyle, $NewLine);
                $section->addTextBreak();
            }
        }
        $section->addTextBreak();
    }

    /** ===== 【チーム一覧】 ===== */
    if ($ShouldExport('Teams')) {
        $section->addText('【チーム一覧】', $SectionTitleStyle);
        if (empty($Teams)) {
            $section->addText('データがありません', $NoDataStyle);
        } else {
            foreach ($Teams as $Team) {
                AddDetailTable($section, [
                    ['チーム名', DisplayValue($Team['TeamName'])],
                    ['リーダー', $Team['CharaName']],
                    ['設立日', $Team['FoundedDate']],
                    ['人数', $Team['PeopleNum']],
                    ['所属組織', $Team['OrgName']],
                    ['概要', $Team['Description']],
                ], $LabelStyle, $SubItemStyle, $NewLine);
                AddImageList($section, $Team['ImgList'], $Team['ImgPath'], $LabelStyle, $SubItemStyle);
                $section->addTextBreak();
            }
        }
        $section->addTextBreak();
    }

    /** ===== 【種族一覧】 ===== */
    if ($ShouldExport('Races')) {
        $section->addText('【種族一覧】', $SectionTitleStyle);
        if (empty($Races)) {
            $section->addText('データがありません', $NoDataStyle);
        } else {
            foreach ($Races as $Race) {
                AddDetailTable($section, [
                    ['種族名', DisplayValue($Race['RaceName'])],
                    ['概要', $Race['Description']],
                    ['身体特徴', EntryTable::FormatLines('Race', 'PhysicalFeature', $Race['PhysicalFeature'])],
                ], $LabelStyle, $SubItemStyle, $NewLine);
                $section->addTextBreak();
            }
        }
        $section->addTextBreak();
    }

    /** ===== 【タグ一覧】 ===== */
    if ($ShouldExport('Tags')) {
        $section->addText('【タグ一覧】', $SectionTitleStyle);
        if (empty($Tags)) {
            $section->addText('データがありません', $NoDataStyle);
        } else {
            foreach ($Tags as $Tag) {
                AddDetailTable($section, [
                    ['タグ名', DisplayValue($Tag['TagName'])],
                    ['備考', $Tag['Description']],
                ], $LabelStyle, $SubItemStyle, $NewLine);
                $section->addTextBreak();
            }
        }
        $section->addTextBreak();
    }

    /** ===== 【能力一覧】 ===== */
    if ($ShouldExport('Powers')) {
        $section->addText('【能力一覧】', $SectionTitleStyle);
        if (empty($Powers)) {
            $section->addText('データがありません', $NoDataStyle);
        } else {
            foreach ($Powers as $Power) {
                AddDetailTable($section, [
                    ['能力名', DisplayValue($Power['PowerName'])],
                    ['概要', $Power['Description']],
                    ['発動条件', EntryTable::FormatLines('Power', 'Conditions', $Power['Conditions'])],
                    ['代償・副作用', EntryTable::FormatLines('Power', 'Risk', $Power['Risk'])],
                ], $LabelStyle, $SubItemStyle, $NewLine);
                $section->addTextBreak();
            }
        }
        $section->addTextBreak();
    }

    /** ===== 【武器一覧】 ===== */
    if ($ShouldExport('Weapons')) {
        $section->addText('【武器一覧】', $SectionTitleStyle);
        if (empty($Weapons)) {
            $section->addText('データがありません', $NoDataStyle);
        } else {
            foreach ($Weapons as $Weapon) {
                AddDetailTable($section, [
                    ['武器名', DisplayValue($Weapon['WeaponName'])],
                    ['概要', $Weapon['Description']],
                ], $LabelStyle, $SubItemStyle, $NewLine);
                AddImageList($section, $Weapon['ImgList'], $Weapon['ImgPath'], $LabelStyle, $SubItemStyle);
                $section->addTextBreak();
            }
        }
        $section->addTextBreak();
    }

    /** ===== 【アイテム一覧】 ===== */
    if ($ShouldExport('Items')) {
        $section->addText('【アイテム一覧】', $SectionTitleStyle);
        if (empty($Items)) {
            $section->addText('データがありません', $NoDataStyle);
        } else {
            foreach ($Items as $Item) {
                AddDetailTable($section, [
                    ['道具名', DisplayValue($Item['ItemName'])],
                    ['概要', $Item['Description']],
                ], $LabelStyle, $SubItemStyle, $NewLine);
                AddImageList($section, $Item['ImgList'], $Item['ImgPath'], $LabelStyle, $SubItemStyle);
                $section->addTextBreak();
            }
        }
        $section->addTextBreak();
    }

    /** ===== 【技一覧】 ===== */
    if ($ShouldExport('Moves')) {
        $section->addText('【技一覧】', $SectionTitleStyle);
        if (empty($Moves)) {
            $section->addText('データがありません', $NoDataStyle);
        } else {
            foreach ($Moves as $Move) {
                AddDetailTable($section, [
                    ['技名', DisplayValue($Move['MoveName'])],
                    ['関連能力', $Move['PowerName']],
                    ['概要', $Move['Description']],
                    ['発動条件', EntryTable::FormatLines('Move', 'Conditions', $Move['Conditions'])],
                    ['代償・副作用', EntryTable::FormatLines('Move', 'Risk', $Move['Risk'])],
                ], $LabelStyle, $SubItemStyle, $NewLine);
                $section->addTextBreak();
            }
        }
        $section->addTextBreak();
    }

    /** ===== 【年表一覧】 ===== */
    if ($ShouldExport('Timelines')) {
        $section->addText('【年表一覧】', $SectionTitleStyle);
        if (empty($Timelines)) {
            $section->addText('データがありません', $NoDataStyle);
        } else {
            foreach ($Timelines as $Time) {
                AddDetailTable($section, [
                    ['出来事タイトル', DisplayValue($Time['Title'])],
                    ['元号', $Time['EraName']],
                    ['開始日', $Time['EventDate']],
                    ['終了日', $Time['EndDate']],
                    ['内容', $Time['Content']],
                ], $LabelStyle, $SubItemStyle, $NewLine);
                /* 年表詳細ダイアログの内容を「出来事タイトル／期間／内容／関連」の一覧表で出力する */
                $Events = $TimeEventMap[(string) $Time['TimeId']] ?? [];
                if (empty($Events)) {
                    AddDetailTable($section, [
                        ['年表詳細', null],
                    ], $LabelStyle, $SubItemStyle, $NewLine);
                } else {
                    $TimeEventTableRows = [];
                    foreach ($Events as $Event) {
                        /* 期間は画面（BuildTimelineEventPeriodText）と同じく「開始 〜 終了」とする */
                        $StartText = trim(($Event['StartDate'] ?? '') . ' ' . ($Event['StartTime'] ?? ''));
                        $EndText = ($Event['EndDate'] ?? '') === ''
                            ? ''
                            : trim($Event['EndDate'] . ' ' . ($Event['EndTime'] ?? ''));
                        $TimeEventTableRows[] = [
                            DisplayValue($Event['Title']),
                            $EndText !== '' ? $StartText . ' 〜 ' . $EndText : $StartText,
                            $Event['Content'],
                            RelationJson::ToDisplayText($Event['CharaText'], $TimeEventCharaNameMap, 'CharaInfo', 'CharaId'),
                            RelationJson::ToDisplayText($Event['StageText'], $TimeEventStageNameMap, 'StageInfo', 'StageId'),
                            RelationJson::ToDisplayText($Event['BuildText'], $TimeEventBuildNameMap, 'BuildInfo', 'BuildId'),
                        ];
                    }
                    AddListTable(
                        $section,
                        ['出来事タイトル', '期間', '内容', '関連キャラ', '関連舞台', '関連建物'],
                        [1500, 1900, 2560, 1200, 1100, 1100],
                        $TimeEventTableRows,
                        $LabelStyle,
                        $SubItemStyle,
                        $NewLine
                    );
                }
                $section->addTextBreak();
            }
        }
        $section->addTextBreak();
    }

    /** ===== 【チャプター一覧】 ===== */
    if ($ShouldExport('Chapters')) {
        $section->addText('【チャプター一覧】', $SectionTitleStyle);
        if (empty($Chapters)) {
            $section->addText('データがありません', $NoDataStyle);
        } else {
            foreach ($Chapters as $Chapter) {
                AddDetailTable($section, [
                    ['チャプター名', DisplayValue($Chapter['ChapterName'])],
                    ['関連年表', $Chapter['TimelineTitle']],
                    ['関連キャラ', $Chapter['CharaNames']],
                    ['概要', $Chapter['Description']],
                ], $LabelStyle, $SubItemStyle, $NewLine);
                $section->addTextBreak();
            }
        }
        $section->addTextBreak();
    }

    /** ===== 【伏線一覧】 ===== */
    if ($ShouldExport('Clues')) {
        $section->addText('【伏線一覧】', $SectionTitleStyle);
        if (empty($Clues)) {
            $section->addText('データがありません', $NoDataStyle);
        } else {
            foreach ($Clues as $Clue) {
                AddDetailTable($section, [
                    ['タイトル', DisplayValue($Clue['Title'])],
                    ['内容', $Clue['Content']],
                    ['回収方法', $Clue['RecoveryStatus']],
                ], $LabelStyle, $SubItemStyle, $NewLine);
                $section->addTextBreak();
            }
        }
        $section->addTextBreak();
    }

    /** ===== 【セリフ一覧】 ===== */
    if ($ShouldExport('Serifs')) {
        $section->addText('【セリフメモ一覧】', $SectionTitleStyle);
        if (empty($Serifs)) {
            $section->addText('データがありません', $NoDataStyle);
        } else {
            foreach ($Serifs as $Serif) {
                AddDetailTable($section, [
                    ['発言者', DisplayValue($Serif['CharaName'])],
                    ['セリフ内容', $Serif['Content']],
                    ['場面', $Serif['Situation']],
                ], $LabelStyle, $SubItemStyle, $NewLine);
                $section->addTextBreak();
            }
        }
        $section->addTextBreak();
    }

    /** ===== 【掛け合い一覧】 ===== */
    if ($ShouldExport('Exchanges')) {
        $section->addText('【掛け合い一覧】', $SectionTitleStyle);
        if (empty($Exchanges)) {
            $section->addText('データがありません', $NoDataStyle);
        } else {
            foreach ($Exchanges as $Exchange) {
                AddDetailTable($section, [
                    ['掛け合い名', DisplayValue($Exchange['ExchangeName'])],
                    ['概要', $Exchange['Description']],
                ], $LabelStyle, $SubItemStyle, $NewLine);
                /* 並び順どおりに「番号／発言者／セリフ／心境」を一覧表で出力する */
                $Rows = $ExchangeRowMap[(string) $Exchange['ExchangeId']] ?? [];
                if (empty($Rows)) {
                    AddDetailTable($section, [
                        ['掛け合い', null],
                    ], $LabelStyle, $SubItemStyle, $NewLine);
                } else {
                    $ExchangeTableRows = [];
                    foreach ($Rows as $RowIndex => $Row) {
                        $ExchangeTableRows[] = [
                            (string) ($RowIndex + 1),
                            DisplayValue($Row['Speaker']),
                            $Row['Content'],
                            $Row['Feeling'],
                        ];
                    }
                    AddListTable(
                        $section,
                        ['No.', '発言者', 'セリフ', '心境'],
                        [760, 1900, 3900, 2800],
                        $ExchangeTableRows,
                        $LabelStyle,
                        $SubItemStyle,
                        $NewLine
                    );
                }
                $section->addTextBreak();
            }
        }
        $section->addTextBreak();
    }

    /** ===== 【作中用語一覧】 ===== */
    if ($ShouldExport('Terms')) {
        $section->addText('【作中用語一覧】', $SectionTitleStyle);
        if (empty($Terms)) {
            $section->addText('データがありません', $NoDataStyle);
        } else {
            foreach ($Terms as $Term) {
                AddDetailTable($section, [
                    ['用語名', DisplayValue($Term['TermName'])],
                    ['説明', $Term['Description']],
                ], $LabelStyle, $SubItemStyle, $NewLine);
                $section->addTextBreak();
            }
        }
        $section->addTextBreak();
    }

    /** ===== 【作品作成用メモ一覧】 ===== */
    if ($ShouldExport('WorkMemos')) {
        $section->addText('【作品作成用メモ一覧】', $SectionTitleStyle);
        if (empty($WorkMemos)) {
            $section->addText('データがありません', $NoDataStyle);
        } else {
            foreach ($WorkMemos as $Memo) {
                AddDetailTable($section, [
                    ['タイトル', DisplayValue($Memo['Title'])],
                    ['内容', $Memo['Content']],
                ], $LabelStyle, $SubItemStyle, $NewLine);
                AddImageList($section, $Memo['ImgList'], $Memo['ImgPath'], $LabelStyle, $SubItemStyle);
                $section->addTextBreak();
            }
        }
        $section->addTextBreak();
    }

    /* ==========================================================
     * ファイル出力
     * ========================================================== */
    // 1. 一時ファイルを作成
    $TempBase = tempnam(sys_get_temp_dir(), 'doc');
    if ($TempBase === false) {
        throw new \RuntimeException('一時ファイルを作成できません。');
    }
    $TempFile = $TempBase . '.docx';

    try {
        // 2. Wordファイルを生成
        $Writer = \PhpOffice\PhpWord\IOFactory::createWriter($phpWord, 'Word2007');
        $Writer->save($TempFile);

        // 3. ファイルを読み込む
        $FileContent = file_get_contents($TempFile);
        if ($FileContent === false) {
            throw new \RuntimeException('生成したWordファイルを読み込めません。');
        }

        // 4. Base64形式に変換
        $Base64Content = base64_encode($FileContent);
    } finally {
        // 5. 一時ファイルを削除
        if (is_file($TempFile)) {
            @unlink($TempFile);
        }
        if (is_file($TempBase)) {
            @unlink($TempBase);
        }
    }

    /* ------------------------------
     * 3. 戻り値返却
     * ------------------------------*/
    // 1. 帳票データを返却
    return [
        'FileData' => $Base64Content,
        'FileName' => $WorkTitle . '_資料.docx',
        'MimeType' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
    ];
}

/**
 * HTML形式の作品資料生成処理
 *
 * @return array{FileData:string,FileName:string,MimeType:string}
 */
function ExportHtml(
    ExportItemData $ExportData,
    array $ExportItems,
    bool $NewLine,
    string $FontName,
    bool $IncludeCover
): array {
    /* ------------------------------
     * 1. 事前処理
     * ------------------------------*/
    // 1. HTMLエスケープ
    $Escape = static fn($Value): string => htmlspecialchars((string)($Value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    // 2. 出力値の整形
    $Value = static function ($Value) use ($Escape, $NewLine): string {
        // Word出力と同じく、表形式項目は行ごとに、空の値は「未設定」として出力する
        $Lines = ToDisplayLines($Value, $NewLine);
        $Text = implode('<br>', array_map($Escape, $Lines));
        return $NewLine ? nl2br($Text, false) : $Text;
    };
    // 3. 出力項目の判定
    $ShouldExport = static fn(string $Key): bool => !$ExportItems || in_array($Key, $ExportItems, true);
    // 4. 詳細表の作成
    $DetailTable = static function (array $Rows) use ($Value, $Escape): string {
        $Html = '<table class="detail"><tbody>';
        foreach ($Rows as [$Label, $Content]) {
            $Html .= '<tr><th scope="row">' . $Escape($Label) . '</th><td>' . $Value($Content) . '</td></tr>';
        }
        return $Html . '</tbody></table>';
    };
    // 5. 一覧表の作成
    $ListTable = static function (array $Headers, array $Rows) use ($Value, $Escape): string {
        $Html = '<div class="listing-scroll" role="region" aria-label="一覧表" tabindex="0"><table class="listing"><thead><tr>';
        foreach ($Headers as $Header) $Html .= '<th scope="col">' . $Escape($Header) . '</th>';
        $Html .= '</tr></thead><tbody>';
        foreach ($Rows as $Row) {
            $Html .= '<tr>';
            foreach ($Row as $Cell) $Html .= '<td>' . $Value($Cell) . '</td>';
            $Html .= '</tr>';
        }
        return $Html . '</tbody></table></div>';
    };
    // 6. 登録画像と説明文を出力（画像はHTMLへ埋め込み、ダウンロード後も表示する）
    $ImageHtml = static function ($ImgList, $ImgPath) use ($Escape): string {
        $Images = ImageList::Parse($ImgList, $ImgPath);
        $UploadsRoot = ReturnImgPath();
        if (empty($Images) || $UploadsRoot === false) return '';
        $UploadsRootNorm = rtrim($UploadsRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $AllowedMimeTypes = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];
        $Html = '';
        foreach ($Images as $Index => $Image) {
            // Word出力と同様、Uploads配下の実ファイルのみを対象にする
            $RealPath = realpath(__DIR__ . '/../../' . ltrim($Image['Path'], '/'));
            if ($RealPath === false || !is_file($RealPath)
                || strncmp($RealPath, $UploadsRootNorm, strlen($UploadsRootNorm)) !== 0) {
                continue;
            }
            $Size = @getimagesize($RealPath);
            if ($Size === false || !in_array($Size['mime'] ?? '', $AllowedMimeTypes, true)) continue;
            $Binary = @file_get_contents($RealPath);
            if ($Binary === false) continue;

            $Src = 'data:' . $Size['mime'] . ';base64,' . base64_encode($Binary);
            $Caption = ($Index === 0 ? '代表画像' : ($Index + 1) . '枚目')
                . ($Image['Caption'] !== '' ? '：' . $Image['Caption'] : '');
            $Html .= '<figure><img src="' . $Src . '" alt="' . $Escape($Caption) . '">'
                . '<figcaption>' . $Escape($Caption) . '</figcaption></figure>';
        }
        return $Html === '' ? '' : '<div class="images">' . $Html . '</div>';
    };
    // 7. 表示名を取得（既存のヘルパーを使用）
    $Display = static fn($V) => DisplayValue($V);
    // 8. 節の生成と目次の収集
    $NavigationItems = [['Id' => 'work', 'Title' => '作品概要', 'Count' => null]];
    $Section = static function (string $Key, string $Title, array $Rows, callable $Render) use ($ShouldExport, $Escape, &$NavigationItems): string {
        if (!$ShouldExport($Key)) return '';
        $Id = 'section-' . $Key;
        $NavigationItems[] = ['Id' => $Id, 'Title' => $Title, 'Count' => count($Rows)];
        $Html = '<section id="' . $Escape($Id) . '" class="document-section">'
            . '<div class="section-heading"><h2>' . $Escape($Title) . '</h2>'
            . '<span class="section-count">' . count($Rows) . '件</span></div>';
        if (!$Rows) return $Html . '<p class="no-data">データがありません</p></section>';
        foreach ($Rows as $Row) $Html .= '<div class="record">' . $Render($Row) . '</div>';
        return $Html . '</section>';
    };
    // 9. 汎用の詳細セクション
    $SimpleSection = static function (string $Key, string $Title, array $Rows, array $Fields, ?array $ImageFields = null) use ($Section, $DetailTable, $ImageHtml, $Display): string {
        return $Section($Key, $Title, $Rows, static function ($Row) use ($Fields, $ImageFields, $DetailTable, $ImageHtml, $Display) {
            $Pairs = [];
            foreach ($Fields as $Label => $Field) {
                $Pairs[] = [$Label, is_callable($Field) ? $Field($Row) : (str_starts_with($Field, '!') ? $Display($Row[substr($Field, 1)] ?? null) : ($Row[$Field] ?? null))];
            }
            $Html = $DetailTable($Pairs);
            if ($ImageFields) $Html .= $ImageHtml($Row[$ImageFields[0]] ?? null, $Row[$ImageFields[1]] ?? null);
            return $Html;
        });
    };

    /* ------------------------------
     * 2. HTML文書の生成
     * ------------------------------*/
    $WorkTitle = $ExportData->WorkInfo->WorkTitle;
    $SafeFont = $Escape($FontName);
    // 外部リソース不要のスタイルで、画面・モバイル・印刷に対応する
    $Styles = <<<'CSS'
        :root{color-scheme:light;--ink:#233047;--muted:#68778e;--accent:#3566ab;--line:#e4eaf2;--paper:#fff;--wash:#f3f6fa}
        *{box-sizing:border-box}
        html{scroll-behavior:smooth;scroll-padding-top:100px}
        body{margin:0;background:var(--wash);color:var(--ink);line-height:1.85;font-size:14px;-webkit-font-smoothing:antialiased}
        a{color:inherit;text-decoration:none}
        a:focus-visible,button:focus-visible{outline:3px solid #8bb8ef;outline-offset:4px}
        .topbar{position:sticky;top:0;z-index:10;background:#ffffffed;border-bottom:1px solid var(--line);backdrop-filter:blur(12px)}
        .topbar-inner{max-width:1440px;margin:auto;padding:16px 40px;display:flex;align-items:center;justify-content:space-between;gap:24px}
        .brand{display:flex;align-items:center;gap:12px;min-width:0}
        .brand-mark{display:grid;place-items:center;flex:none;width:38px;height:38px;border-radius:12px;background:#233b60;color:#fff;font-size:18px}
        .brand-title{font-size:14px;font-weight:700;overflow-wrap:anywhere}
        .brand-subtitle{font-size:10px;letter-spacing:.16em;color:var(--muted)}
        .print-button{flex:none;border:1px solid #ced9e8;border-radius:8px;padding:9px 16px;background:#fff;color:var(--ink);font:inherit;font-size:12px;cursor:pointer}
        .print-button:hover{background:#edf3fb;border-color:#8aa9d0}
        .document-layout{display:grid;grid-template-columns:210px minmax(0,1fr);gap:48px;max-width:1360px;margin:40px auto 0;padding:0 40px}
        .sidebar{align-self:start;position:sticky;top:110px;max-height:calc(100vh - 140px);overflow:auto;padding:6px 12px 12px 0;scrollbar-width:thin}
        .sidebar-label{font-size:10px;font-weight:700;letter-spacing:.2em;color:var(--muted);margin:0 0 16px 12px}
        .contents-nav{display:grid;gap:4px}
        .contents-link{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:9px 12px;border-radius:8px;color:var(--muted);font-size:12px;transition:background .15s,color .15s}
        .contents-link:hover{background:#e6edf7;color:var(--accent)}
        .contents-link span{font-size:10px;font-variant-numeric:tabular-nums;color:#7a8ba3}
        main{min-width:0}
        .cover{position:relative;overflow:hidden;display:flex;flex-direction:column;justify-content:center;min-height:360px;padding:56px;border-radius:20px;background:linear-gradient(125deg,#1c2d49 0%,#2c4870 65%,#3b6188 100%);color:#fff;margin-bottom:48px}
        .cover::after{content:"";position:absolute;width:280px;height:280px;border:1px solid #ffffff16;border-radius:50%;right:-70px;top:-100px;box-shadow:0 0 0 44px #ffffff06,0 0 0 88px #ffffff04;pointer-events:none}
        .cover-eyebrow{font-size:10px;letter-spacing:.28em;color:#b6cbe6;margin:0 0 24px}
        .cover h1{position:relative;z-index:1;font-size:clamp(28px,4vw,44px);font-weight:700;letter-spacing:.035em;line-height:1.5;margin:0 0 20px;overflow-wrap:anywhere}
        .cover-description{color:#c7d7eb;font-size:13px;margin:0}
        .cover-date{display:flex;gap:16px;align-items:center;margin-top:40px;font-size:11px;color:#c7d7eb}
        .cover-date::before{content:"";width:32px;height:1px;background:#8aa9ce}
        .document-section{margin-bottom:44px;scroll-margin-top:100px}
        .section-heading{display:flex;align-items:center;gap:12px;margin:0 0 18px;padding-left:14px;border-left:3px solid var(--accent)}
        .section-heading h2{margin:0;font-size:19px;font-weight:700;letter-spacing:.025em;line-height:1.6}
        .section-count{border:1px solid #dbe5f2;border-radius:20px;padding:1px 9px;font-size:10px;color:var(--accent);background:#ebf1fa}
        .record{margin-bottom:20px;background:var(--paper);border:1px solid var(--line);border-radius:14px;overflow:hidden;box-shadow:0 3px 16px #223b6004}
        table{width:100%;border-collapse:collapse;table-layout:fixed;font-size:13px}
        th,td{padding:14px 22px;text-align:left;vertical-align:top;overflow-wrap:anywhere;border-bottom:1px solid var(--line)}
        tr:last-child>th,tr:last-child>td{border-bottom:0}
        table.detail th{width:24%;background:#fafbfd;color:var(--muted);font-size:11px;font-weight:500}
        table.detail td{line-height:1.95}
        .record>.detail:first-child tr:first-child th{background:#f0f5fc;color:var(--accent)}
        .record>.detail:first-child tr:first-child td{background:#f7faff;font-weight:700;font-size:15px}
        .listing-scroll{overflow-x:auto;border-top:1px solid var(--line)}
        table.listing{min-width:640px;font-size:12px}
        table.listing thead th{background:#edf3fa;color:#526883;font-size:11px;font-weight:600;border-bottom:1px solid #dce5f1}
        table.listing tbody tr:nth-child(even){background:#fafbfd}
        table.listing tbody tr:hover{background:#f2f6fc}
        .record>.detail+.detail{border-top:1px solid var(--line)}
        .no-data{margin:0;padding:26px;border:1px dashed #d2dce9;border-radius:12px;color:var(--muted);background:#f8fafd;text-align:center;font-size:12px}
        .images{display:flex;flex-wrap:wrap;gap:18px;padding:24px;border-top:1px solid var(--line);background:#fcfdff}
        .images figure{margin:0;max-width:280px;flex:0 1 280px;min-width:0}
        .images img{display:block;width:100%;height:220px;object-fit:contain;padding:12px;background:#f2f5f9;border:1px solid var(--line);border-radius:10px}
        .images figcaption{margin-top:10px;color:var(--muted);font-size:11px;line-height:1.7;overflow-wrap:anywhere}
        .document-footer{max-width:1280px;margin:16px auto 0;padding:24px 40px 36px;border-top:1px solid var(--line);display:flex;justify-content:space-between;gap:20px;color:var(--muted);font-size:11px}
        .document-footer a{color:var(--accent)}
        @media(max-width:1000px){
        .document-layout{grid-template-columns:minmax(0,1fr);gap:24px;margin-top:20px;padding:0 24px}
        .sidebar{position:static;max-height:none;padding:0;overflow:visible}
        .sidebar-label{margin:0 0 8px;font-size:9px}
        .contents-nav{display:flex;overflow-x:auto;padding:0 0 8px;gap:6px}
        .contents-link{flex:none;white-space:nowrap;border:1px solid var(--line);background:#fff;padding:6px 12px}
        .topbar-inner{padding:14px 24px}.cover{padding:40px;min-height:300px}
        }
        @media(max-width:600px){
        body{font-size:13px}.topbar-inner{padding:12px 16px;gap:12px}.brand-mark{width:30px;height:30px;font-size:14px;border-radius:9px}
        .brand-title{font-size:12px}.brand-subtitle{font-size:8px}.print-button{padding:7px 9px;font-size:10px}
        .document-layout{padding:0 16px}.cover{padding:32px 24px;min-height:280px;border-radius:14px;margin-bottom:32px}
        .section-heading h2{font-size:17px}.document-section{margin-bottom:32px}
        th,td{padding:12px 14px}table.detail th{width:30%;font-size:10px}table{font-size:12px}
        .images{padding:16px;gap:16px}.images figure{flex-basis:100%;max-width:none}
        .document-footer{padding:20px 16px;flex-wrap:wrap}
        }
        @media(prefers-reduced-motion:reduce){html{scroll-behavior:auto}.contents-link{transition:none}}
        @page{size:A4;margin:16mm}
        @media print{
        html{scroll-behavior:auto}body{background:#fff;color:#18212f;font-size:10pt}
        .topbar,.sidebar,.document-footer{display:none}
        .document-layout{display:block;max-width:none;margin:0;padding:0}
        .cover{
            min-height:245mm;
            margin:0;
            padding:16mm;
            border:1px solid #d3dce8;
            border-radius:0;
            background:#fff;
            color:#18212f;
            break-after:page;
            page-break-after:always
        }
        .cover::after{
            display:none
        }
        .cover h1{
            font-size:30pt
        }.cover-eyebrow,.cover-description,.cover-date{color:#526883}
        .document-section{margin-bottom:9mm;scroll-margin-top:0}.section-heading{break-after:avoid;page-break-after:avoid}.section-heading h2{font-size:15pt}
        .record{border-radius:0;box-shadow:none;overflow:visible;break-inside:auto}
        th,td{padding:7px 10px}table{font-size:9pt}.record>.detail:first-child tr:first-child td{font-size:11pt}
        .listing-scroll{overflow:visible}table.listing{min-width:0;font-size:8pt}
        table.listing th,table.listing td{padding:6px}thead{display:table-header-group}tr{break-inside:avoid;page-break-inside:avoid}
        .images{padding:12px;background:#fff}.images figure{break-inside:avoid;max-width:45%;flex-basis:45%}.images img{height:160px}
        }
        CSS;
    $DocumentStart = '<!DOCTYPE html><html lang="ja"><head><meta charset="UTF-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . $Escape($WorkTitle) . ' - 作品資料</title>'
        . '<style>body{font-family:"' . $SafeFont . '","Yu Gothic",Meiryo,sans-serif}' . $Styles . '</style>'
        . '</head><body id="top">';
    $Html = '';

    if ($IncludeCover) {
        $Html .= '<header class="cover"><p class="cover-eyebrow">作品資料</p>'
            . '<h1>' . $Escape($WorkTitle) . '</h1>'
            . '<div class="cover-date">作成日 ' . $Escape(date('Y年m月d日')) . '</div></header>';
    }


    // 1. 作品情報（元のWord出力と同様、常に出力）
    $Html .= '<section id="work" class="document-section"><div class="section-heading"><h2>作品概要</h2></div><div class="record">' . $DetailTable([
        ['作品名', $Display($WorkTitle)],
        ['ストーリー開始時の年月日', $ExportData->WorkInfo->SettingDate],
        ['テーマ', $ExportData->WorkInfo->Theme],
        ['概要', $ExportData->WorkInfo->Description],
    ]) . '</div></section>';

    // 2. キャラクター一覧
    $PowerNames = $ExportData->AbilityNameMaps['PowerNameById'] ?? [];
    $WeaponNames = $ExportData->AbilityNameMaps['WeaponNameById'] ?? [];
    $MoveNames = $ExportData->AbilityNameMaps['MoveNameById'] ?? [];
    $Html .= $Section('Characters', 'キャラ一覧', $ExportData->CharaInfo, static function ($C) use ($DetailTable, $ImageHtml, $Display, $PowerNames, $WeaponNames, $MoveNames) {
        return $DetailTable([
            ['キャラ名', $Display($C['CharaName'])], ['フリガナ', $C['Furigana']], ['ニックネーム', $C['NickName']],
            ['二つ名', $C['Alias']], ['性別', CodeLabel::Get('Gender', $C['Gender'])],
            ['生年月日', $C['BirthDate']], ['没年月日', $C['DeathDate']], ['死因', $C['CauseOfDeath']],
            ['年齢', $C['Age']], ['身長', $C['Height']], ['体重', $C['Weight']],
            ['血液型', CodeLabel::Get('BloodType', $C['BloodType'])], ['種族', $C['RaceName']],
            ['タグ', $C['TagNames']],
            ['能力', FormatSkillNames('Powers', 'PowerId', $C['Powers'], $PowerNames)],
            ['武器', FormatSkillNames('Weapons', 'WeaponId', $C['Weapons'], $WeaponNames)],
            ['技', FormatSkillNames('Moves', 'MoveId', $C['Moves'], $MoveNames)],
            ['属するチーム', $C['TeamName']], ['階級', $C['ClassName']], ['属する組織', $C['OrgName']],
            ['好き', EntryTable::FormatLines('Character', 'Likes', $C['Likes'])],
            ['嫌い', EntryTable::FormatLines('Character', 'Dislikes', $C['Dislikes'])],
            ['地雷', EntryTable::FormatLines('Character', 'Landmine', $C['Landmine'])],
            ['長所', EntryTable::FormatLines('Character', 'StrongPoint', $C['StrongPoint'])],
            ['短所', EntryTable::FormatLines('Character', 'WeakPoint', $C['WeakPoint'])],
            ['役割', $C['RoleInfo']], ['癖', EntryTable::FormatLines('Character', 'Habit', $C['Habit'])],
            ['口癖', $C['VerbalTic']], ['趣味', EntryTable::FormatLines('Character', 'Hobby', $C['Hobby'])],
            ['性格', $C['Personality']], ['出身地', $C['BirthplaceName']],
            ['生い立ち', $C['Biography']], ['目的', $C['Purpose']], ['思想・信念', $C['Belief']],
            ['一人称', EntryTable::FormatLines('Character', 'FirstPerson', $C['FirstPerson'])],
            ['二人称', EntryTable::FormatLines('Character', 'SecondPerson', $C['SecondPerson'])],
            ['モデル', $C['Model']], ['身体備考', $C['AppearanceRemarks']],
            ['変遷', EntryTable::FormatLines('Character', 'Transition', $C['Transition'])],
            ['検討中・未確定の設定', $C['PendingSetting']], ['備考', $C['Note']],
        ]) . $ImageHtml($C['ImgList'] ?? null, $C['ImgPath'] ?? null);
    });

    // 3. 経歴一覧（キャラ単位でグループ化）
    $Biographies = [];
    foreach ($ExportData->BiographyInfo as $Bio) $Biographies[(string)$Bio['CharaId']][] = $Bio;
    $Html .= $Section('Biographies', '経歴一覧', $Biographies, static function ($Rows) use ($DetailTable, $ListTable, $Display) {
        $TableRows = [];
        foreach ($Rows as $B) $TableRows[] = [$Display($B['EventDate']), $Display($B['Age']), $B['Content']];
        return $DetailTable([['キャラ名', $Display($Rows[0]['CharaName'])]])
            . $ListTable(['日付', '年齢', '内容'], $TableRows);
    });

    // 4〜13. 各種マスタ
    $Html .= $SimpleSection('Calendars', '元号一覧', $ExportData->CalendarInfo, ['元号名'=>'!EraName','開始年'=>'StartYear','終了年'=>'EndYear','概要'=>'Description']);
    $Html .= $SimpleSection('Buildings', '建物一覧', $ExportData->BuildInfo, ['建物名'=>'!BuildName','建設日'=>'BuildDate','高さ'=>'Height','面積'=>'Area','概要'=>'Description'], ['ImgList','ImgPath']);
    $Html .= $SimpleSection('Stages', '舞台一覧', $ExportData->StageInfo, ['舞台名'=>'!StageName','舞台区分'=>static fn($R)=>CodeLabel::Get('StageType',$R['StageType']),'上位舞台'=>'ParentPath','人口'=>'Population','面積'=>'AreaSize','概要'=>'Description'], ['ImgList','ImgPath']);
    $Html .= $SimpleSection('Organizations', '組織一覧', $ExportData->OrgInfo, ['組織名'=>'!OrgName','リーダー'=>'CharaName','設立日'=>'FoundedDate','人数'=>'PeopleNum','目的'=>'Purpose','活動内容'=>'Activity','本拠地'=>'BuildName','備考'=>'Note'], ['ImgList','ImgPath']);
    $Html .= $SimpleSection('Classes', '階級一覧', $ExportData->ClassInfo, ['階級名'=>'!ClassName','所属組織'=>'OrgName','順位'=>'RankOrder','概要'=>'Description']);
    $Html .= $SimpleSection('Teams', 'チーム一覧', $ExportData->TeamInfo, ['チーム名'=>'!TeamName','リーダー'=>'CharaName','設立日'=>'FoundedDate','人数'=>'PeopleNum','所属組織'=>'OrgName','概要'=>'Description'], ['ImgList','ImgPath']);
    $Html .= $SimpleSection('Races', '種族一覧', $ExportData->RaceInfo, ['種族名'=>'!RaceName','概要'=>'Description','身体特徴'=>static fn($R)=>EntryTable::FormatLines('Race','PhysicalFeature',$R['PhysicalFeature'])]);
    $Tags = $ExportData->TagInfo ?? $ExportData->TagMasterInfo ?? [];
    $Html .= $SimpleSection('Tags', 'タグ一覧', $Tags, ['タグ名'=>'!TagName','備考'=>'Description']);
    $Html .= $SimpleSection('Powers', '能力一覧', $ExportData->PowerInfo, ['能力名'=>'!PowerName','概要'=>'Description','発動条件'=>static fn($R)=>EntryTable::FormatLines('Power','Conditions',$R['Conditions']),'代償・副作用'=>static fn($R)=>EntryTable::FormatLines('Power','Risk',$R['Risk'])]);
    $Html .= $SimpleSection('Weapons', '武器一覧', $ExportData->WeaponInfo, ['武器名'=>'!WeaponName','概要'=>'Description'], ['ImgList','ImgPath']);
    $Html .= $SimpleSection('Items', 'アイテム一覧', $ExportData->ItemInfo, ['道具名'=>'!ItemName','概要'=>'Description'], ['ImgList','ImgPath']);
    $Html .= $SimpleSection('Moves', '技一覧', $ExportData->MoveInfo, ['技名'=>'!MoveName','関連能力'=>'PowerName','概要'=>'Description','発動条件'=>static fn($R)=>EntryTable::FormatLines('Move','Conditions',$R['Conditions']),'代償・副作用'=>static fn($R)=>EntryTable::FormatLines('Move','Risk',$R['Risk'])]);

    // 14. 年表一覧（詳細情報と関連名称解決も出力）
    $CharaMap = $ExportData->TimelineEventNameMaps['CharaNameMap'] ?? [];
    $StageMap = $ExportData->TimelineEventNameMaps['StageNameMap'] ?? [];
    $BuildMap = $ExportData->TimelineEventNameMaps['BuildNameMap'] ?? [];
    $Html .= $Section('Timelines', '年表一覧', $ExportData->TimelineInfo, static function ($Time) use ($DetailTable, $ListTable, $ExportData, $Display, $CharaMap, $StageMap, $BuildMap) {
        $Html = $DetailTable([
            ['出来事タイトル', $Display($Time['Title'])], ['元号', $Time['EraName']],
            ['開始日', $Time['EventDate']], ['終了日', $Time['EndDate']], ['内容', $Time['Content']]
        ]);
        $Events = $ExportData->TimelineEventInfo[(string)$Time['TimeId']] ?? [];
        if (!$Events) return $Html . $DetailTable([['年表詳細', null]]);
        $Rows = [];
        foreach ($Events as $Event) {
            $Start = trim(($Event['StartDate'] ?? '') . ' ' . ($Event['StartTime'] ?? ''));
            $End = ($Event['EndDate'] ?? '') === '' ? '' : trim($Event['EndDate'] . ' ' . ($Event['EndTime'] ?? ''));
            $Rows[] = [
                $Display($Event['Title']), $End !== '' ? $Start . ' 〜 ' . $End : $Start,
                $Event['Content'],
                RelationJson::ToDisplayText($Event['CharaText'], $CharaMap, 'CharaInfo', 'CharaId'),
                RelationJson::ToDisplayText($Event['StageText'], $StageMap, 'StageInfo', 'StageId'),
                RelationJson::ToDisplayText($Event['BuildText'], $BuildMap, 'BuildInfo', 'BuildId')
            ];
        }
        return $Html . $ListTable(['出来事タイトル','期間','内容','関連キャラ','関連舞台','関連建物'], $Rows);
    });

    // 15〜18. チャプター・伏線・セリフ・掛け合い
    $Html .= $SimpleSection('Chapters', 'チャプター一覧', $ExportData->ChapterInfo, ['チャプター名'=>'!ChapterName','関連年表'=>'TimelineTitle','関連キャラ'=>'CharaNames','概要'=>'Description']);
    $Html .= $SimpleSection('Clues', '伏線一覧', $ExportData->ClueInfo, ['タイトル'=>'!Title','内容'=>'Content','回収方法'=>'RecoveryStatus']);
    $Html .= $SimpleSection('Serifs', 'セリフメモ一覧', $ExportData->SerifInfo, ['発言者'=>'!CharaName','セリフ内容'=>'Content','場面'=>'Situation']);
    $Html .= $Section('Exchanges', '掛け合い一覧', $ExportData->ExchangeInfo, static function ($Exchange) use ($DetailTable, $ListTable, $ExportData, $Display) {
        $Html = $DetailTable([['掛け合い名', $Display($Exchange['ExchangeName'])], ['概要', $Exchange['Description']]]);
        $Rows = $ExportData->ExchangeRowInfo[(string)$Exchange['ExchangeId']] ?? [];
        if (!$Rows) return $Html . $DetailTable([['掛け合い', null]]);
        $TableRows = [];
        foreach ($Rows as $I => $R) $TableRows[] = [(string)($I + 1), $Display($R['Speaker']), $R['Content'], $R['Feeling']];
        return $Html . $ListTable(['No.','発言者','セリフ','心境'], $TableRows);
    });

    // 19〜20. 作中用語・作品作成用メモ
    $Html .= $SimpleSection('Terms', '作中用語一覧', $ExportData->TermInfo, ['用語名'=>'!TermName','説明'=>'Description']);
    $Html .= $SimpleSection('WorkMemos', '作品作成用メモ一覧', $ExportData->WorkMemoInfo, ['タイトル'=>'!Title','内容'=>'Content'], ['ImgList','ImgPath']);
    // 選択された節だけを目次に表示し、オフラインでもページ内を移動できるようにする
    $Navigation = '<aside class="sidebar"><p class="sidebar-label">目次</p><nav class="contents-nav" aria-label="資料の目次">';
    foreach ($NavigationItems as $Item) {
        $Navigation .= '<a class="contents-link" href="#' . $Escape($Item['Id']) . '">'
            . $Escape($Item['Title'])
            . ($Item['Count'] !== null ? '<span>' . $Item['Count'] . '</span>' : '') . '</a>';
    }
    $Navigation .= '</nav></aside>';
    $Html = $DocumentStart
        . '<div class="topbar"><div class="topbar-inner"><a class="brand" href="#top">'
        . '<div><div class="brand-title">' . $Escape($WorkTitle) . '</div>'
        . '</div></a>'
        . '</div></div>'
        . '<div class="document-layout">' . $Navigation . '<main id="document-content">' . $Html . '</main></div>'
        . '</body></html>';

    /* ------------------------------
     * 3. 戻り値返却
     * ------------------------------*/
    // 1. HTMLをBase64エンコードし、呼び出し元に返却
    return [
        'FileData' => base64_encode($Html),
        'FileName' => $WorkTitle . '_資料.html',
        'MimeType' => 'text/html; charset=UTF-8'
    ];
}

/* ==========================================================
 * 10. 処理部
 * ========================================================== */
try {
    /* ------------------------------
     * 1. POSTメソッド以外の場合
     * ------------------------------*/
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        // 1. エラーレスポンスを返却
        Response::Error('不正なリクエストです', 405);
    }

    /* ------------------------------
     * 2. リクエストの取得
     * ------------------------------*/
    // 1. リクエストを取得
    $Input = Response::GetJsonInput();
    // 2. 作品ID（作品一覧画面から呼び出されるためセッションではなくリクエストの作品Idを使用）
    $WorkId = $Input['WorkId'] ?? '';
    // 3. ユーザーID
    $UserId = $AuthUserId;
    // 4. 出力項目
    $ExportItems = $Input['ExportItems'] ?? [];
    // 5. 改行反映FLG
    $NewLine = $Input['NewLine'] ?? false;
    // 6. 指定のソート順
    $SortOrder = $Input['SortOrder'] ?? SORT_REQUEST_DEFAULT;
    // 7. 指定のフォント
    $FontName = $Input['Font'] ?? DEFAULT_FONT;
    // 8. 表紙の有無FLG
    $IncludeCover = $Input['Cover'] ?? true;
    // 9. 出力形式
    $ExportType = $Input['ExportType'] ?? 'word';

    /* ------------------------------
     * 3. 事前処理
     * ------------------------------*/
    // 1. ソート順の定義
    $OrderDirection = ($SortOrder === 'desc') ? 'DESC' : 'ASC';
    // 2. 開始LOG
    Logger::Info($FunctionName, '作品資料出力処理開始', $UserId);

    /* ------------------------------
     * 4. 作品IDが存在しない場合
     * ------------------------------*/
    if (empty($WorkId)) {
        // 1. エラーレスポンスを返却
        Response::Error('作品IDが指定されていません');
    }

    /* ------------------------------
     * 5. DB接続
     * ------------------------------*/
    // 1. DB接続を取得
    $Pdo = Database::GetConnection();

    /* ------------------------------
     * 6. 権限チェック
     * ------------------------------*/
    /* 1. 出力可能ユーザーに該当するレコードを取得 */
    // 1. クエリを定義
    $PermStmt = $Pdo->prepare('
        SELECT 1
        FROM CreaterList c
        INNER JOIN WorkInfo w ON w.workId = c.workId
        WHERE c.workId = :workId
          AND c.userId = :userId
          AND c.userLockFlg = FALSE
          AND w.projectLockFlg = FALSE
    ');
    // 2. クエリを実行
    $PermStmt->execute([':workId' => $WorkId, ':userId' => $UserId]);

    /* 2. 結果が存在しない・管理者でない場合 */
    if (!$PermStmt->fetch() && !$IsAuthAdmin) {
        // 1. エラーレスポンスを返却
        Response::Error('この作品にアクセスする権限がありません', 403);
    }
    
    /* ------------------------------
     * 7. 作品情報の取得
     * ------------------------------*/
    // 1. DTOを生成
    $ExportData = new ExportItemData();
    // 2. 作品情報を取得
    $ExportData->WorkInfo = GetWorkInfo($Pdo, $WorkId);
    // 3. キャラ情報を取得
    $ExportData->CharaInfo = GetCharaInfo($Pdo, $WorkId, $OrderDirection);
    // 4. 経歴情報を取得
    $ExportData->BiographyInfo = GetBiographyInfo($Pdo, $WorkId, $OrderDirection);
    // 5. 元号情報を取得
    $ExportData->CalendarInfo = GetCalendarInfo($Pdo, $WorkId, $OrderDirection);
    // 6. 建物情報を取得
    $ExportData->BuildInfo = GetBuildInfo($Pdo, $WorkId, $OrderDirection);
    // 7. 舞台情報を取得
    $ExportData->StageInfo = GetStageInfo($Pdo, $WorkId, $OrderDirection);
    // 8. 組織情報を取得
    $ExportData->OrgInfo = GetOrgInfo($Pdo, $WorkId, $OrderDirection);
    // 9. 階級情報を取得
    $ExportData->ClassInfo = GetClassInfo($Pdo, $WorkId, $OrderDirection);
    //10. チーム情報を取得
    $ExportData->TeamInfo = GetTeamInfo($Pdo, $WorkId, $OrderDirection);
    //11. 種族情報を取得
    $ExportData->RaceInfo = GetRaceInfo($Pdo, $WorkId, $OrderDirection);
    //12. タグマスタ情報を取得
    $ExportData->TagInfo = GetTagMasterInfo($Pdo, $WorkId, $OrderDirection);
    //13. 能力情報を取得
    $ExportData->PowerInfo = GetPowerInfo($Pdo, $WorkId, $OrderDirection);
    //14. 武器情報を取得
    $ExportData->WeaponInfo = GetWeaponInfo($Pdo, $WorkId, $OrderDirection);
    //15. 技情報を取得
    $ExportData->MoveInfo = GetMoveInfo($Pdo, $WorkId, $OrderDirection);
    //16. 能力・武器・技の名称対応表を取得
    $ExportData->AbilityNameMaps = GetAbilityNameMaps(
        $ExportData->PowerInfo,
        $ExportData->WeaponInfo,
        $ExportData->MoveInfo
    );
    //17. 年表情報を取得
    $ExportData->TimelineInfo = GetTimelineInfo($Pdo, $WorkId, $OrderDirection);
    //18. 年表詳細情報を取得
    $TimelineEventData = GetTimelineEventInfo($Pdo, $WorkId);
    //19. 年表詳細一覧を保持
    $ExportData->TimelineEventInfo = $TimelineEventData['TimeEventMap'];
    //20. 年表詳細の名称対応表を保持
    $ExportData->TimelineEventNameMaps = [
        'CharaNameMap' => $TimelineEventData['CharaNameMap'],
        'StageNameMap' => $TimelineEventData['StageNameMap'],
        'BuildNameMap' => $TimelineEventData['BuildNameMap']
    ];
    //21. チャプター情報を取得
    $ExportData->ChapterInfo = GetChapterInfo($Pdo, $WorkId, $OrderDirection);
    //22. 伏線情報を取得
    $ExportData->ClueInfo = GetClueInfo($Pdo, $WorkId, $OrderDirection);
    //23. 作中用語情報を取得
    $ExportData->TermInfo = GetTermInfo($Pdo, $WorkId, $OrderDirection);
    //24. セリフ情報を取得
    $ExportData->SerifInfo = GetSerifInfo($Pdo, $WorkId, $OrderDirection);
    //25. 掛け合い情報を取得
    $ExportData->ExchangeInfo = GetExchangeInfo($Pdo, $WorkId, $OrderDirection);
    //26. 掛け合い行情報を取得
    $ExportData->ExchangeRowInfo = GetExchangeRowInfo($Pdo, $WorkId);
    //27. アイテム情報を取得
    $ExportData->ItemInfo = GetItemInfo($Pdo, $WorkId, $OrderDirection);
    //28. 作品作成用メモ情報を取得
    $ExportData->WorkMemoInfo = GetWorkMemoInfo($Pdo, $WorkId, $OrderDirection);

    /* ------------------------------
     * 8. 帳票出力処理
     * ------------------------------*/
    /* 出力形式に応じた処理 */
    switch ($ExportType) {
        /* Word出力の場合 */
        case 'word':
            // 1. Word帳票を生成
            $Result = ExportWordDocument(
                $ExportData,
                $ExportItems,
                $NewLine,
                $FontName,
                $IncludeCover
            );
            break;
        /* HTML出力の場合 */
        case 'html':
            // 1. HTML帳票を生成
            $Result = ExportHtml(
                $ExportData,
                $ExportItems,
                $NewLine,
                $FontName,
                $IncludeCover
            );
            break;

        /* 未対応の出力形式の場合 */
        default:
            // 1. 例外を発生
            throw new InvalidArgumentException('未対応の出力形式です');
    }

    /* ------------------------------
     * 9. 終了処理
     * ------------------------------*/
    // 1. 終了LOG
    Logger::Info($FunctionName, '作品資料出力処理終了', $UserId);

    // 2. レスポンスを返却
    Response::Success($Result);

} catch (Exception $E) {
    // 1. エラーLOG
    Logger::Error($FunctionName, '作品資料出力処理エラー', $UserId, $E->getMessage());
    // 2. エラーレスポンスを返却
    Response::Error('作品資料の出力に失敗しました');
}
