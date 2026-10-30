<?php
/**
 * DateUtil.php
 * 日付文字列パース・年齢計算の共通ユーティリティ
 *
 * フロントの ParseDateStr / CalculateAge（Front/Js/Common.js）と同一の解釈規則を
 * サーバ側でも用いるための共通処理。和暦区切り(年月日)・ハイフン・数値のみに対応する。
 * これによりフロント表示とサーバ側の年齢再計算の食い違いを防ぐ。
 */

class DateUtil
{
    /**
     * 日付文字列を [year, month, day] にパースする。
     * 対応フォーマット:
     *   - "YYYY年MM月DD日" / "YYYY年MM月" / "YYYY年"
     *   - "YYYY-MM-DD" / "YYYY-MM"
     *   - 数値のみ（後方互換: 先頭4桁を年として YYYYMMDD / YYYYMM / YYYY）
     * month は 1〜12 で返す（フロントの Date は 0〜11 だが本メソッドは 1 始まり）。
     *
     * @param string|null $DateStr 日付文字列（例: "2000年5月3日", "2000-05-03", "2000"）
     * @return array{0:int,1:int,2:int}|null [year, month, day]。解釈不能なら null
     */
    public static function Parse(?string $DateStr): ?array
    {
        $Parts = self::ParseParts($DateStr);
        if ($Parts === null) {
            return null;
        }
        // 年・月・日が大きすぎる値は、通算日数の計算（DaysFromCivil）や日数の加算（Shift）で整数の範囲を超え、
        // 戻り値の型違反（TypeError）となって API の catch (Exception) をすり抜けるため、解釈不能として扱う。
        // 上限（15桁）は、通算日数どうしを加減算しても整数の範囲に収まる値とした。
        foreach ($Parts as $Part) {
            if (abs($Part) > self::MAX_DATE_PART) {
                return null;
            }
        }
        return $Parts;
    }

    /** Parse で扱う年・月・日の絶対値の上限（15桁） */
    private const MAX_DATE_PART = 999999999999999;

    /**
     * 日付文字列を [year, month, day] に分解する（Parse の本体。値の範囲は検証しない）
     *
     * @param string|null $DateStr 日付文字列
     * @return array{0:int,1:int,2:int}|null [year, month, day]。解釈不能なら null
     */
    private static function ParseParts(?string $DateStr): ?array
    {
        if ($DateStr === null) {
            return null;
        }
        $Str = trim((string) $DateStr);
        if ($Str === '') {
            return null;
        }

        // 1. 区切りありのパース（年月日 / ハイフン）
        if (preg_match('/^(\d+)年(\d+)月(\d+)日?$/u', $Str, $M)) {
            return [(int) $M[1], (int) $M[2], (int) $M[3]];
        }
        if (preg_match('/^(\d+)-(\d+)-(\d+)$/', $Str, $M)) {
            return [(int) $M[1], (int) $M[2], (int) $M[3]];
        }
        if (preg_match('/^(\d+)年(\d+)月?$/u', $Str, $M)) {
            return [(int) $M[1], (int) $M[2], 1];
        }
        if (preg_match('/^(\d+)-(\d+)$/', $Str, $M)) {
            return [(int) $M[1], (int) $M[2], 1];
        }
        if (preg_match('/^(\d+)年$/u', $Str, $M)) {
            return [(int) $M[1], 1, 1];
        }

        // 2. 区切り無しの数値のみ（後方互換: 先頭4桁=年）
        $Num = preg_replace('/[^0-9]/', '', $Str);
        $Len = strlen($Num);
        if ($Len >= 8) {
            return [(int) substr($Num, 0, 4), (int) substr($Num, 4, 2), (int) substr($Num, 6, 2)];
        }
        if ($Len >= 6) {
            return [(int) substr($Num, 0, 4), (int) substr($Num, 4, 2), 1];
        }
        if ($Len >= 4) {
            return [(int) substr($Num, 0, 4), 1, 1];
        }
        return null;
    }

    /**
     * 基準日と生年月日（いずれも日付文字列）から年齢を計算する。
     * フロント CalculateAge と同じ規則（基準日時点で誕生日未到来なら 1 を引く）。
     *
     * @param string|null $ReferenceStr 基準日（例: 舞台設定日）
     * @param string|null $BirthStr 生年月日
     * @return int|null 年齢。いずれかが解釈不能、または基準日が生年月日より前の場合は null
     */
    public static function CalcAge(?string $ReferenceStr, ?string $BirthStr): ?int
    {
        $Ref = self::Parse($ReferenceStr);
        $Birth = self::Parse($BirthStr);
        if ($Ref === null || $Birth === null) {
            return null;
        }

        // 基準日が生年月日より前 → 年齢未確定（null）
        $RefVal = $Ref[0] * 10000 + $Ref[1] * 100 + $Ref[2];
        $BirthVal = $Birth[0] * 10000 + $Birth[1] * 100 + $Birth[2];
        if ($RefVal < $BirthVal) {
            return null;
        }

        $Age = $Ref[0] - $Birth[0];
        if ($Ref[1] < $Birth[1] || ($Ref[1] === $Birth[1] && $Ref[2] < $Birth[2])) {
            $Age--;
        }
        return $Age;
    }

