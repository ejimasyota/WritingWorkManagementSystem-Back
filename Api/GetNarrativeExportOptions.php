<?php
require_once __DIR__.'/../Common/Database.php';
require_once __DIR__.'/../Common/Auth.php';
require_once __DIR__.'/../Common/WorkDocumentAccess.php';
if ($_SERVER['REQUEST_METHOD'] !== 'GET') Response::Error('不正なリクエストです',405);
try {
    $WorkId = (string)($_GET['WorkId'] ?? '');
    $Pdo = Database::GetConnection();
    WorkDocumentAccess::Check($Pdo,$WorkId,(string)$AuthUserId,$IsAuthAdmin);
    $Result = [];
    foreach (['Story','Plot'] as $Kind) {
        $Stmt = $Pdo->prepare("SELECT {$Kind}Id AS \"{$Kind}Id\", {$Kind}Name AS \"{$Kind}Name\" FROM {$Kind}Info WHERE workId = :workId ORDER BY updateDate DESC, {$Kind}Id DESC");
        $Stmt->execute([':workId'=>$WorkId]);
        $Result[$Kind.'List'] = $Stmt->fetchAll();
    }
    Response::Success($Result);
} catch (Throwable $Error) {
    Response::Error($Error instanceof RuntimeException && $Error->getCode() === 403 ? $Error->getMessage() : '出力対象の取得に失敗しました', $Error->getCode() === 403 ? 403 : 400);
}
