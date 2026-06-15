# CurbIn Backend API

Production-ready Node.js backend server for CurbIn service routing with Airtable integration, image uploads, and route optimization.

## Features

✅ **Airtable Integration**
- Automatic sync of service stops to Airtable base
- Predefined table schema with 8 test records
- CRUD operations for service management

✅ **Image Upload Handling**
- POST /api/upload endpoint with multipart/form-data support
- Files saved to `bin-pics/` folder (auto-created)
- 10MB file size limit
- Support for JPEG, PNG, GIF image formats

✅ **Service Stop Management**
- POST /api/save-service to create/update stops
- Fields: Stop ID, Address, Service Type, Date, Completed, Image URL, Worker Name

✅ **Route Optimization**
- POST /api/optimize-route for intelligent routing
- Accepts stops with lat/lng coordinates
- Returns optimized order for efficient service delivery

✅ **Static File Serving**
- `/bin-pics/*` serves uploaded images
- `/` serves index.html dashboard

✅ **Error Handling**
- Comprehensive error responses with detailed messages
- Validation for required fields
- File upload error handling

## Installation

```bash
# Install dependencies
npm install

# Create .env file (optional - defaults are included)
cp .env.example .env

# Start server
npm start

# Development with auto-reload
npm run dev
```

## Environment Variables

```env
PORT=3000
NODE_ENV=development
AIRTABLE_API_KEY=patxbDkv88pOMXmYx.c7e5fd7974954e3a674087090835d11dd69504f3912f0ef86c3c59f1e91febdd
AIRTABLE_BASE_ID=appCurbInServiceStops
GOOGLE_MAPS_API_KEY=your_key_here
```

## API Endpoints

### Health Check
```http
GET /api/health
```
Returns server status.

**Response:**
```json
{
  "status": "OK",
  "timestamp": "2024-06-13T12:00:00.000Z"
}
```

### Test Airtable (Create Sample Records)
```http
GET /api/test-airtable
```
Creates 8 test service stops with today's date in Airtable.

**Response:**
```json
{
  "success": true,
  "message": "Created 8 test records",
  "records": [
    {
      "id": "rec123...",
      "fields": {
        "Stop ID": "stop-001",
        "Address": "123 King St W, Toronto, ON",
        "Service Type": "Residential",
        "Date": "2024-06-13",
        "Completed": false,
        "Image URL": "",
        "Worker Name": "John Doe"
      }
    }
  ]
}
```

### Get All Services
```http
GET /api/services
```
Retrieve all service stops from Airtable.

**Response:**
```json
{
  "success": true,
  "count": 8,
  "services": [
    {
      "id": "rec123...",
      "Stop ID": "stop-001",
      "Address": "123 King St W, Toronto, ON",
      "Service Type": "Residential",
      "Date": "2024-06-13",
      "Completed": false,
      "Image URL": "",
      "Worker Name": "John Doe"
    }
  ]
}
```

### Upload Image
```http
POST /api/upload
Content-Type: multipart/form-data

file: <binary>
stopId: stop-001 (optional)
date: 2024-06-13 (optional)
```

**Response:**
```json
{
  "success": true,
  "imageUrl": "/bin-pics/stop-001-1718282400000.jpg",
  "filename": "stop-001-1718282400000.jpg",
  "size": 245678
}
```

### Save Service Stop
```http
POST /api/save-service
Content-Type: application/json

{
  "id": "stop-001",
  "address": "123 King St W, Toronto, ON",
  "type": "Residential",
  "date": "2024-06-13",
  "completed": false,
  "imageUrl": "/bin-pics/stop-001-1718282400000.jpg",
  "workerName": "John Doe"
}
```

**Response:**
```json
{
  "success": true,
  "recordId": "rec123...",
  "message": "Service stop saved successfully",
  "fields": {
    "Stop ID": "stop-001",
    "Address": "123 King St W, Toronto, ON",
    "Service Type": "Residential",
    "Date": "2024-06-13",
    "Completed": false,
    "Image URL": "/bin-pics/stop-001-1718282400000.jpg",
    "Worker Name": "John Doe"
  }
}
```

### Optimize Route
```http
POST /api/optimize-route
Content-Type: application/json

{
  "stops": [
    { "id": "stop-001", "address": "123 King St W", "lat": 43.6426, "lng": -79.3957 },
    { "id": "stop-002", "address": "456 Queen St W", "lat": 43.6452, "lng": -79.4003 },
    { "id": "stop-003", "address": "789 Bay St", "lat": 43.6629, "lng": -79.3957 }
  ]
}
```

**Response:**
```json
{
  "success": true,
  "optimizedStops": [
    { "id": "stop-001", "address": "123 King St W", "lat": 43.6426, "lng": -79.3957 },
    { "id": "stop-003", "address": "789 Bay St", "lat": 43.6629, "lng": -79.3957 },
    { "id": "stop-002", "address": "456 Queen St W", "lat": 43.6452, "lng": -79.4003 }
  ],
  "duration": "N/A - Manual calculation needed",
  "distance": "N/A - Manual calculation needed",
  "note": "For production route optimization, integrate with Google Maps Directions API"
}
```