    /**
     * 予定日として扱える日付の下限（yyyy-MM-dd）
     * フロントの WBS_DATE_MIN（Front/Js/Common.js）およびDBのCHECK制約と揃えること
     */
    public const SCHEDULE_MIN_DATE = '1950-01-01';

    /** 予定日として扱える日付の上限（yyyy-MM-dd） */
    public const SCHEDULE_MAX_DATE = '2100-12-31';

    /**
     * 予定日が扱える範囲内かを判定する。
     * 期間が極端に長いと帳票の日付列が際限なく増えるため、上下限を設けている。
     * 日付は yyyy-MM-dd の固定長のため、文字列比較で前後関係を判定できる。
     *
     * @param string|null $Ymd yyyy-MM-dd 形式の日付文字列
     * @return bool 範囲内なら true（null・空文字は判定対象外として true）
     */
    public static function IsInScheduleRange(?string $Ymd): bool
    {
        if ($Ymd === null || $Ymd === '') {
            return true;
        }
        return $Ymd >= self::SCHEDULE_MIN_DATE && $Ymd <= self::SCHEDULE_MAX_DATE;
    }

    /**
     * 予定日の範囲エラーメッセージを取得する
     * @param string $Label 項目名（例: 開始予定日）
     * @return string エラーメッセージ
     */
    public static function GetScheduleRangeMessage(string $Label): string
    {
        return $Label . 'は' . self::SCHEDULE_MIN_DATE . '〜' . self::SCHEDULE_MAX_DATE . 'の範囲で入力してください';
    }

    /**
     * 前後関係の判定に用いる精度（年:1 / 年月:2 / 年月日:3）を求める。
     * 設立日・結成日などは「2025年」「2025年01月」「2025年01月07日」のいずれの
     * 粒度でも登録できるため、比較時にどこまで見るかの判断に使用する。
     *
     * @param string|null $DateStr 日付文字列
     * @return int 精度（解釈不能な場合は 0）
     */
    public static function Precision(?string $DateStr): int
    {
        if ($DateStr === null || trim((string) $DateStr) === '') {
            return 0;
        }
        if (self::Parse($DateStr) === null) {
            return 0;
        }
        $Num = preg_replace('/[^0-9]/', '', trim((string) $DateStr));
        $Len = strlen($Num);
        if ($Len >= 8) {
            return 3;
        }
        if ($Len >= 6) {
            return 2;
        }
        return 1;
    }

    /**
     * 2つの日付文字列の前後関係を比較する。
     *   ・粒度が異なる場合は粗い方に合わせて比較する
     *     （例: 「2026年05月」と「2026年」は年までの比較となり同じとみなす）
     *     こうしないと、粗い側を月初・日初とみなした結果、
     *     実際には矛盾していない組み合わせを不整合と判定してしまう
     *   ・いずれかが未設定・解釈不能な場合は比較しない
     *
     * @param string|null $LeftStr  比較元の日付文字列
     * @param string|null $RightStr 比較先の日付文字列
     * @return int|null 負:LeftStrが過去 / 0:同じ / 正:LeftStrが未来。比較できない場合は null
     */
    public static function Compare(?string $LeftStr, ?string $RightStr): ?int
    {
        $Left = self::Parse($LeftStr);
        $Right = self::Parse($RightStr);
        if ($Left === null || $Right === null) {
            return null;
        }

        // 1. 粗い方の精度に合わせる
        $Precision = min(self::Precision($LeftStr), self::Precision($RightStr));
        if ($Precision <= 0) {
            return null;
        }

        // 2. 精度の範囲だけを比較対象の数値へ組み立てる
        $ToValue = static function (array $Parts) use ($Precision) {
            $Value = $Parts[0];
            if ($Precision >= 2) {
                $Value = $Value * 100 + $Parts[1];
            }
            if ($Precision >= 3) {
                $Value = $Value * 100 + $Parts[2];
            }
            return $Value;
        };

        return $ToValue($Left) <=> $ToValue($Right);
    }

    /**
     * 日付文字列を、指定した精度の表示形式へ組み立てる。
     * フロントの FormatDate（Front/Js/Common.js）と同じ表記に揃える。
     *
     * @param int $Year      年
     * @param int $Month     月（1〜12）
     * @param int $Day       日（1〜31）
     * @param int $Precision 精度（1:年 / 2:年月 / 3:年月日）
     * @return string 表示形式の日付文字列
     */
    public static function Format(int $Year, int $Month, int $Day, int $Precision): string
    {
        if ($Precision <= 1) {
            return $Year . '年';
        }
        $MonthText = str_pad((string) $Month, 2, '0', STR_PAD_LEFT);
        if ($Precision === 2) {
            return $Year . '年' . $MonthText . '月';
        }
        return $Year . '年' . $MonthText . '月' . str_pad((string) $Day, 2, '0', STR_PAD_LEFT) . '日';
    }

