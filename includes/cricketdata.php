<?php
/**
 * Read-only CricketData client. One shared 15-minute cache protects the
 * Lifetime Free plan (100 API hits/day) from per-visitor polling.
 */
function cricketdata_active_key(): string
{
    $row = q("SELECT api_key_enc FROM api_keys WHERE provider = ? AND is_active = 1 ORDER BY id DESC LIMIT 1", ['cricketdata'])->fetch();
    return $row ? secret_decrypt((string)$row['api_key_enc']) : '';
}

function cricketdata_fetch(string $endpoint = 'cricScore', array $params = []): array
{
    if (!in_array($endpoint, ['cricScore', 'match_info'], true)) {
        throw new RuntimeException('Unsupported CricketData endpoint.');
    }
    if ($endpoint === 'match_info' && empty($params['id'])) {
        throw new RuntimeException('A match ID is required.');
    }
    $key = cricketdata_active_key();
    if ($key === '') throw new RuntimeException('CricketData API key is not configured. Ask the administrator to add an active key.');

    $cacheDir = STORAGE_DIR . '/cache';
    if (!is_dir($cacheDir)) @mkdir($cacheDir, 0775, true);
    $cacheFile = $cacheDir . '/cricketdata_' . ($endpoint === 'cricScore' ? 'scores' : 'match_' . preg_replace('/[^A-Za-z0-9_-]/', '', (string)$params['id'])) . '.json';
    if (is_file($cacheFile) && (time() - (int)filemtime($cacheFile)) < 900) {
        $cached = json_decode((string)file_get_contents($cacheFile), true);
        if (is_array($cached)) return $cached;
    }
    if (!function_exists('curl_init')) throw new RuntimeException('PHP cURL extension is required to load cricket data.');

    $query = array_merge(['apikey' => $key], $params);
    $url = 'https://api.cricapi.com/v1/' . $endpoint . '?' . http_build_query($query);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 18,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    if ($raw === false) throw new RuntimeException('Could not connect to CricketData: ' . ($error ?: 'network error'));
    $json = json_decode($raw, true);
    if (!is_array($json)) throw new RuntimeException('CricketData returned an invalid response.');
    if ($status < 200 || $status >= 300 || (isset($json['status']) && strtolower((string)$json['status']) !== 'success')) {
        $msg = $json['reason'] ?? $json['message'] ?? ('CricketData API error (HTTP ' . $status . ').');
        throw new RuntimeException((string)$msg);
    }
    file_put_contents($cacheFile, json_encode($json, JSON_UNESCAPED_SLASHES), LOCK_EX);
    return $json;
}
