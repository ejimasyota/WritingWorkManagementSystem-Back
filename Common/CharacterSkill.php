<?php
/**
 * CharacterSkill.php
 * キャラクターの能力・武器・技（JSON保持）の共通ユーティリティ
 *
 * CharacterInfo.powers / weapons / moves は、
 * 能力・武器・技のIDのみを保持するJSON文字列である。
 *   能力：{"Powers":[{"PowerId":1},{"PowerId":2}]}
 *   武器：{"Weapons":[{"WeaponId":1},{"WeaponId":2}]}
 *   技　：{"Moves":[{"MoveId":1},{"MoveId":2}]}
 *
 * 複数設定に対応するためFK列から移行した経緯があり、外部キー制約による
 * ON DELETE SET NULL が効かない。そのため能力・武器・技マスタの削除時は、
 * 本クラスを通じてキャラ側のJSONからも該当IDを除去する。
 *
 * 除去は該当作品のキャラのみを対象とし、CharacterInfo.updateDate は更新しない。
 * updateDate は画面側の排他制御に使用しているため、マスタ削除を契機に
 * 編集中のキャラが競合エラーとなることを避ける（FK時代の挙動と揃える）。
 */

class CharacterSkill
{
    /** 能力（JSONのルート要素名 / ID項目名 / 格納列） */
    const POWER = ['Key' => 'Powers', 'IdKey' => 'PowerId', 'Column' => 'powers'];

    /** 武器（JSONのルート要素名 / ID項目名 / 格納列） */
    const WEAPON = ['Key' => 'Weapons', 'IdKey' => 'WeaponId', 'Column' => 'weapons'];

    /** 技（JSONのルート要素名 / ID項目名 / 格納列） */
    const MOVE  = ['Key' => 'Moves',  'IdKey' => 'MoveId',  'Column' => 'moves'];

