<?php
/** 6-digit security PIN: required for withdrawals, optionally to open the app. Stored hashed, locked after 5 wrong tries. */
const PIN_MAX_FAILS = 5;
const PIN_LOCK_MINUTES = 30;

function pin_format_error(string $pin): ?string
{
    if (!preg_match('/^\d{6}$/', $pin)) return 'The PIN must be exactly 6 digits.';
    if (preg_match('/^(\d)\1{5}$/', $pin)) return 'Choose a PIN that is not the same digit repeated.';
    if (in_array($pin, ['123456', '654321', '012345', '123123', '121212', '112233', '000123', '234567', '345678'], true)) return 'That PIN is too easy to guess. Choose another.';
    return null;
}

function pin_is_set(array $u): bool
{
    return !empty($u['pin_hash']);
}

/** Minutes left on a lock, 0 when not locked. */
function pin_locked_minutes(array $u): int
{
    if (empty($u['pin_locked_until'])) return 0;
    $left = strtotime($u['pin_locked_until'] . ' UTC') - time();
    return $left > 0 ? (int)ceil($left / 60) : 0;
}

function pin_set(int $uid, string $pin): void
{
    q('UPDATE users SET pin_hash = ?, pin_fails = 0, pin_locked_until = NULL WHERE id = ?', [password_hash($pin, PASSWORD_DEFAULT), $uid]);
}

function pin_clear(int $uid): void
{
    q('UPDATE users SET pin_hash = NULL, pin_fails = 0, pin_locked_until = NULL, app_lock = 0 WHERE id = ?', [$uid]);
}

/** Check a PIN. Returns null when correct, otherwise a message for the user. */
function pin_check(int $uid, string $pin): ?string
{
    $u = q('SELECT pin_hash, pin_fails, pin_locked_until, email, name FROM users WHERE id = ?', [$uid])->fetch();
    if (!$u || !$u['pin_hash']) return 'Set a security PIN first (Profile → Security PIN).';
    if ($m = pin_locked_minutes($u)) return "Too many wrong PINs. Try again in $m minute" . ($m === 1 ? '' : 's') . '.';
    if (preg_match('/^\d{6}$/', $pin) && password_verify($pin, $u['pin_hash'])) {
        if ((int)$u['pin_fails'] > 0) q('UPDATE users SET pin_fails = 0 WHERE id = ?', [$uid]);
        return null;
    }
    $fails = (int)$u['pin_fails'] + 1;
    if ($fails >= PIN_MAX_FAILS) {
        q('UPDATE users SET pin_fails = 0, pin_locked_until = ? WHERE id = ?', [gmdate('Y-m-d H:i:s', time() + PIN_LOCK_MINUTES * 60), $uid]);
        if (mail_ready()) {
            send_mail($u['email'], 'Security alert: PIN locked', mail_html('Your PIN was locked',
                '<p>Hi ' . e($u['name']) . ',</p><p>There were ' . PIN_MAX_FAILS . ' wrong PIN attempts on your account, so the PIN is locked for ' . PIN_LOCK_MINUTES . ' minutes. If this was not you, change your password now.</p>'));
        }
        return 'Too many wrong PINs. The PIN is locked for ' . PIN_LOCK_MINUTES . ' minutes.';
    }
    q('UPDATE users SET pin_fails = ? WHERE id = ?', [$fails, $uid]);
    $left = PIN_MAX_FAILS - $fails;
    return "Wrong PIN. $left attempt" . ($left === 1 ? '' : 's') . ' left.';
}

/** A 6-box PIN input (works with the phone keypad; boxes auto-advance). */
function pin_input(string $name, string $label): string
{
    return '<div class="field"><label>' . e($label) . '</label><div class="pinbox" data-pin="' . e($name) . '">'
        . '<input type="hidden" name="' . e($name) . '" value="">'
        . str_repeat('<input type="password" inputmode="numeric" pattern="[0-9]*" maxlength="1" autocomplete="off" aria-label="PIN digit">', 6)
        . '</div></div>';
}
