# CLAUDE.md – project notes for TradePro (read this before changing anything)

PHP 8 + MySQL app, no framework / no Composer. Runs on XAMPP (Apache on port **8080**, URL `http://localhost:8080/tp/`).
PHP CLI: `D:\XAMPP\php\php.exe`, MySQL CLI: `D:\XAMPP\mysql\bin\mysql.exe` (user `root`, no password, DB `tradepro`).

## Hard rules (do not break these)
1. **Results are never chosen or edited by anyone.** Trade outcomes come from the live market price at expiry. Colour Game and
   Lucky Number results come only from `random_int()` (see `includes/games.php`). Do not add "set result", "win probability",
   "force win/loss" or per-user outcome controls – for any account, including Demo. Admin controls on/off, schedules, limits, payout %.
   The owner has asked several times for admin-chosen results ("it is a demo account, admin should set the colour"). The answer stays **no** –
   it is a rigged game even with virtual coins, and the same site holds real INR / USDT wallets. Offer instead: schedules, limits, multipliers, on/off.
   Also declined (do not build, even if asked again): picking a result before a period ("0931 = red"), "the side with fewer bets wins" logic,
   making a lone player always lose or setting a per-player win rate, and letting the games use the INR wallet.
2. **Games use the Demo wallet only** (`wallet_adjust($uid, 'demo', …)`). Never connect Colour Game / Lucky Number to INR or USDT,
   and never add deposit / withdrawal / conversion for game coins. Keep the "Entertainment only" wording on those pages.
3. Money changes only through `wallet_adjust()` (atomic, never negative, writes the ledger). Network calls happen **outside** DB transactions.
4. Every POST form needs `csrf_field()` + `csrf_require()`. Output goes through `e()`. SQL uses prepared statements (`q()`).
5. Secrets (SMTP password, API keys) are stored encrypted with `secret_encrypt()` and never echoed back to the page.

## Layout
- `config.php` – only DB settings + first admin (`DEFAULT_ADMIN_*`, used once by the installer) + auto-detected URL/HTTPS.
- `install.php` – web/CLI installer and upgrader (`php install.php`). `includes/schema.php` holds tables, default settings and migrations.
- `includes/bootstrap.php` – session, `db()`/`q()`, helpers, auth (`require_user()`, `require_admin()`), settings, password policy, time zones. Loads the rest.
- `includes/market.php` – live prices (Binance, Yahoo, Twelve Data, Finnhub, Alpha Vantage), quote cache in `storage/cache`, candles, settlement prices.
- `includes/engine.php` – wallets, trades, settlement, payments, converter. `includes/stats.php` – admin chart data. `includes/reports.php` – P&L + emailed statements.
- `includes/mailer.php` – SMTP client (no library), email templates, reset/verify tokens. `includes/pin.php` – 6-digit PIN. `includes/games.php` – Colour Game + Lucky Number.
- `includes/layout.php` / `admin_layout.php` – page chrome. `assets/style.css`, `assets/app.js` (SweetAlert helpers, loaders), `assets/charts.js` (admin charts).
- User pages in the root (`trade.php`, `wallet.php`, `history.php`, `profile.php`, `games.php`, `colorgame.php`, `matka.php`, …); admin in `admin/`; JSON in `api/`.
- `cron.php?key=…` (or `php cron.php`) settles trades and runs game rounds when nobody is online.

## Conventions
- **Schema changes**: bump `SCHEMA_VERSION` in `includes/schema.php`, add `CREATE TABLE IF NOT EXISTS` and/or a guarded `ALTER` in `schema_install()`, then run `php install.php`. The site redirects to `install.php` when the DB is older.
- **New admin-editable setting**: add a default in `default_settings()`, a field in `admin/settings.php` (or `admin/games.php`), read with `setting('key')`.
- Times are stored UTC; show with `fmt_dt()` / `fmt_ts()` (IST / UTC switch via cookie). Game rounds and market times use the site time zone (`game_tz()`).
- Mobile first: tables get class `rt` (turns into cards on phones); use `.hide-m`, `.only-m`, and the shared CSS tokens in `:root`.
- Alerts/confirms use SweetAlert through `TP.toast / TP.alert / TP.confirm`; add `data-confirm="…"` to a form/button for a confirm dialog.
- Passwords: `password_error()` policy; PIN: `pin_check()` (locks after 5 wrong tries).
- **Select2** (searchable dropdowns): call `select2_assets()` near the end of the page, give the `<select>` class `s2` (optional `data-search="always"`, `data-numeric="1"`);
  `assets/app.js` initialises it. For lists filled by JS, init manually (see the panna picker in `matka.php`). Dark theme rules are in `style.css` prefixed with `body` so they beat the Select2 stylesheet.
