<?php
/**
 * RelationJson.php
 * 関連要素（キャラ・舞台・建物 等）のJSON保持 共通ユーティリティ
 *
 * チャプター設定の関連キャラ（chapterinfo.charaText）と同じく、中間テーブルを設けず
 * 「{"CharaInfo":[{"CharaId":".."}]}」形式のJSONでIDのみを保持する列を扱う。
 * 表示名は取得時にマスタから解決するため、同じ処理を複数のAPIで書き分けずに済むよう
 * 正規化・ID抽出・名称解決の3処理をここへ集約する。
 */

class RelationJson
{
    /** 1種類（キャラ・舞台・建物 等）あたりに保存できる関連要素の上限件数 */
    public const MAX_IDS_PER_KIND = 100;

    /** IN句1回あたりのID件数の上限（バインド数の上限超過を防ぐため分割して問い合わせる） */
    private const IN_CHUNK_SIZE = 1000;

    /** uuid 列（CharacterInfo.charaId）のID形式 */
    private const PATTERN_UUID = '/\A[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}\z/';

    /** bigint 列（StageInfo.stageId・BuildInfo.buildId 等）のID形式 */
    private const PATTERN_BIGINT = '/\A[1-9][0-9]{0,18}\z/';

    /** uuid 型のID列（小文字）。これ以外のID列は bigint として扱う */
    private const UUID_COLUMNS = ['charaid'];

    /**
     * マスタのID列に応じたIDの形式（正規表現）を返す。
     *
     * @param string $IdColumn マスタのID列名
     * @return string 正規表現
     */
    private static function IdPattern(string $IdColumn): string
    {
        return in_array(strtolower($IdColumn), self::UUID_COLUMNS, true)
            ? self::PATTERN_UUID
            : self::PATTERN_BIGINT;
    }

    /**
     * IDがマスタのID列の形式に合致するかを判定する。
     * 形式不正のIDをIN句へ渡すと型変換エラー（22P02）で一覧取得全体が失敗するため、
     * 問い合わせ前に必ず本判定で除外する。
     *
     * @param string $Id       ID
     * @param string $IdColumn マスタのID列名
     * @return bool 形式に合致する場合 true
     */
    private static function IsValidIdFormat(string $Id, string $IdColumn): bool
    {
        return preg_match(self::IdPattern($IdColumn), $Id) === 1;
    }

    /**
     * 作品内に存在するIDを、IN句を分割しながら取得する。
     *
     * @param PDO               $Pdo        DB接続
     * @param string            $WorkId     作品ID
     * @param array<int,string> $IdList     ID一覧（形式確認済み・重複なし）
     * @param string            $Table      マスタのテーブル名（固定リテラルのみ）
     * @param string            $IdColumn   マスタのID列名（固定リテラルのみ）
     * @param string|null       $NameColumn マスタの名称列名（固定リテラルのみ。null の場合は名称を取得しない）
     * @return array<string,?string> ID→名称のマップ（名称を取得しない場合の値は null）
     */
    private static function FetchExisting(
        PDO $Pdo,
        string $WorkId,
        array $IdList,
        string $Table,
        string $IdColumn,
        ?string $NameColumn
    ): array {
        $Map = [];
        $SelectColumns = $NameColumn !== null ? "{$IdColumn}, {$NameColumn}" : $IdColumn;
        foreach (array_chunk($IdList, self::IN_CHUNK_SIZE) as $Chunk) {
            $Placeholders = implode(',', array_fill(0, count($Chunk), '?'));
            $Stmt = $Pdo->prepare(
                "SELECT {$SelectColumns} FROM {$Table} WHERE workId = ? AND {$IdColumn} IN ({$Placeholders})"
            );
            $Stmt->execute(array_merge([$WorkId], $Chunk));
            foreach ($Stmt->fetchAll() as $Row) {
                $Map[(string) $Row[strtolower($IdColumn)]] = $NameColumn !== null
                    ? $Row[strtolower($NameColumn)]
                    : null;
            }
        }
        return $Map;
    }

