<?php
/**
 * JapaneseHoliday.php
 * 日本の祝日判定の共通ユーティリティ
 *
 * フロントの GetJapaneseHolidayName / IsJapaneseHoliday（Front/Js/Common.js）と
 * 同一の判定規則をサーバ側でも用いるための共通処理。
 * 帳票（WBSのExcel出力）で画面と同じ休日の色分けを行うために使用する。
 * 祝日マスタを持たずに計算で求めるため、外部ライブラリには依存しない。
 *
 * 対応範囲
 *  ・固定日の祝日
 *  ・ハッピーマンデー（成人の日・海の日・敬老の日・スポーツの日）
 *  ・春分の日／秋分の日（天文近似式。1980〜2099年が有効範囲）
 *  ・振替休日（祝日が日曜の場合、直後の祝日でない日）
 *  ・国民の休日（祝日に挟まれた平日）
 *
 * 注意
 *  ・現行（2020年の祝日法改正以降）の祝日で判定する。2020年より前の日付は
 *    当時と異なる祝日（天皇誕生日など）で判定される。
 *  ・春分の日／秋分の日は前年2月の官報で確定するため、近似式の値と
 *    ずれる可能性が理論上ある。
 */

class JapaneseHoliday
{
    /** 年ごとの祝日一覧のキャッシュ（キー: 年 / 値: ['YYYY-MM-DD' => 祝日名]） */
    private static $Cache = [];

    /**
     * 指定日の祝日名を取得する
     * @param DateTimeInterface $Date 対象日
     * @return string 祝日名（祝日でない場合は空文字）
     */
    public static function GetName(DateTimeInterface $Date): string
    {
        $Year = (int) $Date->format('Y');
        if (!isset(self::$Cache[$Year])) {
            self::$Cache[$Year] = self::BuildMap($Year);
        }
        return self::$Cache[$Year][$Date->format('Y-m-d')] ?? '';
    }

    /**
     * 指定日が祝日かどうかを判定する
     * @param DateTimeInterface $Date 対象日
     * @return bool 祝日の場合true
     */
    public static function IsHoliday(DateTimeInterface $Date): bool
    {
        return self::GetName($Date) !== '';
    }

    /**
     * 指定年の祝日一覧を組み立てる
     * @param int $Year 年
     * @return array ['YYYY-MM-DD' => 祝日名] 形式の配列
     */
    private static function BuildMap(int $Year): array
    {
        $Map = [];
        $Add = function (int $Month, int $Day, string $Name) use ($Year, &$Map) {
            // 近似式の有効範囲外は0が返るため、その場合は登録しない
            if ($Day <= 0) {
                return;
            }
            $Map[sprintf('%04d-%02d-%02d', $Year, $Month, $Day)] = $Name;
        };

        $Add(1, 1, '元日');
        $Add(1, self::GetNthWeekdayOfMonth($Year, 1, 1, 2), '成人の日');
        $Add(2, 11, '建国記念の日');
        $Add(2, 23, '天皇誕生日');
        $Add(3, self::GetEquinoxDay($Year, true), '春分の日');
        $Add(4, 29, '昭和の日');
        $Add(5, 3, '憲法記念日');
        $Add(5, 4, 'みどりの日');
        $Add(5, 5, 'こどもの日');
        $Add(7, self::GetNthWeekdayOfMonth($Year, 7, 1, 3), '海の日');
        $Add(8, 11, '山の日');
        $Add(9, self::GetNthWeekdayOfMonth($Year, 9, 1, 3), '敬老の日');
        $Add(9, self::GetEquinoxDay($Year, false), '秋分の日');
        $Add(10, self::GetNthWeekdayOfMonth($Year, 10, 1, 2), 'スポーツの日');
        $Add(11, 3, '文化の日');
        $Add(11, 23, '勤労感謝の日');

        self::AddSubstituteHolidays($Map);
        self::AddNationalHolidays($Map);
        return $Map;
    }

    /**
     * 指定した年月の第N週の指定曜日の日付を取得する（ハッピーマンデーの算出に使用）
     * @param int $Year 年
     * @param int $Month 月（1〜12）
     * @param int $Weekday 曜日（0=日〜6=土）
     * @param int $Nth 第何週か（1〜）
     * @return int 日
     */
    private static function GetNthWeekdayOfMonth(int $Year, int $Month, int $Weekday, int $Nth): int
    {
        $FirstDay = (int) (new DateTimeImmutable(sprintf('%04d-%02d-01', $Year, $Month)))->format('w');
        return 1 + (($Weekday - $FirstDay + 7) % 7) + ($Nth - 1) * 7;
    }

    /**
     * 春分の日／秋分の日を近似式で求める
     * @param int $Year 年
     * @param bool $IsSpring 春分ならtrue、秋分ならfalse
     * @return int 日（有効範囲外の場合は0）
     */
    private static function GetEquinoxDay(int $Year, bool $IsSpring): int
    {
        if ($Year < 1980 || $Year > 2099) {
            return 0;
        }
        $BaseValue = $IsSpring ? 20.8431 : 23.2488;
        return (int) floor(
            $BaseValue + 0.242194 * ($Year - 1980) - floor(($Year - 1980) / 4)
        );
    }

    /**
     * 振替休日を追加する
     * 祝日が日曜と重なった場合、その後の祝日でない最初の日を休日とする
     * @param array $Map 祝日一覧（直接更新する）
     * @return void
     */
    private static function AddSubstituteHolidays(array &$Map): void
    {
        // 走査中に要素を追加するため、キーの一覧を先に確定させる
        $Keys = array_keys($Map);
        sort($Keys);
        foreach ($Keys as $Key) {
            $Date = new DateTimeImmutable($Key);
            if ((int) $Date->format('w') !== 0) {
                continue;
            }
            do {
                $Date = $Date->modify('+1 day');
            } while (isset($Map[$Date->format('Y-m-d')]));
            $Map[$Date->format('Y-m-d')] = '振替休日';
        }
    }

    /**
     * 国民の休日を追加する
     * 前後を祝日に挟まれた平日を休日とする（9月のシルバーウィークなど）
     * @param array $Map 祝日一覧（直接更新する）
     * @return void
     */
    private static function AddNationalHolidays(array &$Map): void
    {
        $Keys = array_keys($Map);
        sort($Keys);
        foreach ($Keys as $Key) {
            $Date = new DateTimeImmutable($Key);
            $Between = $Date->modify('+1 day');
            $Next = $Date->modify('+2 day');
            // 日曜は振替休日の対象であり、国民の休日にはならない
            if ((int) $Between->format('w') === 0) {
                continue;
            }
            if (isset($Map[$Between->format('Y-m-d')])) {
                continue;
            }
            if (!isset($Map[$Next->format('Y-m-d')])) {
                continue;
            }
            $Map[$Between->format('Y-m-d')] = '国民の休日';
        }
    }
}
