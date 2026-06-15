# CurbIn Backend API Documentation

Complete API reference for the CurbIn service routing backend.

## Base URL

```
http://localhost:3000
```

Or your production domain once deployed.

## Authentication

Currently no authentication required. For production, implement:
- API Key authentication
- JWT tokens
- OAuth 2.0

## Response Format

All responses are JSON with the following structure:

### Success Response
```json
{
  "success": true,
  "data": {...},
  "message": "Optional success message"
}
```

### Error Response
```json
{
  "error": "Error message description",
  "stack": "Stack trace (development only)"
}
```

---

## Endpoints

### 1. Health Check

Check if the server is running and responsive.

**Request:**
```http
GET /api/health
```

**Response (200):**
```json
{
  "status": "OK",
  "timestamp": "2024-06-13T12:34:56.789Z"
}
```

**Use Case:** Server monitoring, health checks, load balancer probes

---

### 2. Test Airtable Connection

Create 8 sample service stops in Airtable to test the integration.

**Request:**
```http
GET /api/test-airtable
```

**Response (200):**
```json
{
  "success": true,
  "message": "Created 8 test records",
  "records": [
    {
      "id": "rec3j9K2aB4cD5eF",
      "fields": {
        "Stop ID": "stop-001",
        "Address": "123 King St W, Toronto, ON",
        "Service Type": "Residential",
        "Date": "2024-06-13",
        "Completed": false,
        "Image URL": "",
        "Worker Name": "John Doe"
      }
    },
    {
      "id": "recX9Y8Z7W6V5U4T",
      "fields": {
        "Stop ID": "stop-002",
        "Address": "456 Queen St W, Toronto, ON",
        "Service Type": "Commercial",
        "Date": "2024-06-13",
        "Completed": false,
        "Image URL": "",
        "Worker Name": "Jane Smith"
      }
    }
    // ... 6 more records
  ]
}
```

**Use Case:** Testing, development, creating demo data

**Notes:**
- Creates records with today's date
- Use before testing other endpoints
- Safe to run multiple times

---

### 3. Get All Services

Retrieve all service stops from Airtable.

**Request:**
```http
GET /api/services
```

**Query Parameters:** None

**Response (200):**
```json
{
  "success": true,
  "count": 8,
  "services": [
    {
      "id": "rec3j9K2aB4cD5eF",
      "Stop ID": "stop-001",
      "Address": "123 King St W, Toronto, ON",
      "Service Type": "Residential",
      "Date": "2024-06-13",
      "Completed": false,
      "Image URL": "",
      "Worker Name": "John Doe"
    },
    {
      "id": "recX9Y8Z7W6V5U4T",
      "Stop ID": "stop-002",
      "Address": "456 Queen St W, Toronto, ON",
      "Service Type": "Commercial",
      "Date": "2024-06-13",
      "Completed": true,
      "Image URL": "/bin-pics/stop-002-1718282400000.jpg",
      "Worker Name": "Jane Smith"
    }
  ]
}
```

**Error Response (500):**
```json
{
  "error": "Invalid API key or base ID"
}
```

**Use Case:** Dashboard display, mobile app sync, reports

**Notes:**
- Returns all records in the table
- Includes Airtable record IDs
- Consider pagination for large datasets

---

### 4. Upload Image

Upload a service photo for a specific stop.

**Request:**
```http
POST /api/upload
Content-Type: multipart/form-data

file: <binary image data>
stopId: stop-001 (optional, used for filename)
date: 2024-06-13 (optional)
```

**Form Fields:**
| Field | Type | Required | Description |
|-------|------|----------|-------------|
| file | File | Yes | Image file (JPEG, PNG, GIF) |
| stopId | String | No | Stop identifier for naming |
| date | String | No | Service date (YYYY-MM-DD) |

**Supported Image Types:**
- image/jpeg (.jpg, .jpeg)
- image/png (.png)
- image/gif (.gif)

**File Size Limit:** 10MB