## Testing

### Using cURL

```bash
# Check health
curl http://localhost:3000/api/health

# Get all services
curl http://localhost:3000/api/services

# Create test data (8 stops)
curl http://localhost:3000/api/test-airtable

# Upload image
curl -X POST http://localhost:3000/api/upload \
  -F "file=@/path/to/image.jpg" \
  -F "stopId=stop-001" \
  -F "date=2024-06-13"

# Save service
curl -X POST http://localhost:3000/api/save-service \
  -H "Content-Type: application/json" \
  -d '{
    "id": "stop-001",
    "address": "123 King St W, Toronto, ON",
    "type": "Residential",
    "date": "2024-06-13",
    "completed": false,
    "imageUrl": "/bin-pics/stop-001.jpg",
    "workerName": "John Doe"
  }'

# Optimize route
curl -X POST http://localhost:3000/api/optimize-route \
  -H "Content-Type: application/json" \
  -d '{
    "stops": [
      { "id": "stop-001", "address": "123 King St W", "lat": 43.6426, "lng": -79.3957 },
      { "id": "stop-002", "address": "456 Queen St W", "lat": 43.6452, "lng": -79.4003 }
    ]
  }'
```

## Airtable Integration

The server automatically syncs data to your Airtable base. Make sure these environment variables are set:

- `AIRTABLE_API_KEY` - Your Airtable API key
- `AIRTABLE_BASE_ID` - Your base ID (from Airtable URL)
- Table name: `ServiceStops`

### Table Schema

| Field Name | Type | Description |
|-----------|------|-------------|
| Stop ID | Text | Unique identifier for the stop |
| Address | Text | Full address of the service stop |
| Service Type | Single select | Residential, Commercial, etc. |
| Date | Date | Service date |
| Completed | Checkbox | Whether service is completed |
| Image URL | Text | URL to uploaded image |
| Worker Name | Text | Name of worker handling the stop |

## File Structure

```
curbin-backend/
├── server.js              # Main Express application
├── package.json           # Dependencies
├── .env                   # Environment configuration
├── .gitignore             # Git ignore rules
├── README.md              # This file
├── bin-pics/              # Uploaded images (auto-created)
└── public/
    └── index.html         # Landing page
```

## Production Deployment

### Prerequisites

- Node.js 18+ installed
- Airtable account with API key
- Optional: Google Maps API key for advanced routing

### Deployment Steps

1. **Install dependencies:**
   ```bash
   npm install --production
   ```

2. **Configure environment:**
   ```bash
   cp .env.example .env
   # Edit .env with production values
   ```

3. **Start server:**
   ```bash
   npm start
   ```

4. **Using PM2 for process management:**
   ```bash
   npm install -g pm2
   pm2 start server.js --name "curbin-backend"
   pm2 save
   pm2 startup
   ```

5. **Using systemd:**
   ```bash
   sudo tee /etc/systemd/system/curbin-backend.service << EOF
   [Unit]
   Description=CurbIn Backend
   After=network.target

   [Service]
   Type=simple
   User=www-data
   WorkingDirectory=/opt/curbin-backend
   ExecStart=/usr/bin/node /opt/curbin-backend/server.js
   Restart=always

   [Install]
   WantedBy=multi-user.target
   EOF

   sudo systemctl enable curbin-backend
   sudo systemctl start curbin-backend
   ```

## Performance Considerations

- **Image uploads:** 10MB max (configurable in server.js)
- **Airtable rate limits:** 5 requests per second (built-in limit)
- **Concurrent uploads:** Use a reverse proxy (nginx) for load balancing
- **Database:** Consider implementing caching for frequently accessed services

## Error Handling

The server returns detailed error messages:

```json
{
  "error": "Missing required fields: address, type, date"
}
```

Common HTTP status codes:
- `200` - Success
- `400` - Bad request (missing/invalid fields)
- `404` - Not found
- `500` - Server error

## Security Best Practices

✅ **Implemented:**
- CORS enabled for API access
- File type validation for uploads
- File size limits
- Error handling without exposing stack traces

⚠️ **Recommendations for Production:**
- Use HTTPS/SSL
- Implement API authentication (API keys, JWT)
- Rate limiting on upload endpoints
- Sanitize file names
- Add request logging/monitoring
- Implement CSRF protection
- Regular security audits

## Support & Debugging

### Enable Debug Mode
```bash
NODE_ENV=development npm run dev
```

### Check Upload Directory
```bash
ls -la bin-pics/
```

### Test Airtable Connection
```bash
curl http://localhost:3000/api/test-airtable
```

## License

MIT

## Contributing

Pull requests welcome! Please ensure code follows the existing style and includes error handling.
