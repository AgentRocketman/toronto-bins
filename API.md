# CurbIn Completion Email API - Specification

## Base URL
```
POST https://agentrocketman.com/api/send-completion-email
```

## Endpoint: POST /api/send-completion-email

### Description
Sends a service completion notification email to a customer by looking up their email address in the Airtable Bookings table and matching it to the provided service address.

### Request

#### Headers
```
Content-Type: application/json
```

#### Body
```json
{
  "stopId": "string (optional)",
  "address": "string (required)",
  "serviceType": "string (required: 'roll-out' or 'roll-in')",
  "completed": "boolean (optional)",
  "imageUrl": "string (optional)",
  "workerName": "string (required)",
  "completedDateTime": "string (required, ISO 8601)"
}
```

#### Field Descriptions

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `stopId` | String | No | Unique identifier for this service stop. Used for reference/tracking. |
| `address` | String | **Yes** | Customer's service address. Must match exactly (case-insensitive) with address in Airtable Bookings table. This is used to lookup customer email. |
| `serviceType` | String | **Yes** | Type of service: `"roll-out"` (customer puts bin out) or `"roll-in"` (customer brings bin back). Case-insensitive. |
| `completed` | Boolean | No | Whether the service was completed successfully. Defaults to true if not provided. |
| `imageUrl` | String | No | URL to a photo/image of the completed service. If provided, will be embedded in the email for visual confirmation. |
| `workerName` | String | **Yes** | Name of the service worker who completed the work. Displayed in email as "Service Person". |
| `completedDateTime` | String | **Yes** | ISO 8601 formatted timestamp (e.g., "2026-06-14T15:30:00Z") of when the service was completed. Will be converted to customer-friendly format in email (Toronto timezone). |

### Response

#### Success Response (200 OK)
```json
{
  "success": true,
  "emailSent": true,
  "message": "Completion email sent to customer@example.com",
  "customerName": "Jane Doe"
}
```

#### Validation Error (400 Bad Request)
```json
{
  "success": false,
  "emailSent": false,
  "error": "Missing required fields: address, workerName, completedDateTime"
}
```

```json
{
  "success": false,
  "emailSent": false,
  "error": "Invalid serviceType. Must be \"roll-out\" or \"roll-in\""
}
```

#### Not Found Error (404 Not Found)
```json
{
  "success": false,
  "emailSent": false,
  "error": "Customer email not found for this address"
}
```

#### Server Error (500 Internal Server Error)
```json
{
  "success": false,
  "emailSent": false,
  "error": "SMTP connection failed"
}
```

### Status Codes

| Code | Meaning | Cause |
|------|---------|-------|
| 200 | Success | Email sent successfully |
| 400 | Bad Request | Invalid input or missing required fields |
| 404 | Not Found | Address not found in Airtable |
| 500 | Server Error | Airtable API, SMTP, or internal error |

## Examples

### Example 1: Basic Request
```bash
curl -X POST https://agentrocketman.com/api/send-completion-email \
  -H "Content-Type: application/json" \
  -d '{
    "address": "123 Main St, Toronto, ON M1A 1A1",
    "serviceType": "roll-out",
    "workerName": "John Smith",
    "completedDateTime": "2026-06-14T15:30:00Z"
  }'
```

**Response:**
```json
{
  "success": true,
  "emailSent": true,
  "message": "Completion email sent to jane.doe@example.com",
  "customerName": "Jane Doe"
}
```

### Example 2: Request with Photo
```bash
curl -X POST https://agentrocketman.com/api/send-completion-email \
  -H "Content-Type: application/json" \
  -d '{
    "stopId": "stop_abc123",
    "address": "456 Oak Avenue, Toronto, ON M2B 2B2",
    "serviceType": "roll-in",
    "imageUrl": "https://cdn.example.com/photos/stop_abc123.jpg",
    "workerName": "Sarah Johnson",
    "completedDateTime": "2026-06-14T16:45:00Z"
  }'
```

