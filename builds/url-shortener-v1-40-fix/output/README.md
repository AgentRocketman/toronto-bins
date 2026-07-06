# URL Shortener

A simple, lightweight URL shortener built with vanilla HTML/CSS/JS, PHP, and JSON file storage.

## Features

- **Shorten URLs**: Convert long URLs into short 6-character codes
- **Custom Aliases**: Optional custom short codes (3-10 alphanumeric characters)
- **Click Tracking**: Monitor how many times each short URL is accessed
- **Analytics Dashboard**: View all shortened URLs with statistics
- **QR Codes**: Automatically generated for each short URL (if library available)
- **Mobile Responsive**: Optimized for all screen sizes
- **Rate Limiting**: Session-based rate limiting (10 URLs per hour per IP)

## Requirements

- PHP 8.0 or higher
- Apache web server with mod_rewrite enabled
- Write permissions for the `data/` directory

## Installation

1. **Upload files** to your web server
2. **Set permissions** on the data directory:
   ```bash
   chmod 755 data/
   chmod 664 data/urls.json
   chmod 664 data/stats.json
   ```

3. **Configure BASE_URL** in `config.php`:
   ```php
   define('BASE_URL', 'https://yourdomain.com');
   ```

4. **Enable mod_rewrite** (if not already enabled):
   ```bash
   sudo a2enmod rewrite
   sudo service apache2 restart
   ```

5. **Optional: Enable HTTPS redirect** in `.htaccess` (uncomment lines 6-7 after SSL is configured)

## File Structure

```
url-shortener/
├── index.html              # Main shortening interface
├── analytics.html          # Analytics dashboard
├── error.html             # 404 error page
├── redirect.php           # Handles short URL redirects
├── config.php             # Configuration constants
├── .htaccess              # URL rewriting rules
│
├── api/
│   ├── shorten.php        # URL shortening endpoint
│   ├── stats.php          # Analytics data endpoint
│   └── helpers.php        # Shared utility functions
│
├── assets/
│   ├── css/
│   │   └── style.css      # Responsive styles
│   ├── js/
│   │   ├── app.js         # Main page logic
│   │   └── analytics.js   # Analytics page logic
│   └── lib/
│       └── phpqrcode.php  # QR code library (optional)
│
└── data/
    ├── .htaccess          # Deny web access
    ├── urls.json          # URL mappings
    └── stats.json         # Click statistics
```

## Configuration Options

Edit `config.php` to customize:

- `BASE_URL`: Your domain (required for production)
- `MAX_URLS`: Maximum number of URLs (default: 10,000)
- `MAX_URL_LENGTH`: Maximum URL length (default: 2048)
- `RATE_LIMIT_MAX`: Shortens per hour (default: 10)
- `ENABLE_CUSTOM_ALIASES`: Allow custom short codes (default: true)
- `ENABLE_QR_CODES`: Generate QR codes (default: true)

## API Endpoints

### POST /api/shorten.php
Create a short URL

**Request:**
```json
{
  "url": "https://example.com/long/url",
  "customAlias": "mylink"  // optional
}
```

**Response:**
```json
{
  "success": true,
  "shortUrl": "https://yourdomain.com/aB3xQ9",
  "shortCode": "aB3xQ9",
  "qrCode": "data:image/png;base64,..."  // if enabled
}
```

### GET /api/stats.php
Get analytics data

**Response:**
```json
{
  "success": true,
  "data": [
    {
      "code": "aB3xQ9",
      "url": "https://example.com/long/url",
      "created": "2025-06-15T14:30:00+00:00",
      "customAlias": false,
      "count": 42,
      "lastAccess": "2025-06-20T11:45:00+00:00"
    }
  ]
}
```

## Security Features

- URL validation (protocol whitelist, length limits)
- Input sanitization (HTML escaping)
- Output encoding (JSON_HEX_TAG, JSON_HEX_AMP)
- Rate limiting (session-based)
- Data directory access denial (.htaccess)
- File locking for concurrent access (flock with exponential backoff)
- Custom alias validation (alphanumeric only)

## Performance Notes

- **Concurrency**: File locking with exponential backoff (100ms, 200ms, 400ms) handles concurrent writes
- **Scalability**: Designed for up to 10,000 URLs (hard limit enforced)
- **Rate Limiting**: Session-based to avoid file I/O contention
- **Response Times**: <200ms for shortening, <50ms for redirects (typical)

## Troubleshooting

### Short URLs return 404
- Verify mod_rewrite is enabled
- Check .htaccess file is being read (`AllowOverride All` in Apache config)
- Confirm BASE_URL matches your domain

### "Service temporarily unavailable" errors
- Check data directory write permissions (should be 755)
- Verify JSON files are writable (should be 664)
- Check PHP error logs for file locking issues

### QR codes not generating
- QR library is optional - app works without it
- If desired, download phpqrcode.php to assets/lib/
- Verify ENABLE_QR_CODES is true in config.php

### Rate limit too restrictive
- Adjust RATE_LIMIT_MAX in config.php
- Clear browser session to reset counter

## Migration to Database

For production deployments exceeding 10,000 URLs, consider migrating to SQLite or MySQL:

1. Create tables matching the JSON schema (urls, stats)
2. Replace file I/O functions in helpers.php with database queries
3. Remove file locking logic (database handles concurrency)
4. Import existing JSON data to database tables

## License

Open source - use freely for any purpose.

## Support

For issues or questions, check:
- PHP error logs (usually in /var/log/apache2/)
- Browser console for JavaScript errors
- Network tab for API request/response details