- **Mobile**: test at ~500px wide (headless Edge minimum). Phones get a bottom tab bar (`.mnav`), tables become cards (`table.rt`), `.row.two` keeps two fields per line, and the landing page has a sticky sign-up bar (`.stickycta`).
- **Landing page** (`index.php`): sections are plain HTML + CSS classes `.lsec .reveal`; scroll-reveal and count-up are in the inline script at the bottom. Game cards show real current results only – never invent numbers, testimonials or "winners".

## Features (where to look)
Trading UP/DOWN with TradingView widget or Lightweight Charts (`trade.php`); global payout % + per-market override and data sources (`admin/assets.php`);
IST/UTC; SMTP + reset/verify; deposit QR / UPI apps / bank fields and validated withdrawals (`wallet.php`); P&L tab and emailed reports (`history.php`);
security PIN (`profile.php`, `pin.php`); games hub (`games.php`), Colour Game (`colorgame.php`, `api/cg.php`), Lucky Number with analysis (`matka.php`); admin games (`admin/games.php`).

## Play section, KYC, Demo switch
- Every game page calls `play_nav('hub'|'cg'|'mk'|'tx'|'win')` (tab bar: Overview · Colour · Lucky Number · Transactions · My winnings). `playhistory.php` shows only game ledger rows (`transactions.type` = `game_bet` / `game_win`) and game winnings.
- Colour Game "Latest results" and "My bets" are two separate paged sections fed by `api/cg.php?action=results|mine&page=`. Round lengths come from `cg_modes` (30, 60, 180, 300 s; each must divide 86400).
- **KYC**: `kyc.php` (user submit), `admin/kyc.php` (review), `admin/kyc_file.php` (admin-only streaming). Files live in `storage/kyc/` (deny-all `.htaccess`), only the last 4 Aadhaar digits are stored in the DB, PAN is masked for users. `kyc_required_withdraw` setting gates withdrawals. Contact number is required at sign-up (`valid_phone()`).
- **Demo switch**: `demo_on()` / `accounts_on()`; when the admin turns the Demo wallet off, users move to INR, trading rejects Demo, and `games_on()` becomes false (games need Demo coins).

## Price precision (why trades ended in "draw")
- Yahoo's `meta.regularMarketPrice` for forex is rounded to 4 decimals and rarely changes, so 30-second trades kept tying. `fetch_yahoo_quotes()` now reads the **latest 1-minute candle close** (full precision, stored to 6 dp) and falls back to meta only when no recent candle exists. Do not go back to the meta price.
- A tie still refunds the stake (by design). On the terminal, switching market shows loaders and disables UP/DOWN until the server has fetched a fresh price for that market (`pending` / `waitForPrice()` in `trade.php`, 8 s safety timeout).

## Payout details saved in the profile (no details typed at withdrawal time)
- Users save UPI / bank / USDT payout accounts once in **Profile → Payout details** (`includes/payout.php`, table `payout_accounts`, data encrypted as JSON, max 5). Adding or removing one needs the 6-digit PIN (`data-askpin` forms ask for it with SweetAlert) and sends an email alert.
- The withdrawal form in `wallet.php` has only: from-account, saved payout account, amount, PIN. The admin still receives the full details in `payment_requests.details` (built by `payout_text()`); screens show masked text (`payout_mask()`).
- Do not bring back free-text destination fields on the withdraw form.
- Play pages say **"No financial loss – Demo coins only"** (playbar badge + banner). Keep that wording on any new game page.

## Theme (dark / light) and SQL file
- Dark is the default; light is `:root[data-theme=light]` in `assets/style.css`. **Never hard-code colours** – use the CSS variables (`--bg --panel --text --muted --line --accent --green --red --glass --overlay …`) so both themes work. In JS read them with `getComputedStyle(document.documentElement).getPropertyValue('--x')` (TradingView, Lightweight Charts, SweetAlert already do).
- The choice is a `theme` cookie (read by `theme_now()` in PHP, so there is no flash) and is saved to `users.theme` through `api/theme.php`; login restores it. The sun/moon button is `theme_toggle()`; login/sign-up pages get it injected by `app.js`.
- `database.sql` is **generated** – after any schema change run `php make_sql.php` to rebuild it (clean schema + starter data, no users/secrets). The source of truth stays `includes/schema.php`.

## Testing tips (Windows)
- Lint: `for f in $(find . -name "*.php"); do php -l $f; done`. Upgrade DB: `php install.php`.
- Smoke test with `curl -c jar -b jar` (get the CSRF token from the form first). Headless screenshots: `msedge --headless=new --screenshot=…` (min window width ≈ 500px).
- The C: drive on this machine is often full – keep temp files on D: (e.g. inside `storage/t`, then delete them). Remove any test users/markets you create.
- The owner uses the site live while you work: never delete real users/trades; clean up only what you created.
