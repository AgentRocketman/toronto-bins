# CurbIn Completion Email API - Deployment Guide

## Overview
This is a Node.js/Express backend that sends completion notification emails to CurbIn customers when their bin service is finished. The system:
- Receives service completion data via POST request
- Looks up customer email from Airtable by address
- Sends a professional HTML email via Hostinger SMTP
- Returns success/failure status

## Quick Start

### 1. Prerequisites
- Node.js 16+ installed
- Access to agentrocketman.com hosting
- Airtable API key (provided)
- Hostinger email account credentials (provided)

### 2. Installation

```bash
# Clone or copy to your hosting directory
cd /path/to/agentrocketman.com/public_html

# Install dependencies
npm install

# Copy environment file
cp .env.example .env
```

### 3. Configuration
Edit `.env` with your credentials:
```
AIRTABLE_API_KEY=patxbDkv88pOMXmYx.c7e5fd7974954e3a674087090835d11dd69504f3912f0ef86c3c59f1e91febdd
AIRTABLE_BASE_ID=apptYNRJTXwItvied
AIRTABLE_BOOKINGS_TABLE_ID=tblKMhGnYjsH0z7Lj
SMTP_USER=support@agentrocketman.com
SMTP_PASSWORD=AgentEmail1!
PORT=3000
NODE_ENV=production
```

### 4. Start Server
```bash
npm start
# Or with nodemon for development
npm run dev
```

Server will run on `http://localhost:3000`

## API Usage

### Health Check
```bash
GET /health
```

Response:
```json
{
  "status": "healthy",
  "service": "CurbIn Completion Email API",
  "timestamp": "2026-06-14T15:45:00.000Z"
}
```

### Send Completion Email
```bash
POST /api/send-completion-email
Content-Type: application/json

{
  "stopId": "stop_12345",
  "address": "123 Main St, Toronto, ON M1A 1A1",
  "serviceType": "roll-out",
  "completed": true,
  "imageUrl": "https://example.com/image.jpg",
  "workerName": "John Smith",
  "completedDateTime": "2026-06-14T15:30:00Z"
}
```

**Required fields:**
- `address` - Customer's service address (used to lookup email in Airtable)
- `workerName` - Name of the service worker
- `completedDateTime` - ISO 8601 timestamp of completion

**Optional fields:**
- `stopId` - Service stop ID for reference
- `serviceType` - "roll-out" or "roll-in" (defaults to provided value)
- `completed` - Boolean completion status
- `imageUrl` - URL to completion photo (will be embedded in email)

### Response Success (200)
```json
{
  "success": true,
  "emailSent": true,
  "message": "Completion email sent to customer@example.com",
  "customerName": "Jane Doe"
}
```

### Response Error (400/404/500)
```json
{
  "success": false,
  "emailSent": false,
  "error": "Customer email not found for this address"
}
```

## Airtable Integration

### Bookings Table Schema
The endpoint expects the following fields in the Bookings table:
- **Address** (Text) - Customer's service address
- **Email** (Email) - Customer's email address
- **Customer Name** (Text) - Customer's full name
- **Phone** (Phone) - Customer's phone number
- **Service Type** (Select) - Type of service

### Address Matching
- Addresses are normalized (trimmed, lowercase) for matching
- Partial matches are NOT supported - must be exact match after normalization
- Example: "123 Main St, Toronto" matches "123 Main St, Toronto" but not "123 Main Street"

## Hostinger SMTP Details

- **Host:** smtp.hostinger.com
- **Port:** 465 (TLS)
- **From Address:** support@agentrocketman.com
- **Credentials:** Provided in .env

## Email Template Features

The generated email includes:
- Professional HTML formatting with gradient header
- Service address, type, worker name, and completion time
- Timezone-aware datetime formatting (Toronto/EST)
- Embedded completion photo (if provided)
- Company branding and footer
- Responsive design for mobile devices

## Error Handling

| Status | Error | Cause |
|--------|-------|-------|
| 400 | Missing required fields | address, workerName, or completedDateTime not provided |
| 400 | Invalid serviceType | Must be "roll-out" or "roll-in" |
| 404 | Customer email not found | Address not found in Airtable Bookings table |
| 500 | SMTP error | Email send failed (check credentials, network) |
| 500 | Airtable error | API error (check token, table IDs) |

## Logging

All requests and errors are logged to console with timestamps:
```
[2026-06-14T15:45:00.123Z] POST /api/send-completion-email
Looking up customer for address: 123 Main St, Toronto, ON M1A 1A1
Found customer: Jane Doe (jane@example.com)
Email sent successfully: <message-id>
```

## Deployment to agentrocketman.com

### Option 1: Node.js Hosting (Recommended)
1. Upload files via FTP/SFTP to server
2. Install Node.js if not already present
3. Run `npm install && npm start`
4. Configure reverse proxy (nginx/Apache) to forward requests to port 3000
5. Set up SSL certificate (Let's Encrypt)

### Option 2: PM2 Process Manager (Production)
```bash
npm install -g pm2
pm2 start server.js --name "curbin-email"
pm2 startup
pm2 save
```

### Option 3: Docker (Optional)
Create `Dockerfile`:
```dockerfile
FROM node:18-alpine
WORKDIR /app
COPY package*.json ./
RUN npm install --production
COPY . .
EXPOSE 3000
CMD ["npm", "start"]
```

Then:
```bash
docker build -t curbin-email:latest .
docker run -d -p 3000:3000 --env-file .env curbin-email:latest
```

## Testing

### Using curl
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

### Using JavaScript/Node.js
```javascript
const response = await fetch('http://localhost:3000/api/send-completion-email', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({
    address: '123 Main St, Toronto, ON M1A 1A1',
    serviceType: 'roll-out',
    workerName: 'John Smith',
    completedDateTime: new Date().toISOString(),
  })
});

const data = await response.json();
console.log(data);
```

## Troubleshooting

### Email not sending
- Check SMTP credentials in .env
- Verify Hostinger email account is active
- Check firewall/port 465 is accessible
- Review console logs for specific error

### Customer not found
- Verify address exactly matches Airtable (check case, spacing, punctuation)
- Confirm Email field is populated in Airtable
- Check Bookings table ID in .env

### Airtable errors
- Verify API key is current (regenerate if needed)
- Confirm Base ID and Table ID are correct
- Check Airtable API rate limits (5 req/sec default)

## Security Notes

1. **Never commit .env file** - Use .env.example as template
2. **Rotate credentials periodically** - Especially email password
3. **Use HTTPS** - Always use SSL in production
4. **Validate input** - Already implemented, but monitor for edge cases
5. **Rate limiting** - Consider adding if exposed to public

## Support & Maintenance

For issues or updates:
1. Check logs: `pm2 logs curbin-email` (if using PM2)
2. Verify environment variables: `env | grep -E "AIRTABLE|SMTP"`
3. Test API health: `curl http://localhost:3000/health`
4. Review Airtable and Hostinger settings

---

**Version:** 1.0.0  
**Last Updated:** 2026-06-14  
**Maintained by:** CurbIn Development Team
