<?php
/**
 * EntryTable.php
 * 表形式項目（JSON保持）の共通ユーティリティ
 *
 * 下記の列は、画面の表1つ分の内容をJSON文字列で保持する。
 *   キャラ 好きな物：{"Likes":[{"Content":..,"Reason":..}]}
 *   キャラ 趣味　　：{"Hobby":[{"Content":..,"Reason":..}]}
 *   キャラ 一人称　：{"FirstPerson":[{"Situation":..,"Content":..}]}
 *   キャラ 変遷　　：{"Transition":[{"Early":..,"Late":..,"Final":..}]}
 *   能力　 発動条件：{"Conditions":[{"Content":..,"Detail":..}]}
 *   種族　 身体特徴：{"PhysicalFeature":[{"Content":..,"Detail":..}]}
 *   技　　 代償　　：{"Risk":[{"Content":..,"Detail":..}]}
 *
 * 列の構成は画面・項目ごとに異なるため、本クラスの DEFINITIONS で一元管理し、
 * 更新履歴（SaveCharacter.php）と帳票（ExportDocument.php）の双方から参照する。
 * 定義の内容は画面側の各定義（CHARA_ENTRY_DEFINITIONS／POWER_ENTRY_DEFINITIONS
 * ／RACE_ENTRY_DEFINITIONS／MOVE_ENTRY_DEFINITIONS）と対応させること。
 *
 * JSONとして解釈できない値は移行前のフリーテキストとみなし、
 * 「必須列＝保持値の全文／他の列＝空」の1行として扱う（画面側と同一の解釈）。
 */

class EntryTable
{
    /**
     * 表の定義
     *   第1階層 … 画面（Entity）
     *   第2階層 … JSONのルート要素名（APIのプロパティ名と共通）
     *     Label   … 画面上の項目名
     *     Columns … 列定義（Key＝JSONの項目名／Label＝列名／Required＝必須列）
     */
    const DEFINITIONS = [
        'Character' => [
            'Likes' => [
                'Label'   => '好きな物',
                'Columns' => [
                    ['Key' => 'Content', 'Label' => '内容',   'Required' => true],
                    ['Key' => 'Reason',  'Label' => '理由',   'Required' => false],
                ],
            ],
            'Dislikes' => [
                'Label'   => '嫌いな物',
                'Columns' => [
                    ['Key' => 'Content', 'Label' => '内容',   'Required' => true],
                    ['Key' => 'Reason',  'Label' => '理由',   'Required' => false],
                ],
            ],
            'Landmine' => [
                'Label'   => '地雷',
                'Columns' => [
                    ['Key' => 'Content', 'Label' => '内容',   'Required' => true],
                    ['Key' => 'Reason',  'Label' => '理由',   'Required' => false],
                ],
            ],
            'StrongPoint' => [
                'Label'   => '長所',
                'Columns' => [
                    ['Key' => 'Content', 'Label' => '内容',   'Required' => true],
                    ['Key' => 'Reason',  'Label' => '具体例', 'Required' => false],
                ],
            ],
            'WeakPoint' => [
                'Label'   => '短所',
                'Columns' => [
                    ['Key' => 'Content', 'Label' => '内容',   'Required' => true],
                    ['Key' => 'Reason',  'Label' => '具体例', 'Required' => false],
                ],
            ],
            'Habit' => [
                'Label'   => '癖',
                'Columns' => [
                    ['Key' => 'Content', 'Label' => '内容',   'Required' => true],
                    ['Key' => 'Reason',  'Label' => '具体例', 'Required' => false],
                ],
            ],
            'Hobby' => [
                'Label'   => '趣味',
                'Columns' => [
                    ['Key' => 'Content', 'Label' => '内容',     'Required' => true],
                    ['Key' => 'Reason',  'Label' => 'きっかけ', 'Required' => false],
                ],
            ],
            'FirstPerson' => [
                'Label'   => '一人称',
                'Columns' => [
                    ['Key' => 'Situation', 'Label' => '状況',   'Required' => false],
                    ['Key' => 'Content',   'Label' => '一人称', 'Required' => true],
                ],
            ],
            'SecondPerson' => [
                'Label'   => '二人称',
                'Columns' => [
                    ['Key' => 'Situation', 'Label' => '状況',   'Required' => false],
                    ['Key' => 'Content',   'Label' => '二人称', 'Required' => true],
                ],
            ],
            'Transition' => [
                'Label'   => '変遷',
                'Columns' => [
                    ['Key' => 'Early', 'Label' => '序盤',   'Required' => true],
                    ['Key' => 'Late',  'Label' => '中盤',   'Required' => false],
                    ['Key' => 'Final', 'Label' => '最終的', 'Required' => false],
                ],
            ],
        ],
        'Power' => [
            'Conditions' => [
                'Label'   => '発動条件',
                'Columns' => [
                    ['Key' => 'Content', 'Label' => '条件',     'Required' => true],
                    ['Key' => 'Detail',  'Label' => '条件詳細', 'Required' => false],
                ],
            ],
            'Risk' => [
                'Label'   => '代償・副作用',
                'Columns' => [
                    ['Key' => 'Content', 'Label' => '代償・副作用', 'Required' => true],
                    ['Key' => 'Detail',  'Label' => '詳細',         'Required' => false],
                ],
            ],
        ],
        'Race' => [
            'PhysicalFeature' => [
                'Label'   => '身体特徴',
                'Columns' => [
                    ['Key' => 'Content', 'Label' => '特徴',     'Required' => true],
                    ['Key' => 'Detail',  'Label' => '特徴詳細', 'Required' => false],
                ],
            ],
        ],
        'Move' => [
            'Conditions' => [
                'Label'   => '発動条件',
                'Columns' => [
                    ['Key' => 'Content', 'Label' => '条件',     'Required' => true],
                    ['Key' => 'Detail',  'Label' => '条件詳細', 'Required' => false],
                ],
            ],
            'Risk' => [
                'Label'   => '代償・副作用',
                'Columns' => [
                    ['Key' => 'Content', 'Label' => '代償・副作用', 'Required' => true],
                    ['Key' => 'Detail',  'Label' => '詳細',         'Required' => false],
                ],
            ],
        ],
    ];

