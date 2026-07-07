# Deployment Guide

## Quick Start (Using Installer)

1. Upload all files to your web server
2. Navigate to `https://yourdomain.com/install.php`
3. Follow the installation wizard:
   - Step 1: Configure database connection
   - Step 2: Add API keys
   - Step 3: Complete and delete installer
4. Visit `https://yourdomain.com/` to use the application

## Manual Installation

### Step 1: Server Requirements

Ensure your server meets these requirements:
- PHP 7.4 or higher
- MySQL 5.7 or higher
- Apache with mod_rewrite enabled
- At least 256MB PHP memory limit

### Step 2: Upload Files

Upload all files to your web server document root or subdirectory.

### Step 3: Database Setup

Create the database and import schema:

```bash
mysql -u root -p
```

```sql
CREATE DATABASE domain_generator CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
EXIT;
```

```bash
mysql -u root -p domain_generator < database/schema.sql
```

### Step 4: Configure API Keys

Edit `config.php` and replace placeholder values:

```php
// OpenAI API
define('OPENAI_API_KEY', 'sk-your-actual-key-here');

// Namecheap API
define('NAMECHEAP_API_USER', 'your-username');
define('NAMECHEAP_API_KEY', 'your-api-key');
define('NAMECHEAP_USERNAME', 'your-username');
define('NAMECHEAP_CLIENT_IP', '123.456.789.000');
```

### Step 5: File Permissions

Set proper permissions:

```bash
chmod 755 -R .
chmod 644 config.php
```

### Step 6: Test Installation

Visit `https://yourdomain.com/test.html` to run system checks.

## Apache Configuration

Ensure mod_rewrite is enabled:

```bash
sudo a2enmod rewrite
sudo systemctl restart apache2
```

If using a subdirectory, update `.htaccess` RewriteBase:

```apache
RewriteBase /subdirectory/
```

## Nginx Configuration

For Nginx servers, use this configuration:

```nginx
server {
    listen 80;
    server_name yourdomain.com;
    root /var/www/domain-generator;
    index index.html index.php;

    location / {
        try_files $uri $uri/ /index.html;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/var/run/php/php7.4-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }

    location ~ /database/ {
        deny all;
    }

    location ~ /\.env {
        deny all;
    }

    location ~* \.(css|js|jpg|jpeg|png|gif|ico|svg)$ {
        expires 1M;
        add_header Cache-Control "public, immutable";
    }
}
```

## Security Checklist

- [ ] Change all default API keys
- [ ] Delete `install.php` after setup
- [ ] Verify database credentials are correct
- [ ] Ensure `.htaccess` is protecting sensitive files
- [ ] Check file permissions (644 for files, 755 for directories)
- [ ] Enable HTTPS (SSL certificate)
- [ ] Set up regular database backups
- [ ] Configure PHP error logging (disable display_errors in production)

## Production Optimizations

### 1. Enable OPcache

Add to `php.ini`:

```ini
opcache.enable=1
opcache.memory_consumption=128
opcache.interned_strings_buffer=8
opcache.max_accelerated_files=4000
opcache.revalidate_freq=60
```

### 2. Database Indexing

The schema includes necessary indexes, but verify they exist:

```sql
SHOW INDEX FROM generation_queue;
SHOW INDEX FROM generated_domains;
SHOW INDEX FROM domain_cache;
```

### 3. Enable MySQL Query Cache

Add to MySQL config:

```ini
query_cache_type = 1
query_cache_size = 64M
```

### 4. Set Up Automatic Cache Cleanup

Enable the event scheduler in MySQL:

```sql
SET GLOBAL event_scheduler = ON;
```

Add to MySQL config (`my.cnf`):

```ini
event_scheduler = ON
```

Uncomment the event creation in `database/schema.sql`.

### 5. Rate Limiting

Consider adding IP-based rate limiting at the web server level:

**Apache** (using mod_evasive):
```apache
DOSHashTableSize 3097
DOSPageCount 2
DOSSiteCount 50
DOSPageInterval 1
DOSSiteInterval 1
DOSBlockingPeriod 10
```

