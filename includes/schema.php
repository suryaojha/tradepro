<?php
/**
 * Database schema, defaults and upgrades. Idempotent: safe to run any number of times.
 * Used by install.php (and automatically requested by bootstrap when the version is old).
 */
const SCHEMA_VERSION = 13;

/** TradingView symbols for the starter markets (admin can change them per market). */
function default_tv_symbols(): array
{
    return [
        'EURUSD' => 'FX:EURUSD', 'GBPUSD' => 'FX:GBPUSD', 'USDJPY' => 'FX:USDJPY', 'USDINR' => 'FX_IDC:USDINR', 'AUDUSD' => 'FX:AUDUSD',
        'BTCUSD' => 'BINANCE:BTCUSDT', 'ETHUSD' => 'BINANCE:ETHUSDT', 'SOLUSD' => 'BINANCE:SOLUSDT', 'BNBUSD' => 'BINANCE:BNBUSDT',
        'XRPUSD' => 'BINANCE:XRPUSDT', 'XAUUSD' => 'COMEX:GC1!', 'AAPL' => 'NASDAQ:AAPL', 'TSLA' => 'NASDAQ:TSLA',
    ];
}

/** Default values for admin-editable settings. Inserted only when the key does not exist yet. */
function default_settings(): array
{
    return [
        // general
        'site_name'           => 'TradePro',
        'site_url'            => '',
        'announcement'        => '',
        'timezone'            => 'Asia/Kolkata',
        'trading_enabled'     => '1',
        'registration_open'   => '1',
        'verify_email'        => '0',
        'demo_balance'        => '10000',
        'demo_enabled'        => '1',      // off = nobody can use the Demo wallet (trading uses INR / USDT only; the games need Demo coins)
        'kyc_required_withdraw' => '0',    // 1 = a user must have an approved PAN / Aadhaar check before withdrawing
        // trading rules
        'payout_percent'      => '85',     // profit % on a correct prediction (a market can override it)
        'durations'           => '30,60,120,300,900',
        'max_open_trades'     => '20',
        'min_trade_demo'      => '10',
        'max_trade_demo'      => '5000',
        'min_trade_inr'       => '100',
        'max_trade_inr'       => '100000',
        'min_trade_usdt'      => '1',
        'max_trade_usdt'      => '1000',
        // wallet
        'usdt_rate_mode'      => 'auto',   // auto = live USD/INR market rate, manual = usdt_inr_rate below
        'usdt_inr_rate'       => '0',
        'convert_fee_percent' => '0',
        'min_deposit_inr'     => '500',
        'min_deposit_usdt'    => '10',
        'min_withdraw_inr'    => '500',
        'min_withdraw_usdt'   => '10',
        'withdraw_fee_inr'    => '0',
        'withdraw_fee_usdt'   => '1',
        // entertainment games (play with the Demo wallet only - virtual coins, no cash value)
        'games_enabled'       => '1',
        'bigbang_api_key_enc' => '', // encrypted BigBang sandbox API key; configured in Admin > BigBang Sandbox
        'games_pin_required'  => '1',      // 6-digit PIN needed to enter the games section
        'cg_enabled'          => '1',
        'cg_modes'            => '30,60,180,300',   // round lengths in seconds
        'cg_bet_min'          => '10',
        'cg_bet_max'          => '5000',
        'cg_lock_secs'        => '5',      // no new bets in the last seconds of a round
        'cg_mult_color'       => '2',      // red / green total return when the colour matches fully
        'cg_mult_half'        => '1.5',    // red wins on 0, green wins on 5 (they also carry violet)
        'cg_mult_violet'      => '4.5',
        'cg_mult_number'      => '9',
        'mk_enabled'          => '1',
        'mk_bet_min'          => '10',
        'mk_bet_max'          => '5000',
        'mk_mult_single'      => '9',
        'mk_mult_jodi'        => '90',
        'mk_mult_panna'       => '140',
        'pay_upi_id'          => '',
        'pay_upi_name'        => '',       // payee name shown in the UPI app / QR
        'pay_bank_name'       => '',
        'pay_bank_holder'     => '',
        'pay_bank_account'    => '',
        'pay_bank_ifsc'       => '',
        'pay_bank_details'    => '',       // optional extra bank instructions
        'chart_default'       => 'tradingview',   // tradingview | standard
        'reports_enabled'     => '1',      // users can request their statement by email
        'pay_usdt_trc20'      => '',
        'pay_usdt_bep20'      => '',
        // market data
        'stale_secs'          => '900',    // a quote older than this = market closed
        'quote_ttl_focus'     => '2',      // seconds between API calls for the asset a user is watching
        'quote_ttl_bg'        => '10',     // ... for all other non-crypto assets
        'quote_ttl_api'       => '10',     // keyed APIs (Twelve Data / Finnhub / Alpha Vantage): watched asset / open trades
        'quote_ttl_api_bg'    => '30',     // keyed APIs: everything else (their free plans have strict rate limits)
        'key_twelvedata'      => '',
        'key_finnhub'         => '',
        'key_alphavantage'    => '',
        // email
        'smtp_enabled'        => '0',
        'smtp_host'           => '',
        'smtp_port'           => '587',
        'smtp_secure'         => 'tls',
        'smtp_user'           => '',
        'smtp_pass'           => '',
        'smtp_from_email'     => '',
        'smtp_from_name'      => '',
        'smtp_verify_tls'     => '1',
        'notify_email'        => '',
    ];
}

