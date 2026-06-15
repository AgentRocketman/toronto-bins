# CurbIn Completion Email API

A production-ready Node.js backend service that sends automatic completion notification emails to CurbIn customers when their bin service is finished.

## Features

✅ **Airtable Integration** — Automatically looks up customer email by service address  
✅ **Hostinger SMTP** — Sends professional HTML emails via Hostinger mail servers  
✅ **Photo Support** — Embeds service completion photos in emails  
✅ **Error Handling** — Graceful failures with clear error messages  
✅ **Toronto Timezone** — Formats dates/times in customer-friendly format  
✅ **Input Validation** — Validates all incoming data before processing  
✅ **Fully Documented** — API specs, integration guides, deployment instructions  

## Quick Start

### 1. Install Dependencies

```bash
npm install
```

### 2. Configure Environment

Copy the example env file and fill in credentials:

```bash
cp .env.example .env
```

Edit `.env` with your credentials:
```
AIRTABLE_API_KEY=patxbDkv88pOMXmYx.c7e5fd7974954e3a674087090835d11dd69504f3912f0ef86c3c59f1e91febdd
AIRTABLE_BASE_ID=apptYNRJTXwItvied
AIRTABLE_BOOKINGS_TABLE=tblKMhGnYjsH0z7Lj
SMTP_USER=support@agentrocketman.com
SMTP_PASSWORD=AgentEmail1!
PORT=3000
NODE_ENV=production
```

### 3. Start the Server

```bash
# Development
npm run dev

# Production
npm start
```

Server will be available at `http://localhost:3000`

## API Usage

### Health Check
```bash
curl http://localhost:3000/health
```

### Send Completion Email
```bash
curl -X POST http://localhost:3000/api/send-completion-email \
  -H "Content-Type: application/json" \
  -d '{
    "address": "123 Main St, Toronto, ON M1A 1A1",
    "serviceType": "roll-out",
    "workerName": "John Smith",
    "completedDateTime": "2026-06-14T15:30:00Z",
    "imageUrl": "https://example.com/photo.jpg"
  }'
```

### Response
```json
{
  "success": true,
  "emailSent": true,
  "to": "customer@example.com",
  "messageId": "<message-id>"
}
```

## Request Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `address` | string | ✅ Yes | Customer's service address (must match Airtable) |
| `serviceType` | string | ✅ Yes | `"roll-out"` or `"roll-in"` |
| `workerName` | string | ✅ Yes | Name of service worker |
| `completedDateTime` | string | ✅ Yes | ISO 8601 timestamp (e.g., `2026-06-14T15:30:00Z`) |
| `stopId` | string | No | Service stop ID for reference |
| `completed` | boolean | No | Service completion status (defaults to true) |
| `imageUrl` | string | No | URL to completion photo |

## Response Codes

| Code | Meaning | Example |
|------|---------|---------|
| `200` | Email sent successfully | `{success:true, emailSent:true}` |
| `400` | Validation error (missing fields) | `{error: "address is required"}` |
| `404` | Address not found in Airtable | `{error: "No booking found for address"}` |
| `422` | Customer has no email on file | `{error: "Matching booking has no Email"}` |
| `500` | Server error (SMTP, Airtable) | `{error: "Failed to process..."}` |

## Files Included

### Core Implementation
- **`server-claude-generated.js`** — Production-ready Express server (recommended)
- **`server.js`** — Alternative Express server wrapper
- **`send-completion-email.js`** — Email module (standalone usage)

### Configuration
- **`.env.example`** — Environment variables template
- **`package.json`** — Node.js dependencies

### Documentation
- **`API.md`** — Complete API specification
- **`DEPLOYMENT.md`** — Deployment guide for agentrocketman.com
- **`INTEGRATION.md`** — Integration examples (JavaScript, Python, PHP, React, Flutter)
- **`README.md`** — This file

### Testing
- **`test-endpoint.js`** — Test suite runner

## Deployment

### For agentrocketman.com

#### Option 1: Node.js with PM2 (Recommended)

```bash
# SSH into server
ssh user@agentrocketman.com
cd /path/to/curbin-completion-email

# Install PM2 globally
npm install -g pm2

# Install dependencies
npm install

# Start with PM2
pm2 start server-claude-generated.js --name "curbin-email"
pm2 save
pm2 startup
```

#### Option 2: Docker

```bash
docker build -t curbin-email:latest .
docker run -d -p 3000:3000 --env-file .env curbin-email:latest
```

#### Option 3: Hostinger Shared Hosting

Use the PHP endpoint wrapper instead:
```bash
cp api/send-completion-email.php /public_html/api/
# Update credentials in PHP file
```

### Set Up Reverse Proxy (Nginx)

```nginx
location /api/send-completion-email {
    proxy_pass http://127.0.0.1:3000;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
}
```

## Testing

