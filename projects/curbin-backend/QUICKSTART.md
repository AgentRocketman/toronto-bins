# 🚀 Quick Start Guide

Get the CurbIn backend running in 5 minutes.

## Prerequisites

- **Node.js 18+** - [Download](https://nodejs.org/)
- **Airtable API Key** - Get from [airtable.com/account/token](https://airtable.com/account/token)
- **Terminal/Command Prompt**

## Step 1: Setup

### Clone/Navigate to Project
```bash
cd /path/to/curbin-backend
```

### Install Dependencies
```bash
npm install
```

**Expected output:**
```
added 87 packages in 12.4s
```

## Step 2: Configure

### Copy Environment Template
```bash
cp .env.example .env
```

### Edit .env File
```bash
# Linux/Mac
nano .env

# Windows
notepad .env
```

**Minimum required:**
```env
PORT=3000
AIRTABLE_API_KEY=patxbDkv88pOMXmYx.c7e5fd7974954e3a674087090835d11dd69504f3912f0ef86c3c59f1e91febdd
AIRTABLE_BASE_ID=appCurbInServiceStops
```

## Step 3: Start Server

```bash
npm start
```

**Expected output:**
```
CurbIn backend server running on http://localhost:3000
Upload directory: /path/to/curbin-backend/bin-pics
Airtable Base ID: appCurbInServiceStops
Airtable Table: ServiceStops
```

✅ **Server is running!**

## Step 4: Test It

### In Another Terminal:

```bash
# Test health check
curl http://localhost:3000/api/health

# Create test data
curl http://localhost:3000/api/test-airtable

# Get all services
curl http://localhost:3000/api/services
```

Or run the automated test suite:
```bash
npm run test
```

## Step 5: Start Using

### 📝 Basic Workflow

**1. Upload a Photo**
```bash
curl -X POST http://localhost:3000/api/upload \
  -F "file=@/path/to/image.jpg" \
  -F "stopId=stop-001"
```

**Response:**
```json
{
  "success": true,
  "imageUrl": "/bin-pics/stop-001-1718282400000.jpg"
}
```

**2. Save Service Stop**
```bash
curl -X POST http://localhost:3000/api/save-service \
  -H "Content-Type: application/json" \
  -d '{
    "id": "stop-001",
    "address": "123 King St W, Toronto, ON",
    "type": "Residential",
    "date": "2024-06-13",
    "completed": true,
    "imageUrl": "/bin-pics/stop-001-1718282400000.jpg",
    "workerName": "John Doe"
  }'
```

**3. Optimize Route**
```bash
curl -X POST http://localhost:3000/api/optimize-route \
  -H "Content-Type: application/json" \
  -d '{
    "stops": [
      { "id": "stop-001", "address": "123 King", "lat": 43.64, "lng": -79.40 },
      { "id": "stop-002", "address": "456 Queen", "lat": 43.65, "lng": -79.40 }
    ]
  }'
```

## 📱 Development Mode

For live reloading during development:

```bash
npm run dev
```

Changes to `server.js` will auto-reload!

## 🐛 Debugging

### Check Server Logs
```bash
# Already displayed in terminal where server runs
```

### Verify Files Uploaded
```bash
ls -la bin-pics/
```

### Check Airtable Connection
```bash
curl http://localhost:3000/api/test-airtable | jq '.'
```

## 📚 Next Steps

1. **Read the full API docs:** `API.md`
2. **Understand the code:** `server.js` (well-commented)
3. **Deploy to production:** See `README.md` deployment section
4. **Integrate with frontend:** Use the endpoints in your web/mobile app

## 🎯 Common Tasks

### Add More Test Data
```bash
curl http://localhost:3000/api/test-airtable
```
Creates 8 fresh test records each time.

### View All Services
```bash
curl http://localhost:3000/api/services | jq '.'
```

### Delete Old Images
```bash
rm -rf bin-pics/*
```

### Check Port is Available
```bash
# macOS/Linux
lsof -i :3000

# Windows
netstat -ano | findstr :3000
```

### Use Different Port
Edit `.env`:
```env
PORT=3001
```

## ✅ Checklist

- [ ] Node.js installed (`node --version`)
- [ ] Dependencies installed (`npm install`)
- [ ] `.env` file created with API key
- [ ] Server running (`npm start`)
- [ ] Health check works (`curl http://localhost:3000/api/health`)
- [ ] Test data created (`curl http://localhost:3000/api/test-airtable`)
- [ ] You can see services in Airtable

## 🆘 Troubleshooting

| Problem | Solution |
|---------|----------|
| "Cannot find module" | Run `npm install` |
| "Port 3000 already in use" | Change PORT in .env or kill process on port 3000 |
| "Invalid API key" | Check AIRTABLE_API_KEY in .env |
| "ENOENT: no such file" | Server needs to create bin-pics/ folder — restart |
| "500 error" | Check server logs in terminal |

## 📞 Need Help?

1. **Check logs** - Terminal where server runs shows errors
2. **Verify setup** - Run through steps 1-4 again
3. **Read docs** - Full documentation in `README.md` and `API.md`
4. **Test endpoints** - Use `npm run test` to verify all endpoints

## 🎓 Learning Resources

- **Express.js:** [expressjs.com](https://expressjs.com)
- **Airtable API:** [airtable.com/api](https://airtable.com/api)
- **REST APIs:** [restfulapi.net](https://restfulapi.net)

---

**Ready to build? 🚀 Start with Step 1!**
