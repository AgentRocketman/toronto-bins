# Deployment Guide

Production deployment strategies for the CurbIn backend.

## Pre-Deployment Checklist

- [ ] All tests passing (`npm run test`)
- [ ] `.env` configured with production values
- [ ] Airtable base and table created
- [ ] SSL/TLS certificate ready (for HTTPS)
- [ ] Database backups configured
- [ ] Error logging setup
- [ ] Monitoring/alerts configured
- [ ] API rate limiting configured

## Local Testing

Before deploying to production:

```bash
# Install dependencies
npm install --production

# Set production environment
export NODE_ENV=production

# Start server
npm start

# Test endpoints
curl http://localhost:3000/api/health
```

## Option 1: Deploy to Heroku

### Prerequisites
- Heroku account ([heroku.com](https://www.heroku.com))
- Heroku CLI installed

### Steps

**1. Login to Heroku**
```bash
heroku login
```

**2. Create Heroku App**
```bash
heroku create curbin-backend
```

**3. Set Environment Variables**
```bash
heroku config:set AIRTABLE_API_KEY=your_key_here
heroku config:set AIRTABLE_BASE_ID=appYourBaseId
heroku config:set NODE_ENV=production
```

**4. Deploy**
```bash
git push heroku main
```

**5. View Logs**
```bash
heroku logs --tail
```

**6. Access Your App**
```
https://curbin-backend.herokuapp.com
```

## Option 2: Deploy to AWS EC2

### Prerequisites
- AWS account
- EC2 instance running Ubuntu 20.04+
- SSH access configured

### Steps

**1. Connect to EC2 Instance**
```bash
ssh -i your-key.pem ubuntu@your-instance-ip
```

**2. Install Dependencies**
```bash
# Update system
sudo apt update && sudo apt upgrade -y

# Install Node.js
curl -fsSL https://deb.nodesource.com/setup_18.x | sudo -E bash -
sudo apt install -y nodejs

# Install PM2 (process manager)
sudo npm install -g pm2
```

**3. Clone Repository**
```bash
cd /opt
sudo git clone https://github.com/yourusername/curbin-backend.git
sudo chown -R ubuntu:ubuntu curbin-backend
cd curbin-backend
```

**4. Setup Application**
```bash
npm install --production
cp .env.example .env
# Edit .env with production values
nano .env
```

**5. Start with PM2**
```bash
pm2 start server.js --name "curbin-backend"
pm2 save
pm2 startup
```

**6. Setup Nginx Reverse Proxy**
```bash
sudo apt install -y nginx

# Create nginx config
sudo tee /etc/nginx/sites-available/curbin-backend > /dev/null <<EOF
server {
    listen 80;
    server_name your-domain.com;

    location / {
        proxy_pass http://localhost:3000;
        proxy_http_version 1.1;
        proxy_set_header Upgrade \$http_upgrade;
        proxy_set_header Connection 'upgrade';
        proxy_set_header Host \$host;
        proxy_cache_bypass \$http_upgrade;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
    }

    # Serve uploaded images
    location /bin-pics/ {
        alias /opt/curbin-backend/bin-pics/;
    }
}
EOF

# Enable config
sudo ln -s /etc/nginx/sites-available/curbin-backend /etc/nginx/sites-enabled/
sudo rm /etc/nginx/sites-enabled/default 2>/dev/null || true

# Test and restart nginx
sudo nginx -t
sudo systemctl restart nginx
```

**7. Setup SSL with Let's Encrypt**
```bash
sudo apt install -y certbot python3-certbot-nginx
sudo certbot --nginx -d your-domain.com
```

**8. Verify Deployment**
```bash
curl https://your-domain.com/api/health
pm2 logs curbin-backend
```

## Option 3: Deploy with Docker

### Create Dockerfile

```dockerfile
FROM node:18-alpine

WORKDIR /app

# Copy package files
COPY package*.json ./

# Install dependencies
RUN npm ci --only=production

# Copy application
COPY . .

# Create uploads directory
RUN mkdir -p bin-pics

# Expose port
EXPOSE 3000

# Health check
HEALTHCHECK --interval=30s --timeout=3s --start-period=40s --retries=3 \
  CMD node -e "require('http').get('http://localhost:3000/api/health', (r) => {if (r.statusCode !== 200) throw new Error(r.statusCode)})"

# Start server
CMD ["node", "server.js"]
```

### Create docker-compose.yml

```yaml
version: '3.8'

services:
  api:
    build: .
    ports:
      - "3000:3000"
    environment:
      - NODE_ENV=production
      - AIRTABLE_API_KEY=${AIRTABLE_API_KEY}
      - AIRTABLE_BASE_ID=${AIRTABLE_BASE_ID}
      - GOOGLE_MAPS_API_KEY=${GOOGLE_MAPS_API_KEY}
    volumes:
      - ./bin-pics:/app/bin-pics
    restart: unless-stopped
    healthcheck:
      test: ["CMD", "curl", "-f", "http://localhost:3000/api/health"]
      interval: 30s
      timeout: 3s
      retries: 3

  nginx:
    image: nginx:alpine
    ports:
      - "80:80"
      - "443:443"
    volumes:
      - ./nginx.conf:/etc/nginx/nginx.conf:ro
      - ./ssl:/etc/nginx/ssl:ro
    depends_on:
      - api
    restart: unless-stopped
```

### Deploy with Docker

```bash
# Build and run
docker-compose up -d

# View logs
docker-compose logs -f api

# Stop
docker-compose down
```

## Option 4: Deploy to DigitalOcean App Platform

### Steps

**1. Connect GitHub Repository**
- Push code to GitHub
- Go to DigitalOcean App Platform
- Click "Create App"
- Connect your GitHub repository

**2. Configure Build Settings**
```yaml
name: curbin-backend
services:
- name: api
  github:
    repo: yourusername/curbin-backend
    branch: main
  build_command: npm ci
  run_command: npm start
  http_port: 3000
  env:
  - key: NODE_ENV
    value: production
  - key: AIRTABLE_API_KEY
    value: ${AIRTABLE_API_KEY}
  - key: AIRTABLE_BASE_ID
    value: ${AIRTABLE_BASE_ID}
```

**3. Add Environment Variables**
- Click "Settings"
- Add AIRTABLE_API_KEY and other env vars

**4. Deploy**
- Click "Create"
- Wait for deployment to complete
- Access at provided URL

## Environment Variables for Production

```env
PORT=3000
NODE_ENV=production

# Airtable
AIRTABLE_API_KEY=your_production_key
AIRTABLE_BASE_ID=your_production_base_id

# Google Maps (optional)
GOOGLE_MAPS_API_KEY=your_api_key

# Optional: Monitoring
SENTRY_DSN=https://your-sentry-url
LOG_LEVEL=info
```

## Security Hardening

### 1. HTTPS Only
```javascript
// Add to server.js
if (process.env.NODE_ENV === 'production') {
  app.use((req, res, next) => {
    if (req.header('x-forwarded-proto') !== 'https') {
      res.redirect(`https://${req.header('host')}${req.url}`);
    } else {
      next();
    }
  });
}
```

### 2. Rate Limiting
```javascript
const rateLimit = require('express-rate-limit');

