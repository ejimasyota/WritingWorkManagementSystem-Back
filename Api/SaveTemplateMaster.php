<?php
/**
 * SaveTemplateMaster.php
 * テンプレートマスタ登録・更新API（管理者用）
 *
 * ジャンルに基づくストーリー・プロットの雛形を登録／更新する。作品スコープを
 * 持たないため UpdateHistory や WorkInfo の更新は行わない。
 * 更新は updateDate 一致による楽観ロック。
 *
 * ジャンルと区分は登録後の変更を認めない（既にそのテンプレートから作成された
 * 作品との対応が崩れるため）。更新時はリクエスト値を用いず、既存値を維持する。
 */

require_once __DIR__ . '/../Common/Database.php';
require_once __DIR__ . '/../Common/Logger.php';
require_once __DIR__ . '/../Common/Response.php';
require_once __DIR__ . '/../Common/Auth.php';
// コード区分値 → 名称変換（ジャンル・区分の妥当性チェックに使用）
require_once __DIR__ . '/../Common/CodeLabel.php';

// POSTメソッドのみ許可
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::Error('不正なリクエストです', 405);
}

// 管理者権限チェック（セッション + AdminInfo 実表照合）
if (!$IsAuthAdmin) {
    Response::Error('管理者権限が必要です', 403);
}

/** 区分 : ストーリー */
const TEMPLATE_DIVISION_STORY = 1;
/** 区分 : プロット */
const TEMPLATE_DIVISION_PLOT = 2;

/**
 * テンプレート行の入力を、保存用のJSON文字列へ正規化する
 *   ストーリー : {"Rows":[{"IndexNum":1,"FreeInput":true,"Monologue":false,"Narrator":"","Content":""}]}
 *   プロット   : {"Rows":[{"IndexNum":1,"StructureType":1,"Content":""}]}
 * 区分ごとに意味を持たない項目は保持しない。
 *
 * @param mixed $Rows         リクエストで受け取ったテンプレート行
 * @param int   $DivisionType 区分（1:ストーリー, 2:プロット）
 * @return array{0:?string,1:?string} [JSON文字列, エラーメッセージ]
 */
function BuildTemplateText($Rows, int $DivisionType): array
{
    if (!is_array($Rows) || count($Rows) === 0) {
        return [null, 'テンプレートを1行以上登録してください'];
    }

    $Normalized = [];
    foreach ($Rows as $Row) {
        if (!is_array($Row)) {
            continue;
        }
        $IndexNum = $Row['IndexNum'] ?? null;
        if ($IndexNum === null || $IndexNum === '' || !is_numeric($IndexNum) || (int) $IndexNum < 1) {
            return [null, '並び順は1以上の数値で入力してください'];
        }
        $Content = trim((string) ($Row['Content'] ?? ''));
        if ($Content === '') {
            return [null, '内容を入力してください'];
        }

        if ($DivisionType === TEMPLATE_DIVISION_PLOT) {
            $StructureType = $Row['StructureType'] ?? null;
            if ($StructureType === null || $StructureType === '' || (int) $StructureType < 1) {
                return [null, '起承転結を選択してください'];
            }
            $Normalized[] = [
                'IndexNum' => (int) $IndexNum,
                'StructureType' => (int) $StructureType,
                'Content' => $Content,
            ];
            continue;
        }

        $Normalized[] = [
            'IndexNum' => (int) $IndexNum,
            'FreeInput' => !empty($Row['FreeInput']),
            'Monologue' => !empty($Row['Monologue']),
            'Narrator' => trim((string) ($Row['Narrator'] ?? '')),
            'Content' => $Content,
        ];
    }

    if (empty($Normalized)) {
        return [null, 'テンプレートを1行以上登録してください'];
    }

    return [json_encode(['Rows' => $Normalized], JSON_UNESCAPED_UNICODE), null];
}

$FunctionName = 'テンプレートマスタ';
$UserId = $AuthUserId;

