<?php
/**
 * ExportWbsExcel.php
 * WBS Excel出力API
 *
 * 選択中のWBSのタスクを1シートのExcelとして出力する。
 * 出力方式は既存のWord出力（ExportPlot.php / ExportDocument.php）と同一で、
 * 一時ファイルへ書き出したうえで Base64 に変換して返却し、フロントでダウンロードする。
 *
 * レイアウトは画面のテーブルに合わせ、左に一覧列、右に日付軸のガントチャートを持つ。
 *  ・一覧列は画面と同じ タスク名／担当者／開始予定日／終了予定日／進捗率／状態 の6列とする
 *  ・日付軸の単位（月／週／日）は画面で選択中の表示単位をそのまま使用する
 *  ・ヘッダは画面と同じ2段（上段＝年月／年、下段＝日／週／月）とし、上段はセル結合で表す
 *  ・バーはタスクの期間に該当するセルの塗りつぶしで表す（通常=青／遅延=赤／完了=緑）
 *  ・土日・祝日・当日の列にも画面と同じ考え方で背景色を付ける
 *
 * 参照権限があれば全権限で出力可能（作品への参加可否は Auth.php で検証済み）。
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../Common/Database.php';
require_once __DIR__ . '/../Common/Logger.php';
require_once __DIR__ . '/../Common/Response.php';
require_once __DIR__ . '/../Common/Auth.php';
require_once __DIR__ . '/../Common/JapaneseHoliday.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

$FunctionName = 'WBS Excel出力';
$UserId = '';

/**
 * ガントチャートは日付列 × タスク件数の分だけセルのスタイルを保持するため、
 * 既定の memory_limit（128M）では上限付近の帳票を生成できない。
 * 本APIに限り引き上げる（超過分はセル数の上限で抑える）。
 */
ini_set('memory_limit', '512M');

/**
 * 表示単位ごとの出力設定
 * PaddingDays … チャートの前後に確保する余白の日数（画面の WBS_VIEW_MODES と揃える）
 * ColumnWidth … 日付列1列あたりの幅
 */
const WBS_EXPORT_VIEW_MODES = [
    'Day'   => ['PaddingDays' => 3,  'ColumnWidth' => 3.5],
    'Week'  => ['PaddingDays' => 14, 'ColumnWidth' => 6.0],
    'Month' => ['PaddingDays' => 45, 'ColumnWidth' => 8.0],
];

/**
 * 日付列の上限
 * Excelの列上限（16,384）には遠く及ばないが、これを超えると生成時間とメモリが
 * 跳ね上がり、開いた側のExcelも実用に耐えないため、実用上の上限として設ける。
 */
const WBS_EXPORT_MAX_CHART_COLUMNS = 1000;

/**
 * チャート部分のセル数の上限（日付列 × タスク件数）
 * 罫線・塗りつぶしはセル単位のスタイルとして保持されるため、メモリの消費量は
 * 列数ではなくセル数に比例する。実測では 20万セルでピーク約150MBとなるため、
 * 引き上げ後の memory_limit（512M）に収まる範囲として設定する。
 */
const WBS_EXPORT_MAX_CHART_CELLS = 350000;

/** 一覧列（左側）の列数 */
const WBS_EXPORT_FIXED_COLUMN_COUNT = 6;

/** 配色（画面のCSSと揃える） */
const WBS_EXPORT_COLORS = [
    'Header'   => 'FFDDEBF7',
    'BarNormal'=> 'FF3498DB',
    'BarDelay' => 'FFE74C3C',
    'BarDone'  => 'FF27AE60',
    'Today'    => 'FFFFF9C4',
    'Holiday'  => 'FFE3E9F1',
];

/**
 * チャートの日付軸（目盛り）を組み立てる
 *
 * 画面と同様に、範囲の前後を表示単位の区切りへ丸めることで、
 * 週表示は必ず月曜始まり、月表示は必ず月初始まりとなり列幅が揃う。
 *
 * @param string $ViewMode 表示単位（Day / Week / Month）
 * @param DateTimeImmutable $MinDate タスクの最も早い開始予定日
 * @param DateTimeImmutable $MaxDate タスクの最も遅い終了予定日
 * @param DateTimeImmutable $Today 本日
 * @return array 目盛りの配列（Start / End / Label / GroupKey / GroupLabel / IsWeekend / IsHoliday / IsToday）
 */
