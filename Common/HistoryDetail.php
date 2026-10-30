<?php
/**
 * HistoryDetail.php
 * 更新履歴の詳細メッセージ生成 共通ユーティリティ
 *
 * updatehistory.detail 列に格納する「どうしたか」の詳細を生成する。
 * 登録・更新・削除系APIから呼び出し、履歴の粒度を統一する。
 *
 * 【格納形式：構造化JSON（2026-07-08 変更）】
 *   フロントの差分ダイアログで確実に描画するため、プレーンテキストではなく
 *   構造化JSON文字列を返す。呼び出し側は戻り値をそのまま detail 列へ格納する
 *   （呼び出し側の実装変更は不要）。
 *
 *   登録系：{"Type":"register","Name":<名称>,"Summary":<一覧表示用要約>}
 *   更新系：{"Type":"update","Name":<名称>,
 *           "Changes":[{"Label":<列名>,"Old":<旧値>,"New":<新値>}, ...],
 *           "Summary":<一覧表示用要約>}
 *   削除系：{"Type":"delete","Name":<名称>,"Summary":<一覧表示用要約>}
 *
 *   Summary は更新履歴一覧のセル（アンカー）に表示する短い要約。
 *   Changes[].Old / New は Display() で整形済み（空は「（未設定）」・300字超は省略）。
 *   フロントは JSON をパースして差分ダイアログを描画する。JSONとして解釈できない
 *   旧データ（プレーンテキスト）はフロント側でそのまま全文表示にフォールバックする。
 *
 * 差分検出はDBに依存しない純粋処理。呼び出し側が
 *   $Old（更新前の表示値マップ）／$New（更新後の表示値マップ）／$Labels（列キー→和名）
 * を組み立てて渡す。FK列などIDのままでは可読でない値は、呼び出し側で
 * 表示名へ解決してから渡すこと。
 */

class HistoryDetail
{
    /** 詳細中に埋め込む1値あたりの最大文字数（超過分は … で省略） */
    const MAX_VALUE_LEN = 300;

    /**
     * 登録系の詳細を生成
     * @param string|null $Name 登録データの名称
     * @return string 構造化JSON文字列
     */
    public static function Register(?string $Name): string
    {
        $DisplayName = self::NameOrDefault($Name);
        return self::Encode([
            'Type'    => 'register',
            'Name'    => $DisplayName,
            'Summary' => sprintf('新規に%sというデータを登録しました', $DisplayName),
        ]);
    }

    /**
     * 削除系の詳細を生成
     * @param string|null $Name 削除データの名称
     * @return string 構造化JSON文字列
     */
    public static function Delete(?string $Name): string
    {
        $DisplayName = self::NameOrDefault($Name);
        return self::Encode([
            'Type'    => 'delete',
            'Name'    => $DisplayName,
            'Summary' => sprintf('%sのデータを削除しました', $DisplayName),
        ]);
    }

    /**
     * 更新系の詳細を生成（列単位の差分）
     * @param string|null $Name  対象データの名称
     * @param array $Old         更新前の表示値マップ（列キー => 値）
     * @param array $New         更新後の表示値マップ（列キー => 値）
     * @param array $Labels      列キー => 和名 の対応（この順序・範囲で差分判定する）
     * @return string 構造化JSON文字列（変更が無ければ Changes 空配列）
     */
    public static function Update(?string $Name, array $Old, array $New, array $Labels): string
    {
        $DisplayName = self::NameOrDefault($Name);

        $Changes = [];
        foreach ($Labels as $Key => $Label) {
            $OldVal = self::Normalize($Old[$Key] ?? null);
            $NewVal = self::Normalize($New[$Key] ?? null);
            // 正規化後に一致する（null↔''含む）変更なし列はスキップ
            if ($OldVal === $NewVal) {
                continue;
            }
            $Changes[] = [
                'Label' => $Label,
                'Old'   => self::Display($OldVal),
                'New'   => self::Display($NewVal),
            ];
        }

        if (empty($Changes)) {
            $Summary = sprintf('%sのデータを更新（変更なし）', $DisplayName);
        } else {
            $Summary = sprintf('%sのデータを更新', $DisplayName);
        }

        return self::Encode([
            'Type'    => 'update',
            'Name'    => $DisplayName,
            'Changes' => $Changes,
            'Summary' => $Summary,
        ]);
    }

    /**
     * 詳細メッセージに記録すべき変更が含まれるかを判定する
     *
     * 更新系で1列も変更が無かった場合、履歴に残しても得られる情報が無く
     * 一覧が「（変更なし）」で埋まってしまうため、呼び出し側は本判定が false の
     * 場合に UpdateHistory への登録を行わない。
     * 登録系・削除系（Changes を持たない）は常に記録対象とする。
     *
     * @param string|null $Detail Register/Delete/Update の戻り値
     * @return bool 記録対象であれば true
     */
    public static function HasChanges(?string $Detail): bool
    {
        // 1. 詳細が無い場合は記録対象とする（従来どおり登録する）
        if ($Detail === null || trim($Detail) === '') {
            return true;
        }
        // 2. JSONとして解釈できない場合も記録対象とする（旧形式のプレーンテキスト）
        $Decoded = json_decode($Detail, true);
        if (!is_array($Decoded)) {
            return true;
        }
        // 3. 更新系以外（登録・削除）は常に記録対象とする
        if (($Decoded['Type'] ?? '') !== 'update') {
            return true;
        }
        // 4. 更新系は差分がある場合のみ記録対象とする
        return !empty($Decoded['Changes']);
    }

    /**
     * 連想配列を detail 列格納用のJSON文字列に変換する
     * @param array $Payload
     * @return string
     */
    private static function Encode(array $Payload): string
    {
        return json_encode($Payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * 比較用に値を正規化する
     * ・null は空文字扱い（null↔'' を「変更なし」とみなす）
     * ・前後空白はトリム
     * @param mixed $Value
     * @return string
     */
    private static function Normalize($Value): string
    {
        if ($Value === null) {
            return '';
        }
        return trim((string) $Value);
    }

    /**
     * 表示用に値を整形する（空は「（未設定）」、長文は省略）
     * @param string $Value 正規化済みの値
     * @return string
     */
    private static function Display(string $Value): string
    {
        if ($Value === '') {
            return '（未設定）';
        }
        if (mb_strlen($Value) > self::MAX_VALUE_LEN) {
            return mb_substr($Value, 0, self::MAX_VALUE_LEN) . '…';
        }
        return $Value;
    }

    /**
     * 名称が空の場合の既定表記
     * @param string|null $Name
     * @return string
     */
    private static function NameOrDefault(?string $Name): string
    {
        $Name = $Name === null ? '' : trim($Name);
        return $Name === '' ? '（名称未設定）' : $Name;
    }
}
