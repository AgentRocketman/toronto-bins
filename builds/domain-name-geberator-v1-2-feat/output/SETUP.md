# Domain Name Generator - Setup Guide

## Quick Start

1. **Configure Database & API Keys**
   - Edit `config.php` with your database credentials
   - Add your OpenAI API key
   - Add your Namecheap API credentials

2. **Install Database**
   - Run `install.php` in your browser or via CLI:
   ```bash
   php install.php
   ```

3. **Set Up Background Worker** (for domain availability checking)

   ### Option A: Cron Job (Recommended)
   Add to your crontab to run every minute:
   ```bash
   * * * * * cd /path/to/project && php api/worker.php >> worker.log 2>&1
   ```

   ### Option B: Manual Trigger
   The system will attempt to trigger the worker automatically via HTTP when domains are generated. Ensure `api/worker.php?manual_run=1` is accessible.

   ### Option C: No Background Worker
   If you can't set up a cron job, domain checks will happen synchronously during generation (slower but functional).

## Features

### ✅ Working Features
- Domain name generation using OpenAI
- Real-time availability checking via Namecheap API
- Domain caching (6 hours)
- Favorites system (localStorage)
- Rate limiting
- Responsive design

### 🔧 Configuration

Edit `config.php`:

```php
// Required: OpenAI API
define('OPENAI_API_KEY', 'your-key-here');

// Required: Namecheap API (for availability checking)
define('NAMECHEAP_API_USER', 'your-username');
define('NAMECHEAP_API_KEY', 'your-api-key');
define('NAMECHEAP_USERNAME', 'your-username');
define('NAMECHEAP_CLIENT_IP', 'your-whitelisted-ip');
```

## Testing

1. **System Test**: Open `test.html` in your browser to verify:
   - PHP environment
   - Database connection
   - API configuration
   - Domain generation

2. **Manual Test**: Use the main interface at `index.html`

## Troubleshooting

### Generate Button Not Working
1. Check browser console for JavaScript errors
2. Verify `api/generate.php` is accessible
3. Check that OpenAI API key is valid
4. Review PHP error logs

### Domain Availability Not Updating
1. Ensure worker is running (check `worker.log`)
2. Verify Namecheap API credentials
3. Check that your IP is whitelisted in Namecheap
4. Review browser console for polling errors

### Database Errors
1. Verify database exists and credentials are correct
2. Run `install.php` to create/recreate tables
3. Check MySQL error logs

## Architecture

### Flow
1. User enters business description
2. Frontend JavaScript calls `api/generate.php`
3. Backend generates domain names using OpenAI
4. Domains are saved to database with "pending" status
5. Cached availability is applied immediately
6. Worker checks uncached domains asynchronously
7. Frontend polls `api/poll-results.php` every 2 seconds
8. Results update in real-time as checks complete

### Files
- `index.html` - Main interface
- `assets/js/app.js` - Frontend logic
- `assets/css/style.css` - Styling
- `api/generate.php` - Domain generation endpoint
- `api/poll-results.php` - Results polling endpoint
- `api/worker.php` - Background availability checker
- `config.php` - Configuration
- `install.php` - Database setup

## Performance Notes

- Domain checks are cached for 6 hours
- Rate limiting: 3 seconds between generations per session
- Background worker processes 10 domains per run
- Worker has 50-second max runtime
- Frontend polls every 2 seconds until complete

## Security

- Input sanitization on all user data
- Prepared statements for SQL queries
- Session-based rate limiting
- HTTP-only session cookies
- No sensitive data in frontend
