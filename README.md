# TradePro – live-market trading platform (PHP + MySQL)

Requirements: PHP 8.0+ (pdo_mysql, mbstring, openssl, curl), MySQL/MariaDB, Apache (XAMPP / any host).

## Install / upgrade (one step)
1. Put the folder in your web root (e.g. `htdocs/tp`) and check the DB values in `config.php`.
2. Open `http://localhost/tp/install.php` and click **Install / upgrade**. (CLI: `php install.php`.)
   It creates the database, live-market assets, default settings and the first admin. On an existing install it only
   adds what is missing – users, wallets and trades are kept. Old simulated-feed assets are linked to live data.
3. Admin panel: `/admin/` – first login is the `DEFAULT_ADMIN_*` value in `config.php`
   (email `admin@example.com`). **Change the password in Admin → Settings → My admin account** and you can
   remove the password line from `config.php`.

## Nothing is hard-coded
* **Prices are real**: Binance (crypto) and Yahoo Finance (forex, gold, stocks, crypto). No simulation. Each asset has a
  data source + symbol, editable in Admin → Assets (a live-price test runs before an asset is added).
* Stocks/forex are marked **closed** when the quote is stale; closed markets cannot be traded.
* Settlement uses the real price at the expiry second (Binance 1-second candle / recorded live tick / market history).
  If no price can be found for 15 minutes the stake is refunded.
* Everything else (site name, payouts, limits, expiry times, payment details, USDT↔INR rate, SMTP …) is in Admin → Settings.
  USDT↔INR uses the live USD/INR rate unless you switch to a manual rate.

## Predictions & payout
Users predict **UP or DOWN**. Right → they win the payout %, wrong → they lose the stake, exact tie → stake returned.
Admin → **Markets & Payout** sets one global winning % (optional per-market override), turns markets on/off
(single or by group), and manages data sources. The trading chart shows a prediction arrow + countdown for each open trade
and a live "what traders predicted" bar (last 15 minutes). Admin dashboard has an up-vs-down predictions chart.

## Chart / price API keys
Admin → Markets → *Chart & price data API keys*: Twelve Data, Finnhub, Alpha Vantage (stored encrypted, with a Save & test
button that shows the provider's own error). Each market can use a different **price source** and an optional separate
**chart source**; Binance and Yahoo Finance need no key. Free plans are rate-limited, so keyed quotes refresh every 10–30 s.

## Profit & loss for users
No graphs in the user area. History → **Profit & loss** shows, per account, a clear "You are in PROFIT / LOSS" result, today / 7 days /
30 days / all time, win rate, deposits vs withdrawals and the result by market. The account menu always shows today's result.
History → **Email report** sends a statement (summary + latest trades + CSV files) to the user's email on request
(max 3 per hour; switch off in Settings).

## Deposits & withdrawals
Admin sets UPI ID + payee name, and bank name / holder / account number / IFSC in separate fields (Settings → Payment details).
Users get a **dynamic QR** built from the UPI ID and the amount they type, buttons for Google Pay / PhonePe / Paytm / BHIM / any UPI app,
and copy buttons for bank details. Withdrawals ask for account holder, account number (typed twice), IFSC (checked live) and bank name,
or UPI ID / wallet address, all validated.

## Charts
Users see the **TradingView** chart by default (free TradingView widget: indicators, drawing tools) with a switch to the Standard chart
(prediction arrows + countdown). Each market has a TradingView symbol in Admin → Markets → Data sources; settlement always uses the
price source, never the chart.

## Alerts & loaders
SweetAlert2 dialogs for confirmations, errors and toasts; page loader, top progress bar, button spinners and chart loaders.

## Security PIN
Profile → Security PIN: each user sets a 6-digit PIN (hashed, weak PINs rejected, locked 30 min after 5 wrong tries with an email alert).
It is required for **every withdrawal** and can also lock the app at login. Admin can reset a user's PIN on the user page.

## Dark / light theme
Every page has a sun / moon button. The choice is remembered per user (and per device) and restored at login.

## SQL file
`database.sql` is a clean, ready-to-import database (schema + starter markets + settings + first admin, no user data). Import it with
phpMyAdmin → Import, or `mysql -u root -p < database.sql`; it only creates what is missing. Rebuild it with `php make_sql.php`.
For a backup that includes your data: `mysqldump -u root tradepro > backup.sql`.

## Saved payout details
Users save their UPI / bank / USDT payout accounts once in Profile → Payout details (PIN required, stored encrypted, email alert on changes).
A withdrawal then only needs: choose the saved account, amount, PIN.

## Exports
Admin → Users / Trades / Deposits have a ⬇ CSV button.

## Email (SMTP)
Admin → Settings → Email: host, port, security, username, password (stored encrypted), From, admin-alert address,
quick-fill for Gmail / Outlook / Zoho / Brevo / SendGrid, **Save & send test**, and a log of recent emails.
Used for: password reset (users + admins), optional email confirmation, welcome mail, deposit/withdraw alerts to admins,
approval/rejection mails to users. Gmail needs an **App Password**.

## Passwords
One policy everywhere: 8+ characters with upper & lower case, a number and a symbol (live strength meter). Hashes use
`password_hash` (bcrypt). Forgot-password works for users and admins once SMTP is enabled.

## Time zones
Every page has an **IST / UTC** switch with a live clock (remembered per device). Charts and dates follow it; the default is set in Settings.

## Charts
Admin dashboard, Users, User detail and the user's History → Charts use real database numbers. Every chart has a
⤢ button to open it full screen (range selector inside). The trading chart has an expand button too, with
Higher/Lower buttons in full-screen mode, plus Candles/Line toggle.

## Mobile
Responsive layouts: bottom tab bar for users, slide-in menu for admin, tables become cards on phones.

## Optional background job
`/cron.php?key=<key shown in Settings>` (or `php cron.php`) every minute settles trades when nobody is online.

## Before going live
* Use HTTPS (cookies become `secure` automatically).
* Binary-options style products are restricted or banned in many countries. Check the law and licensing where you operate.
  Yahoo/Binance quotes are unofficial/delayed public data – disclose that to users and check their terms for commercial use.
* Add KYC and a payment gateway before handling real money.


## BigBang sandbox games (feature branch)
The optional BigBang integration is available at `/bigbang.php` and in Admin → BigBang Sandbox.
1. Run `/install.php` once after deploying this branch; schema version 12 adds the encrypted-key setting.
2. Sign in as admin, open **BigBang Sandbox**, enter a newly generated **sandbox** key beginning `ek_test_`, save it, then use **Test API connection**.
3. Users enter the games area using the existing games PIN gate and launch Standard-tier games in provider demo mode.

The key is encrypted server-side using the existing storage key. It is never sent to the browser or committed to Git. This integration deliberately does not implement BigBang wallet callbacks, player balance changes, deposits, withdrawals, Premium, Enterprise or Sports games. Rotate any API key that was previously pasted into a chat or committed to a public repository.
