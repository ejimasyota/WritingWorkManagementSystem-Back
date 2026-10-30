<?php
require_once __DIR__ . '/../Common/Database.php';
require_once __DIR__ . '/../Common/Logger.php';
require_once __DIR__ . '/../Common/Response.php';
require_once __DIR__ . '/../Common/Auth.php';
require_once __DIR__ . '/../Common/Env.php';

// POSTメソッドのみ許可
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::Error('不正なリクエストです', 405);
}

// エラーレポート設定（本番では display_errors は 0 のまま）
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

$FunctionName = 'AI相談';
$UserId = '';

register_shutdown_function(function () use (&$FunctionName, &$UserId) {
    $err = error_get_last();
    if ($err !== null) {
        Logger::Error($FunctionName, 'PHP Fatal Error (shutdown)', $UserId, json_encode($err, JSON_UNESCAPED_UNICODE));
        // 可能ならクライアントにエラーを返す（ただし出力済みの場合は無効）
        try { Response::Error('サーバー内部エラーが発生しました'); } catch (Exception $_) {}
    }
});

try {
    $Input = Response::GetJsonInput();
    $ThreadId = trim((string)($Input['ThreadId'] ?? ''));
    $WorkId = $AuthWorkId ?? '';
    $UserMessage = trim($Input['UserMessage'] ?? '');
    $UseWorkContext = $Input['UseWorkContext'] ?? false;
    $UserId = $AuthUserId;

    Logger::Info($FunctionName, '会話処理開始', $UserId);

    if (empty($WorkId)) Response::Error('作品IDが指定されていません');
    if ($UserMessage === '') Response::Error('メッセージを入力してください');
    // 継続会話でスレッドIDが指定された場合のみ、UUIDの書式を検証する
    if ($ThreadId !== '' && preg_match('/\A[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}\z/', $ThreadId) !== 1) {
        Response::Error('スレッドIDが不正です');
    }

    $Pdo = Database::GetConnection();

    // 新規会話の場合はスレッドIDをAPI側で発行する
    // （クライアント発行の乱数に依存せず、DBのUUID生成に統一する）
    if ($ThreadId === '') {
        $ThreadId = $Pdo->query('SELECT gen_random_uuid()')->fetchColumn();
    }

    // .env 読み込み（解釈は Env クラスへ集約している。未設定時は環境変数へ委ねる）
    $ApiKey = trim((string)(Env::Get('GEMINI_API_KEY') ?? (getenv('GEMINI_API_KEY') ?: '')));
    $ApiUrlFromEnv = trim((string)(Env::Get('GEMINI_API_URL') ?? (getenv('GEMINI_API_URL') ?: '')));
    $EnvModel = trim((string)(Env::Get('GEMINI_MODEL') ?? (getenv('GEMINI_MODEL') ?: '')));

    // どちらか一方は必須（Workerプロキシを使うなら ApiUrlFromEnv があれば OK）
    if (empty($ApiKey) && empty($ApiUrlFromEnv)) {
        Response::Error('Gemini APIキーまたはプロキシURLが見つかりません');
    }

    $ModelName = $EnvModel ?: 'gemini-2.5-flash';

    if (!empty($ApiUrlFromEnv)) {
        // .env に Worker の URL を入れている想定（例: https://xxxxx.workers.dev）
        $ApiUrl = rtrim($ApiUrlFromEnv, '/');
    } else {
        $ApiUrl = "https://generativelanguage.googleapis.com/v1beta/models/{$ModelName}:generateContent";
    }

    // 作品コンテキスト
    $Context = '';
    if ($UseWorkContext) $Context = GenerateWorkContext($Pdo, $WorkId);

    // 履歴取得（直近10件に制限してリクエストボディ肥大化を防ぐ）
    $HistorySql = "
        SELECT postMessage AS post_message, responseMessage AS response_message
        FROM ConversationInfo
        WHERE threadId = :threadId AND workId = :workId
        ORDER BY registDate DESC
        LIMIT 10
    ";
    $HistoryStmt = $Pdo->prepare($HistorySql);
    $HistoryStmt->execute([':threadId' => $ThreadId, ':workId' => $WorkId]);
    $History = array_reverse($HistoryStmt->fetchAll(PDO::FETCH_ASSOC)); // 古い順に戻す

    // System prompt を文字列化
    $SystemPrompt = "あなたは創作活動を支援するAIアシスタントです。作品の設定、キャラクター、ストーリーについてのアドバイスを提供します。丁寧かつ協力的に、親しみやすい口調で回答してください。";
    if (!empty($Context)) $SystemPrompt .= "\n\n以下は現在の作品設定情報です。この情報を参考に回答してください：\n" . $Context;

    // contents 構築
    $Contents = [];
    $Contents[] = [
        'role' => 'user',
        'parts' => [['text' => "[システム指示]\n" . $SystemPrompt]]
    ];
    foreach ($History as $Conv) {
        if (!empty($Conv['post_message'])) {
            $Contents[] = ['role' => 'user', 'parts' => [['text' => $Conv['post_message']]]];
        }
        if (!empty($Conv['response_message'])) {
            $Contents[] = ['role' => 'model', 'parts' => [['text' => $Conv['response_message']]]];
        }
    }
    $Contents[] = ['role' => 'user', 'parts' => [['text' => $UserMessage]]];

    $RequestBody = [
        'contents' => $Contents,
        'generationConfig' => [
            'temperature' => 0.7,
            'maxOutputTokens' => 2048, // 実運用では下げめに。必要なら増やす
            'topP' => 0.95,
            'topK' => 64
        ]
    ];

    $JsonBody = json_encode($RequestBody, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($JsonBody === false) {
        Logger::Error($FunctionName, 'json_encode failed', $UserId, json_last_error_msg());
        Response::Error('リクエスト生成に失敗しました');
    }

    // プロキシ（Worker）を使うかどうか
    $UseProxy = !empty($ApiUrlFromEnv);

    // RequestUrl と Headers の作成
    $UseKeyQuery = strpos($ApiKey, 'AIza') === 0;
    // 直接 Gemini 呼ぶ場合は key/Authorization を付ける。プロキシ使用時は不要（Worker がキーを持つ想定）
    if ($UseProxy) {
        $RequestUrl = $ApiUrl; // Worker の URL (例: https://xxx.workers.dev)
    } else {
        $RequestUrl = $ApiUrl . ($UseKeyQuery ? ('?key=' . urlencode($ApiKey)) : '');
    }

    $Headers = ['Content-Type: application/json'];
    if (!$UseProxy && !$UseKeyQuery && !empty($ApiKey)) {
        $Headers[] = 'Authorization: Bearer ' . $ApiKey;
    }

    // cURL
    $Ch = curl_init();
    // optional: IPv4 指定が必要なら有効にする（Worker を使う場合通常不要）
    if (defined('CURL_IPRESOLVE_V4')) {
        curl_setopt($Ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
    }
    curl_setopt($Ch, CURLOPT_URL, $RequestUrl);
    curl_setopt($Ch, CURLOPT_POST, true);
    curl_setopt($Ch, CURLOPT_POSTFIELDS, $JsonBody);
    curl_setopt($Ch, CURLOPT_HTTPHEADER, $Headers);
    curl_setopt($Ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($Ch, CURLOPT_TIMEOUT, 120);
    curl_setopt($Ch, CURLOPT_CONNECTTIMEOUT, 10);

    // リトライ（指数バックオフ）。最後の段でフォールバックなし。
    $maxRetries = 3;
    $attempt = 0;
    $ApiResponse = null;
    $HttpCode = null;
    $curlErr = '';
    do {
        $attempt++;
        $ApiResponse = curl_exec($Ch);
        $curlErr = curl_error($Ch);
        $curlNo = curl_errno($Ch);
        $HttpCode = curl_getinfo($Ch, CURLINFO_HTTP_CODE);

        if ($ApiResponse === false) {
            // Logger::Error は第4引数（エラー内容）まで必須のため、
            // エラー本文は第2引数に含めず第4引数へ渡す
            Logger::Error($FunctionName, "cURL error (errno={$curlNo}) attempt={$attempt}", $UserId, $curlErr);
        }

        if (in_array($HttpCode, [429, 500, 502, 503, 504]) || $ApiResponse === false || $HttpCode === 0) {
            $wait = pow(2, $attempt);
            Logger::Error($FunctionName, "API request failed (HTTP {$HttpCode}), retry {$attempt} after {$wait}s", $UserId, $ApiResponse ?: $curlErr);
            sleep($wait);
        } else {
            break;
        }
    } while ($attempt < $maxRetries);
    curl_close($Ch);

    // 応答チェック
    if ($ApiResponse === false || $HttpCode === 0 || $ApiResponse === null || $ApiResponse === '') {
        Logger::Error($FunctionName, 'Empty or failed response from API', $UserId, "HTTP: {$HttpCode}, curl_error: {$curlErr}");
        Response::Error('AIからの応答が取得できませんでした（接続エラー）');
    }

    // ログ（エラー解析のため応答本文を残す）
    if (in_array($HttpCode, [400, 401, 403, 429])) {
        Logger::Error($FunctionName, "API returned HTTP {$HttpCode}", $UserId, $ApiResponse);
    }

    if ($HttpCode === 429) {
        Response::Error('AI APIの利用制限に達しました。時間を置いて再試行してください。');
    }
    if ($HttpCode === 400) {
        Response::Error('AIへのリクエストが不正です（400）。ログを確認してください。');
    }
    if ($HttpCode < 200 || $HttpCode >= 300) {
        Logger::Error($FunctionName, 'API Error', $UserId, "HTTP Code: $HttpCode, Response: $ApiResponse");
        Response::Error('AIからの応答取得に失敗しました（エラーコード: ' . $HttpCode . '）');
    }

    $ResponseData = json_decode($ApiResponse, true);
    if ($ResponseData === null && json_last_error() !== JSON_ERROR_NONE) {
        Logger::Error($FunctionName, 'Invalid JSON from API', $UserId, 'json_error: ' . json_last_error_msg() . ', raw: ' . $ApiResponse);
        Response::Error('AIの応答が不正です（JSONパース失敗）');
    }

    // 応答抽出（柔軟に探す）
    $AiMessage = '';
    if (!empty($ResponseData['candidates'][0]['content']['parts'][0]['text'])) {
        $AiMessage = $ResponseData['candidates'][0]['content']['parts'][0]['text'];
    } elseif (!empty($ResponseData['candidates'][0]['content']['parts'])) {
        $texts = [];
        foreach ($ResponseData['candidates'][0]['content']['parts'] as $p) {
            if (!empty($p['text'])) $texts[] = $p['text'];
        }
        $AiMessage = implode("\n", $texts);
    } elseif (!empty($ResponseData['candidates'][0]['output'])) {
        $AiMessage = is_string($ResponseData['candidates'][0]['output']) ? $ResponseData['candidates'][0]['output'] : json_encode($ResponseData['candidates'][0]['output'], JSON_UNESCAPED_UNICODE);
    } else {
        Logger::Error($FunctionName, 'API Empty Response', $UserId, "Full response: $ApiResponse");
        Response::Error('AIからの応答が空でした');
    }

    // DB 保存
    Database::BeginTransaction();
    $InsertSql = "
        INSERT INTO ConversationInfo (
            threadId, workId, postMessage, responseMessage, useWorkContext,
            registDate, updateDate, registUser, updateUser
        ) VALUES (
            :threadId, :workId, :postMessage, :responseMessage, :useWorkContext,
            CURRENT_TIMESTAMP(3), CURRENT_TIMESTAMP(3), :registUser, :updateUser
        )
        RETURNING conversationId, updateDate
    ";
    $InsertStmt = $Pdo->prepare($InsertSql);
    $InsertStmt->execute([
        ':threadId' => $ThreadId,
        ':workId' => $WorkId,
        ':postMessage' => $UserMessage,
        ':responseMessage' => $AiMessage,
        ':useWorkContext' => $UseWorkContext ? 'true' : 'false',
        ':registUser' => $UserId,
        ':updateUser' => $UserId
    ]);
    $Result = $InsertStmt->fetch(PDO::FETCH_ASSOC);
    $ConvId = $Result['conversationid'] ?? $Result['conversationId'] ?? null;
    $NewUpdateDate = $Result['updatedate'] ?? $Result['updateDate'] ?? null;
    Database::Commit();

    Logger::Info($FunctionName, '会話処理終了', $UserId);

    // ThreadId は新規会話でAPI側が発行した値をクライアントへ返す
    Response::Success(['ConvId' => $ConvId, 'ThreadId' => $ThreadId, 'AiResponse' => $AiMessage, 'UpdateDate' => $NewUpdateDate]);

} catch (Exception $E) {
    try { Database::Rollback(); } catch (Exception $_) {}
    Logger::Error($FunctionName, '会話処理エラー', $UserId, $E->getMessage());
    Response::Error('AI会話の処理に失敗しました');
}

function GenerateWorkContext($Pdo, $WorkId) {
    $Context = [];

    $WorkSql = "SELECT workTitle, genre FROM WorkInfo WHERE workId = :workId";
    $WorkStmt = $Pdo->prepare($WorkSql);
    $WorkStmt->execute([':workId' => $WorkId]);
    $Work = $WorkStmt->fetch(PDO::FETCH_ASSOC);
    if ($Work) {
        $Context[] = "【作品タイトル】" . ($Work['workTitle'] ?? $Work['worktitle'] ?? '');
    }

    $CharaSql = "SELECT charaName, roleInfo, personality FROM CharacterInfo WHERE workId = :workId LIMIT 20";
    $CharaStmt = $Pdo->prepare($CharaSql);
    $CharaStmt->execute([':workId' => $WorkId]);
    $Characters = $CharaStmt->fetchAll(PDO::FETCH_ASSOC);
    if (!empty($Characters)) {
        $CharaList = [];
        foreach ($Characters as $Chara) {
            $CharaInfo = $Chara['charaName'] ?? $Chara['charaname'] ?? '';
            if (!empty($Chara['roleInfo'] ?? $Chara['roleinfo'])) {
                $role = $Chara['roleInfo'] ?? $Chara['roleinfo'];
                $CharaInfo .= "（" . $role . "）";
            }
            $CharaList[] = $CharaInfo;
        }
        $Context[] = "【登場キャラクター】" . implode('、', $CharaList);
    }

    $OrgSql = "SELECT orgName FROM OrganizationInfo WHERE workId = :workId LIMIT 10";
    $OrgStmt = $Pdo->prepare($OrgSql);
    $OrgStmt->execute([':workId' => $WorkId]);
    $Orgs = $OrgStmt->fetchAll(PDO::FETCH_ASSOC);
    if (!empty($Orgs)) {
        $OrgNames = array_map(function($o) { return $o['orgName'] ?? $o['orgname'] ?? ''; }, $Orgs);
        $Context[] = "【組織】" . implode('、', $OrgNames);
    }

    $StageSql = "SELECT stageName FROM StageInfo WHERE workId = :workId LIMIT 10";
    $StageStmt = $Pdo->prepare($StageSql);
    $StageStmt->execute([':workId' => $WorkId]);
    $Stages = $StageStmt->fetchAll(PDO::FETCH_ASSOC);
    if (!empty($Stages)) {
        $StageNames = array_map(function($s) { return $s['stageName'] ?? $s['stagename'] ?? ''; }, $Stages);
        $Context[] = "【舞台】" . implode('、', $StageNames);
    }

    $TimelineSql = "SELECT title FROM TimelineInfo WHERE workId = :workId ORDER BY eventDate LIMIT 10";
    $TimelineStmt = $Pdo->prepare($TimelineSql);
    $TimelineStmt->execute([':workId' => $WorkId]);
    $Timelines = $TimelineStmt->fetchAll(PDO::FETCH_ASSOC);
    if (!empty($Timelines)) {
        $TimelineTitles = array_map(function($t) { return $t['title'] ?? ''; }, $Timelines);
        $Context[] = "【主な出来事】" . implode('、', $TimelineTitles);
    }

    $TermSql = "SELECT termName, description FROM TermInfo WHERE workId = :workId LIMIT 15";
    $TermStmt = $Pdo->prepare($TermSql);
    $TermStmt->execute([':workId' => $WorkId]);
    $Terms = $TermStmt->fetchAll(PDO::FETCH_ASSOC);
    if (!empty($Terms)) {
        $TermList = [];
        foreach ($Terms as $Term) {
            $TermInfo = $Term['termName'] ?? $Term['termname'] ?? '';
            if (!empty($Term['description'])) {
                $TermInfo .= "：" . mb_substr($Term['description'], 0, 50);
            }
            $TermList[] = $TermInfo;
        }
        $Context[] = "【作中用語】" . implode('、', $TermList);
    }

    return implode("\n", $Context);
}