### Example 3: JavaScript/Fetch
```javascript
async function sendCompletionEmail(details) {
  const response = await fetch(
    'https://agentrocketman.com/api/send-completion-email',
    {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
      },
      body: JSON.stringify({
        stopId: details.stopId,
        address: details.address,
        serviceType: details.serviceType,
        imageUrl: details.photoUrl,
        workerName: details.workerName,
        completedDateTime: new Date(details.completionTime).toISOString(),
      }),
    }
  );

  const data = await response.json();
  
  if (response.ok && data.emailSent) {
    console.log(`Email sent to ${data.customerName}`);
  } else {
    console.error(`Failed: ${data.error}`);
  }
  
  return data;
}
```

## Data Flow

```
1. POST request with completion details
                    ↓
2. Validate input fields
   - Check required: address, workerName, completedDateTime
   - Check serviceType is valid
                    ↓
3. Query Airtable Bookings table
   - Normalize address (trim, lowercase)
   - Search for matching address
   - Retrieve: Email, Customer Name
                    ↓
4. Generate HTML email
   - Create professional template
   - Include all service details
   - Embed photo if provided
   - Format datetime to Toronto timezone
                    ↓
5. Send via Hostinger SMTP
   - Connect to smtp.hostinger.com:465
   - Authenticate with support@agentrocketman.com
   - Send HTML email
                    ↓
6. Return success/failure response
```

## Airtable Integration Details

### Table Schema
**Bookings Table (`tblKMhGnYjsH0z7Lj`)**

Required fields:
- `Address` (Text or Long text) - Customer's service address
- `Email` (Email) - Customer's email address
- `Customer Name` (Text) - Full name of customer

Optional but useful:
- `Phone` (Phone number) - Customer's phone
- `Service Type` (Single select) - Type of service offered

### Address Matching Logic
```javascript
// Address matching is case-insensitive and trimmed
const normalizedInput = "123 Main St, Toronto".trim().toLowerCase();
const normalizedRecord = "123 MAIN ST, TORONTO".trim().toLowerCase();
// Match: true
```

⚠️ **Important:** Address must match EXACTLY (after normalization)
- "123 Main St" will NOT match "123 Main Street"
- "Toronto, ON" will NOT match "Toronto, Ontario"
- Ensure data consistency in Airtable

## Rate Limiting

Current implementation: **No rate limit** (can be added)

Recommended for production:
- 100 requests per minute per IP
- 1000 requests per hour per account
- Airtable API default: 5 requests per second

## Retry Logic

Recommended for clients:
```javascript
async function sendWithRetry(data, maxAttempts = 3) {
  for (let attempt = 1; attempt <= maxAttempts; attempt++) {
    try {
      const response = await fetch('/api/send-completion-email', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(data),
      });
      
      if (response.status === 200) {
        return await response.json();
      }
      
      if (response.status === 500 && attempt < maxAttempts) {
        await new Promise(r => setTimeout(r, 1000 * attempt));
        continue;
      }
      
      throw new Error(`HTTP ${response.status}`);
    } catch (error) {
      if (attempt === maxAttempts) throw error;
      await new Promise(r => setTimeout(r, 1000 * attempt));
    }
  }
}
```

## Security Considerations

1. **Authentication:** Currently open (no API key). Consider adding for production.
2. **HTTPS:** Must be used in production to protect email addresses.
3. **Input Validation:** All inputs validated on server side.
4. **Rate Limiting:** Recommended to prevent abuse.
5. **Logging:** All requests and errors logged for audit trail.

## Changelog

### Version 1.0.0 (2026-06-14)
- Initial release
- POST endpoint for sending completion emails
- Airtable lookup by address
- Hostinger SMTP integration
- HTML email template with photo support

---

**API Version:** 1.0.0  
**Last Updated:** 2026-06-14  
**Contact:** support@agentrocketman.com