    /**
     * 表の列定義を取得する
     * @param string $Entity 画面
     * @param string $Key    表のキー
     * @return array 列定義（未定義のキーは空配列）
     */
    public static function Columns(string $Entity, string $Key): array
    {
        return self::DEFINITIONS[$Entity][$Key]['Columns'] ?? [];
    }

    /**
     * 表の項目名を取得する
     * @param string $Entity 画面
     * @param string $Key    表のキー
     * @return string 項目名
     */
    public static function Label(string $Entity, string $Key): string
    {
        return self::DEFINITIONS[$Entity][$Key]['Label'] ?? '';
    }

    /**
     * 表の必須列のキーを取得する
     * @param string $Entity 画面
     * @param string $Key    表のキー
     * @return string 必須列のキー（未定義の場合は空文字）
     */
    public static function RequiredKey(string $Entity, string $Key): string
    {
        foreach (self::Columns($Entity, $Key) as $Column) {
            if (!empty($Column['Required'])) {
                return $Column['Key'];
            }
        }
        return '';
    }

    /**
     * DBの保持値を行の配列へ変換する
     *   ・未設定は空配列を返す
     *   ・JSONとして解釈できない場合は移行前のフリーテキストとして1行に変換する
     *   ・必須列が空の行は除外する（画面側の表と同一の判定）
     * @param string      $Entity 画面
     * @param string      $Key    表のキー
     * @param string|null $Value  DBの保持値
     * @return array 行の配列（各行は列キー => 値）
     */
    public static function ParseRows(string $Entity, string $Key, $Value): array
    {
        // 1. 未設定は空の表とする
        if ($Value === null || trim((string) $Value) === '') {
            return [];
        }
        $Columns     = self::Columns($Entity, $Key);
        $RequiredKey = self::RequiredKey($Entity, $Key);
        $Text        = trim((string) $Value);

        // 2. JSONとして解釈できる場合はその内容を採用する
        $Decoded = json_decode($Text, true);
        if (is_array($Decoded) && isset($Decoded[$Key]) && is_array($Decoded[$Key])) {
            $Rows = [];
            foreach ($Decoded[$Key] as $Row) {
                if (!is_array($Row)) {
                    continue;
                }
                // 必須列が空の行は保持しない
                if (!isset($Row[$RequiredKey]) || trim((string) $Row[$RequiredKey]) === '') {
                    continue;
                }
                $Entry = [];
                foreach ($Columns as $Column) {
                    $Entry[$Column['Key']] = isset($Row[$Column['Key']])
                        ? trim((string) $Row[$Column['Key']])
                        : '';
                }
                $Rows[] = $Entry;
            }
            return $Rows;
        }

        // 3. 移行前のフリーテキストは必須列へ全文を格納した1行として扱う
        $LegacyRow = [];
        foreach ($Columns as $Column) {
            $LegacyRow[$Column['Key']] = ($Column['Key'] === $RequiredKey) ? $Text : '';
        }
        return [$LegacyRow];
    }

