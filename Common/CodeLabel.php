<?php
/**
 * CodeLabel.php
 * コード区分値 → 表示名称 変換 共通ユーティリティ
 *
 * プルダウン等で使うコード区分値（性別・血液型・評価・制作区分・起承転結・
 * ジャンル・モチーフ 等）を、可読な名称へ変換する。
 * 更新履歴の差分（HistoryDetail）で、コード値のままでは非可読な列を
 * 名称化してから比較・表示するために用いる。
 *
 * 【重要】各マップは フロント `Front/Js/Common.js` の PullDownList(Type) と
 * 一致させること。フロントの選択肢を変更した場合は本ファイルも追随が必要。
 */

class CodeLabel
{
    /**
     * コード区分 → 名称マップ（PullDownList と対応）
     * キーは区分種別、値は「コード => 名称」。コード 0 は未設定（空）扱い。
     */
    private const MAPS = [
        // 性別区分
        'Gender' => [0 => '', 1 => '男性', 2 => '女性', 3 => 'その他'],
        // 血液型区分
        'BloodType' => [0 => '', 1 => 'A', 2 => 'B', 3 => 'O', 4 => 'AB'],
        // レビュー評価区分
        'Review' => [0 => '', 1 => '◎', 2 => '〇', 3 => '△', 4 => '✕'],
        // 制作区分
        'ProductionType' => [
            0 => '', 1 => '投稿用', 2 => '連載用', 3 => '短編用', 4 => '長編用', 5 => 'その他',
        ],
        // 起承転結区分
        'PlotStructure' => [0 => '', 1 => '起', 2 => '承', 3 => '転', 4 => '結'],
        // 作品ジャンル区分
        'Genre' => [
            0 => '', 1 => 'ホラー', 2 => 'ファンタジー', 3 => 'SF', 4 => '恋愛',
            5 => '日常', 6 => 'アクション', 7 => '冒険', 8 => 'ミステリー', 9 => 'コメディ',
            10 => 'スポーツ', 11 => '歴史', 12 => '学園', 13 => 'エッセイ', 14 => 'その他',
        ],
        // 舞台区分
        'StageType' => [
            0 => '', 1 => '都市', 2 => '村・集落', 3 => '城・拠点', 4 => '施設',
            5 => '自然', 6 => '異界', 7 => 'その他', 8 => '国', 9 => '地方',
        ],
        // モチーフ区分
        'Motif' => [
            0 => '', 1 => '海賊', 2 => '忍者', 3 => '神話', 4 => 'エクソシスト', 5 => '侍・武士',
            6 => '魔法・魔術', 7 => 'ロボット・メカ', 8 => '吸血鬼', 9 => '妖怪・怪異', 10 => '宇宙',
            11 => '近未来・サイバーパンク', 12 => 'スチームパンク', 13 => 'ゾンビ', 14 => '軍事・ミリタリー',
            15 => '探偵・ミステリー', 16 => '錬金術', 17 => '死神', 18 => '怪盗',
        ],
    ];

    /**
     * コード値を名称へ変換する
     * @param string $Type  区分種別（MAPS のキー）
     * @param mixed  $Code  コード値（int/数値文字列/null）
     * @return string|null  名称。null/空は null（HistoryDetail 側で「（未設定）」表示）。
     *                      コード 0 は '' を返し、同じく未設定扱いになる。
     *                      マップに無いコードは文字列化して返す（情報を失わない）。
     */
    public static function Get(string $Type, $Code): ?string
    {
        if ($Code === null || $Code === '') {
            return null;
        }
        $Map = self::MAPS[$Type] ?? null;
        if ($Map === null) {
            return (string) $Code;
        }
        $Key = (int) $Code;
        return array_key_exists($Key, $Map) ? $Map[$Key] : (string) $Code;
    }

    /**
     * 真偽値を「あり／なし」等のラベルへ変換する
     * DB のブール（'t'/'f'）・PHP bool・0/1 のいずれも受け付ける。
     * @param mixed  $Value      判定対象
     * @param string $TrueLabel  真のときのラベル
     * @param string $FalseLabel 偽のときのラベル
     * @return string
     */
    public static function Bool($Value, string $TrueLabel = 'あり', string $FalseLabel = 'なし'): string
    {
        $IsTrue = ($Value === true)
            || ($Value === 1)
            || ($Value === '1')
            || ($Value === 't')
            || ($Value === 'true');
        return $IsTrue ? $TrueLabel : $FalseLabel;
    }
}
