# CurbIn Completion Email API - Integration Guide

## Quick Integration Examples

### JavaScript/Node.js

#### Using Fetch API
```javascript
async function notifyCompletionViaCurbIn(serviceData) {
  const emailPayload = {
    stopId: serviceData.id,
    address: serviceData.customerAddress,
    serviceType: serviceData.type, // 'roll-out' or 'roll-in'
    workerName: serviceData.workerName,
    completedDateTime: new Date().toISOString(),
    imageUrl: serviceData.photoUrl, // optional
  };

  try {
    const response = await fetch(
      'https://agentrocketman.com/api/send-completion-email',
      {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
        },
        body: JSON.stringify(emailPayload),
      }
    );

    const result = await response.json();

    if (response.ok && result.emailSent) {
      console.log(`✅ Email sent to ${result.customerName}`);
      return { success: true, ...result };
    } else {
      console.error(`❌ Failed: ${result.error}`);
      return { success: false, ...result };
    }
  } catch (error) {
    console.error('Request failed:', error);
    return { success: false, error: error.message };
  }
}
```

#### Using Axios
```javascript
const axios = require('axios');

async function sendCompletionEmail(data) {
  try {
    const response = await axios.post(
      'https://agentrocketman.com/api/send-completion-email',
      {
        address: data.address,
        serviceType: data.serviceType,
        workerName: data.workerName,
        completedDateTime: new Date().toISOString(),
        imageUrl: data.photoUrl,
      }
    );

    console.log('Email sent:', response.data.customerName);
    return response.data;
  } catch (error) {
    console.error('Error:', error.response?.data?.error || error.message);
    throw error;
  }
}
```

#### Express Middleware
```javascript
const express = require('express');
const app = express();

app.post('/complete-service', async (req, res) => {
  const { stopId, address, serviceType, workerName, photoUrl } = req.body;

  try {
    const emailResponse = await fetch(
      'https://agentrocketman.com/api/send-completion-email',
      {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          stopId,
          address,
          serviceType,
          workerName,
          completedDateTime: new Date().toISOString(),
          imageUrl: photoUrl,
        }),
      }
    );

    const emailData = await emailResponse.json();

    if (emailData.emailSent) {
      // Update your database, mark service as complete, etc.
      res.json({
        success: true,
        message: 'Service completed and customer notified',
        customerNotified: emailData.customerName,
      });
    } else {
      res.status(404).json({
        success: false,
        message: emailData.error,
      });
    }
  } catch (error) {
    res.status(500).json({ success: false, error: error.message });
  }
});
```

### Python

#### Using Requests Library
```python
import requests
from datetime import datetime

def send_completion_email(address, service_type, worker_name, photo_url=None):
    """Send completion email via CurbIn API"""
    
    payload = {
        "address": address,
        "serviceType": service_type,  # 'roll-out' or 'roll-in'
        "workerName": worker_name,
        "completedDateTime": datetime.utcnow().isoformat() + "Z",
        "imageUrl": photo_url,  # optional
    }
    
    try:
        response = requests.post(
            "https://agentrocketman.com/api/send-completion-email",
            json=payload,
            timeout=30
        )
        
        data = response.json()
        
        if response.status_code == 200 and data.get('emailSent'):
            print(f"✅ Email sent to {data['customerName']}")
            return {"success": True, **data}
        else:
            print(f"❌ Error: {data.get('error')}")
            return {"success": False, **data}
            
    except requests.RequestException as e:
        print(f"Request failed: {e}")
        return {"success": False, "error": str(e)}
```

#### Using Async (aiohttp)
```python
import aiohttp
from datetime import datetime

async def send_completion_email_async(address, service_type, worker_name):
    """Async completion email sender"""
    
    payload = {
        "address": address,
        "serviceType": service_type,
        "workerName": worker_name,
        "completedDateTime": datetime.utcnow().isoformat() + "Z",
    }
    
    async with aiohttp.ClientSession() as session:
        async with session.post(
            "https://agentrocketman.com/api/send-completion-email",
            json=payload
        ) as resp:
            return await resp.json()
```

### PHP

#### Using cURL
```php
<?php
function sendCompletionEmail($address, $serviceType, $workerName, $imageUrl = null) {
    $payload = array(
        'address' => $address,
        'serviceType' => $serviceType,  // 'roll-out' or 'roll-in'
        'workerName' => $workerName,
        'completedDateTime' => date('c'),  // ISO 8601
        'imageUrl' => $imageUrl
    );
    
    $ch = curl_init('https://agentrocketman.com/api/send-completion-email');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    $data = json_decode($response, true);
    
    if ($httpCode === 200 && $data['emailSent']) {
        echo "✅ Email sent to " . $data['customerName'];
        return array('success' => true) + $data;
    } else {
        echo "❌ Error: " . $data['error'];
        return array('success' => false) + $data;
    }
}

// Usage
$result = sendCompletionEmail(
    '123 Main St, Toronto, ON M1A 1A1',
    'roll-out',
    'John Smith',
    'https://example.com/photo.jpg'
);
?>
```

### React Component