try {
    /** リクエストデータを取得 */
    $Input = Response::GetJsonInput();
    $TemplateId = !empty($Input['TemplateId']) ? $Input['TemplateId'] : null;
    $Genre = $Input['Genre'] ?? null;
    $DivisionType = $Input['DivisionType'] ?? null;
    $TemplateRows = $Input['TemplateRows'] ?? null;
    $UpdateDate = $Input['UpdateDate'] ?? null;

    $Operation = empty($TemplateId) ? '登録' : '更新';
    Logger::Info($FunctionName, $Operation . '処理開始', $UserId);

    $Pdo = Database::GetConnection();

    // 二重防御：AdminInfo 実表照合
    $AdminStmt = $Pdo->prepare('SELECT userId FROM AdminInfo WHERE userId = :userId');
    $AdminStmt->execute([':userId' => $UserId]);
    if (!$AdminStmt->fetch()) {
        Response::Error('管理者権限が必要です', 403);
    }

    if (empty($TemplateId)) {
        /** 新規登録：ジャンル・区分は必須 */
        if ($Genre === null || $Genre === '' || (int) $Genre < 1) {
            Response::Error('ジャンルを選択してください');
        }
        if ($DivisionType === null || $DivisionType === '' || (int) $DivisionType < 1) {
            Response::Error('区分を選択してください');
        }
        $Genre = (int) $Genre;
        $DivisionType = (int) $DivisionType;

        if (CodeLabel::Get('Genre', $Genre) === null || CodeLabel::Get('Genre', $Genre) === '') {
            Response::Error('選択されたジャンルは登録できません');
        }
        if ($DivisionType !== TEMPLATE_DIVISION_STORY && $DivisionType !== TEMPLATE_DIVISION_PLOT) {
            Response::Error('選択された区分は登録できません');
        }
    } else {
        /** 更新：ジャンル・区分はリードオンリーのため既存値を使用する */
        $CurrentStmt = $Pdo->prepare("
            SELECT genre, divisionType
            FROM TemplateMasterInfo
            WHERE templateId = :templateId
        ");
        $CurrentStmt->execute([':templateId' => $TemplateId]);
        $Current = $CurrentStmt->fetch();
        if (!$Current) {
            Response::Error('対象のテンプレートが見つかりません');
        }
        $Genre = (int) $Current['genre'];
        $DivisionType = (int) $Current['divisiontype'];
    }

    /** テンプレート行の正規化 */
    [$TemplateText, $ErrorMessage] = BuildTemplateText($TemplateRows, $DivisionType);
    if ($ErrorMessage !== null) {
        Response::Error($ErrorMessage);
    }

    Database::BeginTransaction();

    if (empty($TemplateId)) {
        /** 同一ジャンル × 区分の重複チェック */
        $DuplicateStmt = $Pdo->prepare("
            SELECT templateId
            FROM TemplateMasterInfo
            WHERE genre = :genre AND divisionType = :divisionType
        ");
        $DuplicateStmt->execute([':genre' => $Genre, ':divisionType' => $DivisionType]);
        if ($DuplicateStmt->fetch()) {
            Database::Rollback();
            Response::Error('選択したジャンルと区分の組み合わせは既に登録されています');
        }

        $Stmt = $Pdo->prepare("
            INSERT INTO TemplateMasterInfo (
                genre, divisionType, templateText,
                registDate, updateDate, registUser, updateUser
            ) VALUES (
                :genre, :divisionType, :templateText,
                CURRENT_TIMESTAMP(3), CURRENT_TIMESTAMP(3), :registUser, :updateUser
            )
            RETURNING templateId, updateDate
        ");
        $Stmt->execute([
            ':genre' => $Genre,
            ':divisionType' => $DivisionType,
            ':templateText' => $TemplateText,
            ':registUser' => $UserId,
            ':updateUser' => $UserId,
        ]);
        $Result = $Stmt->fetch();
        $NewTemplateId = $Result['templateid'];
        $NewUpdateDate = $Result['updatedate'];

    } else {
        /** 更新（updateDate 一致の楽観ロック） */
        $Stmt = $Pdo->prepare("
            UPDATE TemplateMasterInfo SET
                templateText = :templateText,
                updateDate = CURRENT_TIMESTAMP(3),
                updateUser = :updateUser
            WHERE templateId = :templateId AND updateDate = :updateDate
            RETURNING updateDate
        ");
        $Stmt->execute([
            ':templateId' => $TemplateId,
            ':templateText' => $TemplateText,
            ':updateUser' => $UserId,
            ':updateDate' => $UpdateDate,
        ]);
        $UpdateResult = $Stmt->fetch();

        if (!$UpdateResult) {
            Database::Rollback();
            Response::ConflictError();
        }

        $NewTemplateId = $TemplateId;
        $NewUpdateDate = $UpdateResult['updatedate'];
    }

    Database::Commit();

    Logger::Info($FunctionName, $Operation . '処理終了', $UserId);

    Response::Success([
        'TemplateId' => $NewTemplateId,
        'UpdateDate' => $NewUpdateDate,
        'Message' => 'テンプレートマスタを' . $Operation . 'しました',
    ]);

} catch (Exception $E) {
    Database::Rollback();
    Logger::Error($FunctionName, '保存処理エラー', $UserId, $E->getMessage());
    Response::Error('テンプレートマスタの保存に失敗しました');
}