    /**
     * 2つの日付文字列の差を日数で求める。
     * 年のみ・年月のみの指定は、それぞれ1月1日・1日とみなす。
     *
     * @param string|null $FromStr 起点の日付文字列
     * @param string|null $ToStr   終点の日付文字列
     * @return int|null 日数（$ToStr が未来なら正）。解釈できない場合は null
     */
    public static function DiffDays(?string $FromStr, ?string $ToStr): ?int
    {
        $From = self::Parse($FromStr);
        $To = self::Parse($ToStr);
        if ($From === null || $To === null) {
            return null;
        }
        return self::DaysFromCivil($To[0], $To[1], $To[2])
             - self::DaysFromCivil($From[0], $From[1], $From[2]);
    }

    /**
     * 日付文字列を指定日数だけ移動する。
     * 元の精度（年 / 年月 / 年月日）を保ったまま組み立て直す。
     *
     * @param string|null $DateStr  移動元の日付文字列
     * @param int         $DiffDays 移動する日数（負値は過去へ）
     * @return string|null 移動後の日付文字列。解釈できない場合や
     *                     移動先が1年より前になる場合は null
     */
    public static function Shift(?string $DateStr, int $DiffDays): ?string
    {
        $Parts = self::Parse($DateStr);
        if ($Parts === null) {
            return null;
        }
        if ($DiffDays === 0) {
            return $DateStr;
        }
        $Precision = self::Precision($DateStr);
        if ($Precision <= 0) {
            return null;
        }
        $Days = self::DaysFromCivil($Parts[0], $Parts[1], $Parts[2]) + $DiffDays;
        [$Year, $Month, $Day] = self::CivilFromDays($Days);
        /* 作中の暦は1年以降を前提としているため、それより前へ遡る移動は行わない */
        if ($Year < 1) {
            return null;
        }
        return self::Format($Year, $Month, $Day, $Precision);
    }

    /**
     * 年月日を通算日数へ変換する（先発グレゴリオ暦）。
     * 架空の暦では西暦の範囲外の年も扱うため、DateTime ではなく
     * 桁あふれしない算術で日数を求める。
     *
     * @param int $Year  年
     * @param int $Month 月（1〜12）
     * @param int $Day   日（1〜31）
     * @return int 1970年1月1日を0とする通算日数
     */
    private static function DaysFromCivil(int $Year, int $Month, int $Day): int
    {
        /* 3月を年の始まりとみなすことで、閏日を年末の扱いへ寄せる */
        $Year -= $Month <= 2 ? 1 : 0;
        $Era = intdiv($Year >= 0 ? $Year : $Year - 399, 400);
        /** 400年周期の中での年（0〜399） */
        $YearOfEra = $Year - $Era * 400;
        /** 3月1日を0とする年内通算日（0〜365） */
        $DayOfYear = intdiv(153 * ($Month + ($Month > 2 ? -3 : 9)) + 2, 5) + $Day - 1;
        /** 400年周期の中での通算日（0〜146096） */
        $DayOfEra = $YearOfEra * 365 + intdiv($YearOfEra, 4) - intdiv($YearOfEra, 100) + $DayOfYear;
        return $Era * 146097 + $DayOfEra - 719468;
    }

    /**
     * 通算日数を年月日へ変換する（DaysFromCivil の逆変換）。
     *
     * @param int $Days 1970年1月1日を0とする通算日数
     * @return array{0:int,1:int,2:int} [year, month, day]
     */
    private static function CivilFromDays(int $Days): array
    {
        $Days += 719468;
        $Era = intdiv($Days >= 0 ? $Days : $Days - 146096, 146097);
        $DayOfEra = $Days - $Era * 146097;
        $YearOfEra = intdiv(
            $DayOfEra - intdiv($DayOfEra, 1460) + intdiv($DayOfEra, 36524) - intdiv($DayOfEra, 146096),
            365
        );
        $Year = $YearOfEra + $Era * 400;
        $DayOfYear = $DayOfEra - (365 * $YearOfEra + intdiv($YearOfEra, 4) - intdiv($YearOfEra, 100));
        $MonthIndex = intdiv(5 * $DayOfYear + 2, 153);
        $Day = $DayOfYear - intdiv(153 * $MonthIndex + 2, 5) + 1;
        $Month = $MonthIndex + ($MonthIndex < 10 ? 3 : -9);
        $Year += $Month <= 2 ? 1 : 0;
        return [$Year, $Month, $Day];
    }
}
