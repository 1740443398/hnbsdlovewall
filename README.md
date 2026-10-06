# Campus Communication Wall

<p align="center"><strong>English</strong> · <a href="README_CN.md">简体中文</a></p>

A full-featured campus social platform built with PHP, featuring a JSON file-based storage system with no database required.

Site: <https://hnbsd.ct.ws>

## Author

- **Name**: 蕭遞 (online handle)
- **QQ**: 1740443398

## License

This project is licensed under the [Love Wall Custom License — Source Available](LICENSE).

**Free for personal, learning, and non-profit campus use.** Any **commercial use requires the prior written authorization of the Licensor.** Any **redistribution must retain the author attribution** (蕭遞 / QQ 1740443398) and include the original LICENSE. This license has **no Change Date and does not convert to a permissive license** (no auto MIT conversion).

See the [LICENSE](LICENSE) file for full terms.

### Third-party components

Bundled third-party components, their licenses and required notices are listed in [THIRD_PARTY_LICENSES.md](THIRD_PARTY_LICENSES.md) (currently `assets/js/vendor/xlsx.full.min.js`, Apache License 2.0 — full text in [licenses/Apache-2.0.txt](licenses/Apache-2.0.txt)).

When redistributing, ship `LICENSE`, `THIRD_PARTY_LICENSES.md` and `licenses/` together. Also note that `data/` and `uploads/` hold **user data** (already excluded by `.gitignore`) and must never be included in any package or redistribution.

## Features