    /**
     * 保存用JSON文字列に含まれるIDを検証する（保存APIから Encode の後に呼び出す）。
     *   ・件数が MAX_IDS_PER_KIND 以内であること
     *   ・マスタのID列の形式に合致すること
     *   ・同一作品内に存在すること
     *
     * @param PDO         $Pdo      DB接続
     * @param string      $WorkId   作品ID
     * @param string|null $Json     Encode で正規化したJSON文字列
     * @param string      $ListKey  JSONのリストキー（例: 'CharaInfo'）
     * @param string      $IdKey    IDのキー（例: 'CharaId'）
     * @param string      $Table    マスタのテーブル名（固定リテラルのみ）
     * @param string      $IdColumn マスタのID列名（固定リテラルのみ）
     * @param string      $Label    エラーメッセージに表示する項目名（例: '関連キャラ'）
     * @return string|null エラーメッセージ。問題が無い場合は null
     */
    public static function ValidateIds(
        PDO $Pdo,
        string $WorkId,
        ?string $Json,
        string $ListKey,
        string $IdKey,
        string $Table,
        string $IdColumn,
        string $Label
    ): ?string {
        $IdList = array_values(array_unique(self::ExtractIds($Json, $ListKey, $IdKey)));
        if (empty($IdList)) {
            return null;
        }
        if (count($IdList) > self::MAX_IDS_PER_KIND) {
            return $Label . 'は' . self::MAX_IDS_PER_KIND . '件以内で指定してください';
        }
        foreach ($IdList as $Id) {
            if (!self::IsValidIdFormat($Id, $IdColumn)) {
                return $Label . 'が存在しません。画面を再表示してください';
            }
        }
        $Existing = self::FetchExisting($Pdo, $WorkId, $IdList, $Table, $IdColumn, null);
        foreach ($IdList as $Id) {
            /* 表示時の名称解決（Resolve）はDBの取得値と完全一致で突き合わせるため、
             * ここでも完全一致を要求する（大文字の uuid 等、表示できない値を保存させない） */
            if (!array_key_exists($Id, $Existing)) {
                return $Label . 'が存在しません。画面を再表示してください';
            }
        }
        return null;
    }

    /**
     * 入力値を保存用のJSON文字列へ正規化する。
     * 入力は [{"<IdKey>": ".."}, ..] / ["..", ..] のいずれの形でも受け付ける。
     *
     * @param mixed  $Input   リクエストで受け取った関連要素
     * @param string $ListKey JSONのリストキー（例: 'CharaInfo'）
     * @param string $IdKey   IDのキー（例: 'CharaId'）
     * @return string|null 保存用のJSON文字列。要素が無い場合は null
     */
    public static function Encode($Input, string $ListKey, string $IdKey): ?string
    {
        if (!is_array($Input) || count($Input) === 0) {
            return null;
        }
        $Normalized = [];
        $Seen = [];
        foreach ($Input as $Item) {
            $Id = is_array($Item) ? ($Item[$IdKey] ?? null) : $Item;
            /* 配列・オブジェクト等の文字列化できない値はIDとして扱わない */
            if ($Id === null || $Id === '' || !is_scalar($Id)) {
                continue;
            }
            $Id = (string) $Id;
            /* 同じ要素を重ねてタグ付けしても意味を成さないため、重複は取り除く */
            if (isset($Seen[$Id])) {
                continue;
            }
            $Seen[$Id] = true;
            $Normalized[] = [$IdKey => $Id];
        }
        if (empty($Normalized)) {
            return null;
        }
        return json_encode([$ListKey => $Normalized], JSON_UNESCAPED_UNICODE);
    }

    /**
     * JSON文字列から、保存順を維持したままIDの配列を取り出す。
     *
     * @param string|null $Json    JSON文字列
     * @param string      $ListKey JSONのリストキー
     * @param string      $IdKey   IDのキー
     * @return array<int,string> IDの配列（解釈できない場合は空配列）
     */
    public static function ExtractIds(?string $Json, string $ListKey, string $IdKey): array
    {
        if (empty($Json)) {
            return [];
        }
        $Decoded = json_decode($Json, true);
        if (!is_array($Decoded) || empty($Decoded[$ListKey]) || !is_array($Decoded[$ListKey])) {
            return [];
        }
        $Ids = [];
        foreach ($Decoded[$ListKey] as $Item) {
            $Id = is_array($Item) ? ($Item[$IdKey] ?? null) : $Item;
            if ($Id !== null && $Id !== '' && is_scalar($Id)) {
                $Ids[] = (string) $Id;
            }
        }
        return $Ids;
    }