const limiter = rateLimit({
  windowMs: 15 * 60 * 1000, // 15 minutes
  max: 100 // limit each IP to 100 requests per windowMs
});

app.use('/api/', limiter);
```

### 3. Input Validation
```javascript
const { body, validationResult } = require('express-validator');

app.post('/api/save-service',
  body('address').trim().isLength({ min: 5 }),
  body('type').isIn(['Residential', 'Commercial']),
  body('date').isISO8601(),
  (req, res) => {
    const errors = validationResult(req);
    if (!errors.isEmpty()) {
      return res.status(400).json({ errors: errors.array() });
    }
    // Process request
  }
);
```

### 4. CORS Configuration
```javascript
const cors = require('cors');

app.use(cors({
  origin: process.env.NODE_ENV === 'production' 
    ? 'https://your-domain.com'
    : '*'
}));
```

### 5. Helmet Security Headers
```javascript
const helmet = require('helmet');
app.use(helmet());
```

## Monitoring & Logging

### Install PM2 Plus (Monitoring)
```bash
pm2 install pm2-auto-pull
pm2 install pm2-logrotate
```

### Error Tracking with Sentry
```bash
npm install @sentry/node
```

```javascript
const Sentry = require("@sentry/node");

Sentry.init({
  dsn: process.env.SENTRY_DSN,
  environment: process.env.NODE_ENV
});

app.use(Sentry.Handlers.errorHandler());
```

### Structured Logging
```bash
npm install winston
```

```javascript
const winston = require('winston');

const logger = winston.createLogger({
  level: process.env.LOG_LEVEL || 'info',
  format: winston.format.json(),
  transports: [
    new winston.transports.File({ filename: 'error.log', level: 'error' }),
    new winston.transports.File({ filename: 'combined.log' })
  ]
});
```

## Database Backups

### Airtable Automatic Backups
Airtable includes automatic backups. To export:

```bash
# Using Airtable API
curl https://api.airtable.com/v0/meta/bases \
  -H "Authorization: Bearer $AIRTABLE_API_KEY" \
  -H "Accept: application/json"
```

## Performance Optimization

### Enable Compression
```javascript
const compression = require('compression');
app.use(compression());
```

### Image Optimization
```bash
npm install sharp
```

```javascript
const sharp = require('sharp');

app.post('/api/upload', upload.single('file'), async (req, res) => {
  // Optimize image
  await sharp(req.file.path)
    .resize(1920, 1080, { fit: 'inside', withoutEnlargement: true })
    .toFile(`${uploadDir}/optimized-${req.file.filename}`);
});
```

### Caching Strategy
```javascript
app.get('/api/services', (req, res) => {
  res.set('Cache-Control', 'public, max-age=300'); // 5 minutes
  // Return data
});
```

## Scaling Considerations

### Load Balancing
- Deploy multiple instances behind a load balancer
- Use sticky sessions for file uploads
- Consider CDN for image delivery

### Database Optimization
- Use Airtable's built-in caching
- Implement Redis caching layer
- Query optimization

### Horizontal Scaling
```yaml
# Docker Compose with multiple instances
services:
  api-1:
    build: .
    ports: ["3001:3000"]
  api-2:
    build: .
    ports: ["3002:3000"]
  api-3:
    build: .
    ports: ["3003:3000"]
  nginx:
    # Load balance between api-1, api-2, api-3
```

## Rollback Plan

### Keep Previous Versions
```bash
# Tag releases
git tag v1.0.0
git push origin v1.0.0

# Checkout previous version
git checkout v1.0.0
npm install
npm start
```

### Database Migration Rollback
```bash
# Export current data from Airtable first
# Then restore from backup
```

## Monitoring Checklist

- [ ] Health checks running
- [ ] Error tracking configured
- [ ] Logs centralized
- [ ] Alerts set up
- [ ] Performance metrics tracked
- [ ] Uptime monitoring active
- [ ] Regular backups automated

## Support & Troubleshooting

**Server won't start:**
- Check logs: `pm2 logs curbin-backend`
- Verify .env variables
- Check port availability

**High memory usage:**
- Enable pm2 memory limits
- Optimize file uploads
- Check for memory leaks

**Slow response times:**
- Check Airtable API quota
- Optimize database queries
- Add caching layer

---

**Ready to deploy? Start with your preferred option above!**