**Response (200):**
```json
{
  "success": true,
  "imageUrl": "/bin-pics/stop-001-1718282400000.jpg",
  "filename": "stop-001-1718282400000.jpg",
  "size": 245678
}
```

**Error Response (400):**
```json
{
  "error": "No file provided"
}
```

**Error Response (400):**
```json
{
  "error": "Only image files are allowed"
}
```

**Error Response (413):**
```json
{
  "error": "File too large"
}
```

**Use Case:** Photo documentation of service completion, evidence capture

**Example cURL:**
```bash
curl -X POST http://localhost:3000/api/upload \
  -F "file=@/path/to/image.jpg" \
  -F "stopId=stop-001" \
  -F "date=2024-06-13"
```

**Example JavaScript:**
```javascript
const formData = new FormData();
formData.append('file', imageFile);
formData.append('stopId', 'stop-001');
formData.append('date', '2024-06-13');

const response = await fetch('http://localhost:3000/api/upload', {
  method: 'POST',
  body: formData
});

const result = await response.json();
console.log(result.imageUrl); // /bin-pics/stop-001-1718282400000.jpg
```

---

### 5. Save Service Stop

Create or update a service stop record in Airtable.

**Request:**
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

**Request Body:**
| Field | Type | Required | Description |
|-------|------|----------|-------------|
| id | String | No | Stop ID (auto-generated if omitted) |
| address | String | Yes | Full address |
| type | String | Yes | Service type (Residential/Commercial) |
| date | String | Yes | Date (YYYY-MM-DD format) |
| completed | Boolean | No | Whether service is completed |
| imageUrl | String | No | URL to image (from /api/upload) |
| workerName | String | No | Worker's name |

**Response (200):**
```json
{
  "success": true,
  "recordId": "rec3j9K2aB4cD5eF",
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

**Error Response (400):**
```json
{
  "error": "Missing required fields: address, type, date"
}
```

**Error Response (500):**
```json
{
  "error": "Airtable API error: Invalid field name"
}
```

**Use Case:** Save service completion data, store stop information, sync with Airtable

**Example Workflow:**
1. Upload image: `POST /api/upload` → get imageUrl
2. Save service: `POST /api/save-service` → include imageUrl

---

### 6. Optimize Route

Get optimized route for multiple service stops.

**Request:**
```http
POST /api/optimize-route
Content-Type: application/json

{
  "stops": [
    {
      "id": "stop-001",
      "address": "123 King St W",
      "lat": 43.6426,
      "lng": -79.3957
    },
    {
      "id": "stop-002",
      "address": "456 Queen St W",
      "lat": 43.6452,
      "lng": -79.4003
    },
    {
      "id": "stop-003",
      "address": "789 Bay St",
      "lat": 43.6629,
      "lng": -79.3957
    }
  ]
}
```

**Request Body:**
```typescript
{
  stops: Array<{
    id: string;           // Stop identifier
    address: string;      // Full address
    lat: number;          // Latitude
    lng: number;          // Longitude
  }>
}
```

**Response (200):**
```json
{
  "success": true,
  "optimizedStops": [
    {
      "id": "stop-001",
      "address": "123 King St W",
      "lat": 43.6426,
      "lng": -79.3957
    },
    {
      "id": "stop-003",
      "address": "789 Bay St",
      "lat": 43.6629,
      "lng": -79.3957
    },
    {
      "id": "stop-002",
      "address": "456 Queen St W",
      "lat": 43.6452,
      "lng": -79.4003
    }
  ],
  "duration": "N/A - Manual calculation needed",
  "distance": "N/A - Manual calculation needed",
  "note": "For production route optimization, integrate with Google Maps Directions API"
}
```

**Error Response (400):**
```json
{
  "error": "stops array is required and must not be empty"
}
```

**Use Case:** Route planning, worker dispatch optimization, logistics planning

**Notes:**
- Current implementation: Basic distance-based sorting
- Production: Integrate Google Maps Directions API
- Returns stops in optimized order for efficient service delivery

**Enhancement for Production:**
```javascript
// Use Google Maps Directions Matrix API
const response = await axios.post(
  'https://maps.googleapis.com/maps/api/directions/json',
  {
    origin: stops[0],
    destination: stops[stops.length - 1],
    waypoints: stops.slice(1, -1),
    key: googleMapsApiKey
  }
);
```

---

## HTTP Status Codes

| Code | Meaning | Example |
|------|---------|---------|
| 200 | Success | Data returned successfully |
| 400 | Bad Request | Missing required fields |
| 404 | Not Found | Endpoint doesn't exist |
| 413 | Payload Too Large | File exceeds 10MB |
| 500 | Server Error | Airtable API error |

---

## Rate Limits

- **Airtable:** 5 requests/second (built-in limit)
- **File uploads:** No explicit limit (use reverse proxy for control)
- **GET endpoints:** No limit

---

## Error Handling

All errors follow this format:

```json
{
  "error": "Human-readable error message",
  "stack": "Stack trace (development only)"
}
```

**Common Error Scenarios:**

| Scenario | Status | Response |
|----------|--------|----------|
| Invalid JSON | 400 | `{ "error": "Unexpected token..." }` |
| Missing field | 400 | `{ "error": "Missing required fields: address, type, date" }` |
| File too large | 413 | `{ "error": "File too large" }` |
| Wrong file type | 400 | `{ "error": "Only image files are allowed" }` |
| Airtable error | 500 | `{ "error": "Airtable API error: ..." }` |

---

## Examples

### Complete Workflow: Save Service with Photo

```bash
#!/bin/bash