/** Starter markets (all real data). Admin can edit / add / disable them. [symbol, name, category, decimals, unused, provider, source symbol] */
function default_assets(): array
{
    return [
        ['EURUSD', 'EUR / USD',  'forex',     5, 85, 'yahoo',   'EURUSD=X'],
        ['GBPUSD', 'GBP / USD',  'forex',     5, 84, 'yahoo',   'GBPUSD=X'],
        ['USDJPY', 'USD / JPY',  'forex',     3, 83, 'yahoo',   'USDJPY=X'],
        ['USDINR', 'USD / INR',  'forex',     4, 80, 'yahoo',   'USDINR=X'],
        ['AUDUSD', 'AUD / USD',  'forex',     5, 82, 'yahoo',   'AUDUSD=X'],
        ['BTCUSD', 'Bitcoin',    'crypto',    2, 88, 'binance', 'BTCUSDT'],
        ['ETHUSD', 'Ethereum',   'crypto',    2, 87, 'binance', 'ETHUSDT'],
        ['SOLUSD', 'Solana',     'crypto',    3, 85, 'binance', 'SOLUSDT'],
        ['BNBUSD', 'BNB',        'crypto',    2, 85, 'binance', 'BNBUSDT'],
        ['XRPUSD', 'XRP',        'crypto',    4, 85, 'binance', 'XRPUSDT'],
        ['XAUUSD', 'Gold',       'commodity', 2, 84, 'yahoo',   'GC=F'],
        ['AAPL',   'Apple Inc.', 'stock',     2, 80, 'yahoo',   'AAPL'],
        ['TSLA',   'Tesla Inc.', 'stock',     2, 80, 'yahoo',   'TSLA'],
    ];
}

function sch_has_table(PDO $pdo, string $t): bool
{
    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $st->execute([$t]);
    return (bool)$st->fetchColumn();
}

function sch_has_col(PDO $pdo, string $t, string $c): bool
{
    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $st->execute([$t, $c]);
    return (bool)$st->fetchColumn();
}

function sch_has_index(PDO $pdo, string $t, string $i): bool
{
    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?');
    $st->execute([$t, $i]);
    return (bool)$st->fetchColumn();
}

function schema_installed_version(PDO $pdo): int
{
    try {
        if (!sch_has_table($pdo, 'settings')) return 0;
        $st = $pdo->prepare("SELECT v FROM settings WHERE k = 'schema_version'");
        $st->execute();
        return (int)$st->fetchColumn();
    } catch (Throwable $ex) {
        return 0;
    }
}