    /**
     * 指定した作品のキャラクターから、能力・武器・技のIDをJSONごと除去する
     * @param PDO    $Pdo    DB接続
     * @param string $WorkId 作品ID
     * @param array  $Target self::POWER / self::WEAPON / self::MOVE のいずれか
     * @param mixed  $Id     除去対象のID
     * @return int 更新したキャラクターの件数
     */
    public static function RemoveId(PDO $Pdo, string $WorkId, array $Target, $Id): int
    {
        // 列名は本クラスの定数のみを受け付けるため、識別子の埋め込みは固定リテラルとなる
        $Column = $Target['Column'];
        if ($Column !== self::POWER['Column']
            && $Column !== self::WEAPON['Column']
            && $Column !== self::MOVE['Column']) {
            return 0;
        }

        /* 1. 対象となり得るキャラクターを取得する */
        $SelectStmt = $Pdo->prepare("
            SELECT charaId, {$Column} AS skillJson
            FROM CharacterInfo
            WHERE workId = :workId AND {$Column} IS NOT NULL
        ");
        $SelectStmt->bindValue(':workId', $WorkId, PDO::PARAM_STR);
        $SelectStmt->execute();
        $Rows = $SelectStmt->fetchAll();
        if (empty($Rows)) {
            return 0;
        }

        /* 2. 該当IDを除いたJSONへ書き換える（updateDate は更新しない） */
        $UpdateStmt = $Pdo->prepare("
            UPDATE CharacterInfo SET {$Column} = :skillJson
            WHERE charaId = :charaId AND workId = :workId
        ");

        $UpdatedCount = 0;
        foreach ($Rows as $Row) {
            $NewJson = self::RemoveIdFromJson($Target, $Row['skilljson'] ?? null, $Id);
            // 除去対象が含まれていなかった場合は更新しない
            if ($NewJson === false) {
                continue;
            }
            $UpdateStmt->bindValue(':skillJson', $NewJson, $NewJson === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $UpdateStmt->bindValue(':charaId',   $Row['charaid'], PDO::PARAM_STR);
            $UpdateStmt->bindValue(':workId',    $WorkId,         PDO::PARAM_STR);
            $UpdateStmt->execute();
            $UpdatedCount++;
        }
        return $UpdatedCount;
    }

    /**
     * 指定した技を、関連能力を保持していないキャラクターの技一覧から除去する
     *
     * 技の関連能力（MoveInfo.powerId）が変更されると、その技を保持しているが
     * 変更後の能力を保持していないキャラクターが不整合となる。
     * 不整合のまま残ると SaveCharacter.php の整合性確認で当該キャラを保存できなくなるため、
     * 変更を行ったAPIから本メソッドを呼び出して整合を取る。
     *
     * 関連能力が未設定（汎用技）へ変更された場合はどのキャラクターでも保持できるため、
     * 呼び出し元で $PowerId に null を渡した場合は何もしない。
     *
     * @param PDO         $Pdo     DB接続
     * @param string      $WorkId  作品ID
     * @param mixed       $MoveId  対象の技ID
     * @param mixed|null  $PowerId 変更後の関連能力ID（nullの場合は対象外）
     * @return int 更新したキャラクターの件数
     */
    public static function RemoveMoveFromCharactersWithoutPower(PDO $Pdo, string $WorkId, $MoveId, $PowerId): int
    {
        // 1. 汎用技（関連能力なし）はどのキャラクターでも保持できるため対象外とする
        if ($PowerId === null || $PowerId === '') {
            return 0;
        }

        /* 2. 当該技を保持している可能性のあるキャラクターを取得する */
        $SelectStmt = $Pdo->prepare("
            SELECT charaId, powers, moves
            FROM CharacterInfo
            WHERE workId = :workId AND moves IS NOT NULL
        ");
        $SelectStmt->bindValue(':workId', $WorkId, PDO::PARAM_STR);
        $SelectStmt->execute();
        $Rows = $SelectStmt->fetchAll();
        if (empty($Rows)) {
            return 0;
        }

        /* 3. 変更後の能力を保持していないキャラクターから当該技を除去する */
        $UpdateStmt = $Pdo->prepare("
            UPDATE CharacterInfo SET moves = :moves
            WHERE charaId = :charaId AND workId = :workId
        ");

        $UpdatedCount = 0;
        foreach ($Rows as $Row) {
            // 変更後の能力を保持しているキャラクターは整合しているため対象外
            if (self::ContainsId(self::POWER, $Row['powers'] ?? null, $PowerId)) {
                continue;
            }
            $NewJson = self::RemoveIdFromJson(self::MOVE, $Row['moves'] ?? null, $MoveId);
            // 当該技を保持していなかった場合は更新しない
            if ($NewJson === false) {
                continue;
            }
            $UpdateStmt->bindValue(':moves',   $NewJson, $NewJson === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $UpdateStmt->bindValue(':charaId', $Row['charaid'], PDO::PARAM_STR);
            $UpdateStmt->bindValue(':workId',  $WorkId,         PDO::PARAM_STR);
            $UpdateStmt->execute();
            $UpdatedCount++;
        }
        return $UpdatedCount;
    }

    /**
     * JSON文字列に該当IDが含まれるかを判定する
     * @param array       $Target self::POWER / self::WEAPON / self::MOVE のいずれか
     * @param string|null $Value  JSON文字列
     * @param mixed       $Id     判定対象のID
     * @return bool 含まれていれば true
     */
    private static function ContainsId(array $Target, $Value, $Id): bool
    {
        if ($Value === null || trim((string) $Value) === '') {
            return false;
        }
        $Decoded = json_decode((string) $Value, true);
        if (!is_array($Decoded) || !isset($Decoded[$Target['Key']]) || !is_array($Decoded[$Target['Key']])) {
            return false;
        }
        foreach ($Decoded[$Target['Key']] as $Row) {
            if (!is_array($Row) || !isset($Row[$Target['IdKey']])) {
                continue;
            }
            if ((string) $Row[$Target['IdKey']] === (string) $Id) {
                return true;
            }
        }
        return false;
    }

    /**
     * JSON文字列から該当IDの要素を除去する
     * @param array       $Target self::POWER / self::WEAPON / self::MOVE のいずれか
     * @param string|null $Value  現在のJSON文字列
     * @param mixed       $Id     除去対象のID
     * @return string|null|false 除去後のJSON（全件消えた場合はNULL）。除去対象が無い場合は false
     */
    private static function RemoveIdFromJson(array $Target, $Value, $Id)
    {
        // 1. 未設定・JSONとして解釈できない場合は対象外とする
        if ($Value === null || trim((string) $Value) === '') {
            return false;
        }
        $Decoded = json_decode((string) $Value, true);
        if (!is_array($Decoded) || !isset($Decoded[$Target['Key']]) || !is_array($Decoded[$Target['Key']])) {
            return false;
        }

        // 2. 該当IDを除いた行を組み立てる
        $Remained = [];
        $Removed  = false;
        foreach ($Decoded[$Target['Key']] as $Row) {
            if (!is_array($Row) || !isset($Row[$Target['IdKey']])) {
                continue;
            }
            if ((string) $Row[$Target['IdKey']] === (string) $Id) {
                $Removed = true;
                continue;
            }
            $Remained[] = [$Target['IdKey'] => (int) $Row[$Target['IdKey']]];
        }

        // 3. 除去対象が含まれていない場合は更新不要とする
        if (!$Removed) {
            return false;
        }

        // 4. 全件除去された場合はNULLへ戻す
        if (count($Remained) === 0) {
            return null;
        }
        return json_encode([$Target['Key'] => $Remained], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
