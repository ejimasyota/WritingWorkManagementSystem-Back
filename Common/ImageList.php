<?php
/**
 * ImageList.php
 * 画像の複数登録（画像一覧）の共通ユーティリティ
 *
 * 下記の各テーブルは、画像の一覧を imgList 列にJSON文字列で保持する。
 *   {"Images":[{"Path":"/Uploads/Characters/..","Caption":".."}]}
 *   キャラ・組織・チーム・舞台・建物・武器・アイテム・作品メモ
 *
 * ・先頭の画像を代表画像とし、imgPath 列には代表画像のパスを保持する。
 *   imgPath は関係図・掛け合い詳細・画像一覧（v_workimage）などが参照しているため、
 *   保存時は本クラスの Prepare の結果で imgPath・imgList を常に揃えて更新すること。
 * ・imgList が未設定の行（移行前のデータ）は imgPath を1件目として扱う。
 * ・ファイルの書き込み・削除は DB の Commit 後に行うため、Prepare では保留リストのみを作成し、
 *   Commit 後に ApplyFiles を呼び出す（従来の単一画像の処理と同じ方針）。
 * ・画面側の処理は Front/Js/Common.js の CreateImageListController に集約している。
 */

require_once __DIR__ . '/FileSafety.php';

class ImageList
{
    /** 1件あたりに登録できる画像の上限枚数（画面側の IMAGE_LIST_MAX_COUNT と揃える） */
    public const MAX_COUNT = 5;

    /** 画像の説明文の上限文字数（画面側の IMAGE_LIST_CAPTION_MAX_LENGTH と揃える） */
    public const CAPTION_MAX_LENGTH = 100;

    /** 公開用パス・物理パスの基点となる Uploads ディレクトリ */
    private const UPLOADS_DIR = __DIR__ . '/../../Uploads/';

    /**
     * 保持している画像一覧（imgList）を [['Path' => .., 'Caption' => ..], ..] の配列へ変換する
     * imgList が未設定の場合（移行前のデータ）は imgPath を1件目として扱う
     *
     * @param string|null $ImgList 画像一覧のJSON文字列
     * @param string|null $ImgPath 代表画像のパス
     * @return array<int, array{Path:string, Caption:string}> 画像の配列
     */
    public static function Parse($ImgList, $ImgPath): array
    {
        $Decoded = !empty($ImgList) ? json_decode($ImgList, true) : null;
        if (is_array($Decoded) && isset($Decoded['Images']) && is_array($Decoded['Images'])) {
            $Images = [];
            foreach ($Decoded['Images'] as $Image) {
                if (is_array($Image) && !empty($Image['Path'])) {
                    $Images[] = ['Path' => (string) $Image['Path'], 'Caption' => (string) ($Image['Caption'] ?? '')];
                }
            }
            return $Images;
        }
        return !empty($ImgPath) ? [['Path' => (string) $ImgPath, 'Caption' => '']] : [];
    }

    /**
     * 保持している画像のパスを全て取得する（データ削除時の画像ファイルの削除に使用する）
     *
     * @param string|null $ImgList 画像一覧のJSON文字列
     * @param string|null $ImgPath 代表画像のパス
     * @return array<int, string> 画像のパス（重複なし）
     */
    public static function CollectPaths($ImgList, $ImgPath): array
    {
        $Paths = array_column(self::Parse($ImgList, $ImgPath), 'Path');
        if (!empty($ImgPath)) {
            $Paths[] = (string) $ImgPath;
        }
        return array_values(array_unique($Paths));
    }