# 1. Upload image
UPLOAD_RESPONSE=$(curl -X POST http://localhost:3000/api/upload \
  -F "file=@./bin-photo.jpg" \
  -F "stopId=stop-001" \
  -F "date=2024-06-13")

IMAGE_URL=$(echo $UPLOAD_RESPONSE | jq -r '.imageUrl')
echo "Image uploaded: $IMAGE_URL"

# 2. Save service with image
curl -X POST http://localhost:3000/api/save-service \
  -H "Content-Type: application/json" \
  -d "{
    \"id\": \"stop-001\",
    \"address\": \"123 King St W, Toronto, ON\",
    \"type\": \"Residential\",
    \"date\": \"2024-06-13\",
    \"completed\": true,
    \"imageUrl\": \"$IMAGE_URL\",
    \"workerName\": \"John Doe\"
  }"
```

### Get Route and Optimize

```javascript
// Fetch stops from API
const response = await fetch('http://localhost:3000/api/services');
const { services } = await response.json();

// Prepare for optimization
const stops = services.map(s => ({
  id: s['Stop ID'],
  address: s.Address,
  lat: parseFloat(s.Latitude || 43.6426),
  lng: parseFloat(s.Longitude || -79.3957)
}));

// Get optimized route
const optimizeResponse = await fetch('http://localhost:3000/api/optimize-route', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({ stops })
});

const { optimizedStops } = await optimizeResponse.json();
console.log('Optimized order:', optimizedStops.map(s => s.id));
```

---

## Best Practices

1. **Always include error handling** in your requests
2. **Upload images before saving** to get the imageUrl
3. **Use optimized routes** for efficient service delivery
4. **Validate data** before sending to the API
5. **Handle rate limits** with exponential backoff
6. **Cache responses** when possible
7. **Use HTTPS** in production

---

## Troubleshooting

**Issue:** "Cannot POST /api/upload"
- Solution: Make sure server is running and endpoint is correct

**Issue:** Airtable API key error
- Solution: Verify AIRTABLE_API_KEY in .env file

**Issue:** Files not saving to bin-pics
- Solution: Check directory permissions: `chmod 755 bin-pics/`

**Issue:** CORS errors
- Solution: CORS is enabled by default; check request headers

---

## Support

For issues or questions:
1. Check the README.md
2. Review this documentation
3. Test with test-api.sh
4. Check server logs for errors