    /**
     * 画面から送られたJSON文字列を保存用に正規化する
     *   ・想定外のキー・必須列が空の行は保存対象から除外する
     *   ・JSONとして解釈できない場合は移行前のフリーテキストとみなし、1行の表として保存する
     *   ・行が1件も無い場合はNULLで保存する
     * @param string      $Entity 画面
     * @param string      $Key    表のキー
     * @param string|null $Value  画面から送られた値
     * @return string|null 正規化後のJSON文字列（該当なしはNULL）
     */
    public static function NormalizeJson(string $Entity, string $Key, $Value): ?string
    {
        $Rows = self::ParseRows($Entity, $Key, $Value);
        if (count($Rows) === 0) {
            return null;
        }
        return json_encode([$Key => $Rows], JSON_UNESCAPED_UNICODE);
    }

    /**
     * 1行を「必須列の値（列名: 値, ...）」の形式へ整形する
     * 値の無い列は出力しない
     * @param string $Entity 画面
     * @param string $Key    表のキー
     * @param array  $Row    行
     * @return string 整形結果
     */
    public static function FormatRow(string $Entity, string $Key, array $Row): string
    {
        $RequiredKey = self::RequiredKey($Entity, $Key);
        $Head        = $Row[$RequiredKey] ?? '';
        $Parts       = [];
        foreach (self::Columns($Entity, $Key) as $Column) {
            if ($Column['Key'] === $RequiredKey) {
                continue;
            }
            $ColumnValue = $Row[$Column['Key']] ?? '';
            if ($ColumnValue === '') {
                continue;
            }
            $Parts[] = $Column['Label'] . ': ' . $ColumnValue;
        }
        return empty($Parts) ? $Head : $Head . '（' . implode('、', $Parts) . '）';
    }

    /**
     * DBの保持値を行単位の文字列配列へ整形する
     * @param string      $Entity 画面
     * @param string      $Key    表のキー
     * @param string|null $Value  DBの保持値
     * @return array 整形済みの行（有効な行が無い場合は空配列）
     */
    public static function FormatLines(string $Entity, string $Key, $Value): array
    {
        $Lines = [];
        foreach (self::ParseRows($Entity, $Key, $Value) as $Row) {
            $Lines[] = self::FormatRow($Entity, $Key, $Row);
        }
        return $Lines;
    }

    /**
     * DBの保持値を一覧・更新履歴の表示値へ整形する
     * 行が存在しない場合はNULLを返す（差分比較で未設定として扱う）
     * @param string      $Entity 画面
     * @param string      $Key    表のキー
     * @param string|null $Value  DBの保持値
     * @return string|null 整形結果
     */
    public static function FormatForHistory(string $Entity, string $Key, $Value): ?string
    {
        $Lines = self::FormatLines($Entity, $Key, $Value);
        return empty($Lines) ? null : implode('、', $Lines);
    }
}