### Core Social Features
- Post creation with categories (Lost & Found, Study Help, Social Chat, Confession Wall, School Info, Announcements)
- Anonymous posting support
- Post visibility control (public, whitelist, blacklist)
- Image upload support (up to 9 images per post, 10MB total limit)
- Comment system with like functionality
- Post likes and favorites
- Poll system for community voting
- Post search by keyword
- Sensitive word filtering with automatic review
- In-site private messaging (server-stored, multi-device sync; with chat record reporting)
- Private-message notifications in the notification center (sender name plus a short preview)
- Report system for posts and private messages (with chat record selection)
- External URL risk warning in private messages
- Email notifications (welcome email for new users, password-reset verification code email; both clarify this is NOT a phishing/scam site, include the open-source repo link and the developer's real identity as a guarantee)
- Every outgoing email carries the site URL in its footer
- Roll call / check-in page (`pages/rollcall.php`)

### User System
- QQ-based registration and login
- QQ number change request (submitted in user center, approved by admin) and admin review panel
- Remembered login (15 days auto-login)
- Relaxed password rule (min 6 chars, strength hint only)
- Registration without subject-selection options
- **New-student verification question** (live site only): on registration the user must answer one of two school-specific questions correctly (the campus slogan's missing half, or the principal's full name). Answers are normalised (whitespace and full/half-width punctuation ignored) and checked server-side; brute-forcing is impractical because every attempt must also pass the click captcha and the per-IP daily registration limit
- First-time registration skips sponsor popup
- Landing page introducing the site & open-source repo (also an official-entry anti-phishing notice)
- Automatically guides first-time new devices to registration (Cookie + IP double check)
- Two-factor authentication (2FA) with TOTP
- Password reset and recovery
- User profile management (nickname, avatar, bio)
- Grade / class / Anhui 3+1+2 exam subjects selection (with auto summer upgrade)
- Custom user titles with colour and gradient options
- User-customizable title application (apply, admin review)
- Theme customization (light/dark mode)
- Activity logs and notification system
- Personal stats dashboard (my posts / likes / comments / mentions, with a 7-day trend and my top posts)
- Ban/unban system with timed bans
- Visit tracking (total visits, 30-day trend, per-user count)
- **Growth & achievement system**: experience points (daily cap), level curve `50*(n-1)*n` up to 30, 20 achievements computed in real time, a growth-centre page (level ring + progress + daily tasks + achievement wall + experience leaderboard), and a per-user level badge with dark-theme colours
- **User blocking** (one-way, two-way isolation, no notification to the blocked party) and **PM mute / conversation pin**; the conversation list flags `muted/pinned/blocked` and pinned conversations sort first

### Admin Dashboard
- Comprehensive admin panel with role-based access control
- Super admin and admin roles with granular permissions
- Post management (view, audit, delete)
- Comment management (filter by keyword / post ID, pagination, single/batch delete)
- User management (ban, unban, reset 2FA, reset password, change username)
- Directly change a user's bound QQ number — the **previous** QQ mailbox is emailed the new number, and the user's active sessions are invalidated immediately
- Admin permission templates: **T1** read-only panel (no anonymous authors, no writes), **T2** adds low-risk operations, **T3** full-site access (still below the owner) — plus a fully custom permission set
- Dashboard change alerts pushed into admins' notification centre (new registration, new post, new report)
- Every admin write endpoint re-checks its own granular permission (site settings, sponsors, user titles, feature requests, data export)
- New admin permissions: view anonymous post authors / non-public posts (with confidentiality warning), view user details
- Title application review (approve / reject user title requests)
- Report management (private message & post reports)
- Announcement management
- Site statistics dashboard (with visit tracking and a **live online-user list**)
- Online-user tracking: one heartbeat per 60s site-wide (paused while the tab is hidden); a user goes offline after 5 minutes without a heartbeat. Logged-in users are listed individually; guests are counted only, with no IP or session stored
- Operation logs, **AI assistant call logs**, and illegal access logs
- AI call logs: time / user / guest flag / IP / success or failure / failure reason / latency / which site data the answer read / matched intent / **the first 200 characters of the question**. **AI replies, system prompts and API keys are never stored.** The last 2000 records are kept automatically, and a dedicated `view_ai_logs` permission can be granted on its own
- IP blacklist management
- Data export / import (full backup) for maintenance
- Public download requests (any registered user may apply; a verification code is emailed to their registered QQ address before download; requests visible in the admin panel). The archive is a ZIP of the full site source code with **all data sanitized** — an applicant receives only their own records, and anonymous post authors are never exposed
- "Search + dropdown" target-user picker in the admin panel (no need to type a QQ number)
- Sponsor management
- **Admin dark mode** (system / light / dark, remembered per admin) and sidebar entries for the new pages below
- **Growth-system management**: rules editor, level distribution chart, experience leaderboard, achievement unlock stats, per-user growth-data reset
- **Sensitive-word library management**: bulk paste (newline / comma / semicolon), inline edit, batch delete, and a live tester that highlights hits in the original text (reuses the same `checkSensitiveWords()` the front end uses)
- **System health check**: environment / data / config / security checks with a composite score, orphan-record detection and leftover-file cleanup
- **Permission matrix** (read-only admin×permission grid), **data dictionary** (real `data/*.json` fields), **title presets**, **check-in rules**, **post-vote management** (list / close / open / reset / delete)

### Utility Tools
- Pomodoro timer with presets
- Countdown timer (precise to seconds)
- Stopwatch
- Todo list with local storage
- Calculator
- QR code generator
- Password generator
- Color picker
- Word counter
- Unit converter
- Random number generator
- Dice roller
- Coin flip
- BMI calculator
- Lucky wheel with customizable options
- World clock
- IP address lookup
- Weather query (defaults to Huainan)
- Daily quote (Chinese poetry)
- Notes/scratchpad
- Text-to-speech (Web Speech API)
- Base64 encode / decode
- Scratch card

> Every tool card is keyboard-reachable: Tab to focus, Enter/Space to open, with a visible focus ring.

### AI Assistant
- In-site AI assistant "小墙" powered by Zhipu **GLM-4.1V-Thinking-Flash** (the API key lives only on the server and is never sent to the browser)
- **Available on every page**: a floating assistant in the bottom-right corner on all pages (including login, error and admin pages). `pages/ai_assistant.php` is its full-page edition; both share one rendering core and one conversation store
- **Site-aware**: reads site overview, announcements, latest/hot posts, **weekly/monthly leaderboards**, post detail and comments, keyword search, category stats, active-user leaderboard, public user profiles, my posts / favorites / comments / notifications / DM summary / check-ins / follows, check-in leaderboard, feature votes, sponsors and titles, client version, weather and daily quote on demand (~26 intent classes)
- **Smart drafting on the compose page**: describe the gist in one sentence (lost item, question, confession…) and it is turned into a title and body. Facts that are not in the input (places, contact details) are never invented, and existing text is only replaced after you confirm
- **Human-feeling persona**: a dependable senior-student voice, covering study tutoring (nine subjects plus essay writing, memorization, exam mindset, time planning) and everyday-life companionship (sleep, mood, relationships, family), with serious crisis-intervention guidance for emotional distress
- **In-site links**: internal paths in replies render as clickable links; external URLs render as non-clickable text with an "external" badge so the assistant can never be used as a phishing hop
- **Confirmation cards instead of acting silently**: favorite/unfavorite, like/unlike, follow/unfollow, daily check-in, poll voting, mark notifications read, post a comment, delete your own post or comment, **create a post, send a DM** — the AI only *prepares* a card; nothing is written until the user clicks "Confirm" (14 actions in total)
- **Guest read-only**: visitors can ask about public content and study/life questions; personal data and write actions prompt sign-in
- **Security**: card parameters live only in the server session and the browser only ever holds an opaque token (so it cannot tamper with parameters); tokens are single-use with a 10-minute TTL; card issuance is throttled, per-action rate limits and audit logging apply. Password changes, QQ rebinding, 2FA toggles, logout, reporting, title applications and all admin actions are **explicitly forbidden** for the AI
- **Injection hardening**: user-generated content fed into the model context (post titles/bodies, comments, nicknames) is sanitized first (neutralize `<lw-action>` protocol tags, strip Markdown link targets, replace bare URLs) and the prompt states clearly that data is not instruction

#### Advanced options · bring your own AI

A three-button configuration panel is available (AI bubble → "Advanced options · connect my own AI"):

| Button | Behaviour |
|---|---|
| Test connection | Sends one minimal request using the values you just typed (or the saved ones) to verify the endpoint works |
| Save | Writes the configuration (leave the API key blank to keep the previously saved key) |
| Restore defaults | Deletes your own configuration and falls back to the site's default model |

Configurable: endpoint URL (validated against an endpoint whitelist to prevent SSRF), model name, API key, custom system prompt, and more.

Three hard rules:

1. **The key is never sent back in plaintext** — only a masked value (last 4 characters) crosses the wire; the plaintext exists only for a moment in server memory, and is stored **encrypted** on disk.
2. **Sign-in is required** — guests do not get a "my own AI".
3. **"Restore defaults" only deletes your own record** — it never touches the site configuration and never affects anyone else.

A user-supplied AI **shares the exact same core** as the site default, so it keeps full in-site read/write ability (all 14 confirmation-card actions) — it is not a degraded experience.

### Entertainment & Social
- Follow/unfollow system
- Built-in web browser for navigation
- Snow effect (seasonal)
- Night mode toggle
- Page load progress bar
- Floating action buttons
- Keyboard shortcuts (Ctrl+Enter to submit)
- Check-in system (with streak)
- Feature voting (submit and vote on feature suggestions)
- Random post
- Emoji reactions (👍 ❤️ 😂 😮 😢 😡 on every post card; the chosen set is summarised on the card and hidden in print styles)
- Leaderboards (weekly / monthly): hot posts (compared tier by tier on likes → comments → views), active users, and hot topics (ranked by how many posts carry the `#tag`); only currently visible posts are counted

### Security
- Web Application Firewall (WAF) with SQL injection, XSS, and command injection protection
- CSRF token protection with enhanced origin/referer checks
- Rate limiting and DDoS protection
- IP blacklist with automatic banning
- Session security binding (IP/UA change detection)
- Content Security Policy (CSP) headers
- Request fingerprinting and bot detection
- Honeypot anti-spam for registration
- File upload validation with MIME type and magic byte checks
- One-time form submission tokens
- Password brute-force protection with lockout
- Click-based captcha with session-stored answers (anti-direct-access)
- Login required site-wide (unauthenticated users redirected)
- Sensitive directory protection (config/, browser cache Default/ etc. return 403)
- Anti-hijack guard: front-end whitelist check redirects visitors away from impersonation/mirror domains
- Per-permission authorization on every admin write endpoint (an admin can never exceed the template granted to them)
- Session invalidation on credential changes (rebinding a QQ number forces the user to log in again)
- SSRF protection: the endpoint URL of a user-supplied AI is checked against a whitelist and can never point at an internal network
- User-supplied AI keys are **stored encrypted** and the plaintext is never sent back to the browser
- **Admin IP allowlist** (`ADMIN_ALLOWED_IPS`) gates the admin panel to a configured set of addresses
- Production error display is **off** (errors are logged, never echoed to the browser unless `LW_SHOW_ERRS=1` is set locally)
- Sensitive-data masking (phone / email / QQ) is applied to audit logs
- Lightweight devtools-aware `anti_debug.js` (non-blocking, wrapped in try/catch so it can never break the main flow)
- See [SECURITY.md](SECURITY.md) for the full security policy (response headers, session/auth, CSP, upload safety, WAF, ops notes, incident response)

### SEO & Discoverability
- Dynamic `sitemap.xml` (generated on demand by `sitemap.php`, listing public pages only, cached to file for 6 hours)
- `robots.txt` declaring the admin panel, API endpoints, auth pages and user-privacy pages as not-for-indexing
- canonical, Open Graph and Twitter Card metadata emitted on every page

### PWA & Offline
- "Add to Home Screen": mobile browsers can install the site as an app-like, full-screen experience with no address bar (iOS via apple-touch-icon, Android via Web App Manifest)
- Service Worker (`sw.js`) uses a **network-first** strategy, with an `offline.html` fallback page

### Design & UX
- Responsive design with mobile bottom navigation
- One-time new-user onboarding guide
- Low-end device optimization (reduced backdrop blur, lazy image loading, 30-day asset caching)
- Pill-shaped buttons and tags (not elliptical)
- Inter + Noto Sans SC typography
- Glassmorphism UI with backdrop blur
- Unified neutral design system (slate palette, 18–28px radii, liquid-glass surfaces) shared by the site and the admin panel
- Redesigned admin panel: 240px white sidebar, frosted-glass top bar, 20px card radii
- Smooth transitions and micro-interactions
- Skeleton loading states
- Toast notifications
- Infinite scroll
- **Dynamic SVG icons** (lift / stroke-draw / heart-pop / bell-shake / spin / pulse / download), all gated behind `prefers-reduced-motion: no-preference`
- **`polish.js` progressive enhancements**: top-bar scroll shadow, back-to-top, reading-progress bar (long pages only), font-size adjuster (three steps, accessibility), `?` keyboard-shortcut panel, image lightbox, copy-link on post cards — each block independent and try/catch-wrapped so a failure never leaves an empty shell
- Cross-browser compatibility
- Safe area adaptation for notched screens
- Accessibility: site-wide `aria-pressed` state sync, `role="group"` labelling, `aria-live="polite"` toast container, `autocomplete` hints on password fields, keyboard-reachable focus rings
- High contrast / print / reduced-motion preferences, plus a `forced-colors` fallback for glass panels under Windows forced-colour mode

## Technical Stack

- **Backend**: Hand-written PHP 8.4 (no framework, no Composer)
- **Storage**: JSON file-based (no database required)
- **Frontend**: Vanilla JavaScript + CSS3 (no build chain; optional minification script below)
- **Font**: Inter / Noto Sans SC
- **Integrations**: Zhipu AI (GLM), SMTP (QQ Mail)
- **Security**: Custom WAF, CSP, CSRF, rate limiting

## Requirements

- **PHP 8.0 or higher** (both development and production run PHP 8.4; the code itself uses no PHP 8-only syntax, but has not been fully verified on 7.x)
- Required extensions: `curl`, `mbstring`, `json`, `gd`, `openssl` (without `curl`/`mbstring` some features such as the AI assistant are unavailable, but the rest of the site works normally)
- A web server with PHP support (Apache/Nginx), or the PHP built-in server directly
- Write permissions on `data/` and `uploads/` directories

### Quick local run

`启动.bat` in the project root (Windows) will: detect PHP and the required extensions → download a portable build from a domestic mirror if PHP is missing → pick a free port → detect the public IP and ask whether to use it → start the built-in server and open the browser automatically.

- The script **keeps the window open**; to stop the server, **type `Q` and press Enter** as prompted (closing the window also stops it).
- The script is **GBK-encoded** (the default code page of Chinese Windows). Do not re-save it as UTF-8 when editing.

## Installation

1. Upload all files to your web server
2. Ensure `data/` and `uploads/` directories are writable (or let `.htaccess` secure them)
3. Access `init.php` in your browser once (e.g. `https://your-domain/init.php`) to generate the default super admin account
4. Default super admin credentials:
   - **Username**: `admin`
   - **Password**: `admin`
5. **Security**: Log in with `admin` / `admin`, then change your password immediately at the security/change-password page. The account is flagged to force a password change on first login.
6. After initialization the site is ready — just visit the site root (`index.php`).

### Do **not** upload these to a live site

Upload the program code only (`admin/`, `api/`, `assets/`, `config/*.php` except the secret-bearing ones, `includes/`, `lang/`, `pages/`, `licenses/`, `tools/`, `index.php`, `init.php`, `sitemap.php`, `sw.js`, `offline.html`, `manifest.webmanifest`, `.htaccess`, `robots.txt`). The following are local data or credentials:

- `data/` (all site JSON data: users, posts, DMs, logs)
- `uploads/` (user-uploaded images)
- `config/ai_config.php`, `config/mail_config.php`, `config/sync_config.php` (API key / mailbox password / sync seed — **rotate them immediately if leaked**)
- `config/sync_nonces.json` (anti-replay records, generated at runtime)
- `keystore.properties`, `*.jks`, `*.keystore` (Android signing keys — **never share these**)
- `.shots/`, `.idea/`, `.trae/`, `node_modules/`, `*.log`, `*.bak`, `love_wall.zip`
- `data_backup_*/` (local backup directories)

After uploading, open `init.php` once to create the default super admin, then change the default password immediately.

> `config/app_config.php` may be uploaded if it holds the real APK filename and SHA-256 — it contains **no secret**, and the in-site "Install app" entry and the app's "Check for updates" screen both depend on it.

## Android Client

A companion Android app ships with this project (a WebView shell plus native enhancements). Its version metadata lives in `config/app_config.php`, which both the site's "Install app" menu entry and the app's "Check for updates" screen read from.

**Current version: 1.7.0 (version_code 8)**

- **APK size cut by ~58%** (2.66 MB → 1.11 MB): R8 obfuscation + resource shrinking enabled (with keep rules protecting the 13 `@JavascriptInterface` bridge methods), a single vector launch icon replaces the 5-density PNG set, and only `zh`/`en` language resources are kept
- Desktop-layout mode, release-key signing, SHA-256 update verification and power-optimised background notifications (carried over from 1.6.0)
- The 13 native bridge methods (`LoveWallApp.*`) are verified present by name in the final DEX, so the JS bridge keeps working after R8

> ⚠️ **1.6.0 changed the signing key.** Android refuses to install an APK whose signature differs from the installed one, so **users on an older build must uninstall it before installing 1.6.0+**. After that, 1.7.0 upgrades in place normally.

## Data sync tools (`tools/`)

When the owner/developer can reach the site directly from their own machine, they can bypass the email-verification-code flow and pull or write back the full-site JSON data.

| File | Purpose |
|---|---|
| `tools/lw-sync-key.ps1` / `lw-sync-key.bat` | Show the current sync key and its remaining validity, and generate **one-time signed links** (one for production, one for local) that can be opened straight in a browser |
| `tools/lw_sync_pull.php` / `lw-sync-pull.bat` | **Pull the full dataset from the live `/api/ai_sync.php` and overwrite the local `data/`** |

Authentication uses the HMAC seed in `config/sync_config.php` (auto-generated, rotated every 90 days). The seed itself is never transmitted:

```
X-Sync-Timestamp: <Unix seconds>
X-Sync-Key:       hex(HMAC-SHA256(seed, canonical string))
canonical string = timestamp \n method \n path \n sha256(request body)
```

A signature is **single-use** (the server keeps an anti-replay nonce), and the timestamp must be within ±300 seconds.

```bash
# Preview which tables would be written, without touching disk
php tools/lw_sync_pull.php --dry-run

# Overwrite for real once you are happy
php tools/lw_sync_pull.php
```

> Note: `lw_sync_pull.php` **overwrites every table in the target** (including logs and rate-limit records). Confirm the target copy beforehand and back up `data/` if needed.
>
> Also note the site host ships a JS anti-bot challenge; the tool handles it automatically with no manual intervention.

## Static asset minification (optional)

The project has no build chain — the `.js` / `.css` files under `assets/` are directly readable and editable, and `.min.*` files are derived artefacts.

```bash
bash tools_build_assets.sh           # minify only those whose source is newer than the .min
bash tools_build_assets.sh --force   # minify everything again
```

Requires Node.js plus `terser` and `clean-css-cli`:

```bash
npm i --prefix .buildtools terser clean-css-cli
```

The server-side `asset_url()` emits the `.min` file when it exists **and is not older than its source**, and otherwise falls back to the source file automatically — so **forgetting to run the minifier costs you file size at worst; it can never ship stale logic**. Remember to upload the `.min.*` files too (there is no Node on the production host).

An optional **obfuscation hardening** mode is available for pre-release packaging (default OFF; does not affect daily minification):

```bash
# domain-lock + tamper-check wrapping on the 4 entry JS files (only active when LW_ALLOWED_HOSTS is set)
LW_ALLOWED_HOSTS=hnbsd.ct.ws bash tools_build_assets.sh --force --obfuscate
```

It never mangles property names (`mangle.properties=false`), so JSON field contracts, DOM `class`/`id` and `window.X` globals stay intact. Full design and regression notes are in [tools_obfuscate_readme.md](tools_obfuscate_readme.md).

## Directory Structure

```
love_wall/
├── admin/          # Admin panel pages
├── api/            # API endpoints
│   ├── admin/      # Admin API
│   ├── auth/       # Authentication API
│   ├── posts/      # Posts API
│   └── user/       # User API
├── assets/         # Static assets
│   ├── css/        # Stylesheets
│   ├── images/     # Images
│   └── js/         # JavaScript
├── config/         # Configuration files
├── data/           # JSON data storage (runtime data, do not upload)
├── download/       # Downloadable files such as the APK
├── includes/       # Core libraries
│   ├── filestorage.php    # File-based storage engine
│   ├── security.php       # Security functions
│   ├── waf.php            # Web Application Firewall
│   ├── totp.php           # TOTP 2FA implementation
│   ├── online.php         # Online-presence heartbeat
│   ├── i18n.php           # Multi-language (i18n) support
│   ├── ai_client.php      # AI call client
│   ├── ai_actions.php     # AI confirmation-card actions
│   ├── ai_site_data.php   # Site data surface readable by the AI
│   ├── user_ai_config.php # User-supplied AI (encrypted storage + endpoint whitelist)
│   ├── sync_auth.php      # Seed management and request verification for the ai_sync endpoint
│   ├── seo_meta.php       # canonical / OG / Twitter Card
│   └── pwa_head.php       # PWA head tags
├── lang/           # Language translation files
├── licenses/       # Full text of third-party licenses
├── pages/          # Frontend pages
├── tools/          # Ops scripts (sync key / data pull)
├── uploads/        # User uploads
└── zanzhu/         # Sponsor images
```

Also at the top level: `index.php` (entry point), `init.php` (initialization), `sitemap.php` (dynamic sitemap), `sw.js` (Service Worker), `offline.html` (offline page), `manifest.webmanifest` (PWA manifest), `robots.txt`, `.htaccess`, `启动.bat`, `tools_build_assets.sh`.

## Configuration

Edit `config/constants.php` to customize:
- `SITE_NAME` - Site name
- `EXPECTED_HOSTS` - Allowed host whitelist (anti-impersonation guard)
- `SUPER_ADMIN_QQ` - Super admin QQ number
- `BCRYPT_COST` - Password hashing cost
- `SESSION_LIFETIME` - Session lifetime
- `ITEMS_PER_PAGE` - Posts per page
- `GUEST_VISIBLE_POSTS` - Number of posts visible to guests

## Notes

- This project uses JSON files for data storage, no MySQL or other database is required
- All data is stored in the `data/` directory
- Uploaded images are stored in the `uploads/` directory
- `data/*.json` is written in **compact form** (no indentation) to save disk quota on shared hosting and speed up `json_decode`
- Multi-language support (Simplified Chinese, Traditional Chinese, English); language is detected automatically and can be switched via the language switcher in the header (stored in a cookie)
- Secret-bearing config files (`config/mail_config.php`, `config/sync_config.php`, `config/ai_config.php`) are **excluded from version control** — supply your own credentials. The AI assistant degrades gracefully when left unconfigured
- Every email sent to users includes the site URL
- The project is designed for deployment on shared hosting (e.g., InfinityFree)

## Known TODOs

- The **list branches** of `users.php`, `posts.php` and `announcements.php` under `api/admin/` still lack fine-grained permission checks (all write endpoints are checked)
- The Android "desktop UA" behaviour has not yet been fully validated on a real device
- The launcher's "public IP mode" only warns that the built-in server does not listen externally; for LAN/WAN access you still need your own port forwarding or Apache/Nginx