    /**
     * 画面から送られた画像一覧を検証し、DB登録値とCommit後のファイル操作の保留リストを作成する
     *
     * 画面からは [{Path: 既存画像のパス, Data: 新規画像のデータURL, Caption: 説明文}] の順に送られる。
     * 新規画像はデータURL、既存画像はパスのみが送られ、保持していないパスは受け付けない（任意のパスの登録を防ぐ）。
     *
     * @param array  $Images     画面から送られた画像一覧
     * @param array  $OldImages  保持している画像一覧（Parse の結果。新規登録時は空配列）
     * @param string $UploadDir  保存先（Uploads 配下のディレクトリ名。例: Characters）
     * @param string $FilePrefix ファイル名の接頭辞（例: chara）
     * @return array 検証エラーの場合は ['Error' => メッセージ]。
     *               正常な場合は ['ImgPath' => 代表画像のパス, 'ImgList' => 画像一覧のJSON,
     *               'PendingWrites' => Commit後に書き込むファイル, 'PendingDeletes' => Commit後に削除するファイル]
     * @throws Exception 画像のデコードに失敗した場合・許可されていない形式の場合・サイズ超過の場合・保存先を作成できない場合
     */
    public static function Prepare(array $Images, array $OldImages, string $UploadDir, string $FilePrefix): array
    {
        /* 1. 枚数の上限チェック */
        if (count($Images) > self::MAX_COUNT) {
            return ['Error' => '画像は' . self::MAX_COUNT . '枚まで登録できます'];
        }

        $OldPaths = array_column($OldImages, 'Path');
        $NewImages = [];
        $PendingWrites = [];
        $NewImageTotalSize = 0;

        /* 2. 送られてきた順に画像一覧を組み立てる */
        foreach ($Images as $Image) {
            if (!is_array($Image)) {
                continue;
            }
            $Caption = trim((string) ($Image['Caption'] ?? ''));
            if (mb_strlen($Caption) > self::CAPTION_MAX_LENGTH) {
                return ['Error' => '画像の説明文は' . self::CAPTION_MAX_LENGTH . '文字以内で入力してください'];
            }
            $Data = (string) ($Image['Data'] ?? '');
            $Path = (string) ($Image['Path'] ?? '');

            if (strpos($Data, 'data:image') === 0) {
                /* 新しい画像データ（Base64）の場合：検証してCommit後に書き込む */
                $Decoded = self::DecodeImageData($Data);
                $NewImageTotalSize += strlen($Decoded['data']);
                // 暗号論的乱数を用いて推測できないファイル名を生成（uniqid() は時刻ベースで予測可能なため使用しない）
                $FileName = $FilePrefix . '_' . bin2hex(random_bytes(16)) . '.' . $Decoded['ext'];
                $PendingWrites[] = [
                    'path' => self::UPLOADS_DIR . $UploadDir . '/' . $FileName,
                    'data' => $Decoded['data'],
                ];
                $NewImages[] = ['Path' => '/Uploads/' . $UploadDir . '/' . $FileName, 'Caption' => $Caption];
            } elseif ($Path !== '') {
                /* 既存の画像の場合：保持している画像のパスのみ受け付ける */
                if (!in_array($Path, $OldPaths, true)) {
                    return ['Error' => '画像の情報が古くなっています。画面を再表示してください'];
                }
                $NewImages[] = ['Path' => $Path, 'Caption' => $Caption];
            }
        }

        /* 3. 1回の保存で追加できる画像の合計サイズのチェック
         *    Base64化で約1.33倍に膨らむため、送信サイズの上限（post_max_size）を超えないよう
         *    画像1枚分の上限と同じ値を合計の上限とする（画面側でも同じ判定を行う） */
        if ($NewImageTotalSize > FileSafety::MAX_IMAGE_SIZE) {
            return ['Error' => '一度に追加できる画像は合計' . (FileSafety::MAX_IMAGE_SIZE / 1024 / 1024) . 'MBまでです。一度保存してから追加してください'];
        }

        /* 4. 保存先ディレクトリが存在しない場合は作成する */
        if (count($PendingWrites) > 0) {
            $Dir = self::UPLOADS_DIR . $UploadDir . '/';
            if (!is_dir($Dir) && !mkdir($Dir, 0755, true)) {
                throw new Exception('保存先ディレクトリの作成に失敗しました');
            }
        }

        /* 5. 一覧から外れた既存画像は Commit 後に削除する */
        $KeptPaths = array_column($NewImages, 'Path');
        $PendingDeletes = [];
        foreach ($OldPaths as $OldPath) {
            if (!in_array($OldPath, $KeptPaths, true)) {
                $PendingDeletes[] = $OldPath;
            }
        }

        /* 6. DB登録値（代表画像は先頭の画像。1枚も無い場合はいずれもNULL） */
        return [
            'ImgPath'        => count($NewImages) > 0 ? $NewImages[0]['Path'] : null,
            'ImgList'        => count($NewImages) > 0
                ? json_encode(['Images' => $NewImages], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : null,
            'PendingWrites'  => $PendingWrites,
            'PendingDeletes' => $PendingDeletes,
        ];
    }

    /**
     * Commit 後のファイル操作（新規画像の書き込み・一覧から外れた画像の削除）を行う
     * ファイル操作の失敗は DB 状態を巻き戻せないため、ログ記録に留める
     *
     * @param array  $Prepared     Prepare の結果（未実行の場合は空配列）
     * @param string $FunctionName 機能名（ログ出力用）
     * @param string $UserId       ユーザーID（ログ出力用）
     */
    public static function ApplyFiles(array $Prepared, string $FunctionName, string $UserId): void
    {
        foreach ($Prepared['PendingWrites'] ?? [] as $PendingWrite) {
            if (@file_put_contents($PendingWrite['path'], $PendingWrite['data']) === false) {
                Logger::Error($FunctionName, '画像書込失敗（Commit後）', $UserId, $PendingWrite['path']);
            }
        }
        self::DeleteFiles($Prepared['PendingDeletes'] ?? []);
    }

    /**
     * 画像ファイルを削除する（Uploads 配下以外のパスは FileSafety 側で削除しない）
     *
     * @param array<int, string> $Paths 画像の公開用パス（例: /Uploads/Characters/..）
     */
    public static function DeleteFiles(array $Paths): void
    {
        foreach ($Paths as $Path) {
            FileSafety::SafeUnlinkInUploads(__DIR__ . '/../../' . ltrim($Path, '/'));
        }
    }

    /**
     * 画面から送られた画像データ（Base64のデータURL）をデコードし、形式・サイズを検証する
     *
     * @param string $ImageData データURL（data:image/..;base64,..）
     * @return array{data:string, ext:string} デコード済みのバイナリと拡張子
     * @throws Exception デコードに失敗した場合・許可されていない形式の場合・サイズ超過の場合
     */
    private static function DecodeImageData(string $ImageData): array
    {
        // 1. ヘッダーと本体を分離し、Base64文字列をバイナリデータにデコード
        $Parts = explode(',', $ImageData, 2);
        $BinaryData = base64_decode($Parts[1] ?? '', true);
        if ($BinaryData === false || $BinaryData === '') {
            throw new Exception('画像のデコードに失敗しました');
        }

        // 2. finfo で実バイナリのMIMEタイプを検証（ヘッダー文字列だけを信用しない）
        $Finfo = new finfo(FILEINFO_MIME_TYPE);
        $DetectedMime = $Finfo->buffer($BinaryData);
        $MimeToExt = [
            'image/png'  => 'png',
            'image/jpeg' => 'jpg',
            'image/gif'  => 'gif',
            'image/webp' => 'webp',
        ];
        if (!isset($MimeToExt[$DetectedMime])) {
            throw new Exception('許可されていない画像形式です');
        }

        // 3. サイズ上限の検証（画面側の第一次チェックは改ざん可能なため API 側でも判定する）
        if (!FileSafety::IsAllowedImageSize($BinaryData)) {
            throw new Exception(FileSafety::GetImageSizeErrorMessage());
        }

        return ['data' => $BinaryData, 'ext' => $MimeToExt[$DetectedMime]];
    }
}