function BuildWbsExportTicks(string $ViewMode, DateTimeImmutable $MinDate, DateTimeImmutable $MaxDate, DateTimeImmutable $Today): array
{
    $Padding = WBS_EXPORT_VIEW_MODES[$ViewMode]['PaddingDays'];
    $Start = $MinDate->modify("-{$Padding} day");
    $End = $MaxDate->modify("+{$Padding} day");

    /** 範囲の前後を表示単位の区切りへ丸める */
    if ($ViewMode === 'Week') {
        // 週の区切りは月曜。日曜(w=0)は6日戻す
        $Start = $Start->modify('-' . (((int) $Start->format('w') + 6) % 7) . ' day');
        $End = $End->modify('+' . ((7 - (int) $End->format('w')) % 7) . ' day');
    } elseif ($ViewMode === 'Month') {
        $Start = $Start->modify('first day of this month');
        $End = $End->modify('last day of this month');
    }

    $Ticks = [];
    $Current = $Start;
    while ($Current <= $End) {
        if ($ViewMode === 'Day') {
            $TickEnd = $Current;
            $Label = $Current->format('j');
            $Next = $Current->modify('+1 day');
        } elseif ($ViewMode === 'Week') {
            $TickEnd = $Current->modify('+6 day');
            $Label = $Current->format('n/j');
            $Next = $Current->modify('+7 day');
        } else {
            $TickEnd = $Current->modify('last day of this month');
            $Label = $Current->format('n') . '月';
            $Next = $Current->modify('first day of next month');
        }

        $DayOfWeek = (int) $Current->format('w');
        $Ticks[] = [
            'Start'      => $Current,
            'End'        => $TickEnd,
            'Label'      => $Label,
            // 上段は月表示のみ年単位、それ以外は年月単位で区切る
            'GroupKey'   => $ViewMode === 'Month' ? $Current->format('Y') : $Current->format('Y-m'),
            'GroupLabel' => $ViewMode === 'Month'
                ? $Current->format('Y') . '年'
                : $Current->format('Y') . '年' . $Current->format('n') . '月',
            // 土日・祝日は1目盛り＝1日となる日表示でのみ成立する
            'IsWeekend'  => $ViewMode === 'Day' && ($DayOfWeek === 0 || $DayOfWeek === 6),
            'IsHoliday'  => $ViewMode === 'Day' && JapaneseHoliday::IsHoliday($Current),
            'IsToday'    => $Today >= $Current && $Today <= $TickEnd,
        ];
        $Current = $Next;
    }
    return $Ticks;
}

/**
 * 日付軸ヘッダーの上段（年月／年）を、対象範囲のセルを結合して書き込む
 * @param \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $Sheet 対象シート
 * @param int $FromColumnIndex 開始列の番号（1始まり）
 * @param int $ToColumnIndex 終了列の番号（1始まり）
 * @param string $Label 表示するラベル
 * @return void
 */
