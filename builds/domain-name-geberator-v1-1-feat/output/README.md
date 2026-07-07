# Domain Name Generator

A creative domain name generator that uses AI to suggest unique, memorable domain names based on business descriptions and checks their availability in real-time.

## Features

- **AI-Powered Generation**: Uses OpenAI GPT-3.5 to generate 10 creative domain names per request
- **Real-Time Availability**: Checks domain availability via Namecheap API
- **Multiple TLDs**: Supports .com, .io, .ai, and .co extensions
- **Smart Caching**: Caches availability checks for 6 hours to reduce API calls
- **Favorites System**: Save favorite domains using localStorage
- **Responsive Design**: Works on desktop and mobile devices
- **Rate Limiting**: Prevents API abuse with 3-second cooldown between requests
- **Security**: SQL injection protection, XSS prevention, secure session handling

## Requirements

- PHP 7.4 or higher
- MySQL 5.7 or higher
- Apache web server with mod_rewrite
- OpenAI API key
- Namecheap API access

## Installation

### 1. Database Setup

```bash
mysql -u root -p < database/schema.sql
```

Or import via phpMyAdmin.

### 2. Configuration

Edit `config.php` and add your API credentials:

```php
define('OPENAI_API_KEY', 'your-openai-api-key-here');
define('NAMECHEAP_API_USER', 'your-namecheap-username');
define('NAMECHEAP_API_KEY', 'your-namecheap-api-key');
define('NAMECHEAP_USERNAME', 'your-namecheap-username');
define('NAMECHEAP_CLIENT_IP', 'your-whitelisted-ip');
```

Update database credentials if needed:

```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'domain_generator');
define('DB_USER', 'root');
define('DB_PASS', '');
```

### 3. File Permissions

Ensure the web server can read all files:

```bash
chmod -R 755 .
```

### 4. Web Server

Point your web server document root to this directory. For Apache, ensure mod_rewrite is enabled:

```bash
sudo a2enmod rewrite
sudo systemctl restart apache2
```

## API Keys Setup

### OpenAI API Key

1. Visit https://platform.openai.com/
2. Create an account or sign in
3. Navigate to API Keys section
4. Generate a new secret key
5. Copy the key to `config.php`

### Namecheap API Key

1. Visit https://www.namecheap.com/
2. Sign in to your account
3. Navigate to Profile > Tools > API Access
4. Enable API access
5. Whitelist your server IP address
6. Copy API credentials to `config.php`

**Note**: Namecheap API requires you to have $50+ in your account and whitelist your IP.

## Usage

1. Open the application in your browser
2. Enter a business description (minimum 10 characters)
3. Select desired TLD (.com, .io, .ai, or .co)
4. Click "Generate Domains"
5. Wait for availability checks to complete
6. Click the star icon to save favorites

## Architecture

### Frontend
- **index.html**: Main application interface
- **assets/css/style.css**: Responsive styling
- **assets/js/app.js**: Client-side logic, polling, favorites

### Backend
- **config.php**: Configuration and database connection
- **api/generate.php**: Domain generation and queueing
- **api/poll-results.php**: Polling endpoint for availability status

### Database
- **generation_queue**: Tracks generation requests
- **generated_domains**: Stores generated domains and status
- **domain_cache**: Caches availability checks (6-hour TTL)
- **favorites**: Optional server-side favorites storage

## API Endpoints

### POST /api/generate.php

Generate domain names.

**Request:**
```json
{
  "description": "organic coffee roastery",
  "tld": "com"
}
```

**Response:**
```json
{
  "success": true,
  "generation_id": "abc123...",
  "domains": ["domain1", "domain2", ...],
  "tld": "com"
}
```

### GET /api/poll-results.php?generation_id={id}

Check generation status and availability results.

**Response:**
```json
{
  "generation_id": "abc123...",
  "status": "complete",
  "results": [
    {
      "domain": "domain1.com",
      "status": "available"
    },
    {
      "domain": "domain2.com",
      "status": "taken"
    }
  ]
}
```

## Security Features

- **SQL Injection Protection**: PDO prepared statements
- **XSS Prevention**: HTML escaping on output
- **CSRF Protection**: Session validation
- **Rate Limiting**: 3-second cooldown between requests
- **Input Validation**: Server-side validation of all inputs
- **Secure Headers**: X-Frame-Options, X-Content-Type-Options, etc.
- **API Key Protection**: Keys stored in config, never exposed

## Performance Optimization

- **Batch API Calls**: Checks multiple domains in single request
- **Smart Caching**: 6-hour cache for availability checks
- **Async Polling**: Non-blocking UI during availability checks
- **Database Indexing**: Optimized queries with proper indexes

## Troubleshooting

### "Service temporarily unavailable"
- Check database connection settings in `config.php`
- Verify MySQL is running
- Check database exists and schema is imported

### "AI service error"
- Verify OpenAI API key is correct
- Check you have available credits
- Review error logs for detailed messages

### "Namecheap API error"
- Ensure IP is whitelisted in Namecheap account
- Verify API credentials are correct
- Check account has $50+ balance (Namecheap requirement)

### Domains always show "?"
- Check Namecheap API connectivity
- Review PHP error logs
- Verify API credentials and IP whitelist

## Maintenance

### Clean Old Data

Run the cleanup procedure periodically:

```sql
CALL cleanup_old_data();
```

This removes:
- Generations older than 7 days
- Cache entries older than 24 hours

Or enable automatic cleanup by uncommenting the event scheduler in `schema.sql`.

## License

This project is provided as-is for educational and commercial use.

## Support

For issues or questions, please review the error logs:
- PHP errors: Check web server error log
- Application errors: Check browser console
- API errors: Check network tab in browser DevTools