function schema_install(PDO $pdo): array
{
    $log = [];
    $from = schema_installed_version($pdo);
    $pdo->exec('SET NAMES utf8mb4');

    // ------------------------------------------------------------ tables
    $tables = [
    'admins' => "CREATE TABLE IF NOT EXISTS `admins` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `name` VARCHAR(100) NOT NULL DEFAULT 'Admin',
        `email` VARCHAR(150) NOT NULL,
        `password_hash` VARCHAR(255) NOT NULL,
        `last_login` DATETIME NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_admin_email` (`email`)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    'users' => "CREATE TABLE IF NOT EXISTS `users` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `name` VARCHAR(100) NOT NULL,
        `email` VARCHAR(150) NOT NULL,
        `phone` VARCHAR(30) NULL,
        `password_hash` VARCHAR(255) NOT NULL,
        `active_account` ENUM('demo','inr','usdt') NOT NULL DEFAULT 'demo',
        `status` ENUM('active','blocked') NOT NULL DEFAULT 'active',
        `email_verified_at` DATETIME NULL,
        `pin_hash` VARCHAR(255) NULL,
        `pin_fails` TINYINT UNSIGNED NOT NULL DEFAULT 0,
        `pin_locked_until` DATETIME NULL,
        `app_lock` TINYINT(1) NOT NULL DEFAULT 0,
        `theme` VARCHAR(8) NULL,
        `last_login` DATETIME NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_user_email` (`email`)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    'wallets' => "CREATE TABLE IF NOT EXISTS `wallets` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `user_id` INT UNSIGNED NOT NULL,
        `type` ENUM('demo','inr','usdt') NOT NULL,
        `balance` DECIMAL(18,4) NOT NULL DEFAULT 0,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_wallet` (`user_id`,`type`),
        CONSTRAINT `fk_wallet_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    'assets' => "CREATE TABLE IF NOT EXISTS `assets` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `symbol` VARCHAR(20) NOT NULL,
        `name` VARCHAR(60) NOT NULL,
        `category` VARCHAR(20) NOT NULL DEFAULT 'forex',
        `decimals` TINYINT UNSIGNED NOT NULL DEFAULT 4,
        `payout_percent` DECIMAL(5,2) NULL,
        `provider` VARCHAR(20) NULL,
        `source_symbol` VARCHAR(40) NULL,
        `chart_provider` VARCHAR(20) NULL,
        `chart_symbol` VARCHAR(40) NULL,
        `tv_symbol` VARCHAR(60) NULL,
        `is_active` TINYINT(1) NOT NULL DEFAULT 1,
        `sort_order` INT NOT NULL DEFAULT 0,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_asset_symbol` (`symbol`)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    'trades' => "CREATE TABLE IF NOT EXISTS `trades` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `user_id` INT UNSIGNED NOT NULL,
        `asset_id` INT UNSIGNED NOT NULL,
        `account_type` ENUM('demo','inr','usdt') NOT NULL,
        `direction` ENUM('up','down') NOT NULL,
        `amount` DECIMAL(18,4) NOT NULL,
        `payout_percent` DECIMAL(5,2) NOT NULL,
        `open_price` DECIMAL(18,6) NOT NULL,
        `close_price` DECIMAL(18,6) NULL,
        `open_ts` INT UNSIGNED NOT NULL,
        `expiry_ts` INT UNSIGNED NOT NULL,
        `status` ENUM('open','won','lost','draw') NOT NULL DEFAULT 'open',
        `profit` DECIMAL(18,4) NOT NULL DEFAULT 0,
        `notified` TINYINT(1) NOT NULL DEFAULT 0,
        `settle_note` VARCHAR(120) NULL,
        `settled_at` DATETIME NULL,
        PRIMARY KEY (`id`),
        KEY `idx_trade_user` (`user_id`,`status`),
        KEY `idx_trade_due` (`status`,`expiry_ts`),
        KEY `idx_trade_open_ts` (`open_ts`),
        CONSTRAINT `fk_trade_user`  FOREIGN KEY (`user_id`)  REFERENCES `users`(`id`)  ON DELETE CASCADE,
        CONSTRAINT `fk_trade_asset` FOREIGN KEY (`asset_id`) REFERENCES `assets`(`id`) ON DELETE CASCADE
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    'transactions' => "CREATE TABLE IF NOT EXISTS `transactions` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `user_id` INT UNSIGNED NOT NULL,
        `account_type` ENUM('demo','inr','usdt') NOT NULL,
        `type` VARCHAR(30) NOT NULL,
        `amount` DECIMAL(18,4) NOT NULL,
        `balance_after` DECIMAL(18,4) NOT NULL,
        `ref_id` BIGINT UNSIGNED NULL,
        `note` VARCHAR(255) NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_tx_user` (`user_id`,`created_at`),
        CONSTRAINT `fk_tx_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    'payment_requests' => "CREATE TABLE IF NOT EXISTS `payment_requests` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `user_id` INT UNSIGNED NOT NULL,
        `type` ENUM('deposit','withdraw') NOT NULL,
        `account_type` ENUM('inr','usdt') NOT NULL,
        `amount` DECIMAL(18,4) NOT NULL,
        `fee` DECIMAL(18,4) NOT NULL DEFAULT 0,
        `method` VARCHAR(30) NOT NULL,
        `details` VARCHAR(255) NOT NULL,
        `status` ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
        `admin_note` VARCHAR(255) NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `processed_at` DATETIME NULL,
        PRIMARY KEY (`id`),
        KEY `idx_pr_status` (`status`,`type`),
        KEY `idx_pr_user` (`user_id`),
        CONSTRAINT `fk_pr_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    'settings' => "CREATE TABLE IF NOT EXISTS `settings` (
        `k` VARCHAR(60) NOT NULL,
        `v` TEXT NOT NULL,
        PRIMARY KEY (`k`)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    'admin_logs' => "CREATE TABLE IF NOT EXISTS `admin_logs` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `admin_id` INT UNSIGNED NOT NULL,
        `action` VARCHAR(60) NOT NULL,
        `detail` VARCHAR(255) NULL,
        `ip` VARCHAR(45) NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    'price_ticks' => "CREATE TABLE IF NOT EXISTS `price_ticks` (
        `asset_id` INT UNSIGNED NOT NULL,
        `ts` INT UNSIGNED NOT NULL,
        `price` DECIMAL(18,6) NOT NULL,
        `qts` INT UNSIGNED NOT NULL,
        PRIMARY KEY (`asset_id`,`ts`),
        KEY `idx_tick_ts` (`ts`)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    'password_resets' => "CREATE TABLE IF NOT EXISTS `password_resets` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `kind` ENUM('user','admin','verify') NOT NULL,
        `account_id` INT UNSIGNED NOT NULL,
        `token_hash` CHAR(64) NOT NULL,
        `expires_at` DATETIME NOT NULL,
        `used_at` DATETIME NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_token` (`token_hash`),
        KEY `idx_reset_acc` (`kind`,`account_id`)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    'cg_rounds' => "CREATE TABLE IF NOT EXISTS `cg_rounds` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `secs` INT UNSIGNED NOT NULL,
        `start_ts` INT UNSIGNED NOT NULL,
        `end_ts` INT UNSIGNED NOT NULL,
        `period_no` VARCHAR(24) NOT NULL,
        `result` TINYINT UNSIGNED NULL,
        `settled_at` DATETIME NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_round` (`secs`,`start_ts`),
        KEY `idx_round_end` (`settled_at`,`end_ts`)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    'cg_bets' => "CREATE TABLE IF NOT EXISTS `cg_bets` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `round_id` INT UNSIGNED NOT NULL,
        `user_id` INT UNSIGNED NOT NULL,
        `kind` ENUM('color','number') NOT NULL,
        `pick` VARCHAR(8) NOT NULL,
        `amount` DECIMAL(18,4) NOT NULL,
        `status` ENUM('open','won','lost') NOT NULL DEFAULT 'open',
        `payout` DECIMAL(18,4) NOT NULL DEFAULT 0,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_cgb_round` (`round_id`),
        KEY `idx_cgb_user` (`user_id`,`id`),
        CONSTRAINT `fk_cgb_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    'mk_markets' => "CREATE TABLE IF NOT EXISTS `mk_markets` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `name` VARCHAR(60) NOT NULL,
        `open_time` CHAR(5) NOT NULL,
        `close_time` CHAR(5) NOT NULL,
        `days` VARCHAR(20) NOT NULL DEFAULT '1,2,3,4,5,6',
        `is_active` TINYINT(1) NOT NULL DEFAULT 1,
        `sort_order` INT NOT NULL DEFAULT 0,
        PRIMARY KEY (`id`)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    'mk_results' => "CREATE TABLE IF NOT EXISTS `mk_results` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `market_id` INT UNSIGNED NOT NULL,
        `day` DATE NOT NULL,
        `open_panna` CHAR(3) NULL,
        `open_digit` TINYINT UNSIGNED NULL,
        `close_panna` CHAR(3) NULL,
        `close_digit` TINYINT UNSIGNED NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_mk_day` (`market_id`,`day`),
        CONSTRAINT `fk_mkr_market` FOREIGN KEY (`market_id`) REFERENCES `mk_markets`(`id`) ON DELETE CASCADE
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    'mk_bets' => "CREATE TABLE IF NOT EXISTS `mk_bets` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `market_id` INT UNSIGNED NOT NULL,
        `day` DATE NOT NULL,
        `user_id` INT UNSIGNED NOT NULL,
        `game` ENUM('single','jodi','panna') NOT NULL,
        `session` ENUM('open','close') NOT NULL,
        `pick` VARCHAR(3) NOT NULL,
        `amount` DECIMAL(18,4) NOT NULL,
        `status` ENUM('open','won','lost') NOT NULL DEFAULT 'open',
        `payout` DECIMAL(18,4) NOT NULL DEFAULT 0,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_mkb_mkt` (`market_id`,`day`,`status`),
        KEY `idx_mkb_user` (`user_id`,`id`),
        CONSTRAINT `fk_mkb_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
        CONSTRAINT `fk_mkb_market` FOREIGN KEY (`market_id`) REFERENCES `mk_markets`(`id`) ON DELETE CASCADE
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    'payout_accounts' => "CREATE TABLE IF NOT EXISTS `payout_accounts` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `user_id` INT UNSIGNED NOT NULL,
        `kind` ENUM('upi','bank','trc20','bep20') NOT NULL,
        `label` VARCHAR(60) NOT NULL,
        `data` TEXT NOT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_payout_user` (`user_id`),
        CONSTRAINT `fk_payout_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    'kyc' => "CREATE TABLE IF NOT EXISTS `kyc` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `user_id` INT UNSIGNED NOT NULL,
        `full_name` VARCHAR(100) NOT NULL,
        `pan_number` CHAR(10) NOT NULL,
        `aadhaar_last4` CHAR(4) NOT NULL,
        `pan_file` VARCHAR(60) NOT NULL,
        `aadhaar_file` VARCHAR(60) NOT NULL,
        `status` ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
        `admin_note` VARCHAR(255) NULL,
        `submitted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `reviewed_at` DATETIME NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_kyc_user` (`user_id`),
        KEY `idx_kyc_status` (`status`),
        CONSTRAINT `fk_kyc_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    'sim_wallets' => "CREATE TABLE IF NOT EXISTS `sim_wallets` (`user_id` INT UNSIGNED NOT NULL, `currency` ENUM('INR','USDT') NOT NULL, `balance` DECIMAL(18,4) NOT NULL DEFAULT 0, PRIMARY KEY (`user_id`,`currency`), CONSTRAINT `fk_sim_wallet_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    'sim_periods' => "CREATE TABLE IF NOT EXISTS `sim_periods` (`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, `period_no` VARCHAR(40) NOT NULL, `scheduled_at` DATETIME NOT NULL, `result` ENUM('UP','DOWN') NULL, `status` ENUM('scheduled','published') NOT NULL DEFAULT 'scheduled', `created_by` INT UNSIGNED NOT NULL, `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, `published_at` DATETIME NULL, PRIMARY KEY (`id`), UNIQUE KEY `uq_sim_period` (`period_no`), KEY `idx_sim_period_schedule` (`scheduled_at`,`status`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    'sim_entries' => "CREATE TABLE IF NOT EXISTS `sim_entries` (`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, `user_id` INT UNSIGNED NOT NULL, `period_id` BIGINT UNSIGNED NOT NULL, `currency` ENUM('INR','USDT') NOT NULL, `direction` ENUM('UP','DOWN') NOT NULL, `amount` DECIMAL(18,4) NOT NULL, `status` ENUM('pending','won','lost') NOT NULL DEFAULT 'pending', `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (`id`), KEY `idx_sim_entry_user` (`user_id`,`id`), KEY `idx_sim_entry_period` (`period_id`), CONSTRAINT `fk_sim_entry_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE, CONSTRAINT `fk_sim_entry_period` FOREIGN KEY (`period_id`) REFERENCES `sim_periods`(`id`) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    'mail_log' => "CREATE TABLE IF NOT EXISTS `mail_log` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `to_email` VARCHAR(150) NOT NULL,
        `subject` VARCHAR(200) NOT NULL,
        `ok` TINYINT(1) NOT NULL DEFAULT 0,
        `error` VARCHAR(500) NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];
    foreach ($tables as $name => $sql) {
        $existed = sch_has_table($pdo, $name);
        $pdo->exec($sql);
        if (!$existed) $log[] = "Created table $name";
    }

    // ------------------------------------------------------------ upgrades of older installs
    // admins: username -> name + email
    if (!sch_has_col($pdo, 'admins', 'email')) {
        $pdo->exec("ALTER TABLE admins ADD COLUMN email VARCHAR(150) NULL AFTER id, ADD COLUMN name VARCHAR(100) NOT NULL DEFAULT 'Admin' AFTER id");
        if (sch_has_col($pdo, 'admins', 'username')) {
            $pdo->exec('UPDATE admins SET name = username');
            $pdo->exec('ALTER TABLE admins DROP COLUMN username');
        }
        $first = true;
        foreach ($pdo->query('SELECT id, password_hash FROM admins ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) as $a) {
            if ($first) {
                $first = false;
                $upd = $pdo->prepare('UPDATE admins SET email = ? WHERE id = ?');
                $upd->execute([DEFAULT_ADMIN_EMAIL, $a['id']]);
                if (password_verify('password', $a['password_hash'])) {   // old insecure default login
                    $pdo->prepare('UPDATE admins SET name = ?, password_hash = ? WHERE id = ?')
                        ->execute([DEFAULT_ADMIN_NAME, password_hash(DEFAULT_ADMIN_PASSWORD, PASSWORD_DEFAULT), $a['id']]);
                    $log[] = 'Replaced the old default admin login with ' . DEFAULT_ADMIN_EMAIL;
                }
            } else {
                $pdo->prepare('UPDATE admins SET email = ? WHERE id = ?')->execute(['admin' . $a['id'] . '@admin.local', $a['id']]);
            }
        }
        $pdo->exec('ALTER TABLE admins MODIFY email VARCHAR(150) NOT NULL');
        if (!sch_has_index($pdo, 'admins', 'uq_admin_email')) {
            $pdo->exec('ALTER TABLE admins ADD UNIQUE KEY uq_admin_email (email)');
        }
    }
    if ((int)$pdo->query('SELECT COUNT(*) FROM admins')->fetchColumn() === 0) {
        $pdo->prepare('INSERT INTO admins (name, email, password_hash) VALUES (?,?,?)')
            ->execute([DEFAULT_ADMIN_NAME, DEFAULT_ADMIN_EMAIL, password_hash(DEFAULT_ADMIN_PASSWORD, PASSWORD_DEFAULT)]);
        $log[] = 'Created admin ' . DEFAULT_ADMIN_EMAIL;
    }

    // users: email verification
    if (!sch_has_col($pdo, 'users', 'email_verified_at')) {
        $pdo->exec('ALTER TABLE users ADD COLUMN email_verified_at DATETIME NULL AFTER status');
        $pdo->exec('UPDATE users SET email_verified_at = created_at');
        $log[] = 'Added email verification to users';
    }

    // assets: real market data instead of the old simulated feed
    $legacyAssets = false;
    if (!sch_has_col($pdo, 'assets', 'provider')) {
        $pdo->exec('ALTER TABLE assets ADD COLUMN provider VARCHAR(20) NULL AFTER payout_percent, ADD COLUMN source_symbol VARCHAR(40) NULL AFTER provider');
        $legacyAssets = true;
    }
    foreach (['base_price', 'volatility'] as $old) {
        if (sch_has_col($pdo, 'assets', $old)) {
            $pdo->exec("ALTER TABLE assets DROP COLUMN `$old`");
            $log[] = "Removed simulated-feed column assets.$old";
        }
    }
    if ($legacyAssets) {
        $map = $pdo->prepare('UPDATE assets SET provider = ?, source_symbol = ? WHERE symbol = ? AND provider IS NULL');
        foreach (default_assets() as [$sym, , , , , $prov, $src]) $map->execute([$prov, $src, $sym]);
        $pdo->exec('UPDATE assets SET is_active = 0 WHERE provider IS NULL');
        $log[] = 'Linked existing assets to live market data';
    }
    if (!sch_has_col($pdo, 'trades', 'settle_note')) {
        $pdo->exec('ALTER TABLE trades ADD COLUMN settle_note VARCHAR(120) NULL AFTER notified');
    }
    if (!sch_has_index($pdo, 'trades', 'idx_trade_open_ts')) {
        $pdo->exec('ALTER TABLE trades ADD KEY idx_trade_open_ts (open_ts)');
    }

    // v5: one global payout %, optional per-market override, separate chart data source
    if (!sch_has_col($pdo, 'assets', 'chart_provider')) {
        $pdo->exec('ALTER TABLE assets ADD COLUMN chart_provider VARCHAR(20) NULL AFTER source_symbol, ADD COLUMN chart_symbol VARCHAR(40) NULL AFTER chart_provider');
    }
    if ($from < 5) {
        $pdo->exec('ALTER TABLE assets MODIFY payout_percent DECIMAL(5,2) NULL DEFAULT NULL');
        $pdo->exec('UPDATE assets SET payout_percent = NULL');          // every market follows the global payout %
        $log[] = 'Payout is now one global % (set it in Admin > Markets)';
    }

    // v10: 30-second colour rounds (only if the admin had not customised the list)
    if ($from < 10) {
        $pdo->exec("UPDATE settings SET v = '30,60,180,300' WHERE k = 'cg_modes' AND v = '60,180,300'");
    }
    if (!is_dir(STORAGE_DIR . '/kyc')) @mkdir(STORAGE_DIR . '/kyc', 0775, true);
    @file_put_contents(STORAGE_DIR . '/kyc/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
    @file_put_contents(STORAGE_DIR . '/kyc/index.html', '');

    // v9: dark / light theme saved per user
    if (!sch_has_col($pdo, 'users', 'theme')) {
        $pdo->exec('ALTER TABLE users ADD COLUMN theme VARCHAR(8) NULL AFTER app_lock');
        $log[] = 'Added theme preference to users';
    }

    // v8: starter Lucky Number market (editable / removable by the admin)
    if ($from < 8 && (int)$pdo->query('SELECT COUNT(*) FROM mk_markets')->fetchColumn() === 0) {
        $pdo->exec("INSERT INTO mk_markets (name, open_time, close_time, days, sort_order) VALUES ('Kalyan', '16:45', '18:45', '1,2,3,4,5,6', 1)");
        $log[] = 'Added the Kalyan-style market';
    }

    // v7: 6-digit security PIN (withdrawals + optional app lock)
    if (!sch_has_col($pdo, 'users', 'pin_hash')) {
        $pdo->exec('ALTER TABLE users ADD COLUMN pin_hash VARCHAR(255) NULL AFTER email_verified_at, ADD COLUMN pin_fails TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER pin_hash,
                    ADD COLUMN pin_locked_until DATETIME NULL AFTER pin_fails, ADD COLUMN app_lock TINYINT(1) NOT NULL DEFAULT 0 AFTER pin_locked_until');
        $log[] = 'Added security PIN to users';
    }

    // v6: TradingView symbol per market
    if (!sch_has_col($pdo, 'assets', 'tv_symbol')) {
        $pdo->exec('ALTER TABLE assets ADD COLUMN tv_symbol VARCHAR(60) NULL AFTER chart_symbol');
    }
    if ($from < 6) {
        $up = $pdo->prepare('UPDATE assets SET tv_symbol = ? WHERE symbol = ? AND tv_symbol IS NULL');
        foreach (default_tv_symbols() as $sym => $tv) $up->execute([$tv, $sym]);
        $log[] = 'Linked markets to TradingView symbols';
    }

    // v6: a bare account number saved in the old single "bank details" text becomes the account-number field
    $oldBank = $pdo->query("SELECT v FROM settings WHERE k = 'pay_bank_details'")->fetchColumn();
    if ($from < 6 && $oldBank !== false && preg_match('/^\d{9,18}$/', trim((string)$oldBank))) {
        $pdo->prepare("INSERT INTO settings (k, v) VALUES ('pay_bank_account', ?) ON DUPLICATE KEY UPDATE v = IF(v = '', VALUES(v), v)")->execute([trim($oldBank)]);
        $pdo->exec("UPDATE settings SET v = '' WHERE k = 'pay_bank_details'");
        $log[] = 'Moved the bank account number into its own field';
    }

    // starter markets (fresh install or first move to live data only - never resurrects deleted assets later)
    if ($from < 3) {
        $ins = $pdo->prepare('INSERT IGNORE INTO assets (symbol,name,category,decimals,payout_percent,provider,source_symbol,tv_symbol,sort_order) VALUES (?,?,?,?,?,?,?,?,?)');
        $order = (int)$pdo->query('SELECT COALESCE(MAX(sort_order),0) FROM assets')->fetchColumn();
        $tvs = default_tv_symbols();
        foreach (default_assets() as [$sym, $name, $cat, $dec, $pay, $prov, $src]) {
            $ins->execute([$sym, $name, $cat, $dec, null, $prov, $src, $tvs[$sym] ?? null, ++$order]);
        }
        $log[] = 'Added live-market assets';
    }

    // ------------------------------------------------------------ settings
    $ins = $pdo->prepare('INSERT IGNORE INTO settings (k, v) VALUES (?, ?)');
    foreach (default_settings() as $k => $v) $ins->execute([$k, $v]);
    $ins->execute(['cron_key', bin2hex(random_bytes(16))]);
    // remove placeholder payment details from older versions
    foreach (['pay_upi_id' => 'yourname@upi', 'pay_usdt_trc20' => 'TXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX',
              'pay_usdt_bep20' => '0x0000000000000000000000000000000000000000',
              'pay_bank_details' => 'Bank: XYZ Bank | A/C: 0000000000 | IFSC: XYZB0000000 | Name: Your Company'] as $k => $old) {
        $pdo->prepare('UPDATE settings SET v = ? WHERE k = ? AND v = ?')->execute(['', $k, $old]);
    }
    $pdo->prepare("UPDATE settings SET v = 'auto' WHERE k = 'usdt_rate_mode' AND ? < 4")->execute([$from]);

    // ------------------------------------------------------------ storage folder
    foreach ([STORAGE_DIR, STORAGE_DIR . '/cache'] as $d) {
        if (!is_dir($d)) @mkdir($d, 0775, true);
    }
    @file_put_contents(STORAGE_DIR . '/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
    @file_put_contents(STORAGE_DIR . '/index.html', '');

    $pdo->prepare("INSERT INTO settings (k, v) VALUES ('schema_version', ?) ON DUPLICATE KEY UPDATE v = VALUES(v)")->execute([(string)SCHEMA_VERSION]);
    $log[] = 'Database is at version ' . SCHEMA_VERSION;
    return $log;
}
