# Love Wall - Campus Communication Wall

<p align="center"><strong>English</strong> · <a href="README_CN.md">简体中文</a></p>

A full-featured campus social platform built with PHP, featuring a JSON file-based storage system with no database required.

## Author

- **Name**: 蕭遞 (online handle)
- **QQ**: 1740443398

## License

This project is licensed under the [Love Wall Custom License — Source Available](LICENSE).

**Free for personal, learning, and non-profit campus use.** Any **commercial use requires the prior written authorization of the Licensor.** Any **redistribution must retain the author attribution** (余灏明 / 蕭遞 / QQ 1740443398) and include the original LICENSE. This license has **no Change Date and does not convert to a permissive license** (no auto MIT conversion).

See the [LICENSE](LICENSE) file for full terms.

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
- Report system for posts and private messages (with chat record selection)
- External URL risk warning in private messages
- Email notifications (welcome email for new users, password-reset verification code email; both clarify this is NOT a phishing/scam site, include the open-source repo link and the developer's real identity as a guarantee)
- User-customizable title application (apply, admin review)

### User System
- QQ-based registration and login
- QQ number change request (submitted in user center, approved by admin) and admin review panel
- Remembered login (15 days auto-login)
- Relaxed password rule (min 6 chars, strength hint only)
- Registration without subject-selection options
- First-time registration skips sponsor popup
- Two-factor authentication (2FA) with TOTP
- Password reset and recovery
- User profile management (nickname, avatar, bio)
- Grade / class / Anhui 3+1+2 exam subjects selection (with auto summer upgrade)
- Custom user titles with color and rainbow effects
- Theme customization (light/dark mode)
- Activity logs and notification system
- Ban/unban system with timed bans
- Visit tracking (total visits, 30-day trend, per-user count)

### Admin Dashboard
- Comprehensive admin panel with role-based access control
- Super admin and admin roles with granular permissions
- Post management (view, audit, delete)
- Comment management (filter, search, single/batch delete)
- User management (ban, unban, reset 2FA, reset password, change username)
- New admin permissions: view anonymous post authors / non-public posts (with confidentiality warning), view user details
- Title application review (approve / reject user title requests)
- Report management (private message & post reports)
- Announcement management
- Site statistics dashboard (with visit tracking)
- Operation logs and illegal access logs
- IP blacklist management
- Data export / import (full backup, super admin only) for maintenance
- Music management (upload, delete, playback settings)
- Sponsor management

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
- Weather query
- Daily quote (Chinese poetry)
- Notes/scratchpad
- Text-to-speech (Web Speech API)

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

### Design & UX
- Responsive design with mobile bottom navigation
- One-time new-user onboarding guide
- Low-end device optimization (reduced backdrop blur, lazy image loading, 30-day asset caching)
- Pill-shaped buttons and tags (not elliptical)
- Genshin Impact font integration
- Glassmorphism UI with backdrop blur
- Animated gradient backgrounds
- Smooth transitions and micro-interactions
- Skeleton loading states
- Toast notifications
- Infinite scroll
- Cross-browser compatibility
- Safe area adaptation for notched screens
- High contrast / print / reduced-motion preferences

## Technical Stack

- **Backend**: PHP 7.4+
- **Storage**: JSON file-based (no database required)
- **Frontend**: Vanilla JavaScript, CSS3
- **Font**: HYWenHei (Genshin Impact style)
- **Security**: Custom WAF, CSP, CSRF, rate limiting

## Requirements

- PHP 7.4 or higher
- Web server with PHP support (Apache/Nginx)
- Write permissions on `data/`, `uploads/`, and `music/` directories

## Installation

1. Upload all files to your web server
2. Ensure `data/`, `uploads/`, and `music/` directories are writable (or let `.htaccess` secure them)
3. Access `init.php` in your browser once (e.g. `https://your-domain/init.php`) to generate the default super admin account
4. Default super admin credentials:
   - **Username**: `admin`
   - **Password**: `admin`
5. **Security**: Log in with `admin` / `admin`, then change your password immediately at the security/change-password page. The account is flagged to force a password change on first login.
6. After initialization, the site is ready. Subsequent visits go through the normal entry point (`router.php` or your virtual host).

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
├── data/           # JSON data storage
├── includes/       # Core libraries
│   ├── filestorage.php  # File-based storage engine
│   ├── security.php     # Security functions
│   ├── waf.php          # Web Application Firewall
│   ├── totp.php         # TOTP 2FA implementation
│   └── music_player.php # Music player component
├── music/          # Music files for background player
├── pages/          # Frontend pages
├── uploads/        # User uploads
└── zanzhu/         # Sponsor images
```

## Configuration

Edit `config/constants.php` to customize:
- `SITE_NAME` - Site name
- `SUPER_ADMIN_QQ` - Super admin QQ number
- `BCRYPT_COST` - Password hashing cost
- `SESSION_LIFETIME` - Session lifetime
- `ITEMS_PER_PAGE` - Posts per page

## Notes

- This project uses JSON files for data storage, no MySQL or other database is required
- All data is stored in the `data/` directory
- Music files should be placed in the `music/` directory (MP3, WAV, OGG, M4A, AAC, FLAC supported)
- Uploaded images are stored in the `uploads/` directory
- The project is designed for deployment on shared hosting (e.g., InfinityFree)