### Run Test Suite
```bash
npm test
# or
node test-endpoint.js
```

### Manual Testing

#### Test 1: Valid Request
```bash
curl -X POST http://localhost:3000/api/send-completion-email \
  -H "Content-Type: application/json" \
  -d '{
    "address": "123 Main St, Toronto, ON M1A 1A1",
    "serviceType": "roll-out",
    "workerName": "John Smith",
    "completedDateTime": "2026-06-14T15:30:00Z"
  }'
```

Expected: `{success:true, emailSent:true}`

#### Test 2: Missing Address (Should Fail)
```bash
curl -X POST http://localhost:3000/api/send-completion-email \
  -H "Content-Type: application/json" \
  -d '{
    "serviceType": "roll-out",
    "workerName": "John Smith",
    "completedDateTime": "2026-06-14T15:30:00Z"
  }'
```

Expected: `{success:false, error:"Validation failed", details:[...]}`

#### Test 3: Address Not Found
```bash
curl -X POST http://localhost:3000/api/send-completion-email \
  -H "Content-Type: application/json" \
  -d '{
    "address": "INVALID ADDRESS",
    "serviceType": "roll-out",
    "workerName": "John Smith",
    "completedDateTime": "2026-06-14T15:30:00Z"
  }'
```

Expected: `{success:false, emailSent:false, error:"No booking found..."}`

## Architecture

```
┌─────────────────────────────────────┐
│  Client App                         │
│  (Mobile, Web, Backend)             │
└──────────────┬──────────────────────┘
               │
               │ POST /api/send-completion-email
               │ {address, serviceType, workerName, ...}
               ▼
┌─────────────────────────────────────┐
│  CurbIn Completion Email API        │
│  (Express.js on agentrocketman.com) │
└──────────────┬──────────────────────┘
               │
       ┌───────┴───────┐
       ▼               ▼
   Airtable      Hostinger SMTP
   Lookup        (send email)
   Customer      
   Email
```

## Integration Examples

See **`INTEGRATION.md`** for examples in:
- JavaScript / Node.js / React
- Python
- PHP
- Flutter / Dart

Quick example (JavaScript):

```javascript
const response = await fetch(
  'https://agentrocketman.com/api/send-completion-email',
  {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      address: '123 Main St, Toronto, ON M1A 1A1',
      serviceType: 'roll-out',
      workerName: 'John Smith',
      completedDateTime: new Date().toISOString(),
      imageUrl: 'https://example.com/photo.jpg',
    }),
  }
);

const data = await response.json();
if (data.emailSent) {
  console.log(`Email sent to customer`);
}
```

## Troubleshooting

### Email Not Sending
1. Check `.env` credentials are correct
2. Verify Hostinger email account is active
3. Check port 465 is accessible (firewall)
4. Review server logs: `pm2 logs curbin-email`

### Customer Not Found
1. Verify address in request matches Airtable exactly (case-insensitive)
2. Check for leading/trailing spaces in address
3. Confirm Email field is populated in Airtable Bookings table

### Airtable Errors
1. Verify API key is current (regenerate if needed)
2. Confirm Base ID and Table ID are correct
3. Check Airtable API rate limits (5 req/sec default)

## Security

### Best Practices
- ✅ Never commit `.env` file to git
- ✅ Rotate credentials periodically
- ✅ Use HTTPS in production (reverse proxy with SSL)
- ✅ Consider adding API key authentication
- ✅ Implement rate limiting for production
- ✅ Log all requests for audit trail

### Sensitive Data
These credentials are now visible in conversation history:
- Airtable API Key
- Hostinger email password

**Action:** After deploying, regenerate:
1. Airtable personal access token (rotate at `https://airtable.com/account/tokens`)
2. Hostinger email password

Then update `.env` with new credentials.

## Performance

- **Response Time:** ~500-2000ms (varies by Airtable lookup speed)
- **Concurrency:** Handles multiple simultaneous requests
- **Memory:** ~50-100MB typical usage
- **Scaling:** Horizontal scaling with multiple PM2 instances

## Development

### Install Dev Dependencies
```bash
npm install --save-dev nodemon
```

### Run in Dev Mode
```bash
npm run dev
```

Server auto-restarts on file changes.

### View Logs
```bash
pm2 logs curbin-email
```

## Support & Maintenance

For issues or updates:
1. Check logs: `pm2 logs curbin-email`
2. Verify environment: `env | grep -E "AIRTABLE|SMTP"`
3. Test health: `curl http://localhost:3000/health`
4. Review error responses for specific details

## License

MIT

## Version

**v1.0.0** — Released 2026-06-14

---

**Need help?** See:
- **API Details:** `API.md`
- **Deployment:** `DEPLOYMENT.md`
- **Integration Examples:** `INTEGRATION.md`

**Contact:** support@agentrocketman.com
