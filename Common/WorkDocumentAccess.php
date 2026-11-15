<?php
/** 作品一覧からの帳票取得でも、セッションの選択作品を変更せずに対象作品を検証する。 */
final class WorkDocumentAccess
{
    public static function Check(PDO $Pdo, string $WorkId, string $UserId, bool $Admin, bool $Edit = false): void
    {
        if ($WorkId === '') throw new InvalidArgumentException('作品IDが指定されていません');
        $Stmt = $Pdo->prepare('SELECT w.projectLockFlg, c.userLockFlg, c.isCreator FROM WorkInfo w LEFT JOIN CreaterList c ON c.workId = w.workId AND c.userId = :userId WHERE w.workId = :workId');
        $Stmt->execute([':userId'=>$UserId, ':workId'=>$WorkId]);
        $Row = $Stmt->fetch();
        if (!$Row || (!$Admin && ($Row['iscreator'] === null || !empty($Row['userlockflg']) || !empty($Row['projectlockflg']) || ($Edit && empty($Row['iscreator']))))) {
            throw new RuntimeException('この作品を操作する権限がありません', 403);
        }
    }
}