    /**
     * 複数のJSON文字列に含まれるIDをまとめてマスタと突き合わせ、ID→名称のマップを返す。
     * IN句でまとめて解決するため、一覧の件数に応じてクエリが増えることはない
     * （IN_CHUNK_SIZE 件ごとに分割して問い合わせる）。
     * 保存済みの値に形式不正のIDが含まれていても一覧全体が失敗しないよう、
     * ID列の形式（uuid / bigint）に合致しないIDは問い合わせ前に除外する。
     *
     * @param PDO                 $Pdo        DB接続
     * @param string              $WorkId     作品ID
     * @param array<int,?string>  $JsonList   JSON文字列の配列
     * @param string              $ListKey    JSONのリストキー
     * @param string              $IdKey      IDのキー
     * @param string              $Table      マスタのテーブル名
     * @param string              $IdColumn   マスタのID列名
     * @param string              $NameColumn マスタの名称列名
     * @return array<string,string> ID→名称のマップ
     */
    public static function BuildNameMap(
        PDO $Pdo,
        string $WorkId,
        array $JsonList,
        string $ListKey,
        string $IdKey,
        string $Table,
        string $IdColumn,
        string $NameColumn
    ): array {
        $Ids = [];
        foreach ($JsonList as $Json) {
            foreach (self::ExtractIds($Json, $ListKey, $IdKey) as $Id) {
                if (self::IsValidIdFormat($Id, $IdColumn)) {
                    $Ids[$Id] = true;
                }
            }
        }
        if (empty($Ids)) {
            return [];
        }
        // 配列のキーに数字文字列を使うと int へ変換されるため、文字列へ戻す
        $IdList = array_map('strval', array_keys($Ids));
        return self::FetchExisting($Pdo, $WorkId, $IdList, $Table, $IdColumn, $NameColumn);
    }

    /**
     * JSON文字列を {IdKey, NameKey} の配列へ解決する。
     * マスタに存在しないID（削除済み）は表示対象から除外する。
     *
     * @param string|null          $Json    JSON文字列
     * @param array<string,string> $NameMap ID→名称のマップ
     * @param string               $ListKey JSONのリストキー
     * @param string               $IdKey   IDのキー
     * @param string               $NameKey 名称のキー
     * @return array<int,array<string,string>> 解決結果
     */
    public static function Resolve(
        ?string $Json,
        array $NameMap,
        string $ListKey,
        string $IdKey,
        string $NameKey
    ): array {
        $Resolved = [];
        foreach (self::ExtractIds($Json, $ListKey, $IdKey) as $Id) {
            if (isset($NameMap[$Id])) {
                $Resolved[] = [$IdKey => $Id, $NameKey => $NameMap[$Id]];
            }
        }
        return $Resolved;
    }

    /**
     * JSON文字列を更新履歴の表示用テキスト（名称のカンマ区切り）へ変換する。
     *
     * @param string|null          $Json    JSON文字列
     * @param array<string,string> $NameMap ID→名称のマップ
     * @param string               $ListKey JSONのリストキー
     * @param string               $IdKey   IDのキー
     * @return string|null 名称のカンマ区切り。要素が無い場合は null
     */
    public static function ToDisplayText(
        ?string $Json,
        array $NameMap,
        string $ListKey,
        string $IdKey
    ): ?string {
        $Names = [];
        foreach (self::ExtractIds($Json, $ListKey, $IdKey) as $Id) {
            if (isset($NameMap[$Id])) {
                $Names[] = $NameMap[$Id];
            }
        }
        return empty($Names) ? null : implode('、', $Names);
    }
}
