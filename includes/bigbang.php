<?php
/**
 * BigBang sandbox-only API client. The key remains server-side.
 * No wallet, deposits, withdrawals or provider callbacks are implemented.
 */
function bigbang_key(): string
{
    return secret_decrypt((string)setting('bigbang_api_key_enc', ''));
}

function bigbang_request(string $method, string $path, ?array $payload = null): array
{
    if (!function_exists('curl_init')) throw new RuntimeException('PHP cURL extension is required.');
    $key = bigbang_key();
    if ($key === '' || !str_starts_with($key, 'ek_test_')) {
        throw new RuntimeException('A valid BigBang sandbox key (ek_test_...) is not configured.');
    }
    if (!preg_match('#^/[a-zA-Z0-9/_-]+(?:\?[a-zA-Z0-9_=&%-]*)?$#', $path)) {
        throw new RuntimeException('Invalid API path.');
    }
    $ch = curl_init('https://api.bigbangcasino.bet/api/v1' . $path);
    $headers = ['X-API-Key: ' . $key, 'Accept: application/json'];
    if ($payload !== null) $headers[] = 'Content-Type: application/json';
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    if ($payload !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_SLASHES));
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($raw === false) throw new RuntimeException('BigBang connection failed: ' . ($err ?: 'network error'));
    $json = json_decode($raw, true);
    if (!is_array($json)) throw new RuntimeException('BigBang returned an unreadable response (HTTP ' . $status . ').');
    if ($status < 200 || $status >= 300 || empty($json['success'])) {
        $message = $json['error']['message'] ?? ('API request failed (HTTP ' . $status . ').');
        throw new RuntimeException((string)$message);
    }
    return $json;
}