function WriteWbsExportGroupLabel($Sheet, int $FromColumnIndex, int $ToColumnIndex, string $Label): void
{
    $From = Coordinate::stringFromColumnIndex($FromColumnIndex);
    $To = Coordinate::stringFromColumnIndex($ToColumnIndex);
    $Sheet->setCellValue($From . '1', $Label);
    if ($FromColumnIndex !== $ToColumnIndex) {
        $Sheet->mergeCells($From . '1:' . $To . '1');
    }
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        Response::Error('不正なリクエストです', 405);
    }

    /** リクエストデータを取得 */
    $Input = Response::GetJsonInput();
    $WorkId = $AuthWorkId ?? '';
    $UserId = $AuthUserId;
    /** 出力対象のWBS（画面で選択中のWBSに限定して出力する） */
    $WbsId = $Input['WbsId'] ?? null;
    /** 日付軸の単位（画面で選択中の表示単位。不正な値は月として扱う） */
    $ViewMode = $Input['ViewMode'] ?? 'Month';
    if (!isset(WBS_EXPORT_VIEW_MODES[$ViewMode])) {
        $ViewMode = 'Month';
    }

    Logger::Info($FunctionName, 'Excel出力処理開始', $UserId);

    /** 入力チェック */
    if (empty($WorkId)) {
        Response::Error('作品IDが指定されていません');
    }
    if (empty($WbsId)) {
        Response::Error('WBSが指定されていません');
    }

    /** ライブラリ未導入時は原因が分かるメッセージを返す */
    if (!class_exists(Spreadsheet::class)) {
        Logger::Error($FunctionName, 'PhpSpreadsheet未導入', $UserId, '');
        Response::Error('Excel出力ライブラリ（PhpSpreadsheet）が導入されていません', 500);
    }

    /** DB接続 */
    $Pdo = Database::GetConnection();

    /** 作品名を取得（ファイル名・見出しに使用） */
    $WorkStmt = $Pdo->prepare('SELECT workTitle FROM WorkInfo WHERE workId = :workId');
    $WorkStmt->execute([':workId' => $WorkId]);
    $WorkRow = $WorkStmt->fetch();
    $WorkTitle = $WorkRow ? $WorkRow['worktitle'] : '作品';

    /** 出力対象のWBSを取得（ファイル名に使用。他作品のIDが指定された場合はここで弾く） */
    $WbsStmt = $Pdo->prepare('SELECT wbsName FROM WbsInfo WHERE wbsId = :wbsId AND workId = :workId');
    $WbsStmt->execute([':wbsId' => $WbsId, ':workId' => $WorkId]);
    $WbsRow = $WbsStmt->fetch();
    if (!$WbsRow) {
        Response::Error('出力対象のWBSが見つかりません');
    }
    $WbsName = $WbsRow['wbsname'];

    /** タスク一覧を取得（並び順は GetWbsTaskList.php と同一） */
    $Sql = "
        SELECT
            w.taskId         AS \"TaskId\",
            w.parentTaskId   AS \"ParentTaskId\",
            w.taskName       AS \"TaskName\",
            (SELECT string_agg(a.userId, '、' ORDER BY a.userId)
               FROM WbsTaskAssignee a
              WHERE a.taskId = w.taskId) AS \"Assignees\",
            to_char(w.startDate, 'YYYY-MM-DD') AS \"StartDate\",
            to_char(w.endDate,   'YYYY-MM-DD') AS \"EndDate\",
            w.progress       AS \"Progress\",
            w.isDelayed      AS \"IsDelayed\"
        FROM v_wbstask_detail w
        LEFT JOIN WbsTaskInfo p ON w.parentTaskId = p.taskId
        WHERE w.workId = :workId
          AND w.wbsId = :wbsId
        ORDER BY
            COALESCE(p.indexNum, w.indexNum) ASC,
            COALESCE(w.parentTaskId, w.taskId) ASC,
            w.parentTaskId ASC NULLS FIRST,
            w.indexNum ASC,
            w.taskId ASC
    ";
    $Stmt = $Pdo->prepare($Sql);
    $Stmt->execute([':workId' => $WorkId, ':wbsId' => $WbsId]);
    $TaskList = $Stmt->fetchAll();

    /* ==========================================================================
     * 日付軸の組み立て
     *   タスクが0件の場合はチャートを持たない一覧のみの帳票とする
     * ========================================================================== */
    $Today = new DateTimeImmutable(date('Y-m-d'));
    $Ticks = [];
    if (!empty($TaskList)) {
        // 本日線を必ず引けるよう、タスクの期間に本日を含めた範囲を求める
        $MinDate = $Today;
        $MaxDate = $Today;
        foreach ($TaskList as $Task) {
            if (!empty($Task['StartDate'])) {
                $StartDate = new DateTimeImmutable($Task['StartDate']);
                if ($StartDate < $MinDate) {
                    $MinDate = $StartDate;
                }
            }
            if (!empty($Task['EndDate'])) {
                $EndDate = new DateTimeImmutable($Task['EndDate']);
                if ($EndDate > $MaxDate) {
                    $MaxDate = $EndDate;
                }
            }
        }
        $Ticks = BuildWbsExportTicks($ViewMode, $MinDate, $MaxDate, $Today);

        /** 列数が実用の範囲を超える場合は、表示単位の変更を促して中断する */
        if (count($Ticks) > WBS_EXPORT_MAX_CHART_COLUMNS) {
            Logger::Info($FunctionName, '日付列数の上限超過により中断', $UserId);
            Response::Error(
                '対象期間が長いため、この表示単位では出力できません（日付列 '
                . count($Ticks) . '列 / 上限 ' . WBS_EXPORT_MAX_CHART_COLUMNS . '列）。'
                . '表示単位を【週】または【月】に変更してから出力してください'
            );
        }

        /** セル数（日付列 × タスク件数）が上限を超える場合も同様に中断する */
        $ChartCells = count($Ticks) * count($TaskList);
        if ($ChartCells > WBS_EXPORT_MAX_CHART_CELLS) {
            Logger::Info($FunctionName, 'チャートのセル数の上限超過により中断', $UserId);
            Response::Error(
                'タスク件数と対象期間に対してこの表示単位では出力できません（'
                . count($Ticks) . '列 × ' . count($TaskList) . '件 = '
                . number_format($ChartCells) . 'セル / 上限 '
                . number_format(WBS_EXPORT_MAX_CHART_CELLS) . 'セル）。'
                . '表示単位を【週】または【月】に変更してから出力してください'
            );
        }
    }

    /* ==========================================================================
     * Excel生成
     * ========================================================================== */
    $Spreadsheet = new Spreadsheet();
    $Sheet = $Spreadsheet->getActiveSheet();
    $Sheet->setTitle('WBS');

    /**
     * ヘッダー行
     * 画面と同じく2段構成とし、一覧列は2行を結合して1つの見出しとする
     */
    $Headers = [
        'A' => 'タスク名',
        'B' => '担当者',
        'C' => '開始予定日',
        'D' => '終了予定日',
        'E' => '進捗率',
        'F' => '状態',
    ];
    foreach ($Headers as $Column => $Label) {
        $Sheet->setCellValue($Column . '1', $Label);
        $Sheet->mergeCells($Column . '1:' . $Column . '2');
    }

    /** 日付軸のヘッダー（上段＝年月／年、下段＝日／週／月） */
    $ChartFirstColumn = WBS_EXPORT_FIXED_COLUMN_COUNT + 1;
    $GroupStartIndex = null;
    $GroupKey = null;
    foreach ($Ticks as $Index => $Tick) {
        $ColumnIndex = $ChartFirstColumn + $Index;
        $ColumnName = Coordinate::stringFromColumnIndex($ColumnIndex);
        $Sheet->setCellValue($ColumnName . '2', $Tick['Label']);
        $Sheet->getColumnDimension($ColumnName)
            ->setWidth(WBS_EXPORT_VIEW_MODES[$ViewMode]['ColumnWidth']);

        // 上段は同じ年月（月表示は同じ年）の範囲をまとめて1つのセルにする
        if ($GroupKey !== $Tick['GroupKey']) {
            if ($GroupStartIndex !== null) {
                WriteWbsExportGroupLabel($Sheet, $GroupStartIndex, $ColumnIndex - 1, $Ticks[$GroupStartIndex - $ChartFirstColumn]['GroupLabel']);
            }
            $GroupKey = $Tick['GroupKey'];
            $GroupStartIndex = $ColumnIndex;
        }
    }
    if ($GroupStartIndex !== null) {
        $LastColumnIndex = $ChartFirstColumn + count($Ticks) - 1;
        WriteWbsExportGroupLabel($Sheet, $GroupStartIndex, $LastColumnIndex, $Ticks[$GroupStartIndex - $ChartFirstColumn]['GroupLabel']);
    }

    /** データ行（ヘッダーが2行のため3行目から） */
    $RowNo = 3;
    /** 行番号を引けるようタスクごとの出力先行を控える */
    $TaskRowMap = [];
    foreach ($TaskList as $Task) {
        $IsDelayed = (bool)$Task['IsDelayed'];
        $Progress = (int)$Task['Progress'];

        // 状態は画面表示（GetWbsStatusLabel）と同一の判定基準で決定する
        if ($Progress >= 100) {
            $Status = '完了';
        } elseif ($IsDelayed) {
            $Status = '遅延';
        } elseif ($Progress > 0) {
            $Status = '進行中';
        } else {
            $Status = '未着手';
        }

        // 子タスクは親タスクとの区別が付くよう、画面と同じ記号を前置する
        $TaskName = ($Task['ParentTaskId'] !== null ? '└ ' : '') . ($Task['TaskName'] ?? '');

        // タスク名・担当者は利用者が入力した文字列のため、「=」等で始まる値が数式として
        // 解釈されないよう（CSV/数式インジェクション対策）、文字列型を明示して格納する
        $Sheet->setCellValueExplicit('A' . $RowNo, $TaskName, DataType::TYPE_STRING);
        $Sheet->setCellValueExplicit('B' . $RowNo, (string) ($Task['Assignees'] ?? ''), DataType::TYPE_STRING);
        $Sheet->setCellValue('C' . $RowNo, $Task['StartDate'] ?? '');
        $Sheet->setCellValue('D' . $RowNo, $Task['EndDate'] ?? '');
        // Excel側で百分率として扱えるよう 0〜1 の数値で格納する
        $Sheet->setCellValue('E' . $RowNo, $Progress / 100);
        $Sheet->setCellValue('F' . $RowNo, $Status);

        // 遅延行は文字色・背景色で強調する（一覧列のみ。チャート側はバーの色で表す）
        if ($IsDelayed) {
            $Sheet->getStyle('A' . $RowNo . ':F' . $RowNo)
                ->getFont()->getColor()->setARGB('FFC00000');
            $Sheet->getStyle('A' . $RowNo . ':F' . $RowNo)
                ->getFill()->setFillType(Fill::FILL_SOLID)
                ->getStartColor()->setARGB('FFFDE7E9');
        }

        $TaskRowMap[] = ['Row' => $RowNo, 'Task' => $Task, 'Status' => $Status];
        $RowNo++;
    }

    $LastRow = max($RowNo - 1, 2);

    /* ==========================================================================
     * ガントチャートの描画
     *   セル単位でスタイルを設定すると件数×列数の分だけ重くなるため、
     *   「列ごとの背景（土日・祝日・当日）」と「行ごとのバー（連続した範囲）」を
     *   それぞれ範囲指定で一括設定する。
     * ========================================================================== */
    if (!empty($Ticks) && !empty($TaskRowMap)) {
        /** 1. 土日・祝日・当日の列に背景色を付ける（列ごとに1回だけ設定する） */
        foreach ($Ticks as $Index => $Tick) {
            if (!$Tick['IsToday'] && !$Tick['IsWeekend'] && !$Tick['IsHoliday']) {
                continue;
            }
            // 当日は休日より優先して塗る（画面のCSSと同じ優先順位）
            $Color = $Tick['IsToday'] ? WBS_EXPORT_COLORS['Today'] : WBS_EXPORT_COLORS['Holiday'];
            $ColumnName = Coordinate::stringFromColumnIndex($ChartFirstColumn + $Index);
            $Sheet->getStyle($ColumnName . '3:' . $ColumnName . $LastRow)
                ->getFill()->setFillType(Fill::FILL_SOLID)
                ->getStartColor()->setARGB($Color);
        }

        /** 2. タスクの期間をバーとして塗る（行ごとに連続した1範囲で設定する） */
        foreach ($TaskRowMap as $Entry) {
            if (empty($Entry['Task']['StartDate']) || empty($Entry['Task']['EndDate'])) {
                continue;
            }
            $TaskStart = new DateTimeImmutable($Entry['Task']['StartDate']);
            $TaskEnd = new DateTimeImmutable($Entry['Task']['EndDate']);

            // 期間に重なる目盛りの範囲を求める
            $FromIndex = null;
            $ToIndex = null;
            foreach ($Ticks as $Index => $Tick) {
                if ($Tick['End'] < $TaskStart || $Tick['Start'] > $TaskEnd) {
                    continue;
                }
                if ($FromIndex === null) {
                    $FromIndex = $Index;
                }
                $ToIndex = $Index;
            }
            if ($FromIndex === null) {
                continue;
            }

            if ($Entry['Status'] === '完了') {
                $BarColor = WBS_EXPORT_COLORS['BarDone'];
            } elseif ($Entry['Status'] === '遅延') {
                $BarColor = WBS_EXPORT_COLORS['BarDelay'];
            } else {
                $BarColor = WBS_EXPORT_COLORS['BarNormal'];
            }

            $From = Coordinate::stringFromColumnIndex($ChartFirstColumn + $FromIndex);
            $To = Coordinate::stringFromColumnIndex($ChartFirstColumn + $ToIndex);
            $Sheet->getStyle($From . $Entry['Row'] . ':' . $To . $Entry['Row'])
                ->getFill()->setFillType(Fill::FILL_SOLID)
                ->getStartColor()->setARGB($BarColor);
        }
    }

    /* ==========================================================================
     * 書式設定
     * ========================================================================== */
    /** 日付軸を含めた最終列 */
    $LastColumnIndex = WBS_EXPORT_FIXED_COLUMN_COUNT + count($Ticks);
    $LastColumn = Coordinate::stringFromColumnIndex($LastColumnIndex);

    /** ヘッダーは太字・中央揃え・背景色付き（2行とも） */
    $HeaderRange = 'A1:' . $LastColumn . '2';
    $Sheet->getStyle($HeaderRange)->getFont()->setBold(true);
    $Sheet->getStyle($HeaderRange)->getAlignment()
        ->setHorizontal(Alignment::HORIZONTAL_CENTER)
        ->setVertical(Alignment::VERTICAL_CENTER);
    $Sheet->getStyle($HeaderRange)->getFill()->setFillType(Fill::FILL_SOLID)
        ->getStartColor()->setARGB(WBS_EXPORT_COLORS['Header']);
    $Sheet->getStyle($HeaderRange)->getBorders()->getAllBorders()
        ->setBorderStyle(Border::BORDER_THIN);
    // 日付のラベルは幅が狭いため小さめの文字とする
    if (!empty($Ticks)) {
        $ChartFirstColumnName = Coordinate::stringFromColumnIndex($ChartFirstColumn);
        $Sheet->getStyle($ChartFirstColumnName . '1:' . $LastColumn . '2')
            ->getFont()->setSize(9);
    }

    /** データが1件以上ある場合のみ一覧列の罫線・書式を適用する */
    if (!empty($TaskRowMap)) {
        // 一覧列・チャート列ともに罫線を引く
        $Sheet->getStyle('A1:' . $LastColumn . $LastRow)->getBorders()->getAllBorders()
            ->setBorderStyle(Border::BORDER_THIN);
        // 進捗率は百分率表示
        $Sheet->getStyle('E3:E' . $LastRow)->getNumberFormat()
            ->setFormatCode(NumberFormat::FORMAT_PERCENTAGE);
        // 開始・終了予定日／進捗率／状態は中央揃え
        $Sheet->getStyle('C3:F' . $LastRow)->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $Sheet->getStyle('A3:F' . $LastRow)->getAlignment()
            ->setVertical(Alignment::VERTICAL_CENTER);
        // 見出しの下段にオートフィルタを設定する（上段は結合セルのため対象外）
        $Sheet->setAutoFilter('A2:F' . $LastRow);
    }

    /** 列幅（一覧列。日付列はヘッダーの生成時に設定済み） */
    $ColumnWidths = [
        'A' => 36,
        'B' => 18,
        'C' => 14,
        'D' => 14,
        'E' => 10,
        'F' => 10,
    ];
    foreach ($ColumnWidths as $Column => $Width) {
        $Sheet->getColumnDimension($Column)->setWidth($Width);
    }

    /** ヘッダー2行と一覧列を固定する（チャートを横スクロールしても見出しが残る） */
    $Sheet->freezePane(
        Coordinate::stringFromColumnIndex(WBS_EXPORT_FIXED_COLUMN_COUNT + 1) . '3'
    );

    /** 先頭セルを選択した状態で開く */
    $Sheet->setSelectedCell('A1');

    /* ==========================================================================
     * ファイル出力
     * ========================================================================== */
    // tempnam() が作成した空ファイルも削除するため、元のパスを保持しておく
    $TempBase = tempnam(sys_get_temp_dir(), 'wbs');
    $TempFile = $TempBase . '.xlsx';
    $Writer = new Xlsx($Spreadsheet);
    $Writer->save($TempFile);

    $FileContent = file_get_contents($TempFile);
    $Base64Content = base64_encode($FileContent);
    unlink($TempFile);
    @unlink($TempBase);
    // メモリを速やかに解放する
    $Spreadsheet->disconnectWorksheets();
    unset($Spreadsheet);

    $FileName = $WorkTitle . '_' . $WbsName . '.xlsx';

    Logger::Info($FunctionName, 'Excel出力処理終了', $UserId);

    Response::Success([
        'FileData' => $Base64Content,
        'FileName' => $FileName,
        'MimeType' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
    ]);

} catch (Exception $E) {
    Logger::Error($FunctionName, 'Excel出力処理エラー', $UserId, $E->getMessage());
    Response::Error('Excel出力に失敗しました');
}
