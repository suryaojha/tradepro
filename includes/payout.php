<?php
/**
 * Saved payout details (UPI / bank / USDT address). Users add them once in Profile (PIN required);
 * a withdrawal then only needs: choose a saved account, amount, PIN.
 * The details are stored encrypted (secret_encrypt) as JSON.
 */
const PAYOUT_KINDS = [
    'upi'   => ['label' => 'UPI ID',                  'acc' => 'inr'],
    'bank'  => ['label' => 'Bank account (IMPS/NEFT)', 'acc' => 'inr'],
    'trc20' => ['label' => 'USDT – TRC20 (Tron)',     'acc' => 'usdt'],
    'bep20' => ['label' => 'USDT – BEP20 (BSC)',      'acc' => 'usdt'],
];
const PAYOUT_MAX = 5;

/** Validate user input for one payout account. Returns the clean data array or throws RuntimeException. */
function payout_validate(string $kind, array $in): array
{
    $p = fn($k) => trim((string)($in[$k] ?? ''));
    switch ($kind) {
        case 'upi':
            $id = $p('upi');
            if (!preg_match('/^[A-Za-z0-9._\-]{2,64}@[A-Za-z][A-Za-z0-9.\-]{1,30}$/', $id)) throw new RuntimeException('Enter a valid UPI ID, e.g. name@bank.');
            return ['upi' => $id];
        case 'bank':
            $holder = $p('holder'); $bank = $p('bank'); $acc = preg_replace('/\s+/', '', $p('acc')); $acc2 = preg_replace('/\s+/', '', $p('acc2')); $ifsc = strtoupper($p('ifsc'));
            if (!preg_match('/^[A-Za-z][A-Za-z .\']{1,59}$/', $holder)) throw new RuntimeException('Enter the account holder name exactly as in the bank.');
            if (mb_strlen($bank) < 2 || mb_strlen($bank) > 60) throw new RuntimeException('Enter the bank name.');
            if (!preg_match('/^\d{9,18}$/', $acc)) throw new RuntimeException('Account number must be 9–18 digits.');
            if ($acc !== $acc2) throw new RuntimeException('Account numbers do not match.');
            if (!preg_match('/^[A-Z]{4}0[A-Z0-9]{6}$/', $ifsc)) throw new RuntimeException('IFSC must be 11 characters, e.g. HDFC0001234.');
            return ['holder' => $holder, 'bank' => $bank, 'acc' => $acc, 'ifsc' => $ifsc];
        case 'trc20':
            $a = $p('addr');
            if (!preg_match('/^T[1-9A-HJ-NP-Za-km-z]{33}$/', $a)) throw new RuntimeException('Enter a valid TRC20 (Tron) address starting with T.');
            return ['addr' => $a];
        case 'bep20':
            $a = $p('addr');
            if (!preg_match('/^0x[a-fA-F0-9]{40}$/', $a)) throw new RuntimeException('Enter a valid BEP20 address starting with 0x.');
            return ['addr' => $a];
    }
    throw new RuntimeException('Choose what kind of payout account this is.');
}

function payout_data(array $row): array
{
    $j = json_decode(secret_decrypt((string)$row['data']), true);
    return is_array($j) ? $j : [];
}

/** Masked one-line description that is safe to show on screen. */
function payout_mask(array $row): string
{
    $d = payout_data($row);
    switch ($row['kind']) {
        case 'upi':   return $d['upi'] ?? '';
        case 'bank':  return ($d['bank'] ?? '') . ' · A/C ••••' . substr((string)($d['acc'] ?? ''), -4) . ' · ' . ($d['ifsc'] ?? '');
        default:      $a = (string)($d['addr'] ?? ''); return substr($a, 0, 6) . '…' . substr($a, -6);
    }
}

/** Full details string handed to the admin who pays out (stored in the withdrawal request). */
function payout_text(array $row): string
{
    $d = payout_data($row);
    switch ($row['kind']) {
        case 'upi':  return 'UPI: ' . ($d['upi'] ?? '');
        case 'bank': return 'Holder: ' . ($d['holder'] ?? '') . ' | Bank: ' . ($d['bank'] ?? '') . ' | A/C: ' . ($d['acc'] ?? '') . ' | IFSC: ' . ($d['ifsc'] ?? '');
        default:     return strtoupper($row['kind']) . ': ' . ($d['addr'] ?? '');
    }
}

function payout_list(int $uid): array
{
    return q('SELECT * FROM payout_accounts WHERE user_id = ? ORDER BY id DESC', [$uid])->fetchAll();
}

function payout_find(int $uid, int $id): ?array
{
    return q('SELECT * FROM payout_accounts WHERE id = ? AND user_id = ?', [$id, $uid])->fetch() ?: null;
}

function payout_add(int $uid, string $kind, string $label, array $data): int
{
    if (!isset(PAYOUT_KINDS[$kind])) throw new RuntimeException('Choose what kind of payout account this is.');
    if ((int)q('SELECT COUNT(*) FROM payout_accounts WHERE user_id = ?', [$uid])->fetchColumn() >= PAYOUT_MAX) {
        throw new RuntimeException('You can save up to ' . PAYOUT_MAX . ' payout accounts. Delete one first.');
    }
    $label = mb_substr($label !== '' ? $label : PAYOUT_KINDS[$kind]['label'], 0, 60);
    q('INSERT INTO payout_accounts (user_id, kind, label, data) VALUES (?,?,?,?)', [$uid, $kind, $label, secret_encrypt(json_encode($data))]);
    return (int)db()->lastInsertId();
}