**Nginx**:
```nginx
limit_req_zone $binary_remote_addr zone=api:10m rate=1r/s;

location /api/ {
    limit_req zone=api burst=5;
}
```

## Monitoring

### Error Logs

Monitor these logs regularly:
- Apache error log: `/var/log/apache2/error.log`
- PHP error log: Check `error_log` directive in `php.ini`
- Application logs: Custom logging in PHP files

### Database Performance

Monitor slow queries:

```sql
SET GLOBAL slow_query_log = 'ON';
SET GLOBAL long_query_time = 2;
```

### API Usage

Track API usage to avoid hitting rate limits:

```sql
SELECT COUNT(*) as total_checks, DATE(checked_at) as date
FROM domain_cache
GROUP BY DATE(checked_at)
ORDER BY date DESC
LIMIT 30;
```

## Backup Strategy

### Database Backup

Daily automated backup:

```bash
#!/bin/bash
mysqldump -u root -p domain_generator > /backups/domain_generator_$(date +%Y%m%d).sql
```

Add to crontab:

```cron
0 2 * * * /path/to/backup-script.sh
```

### File Backup

Backup `config.php` securely (contains API keys).

## Troubleshooting

### "Service temporarily unavailable"
- Check database connection in `config.php`
- Verify MySQL is running: `systemctl status mysql`
- Check database exists: `mysql -e "SHOW DATABASES;"`

### Domains always show "?"
- Verify Namecheap IP is whitelisted
- Check Namecheap API credentials
- Review PHP error log for API errors

### Generation fails
- Verify OpenAI API key is valid
- Check OpenAI account has credits
- Review network connectivity to OpenAI API

### Slow performance
- Enable OPcache
- Add database indexes
- Increase cache duration in `config.php`
- Consider adding Redis for session storage

## Scaling Considerations

### High Traffic

For sites with >1000 requests/day:

1. **Use Redis for caching**:
   - Store domain availability cache in Redis
   - Set TTL to 6 hours matching CACHE_DURATION

2. **Queue Background Jobs**:
   - Move availability checks to background queue
   - Use tools like Supervisor to manage workers

3. **Load Balancing**:
   - Use multiple app servers
   - Share session storage via Redis/Memcached
   - Use read replicas for database

4. **CDN**:
   - Serve static assets (CSS, JS) via CDN
   - Cache API responses where appropriate

## Environment Variables (Alternative to config.php)

For containerized deployments, use environment variables:

1. Copy `.env.example` to `.env`
2. Modify `config.php` to read from environment:

```php
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_NAME', getenv('DB_NAME') ?: 'domain_generator');
// ... etc
```

## Docker Deployment

Example `docker-compose.yml`:

```yaml
version: '3.8'
services:
  web:
    image: php:7.4-apache
    ports:
      - "80:80"
    volumes:
      - ./:/var/www/html
    environment:
      - OPENAI_API_KEY=${OPENAI_API_KEY}
      - NAMECHEAP_API_KEY=${NAMECHEAP_API_KEY}
    depends_on:
      - db

  db:
    image: mysql:8.0
    environment:
      - MYSQL_ROOT_PASSWORD=${DB_PASSWORD}
      - MYSQL_DATABASE=domain_generator
    volumes:
      - db_data:/var/lib/mysql
      - ./database/schema.sql:/docker-entrypoint-initdb.d/schema.sql

volumes:
  db_data:
```

Run with:

```bash
docker-compose up -d
```

## Post-Deployment

1. Test domain generation with various inputs
2. Verify availability checks work correctly
3. Test favorites functionality
4. Check responsive design on mobile devices
5. Run security scan (OWASP ZAP, etc.)
6. Monitor initial API usage and costs
7. Set up uptime monitoring (UptimeRobot, Pingdom, etc.)

## Support

For issues during deployment:
1. Check error logs
2. Run `test.html` system checks
3. Verify all requirements are met
4. Review this guide's troubleshooting section