```javascript
import React, { useState } from 'react';

function CompletionEmailSender({ serviceData }) {
  const [loading, setLoading] = useState(false);
  const [result, setResult] = useState(null);
  const [error, setError] = useState(null);

  const handleSendEmail = async () => {
    setLoading(true);
    setError(null);

    try {
      const response = await fetch(
        'https://agentrocketman.com/api/send-completion-email',
        {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
          },
          body: JSON.stringify({
            address: serviceData.address,
            serviceType: serviceData.type,
            workerName: serviceData.workerName,
            completedDateTime: new Date().toISOString(),
            imageUrl: serviceData.photoUrl,
          }),
        }
      );

      const data = await response.json();

      if (data.emailSent) {
        setResult({
          success: true,
          message: `Email sent to ${data.customerName}`,
        });
      } else {
        setError(data.error);
      }
    } catch (err) {
      setError(err.message);
    } finally {
      setLoading(false);
    }
  };

  return (
    <div>
      <button onClick={handleSendEmail} disabled={loading}>
        {loading ? 'Sending...' : 'Send Completion Email'}
      </button>

      {result && <p style={{ color: 'green' }}>{result.message}</p>}
      {error && <p style={{ color: 'red' }}>Error: {error}</p>}
    </div>
  );
}

export default CompletionEmailSender;
```

### Flutter/Dart

```dart
import 'package:http/http.dart' as http;
import 'dart:convert';

Future<Map<String, dynamic>> sendCompletionEmail({
  required String address,
  required String serviceType,
  required String workerName,
  String? imageUrl,
}) async {
  final payload = {
    'address': address,
    'serviceType': serviceType,  // 'roll-out' or 'roll-in'
    'workerName': workerName,
    'completedDateTime': DateTime.now().toUtc().toIso8601String() + 'Z',
    if (imageUrl != null) 'imageUrl': imageUrl,
  };

  try {
    final response = await http.post(
      Uri.parse('https://agentrocketman.com/api/send-completion-email'),
      headers: {'Content-Type': 'application/json'},
      body: jsonEncode(payload),
    ).timeout(Duration(seconds: 30));

    final data = jsonDecode(response.body);

    if (response.statusCode == 200 && data['emailSent']) {
      return {'success': true, ...data};
    } else {
      return {'success': false, ...data};
    }
  } catch (e) {
    return {'success': false, 'error': e.toString()};
  }
}
```

## Error Handling Best Practices

### Retry Logic with Exponential Backoff
```javascript
async function sendWithRetry(payload, maxAttempts = 3) {
  let lastError;
  
  for (let attempt = 1; attempt <= maxAttempts; attempt++) {
    try {
      const response = await fetch(
        'https://agentrocketman.com/api/send-completion-email',
        {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(payload),
        }
      );

      const data = await response.json();

      if (response.ok && data.emailSent) {
        return { success: true, ...data };
      }

      // Don't retry 4xx errors (bad request, not found)
      if (response.status >= 400 && response.status < 500) {
        return { success: false, ...data };
      }

      lastError = data.error;
    } catch (error) {
      lastError = error.message;
    }

    // Wait before retry (exponential backoff)
    if (attempt < maxAttempts) {
      const waitMs = 1000 * Math.pow(2, attempt - 1);
      await new Promise(r => setTimeout(r, waitMs));
    }
  }

  return { success: false, error: lastError };
}
```

## Webhook Pattern (Optional)

If you want to notify the API when service is complete without waiting for response:

```javascript
// In your service completion handler
app.post('/service/complete', async (req, res) => {
  const serviceData = req.body;
  
  // Mark as complete immediately
  await markServiceComplete(serviceData.stopId);
  res.json({ success: true });
  
  // Send email in background (don't wait)
  sendCompletionEmailAsync(serviceData).catch(err => {
    console.error('Failed to send completion email:', err);
    // Log to error tracking service, notify admin, etc.
  });
});

async function sendCompletionEmailAsync(data) {
  return fetch('https://agentrocketman.com/api/send-completion-email', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      address: data.address,
      serviceType: data.serviceType,
      workerName: data.workerName,
      completedDateTime: data.completedTime,
      imageUrl: data.photoUrl,
    }),
  }).then(r => r.json());
}
```

## Monitoring & Logging

### Log Completion Email Sends
```javascript
const fs = require('fs');

function logEmailSend(payload, result) {
  const log = {
    timestamp: new Date().toISOString(),
    address: payload.address,
    workerName: payload.workerName,
    success: result.emailSent,
    customerName: result.customerName,
    error: result.error,
  };

  fs.appendFileSync(
    'completion-emails.log',
    JSON.stringify(log) + '\n'
  );
}
```

### Metrics/Analytics
```javascript
// Track email send success rate
class EmailMetrics {
  constructor() {
    this.total = 0;
    this.successful = 0;
    this.failed = 0;
  }

  record(success) {
    this.total++;
    if (success) {
      this.successful++;
    } else {
      this.failed++;
    }
  }

  getSuccessRate() {
    return (this.successful / this.total * 100).toFixed(2) + '%';
  }
}

const metrics = new EmailMetrics();
```

---

## Troubleshooting Integration Issues

### Connection Timeout
- Check network connectivity to agentrocketman.com
- Verify firewall allows outbound HTTPS on port 443
- Increase timeout setting (default 30 seconds recommended)

### 404 Error (Customer Not Found)
- Verify address in request matches exactly in Airtable (case-insensitive)
- Check for leading/trailing spaces in address
- Confirm Email field is populated in Airtable record

### 400 Error (Validation Failed)
- Ensure serviceType is "roll-out" or "roll-in"
- Verify completedDateTime is valid ISO 8601 format
- Check all required fields are present

### 500 Error (Server Error)
- Retry with exponential backoff
- Check Hostinger SMTP status
- Verify Airtable API key is still valid
- Contact support if persistent

---

**Integration Guide Version:** 1.0.0  
**Last Updated:** 2026-06-14
