const express = require('express');
const multer = require('multer');
const path = require('path');
const fs = require('fs');
const axios = require('axios');
const cors = require('cors');
require('dotenv').config();

const app = express();
const PORT = process.env.PORT || 3000;

// Airtable Configuration
const Airtable = require('airtable');
const airtableApiKey = process.env.AIRTABLE_API_KEY || 'patxbDkv88pOMXmYx.c7e5fd7974954e3a674087090835d11dd69504f3912f0ef86c3c59f1e91febdd';
const airtableBaseId = process.env.AIRTABLE_BASE_ID || 'appCurbInServiceStops';
const airtableTableName = 'ServiceStops';

const base = new Airtable({ apiKey: airtableApiKey }).base(airtableBaseId);

// Google Maps API key
const googleMapsApiKey = process.env.GOOGLE_MAPS_API_KEY || '';

// Middleware
app.use(cors());
app.use(express.json());
app.use(express.urlencoded({ extended: true }));

// Setup multer for file uploads
const uploadDir = path.join(__dirname, 'bin-pics');
if (!fs.existsSync(uploadDir)) {
  fs.mkdirSync(uploadDir, { recursive: true });
}

const storage = multer.diskStorage({
  destination: (req, file, cb) => {
    cb(null, uploadDir);
  },
  filename: (req, file, cb) => {
    const timestamp = Date.now();
    const ext = path.extname(file.originalname);
    const name = `${req.body.stopId || 'image'}-${timestamp}${ext}`;
    cb(null, name);
  }
});

const upload = multer({
  storage,
  limits: { fileSize: 10 * 1024 * 1024 }, // 10MB limit
  fileFilter: (req, file, cb) => {
    const allowedTypes = /jpeg|jpg|png|gif/;
    const extname = allowedTypes.test(path.extname(file.originalname).toLowerCase());
    const mimetype = allowedTypes.test(file.mimetype);
    if (mimetype && extname) {
      return cb(null, true);
    } else {
      cb(new Error('Only image files are allowed'));
    }
  }
});

// Serve static files from bin-pics
app.use('/bin-pics', express.static(uploadDir));

// Serve index.html
app.get('/', (req, res) => {
  res.sendFile(path.join(__dirname, 'public', 'index.html'));
});

/**
 * POST /api/upload
 * Upload an image and save it to bin-pics folder
 * Body: { file, stopId, date }
 */
app.post('/api/upload', upload.single('file'), (req, res) => {
  try {
    if (!req.file) {
      return res.status(400).json({ error: 'No file provided' });
    }

    const imageUrl = `/bin-pics/${req.file.filename}`;
    res.json({
      success: true,
      imageUrl,
      filename: req.file.filename,
      size: req.file.size
    });
  } catch (err) {
    console.error('Upload error:', err);
    res.status(500).json({ error: err.message });
  }
});

/**
 * POST /api/save-service
 * Save a service stop and sync to Airtable
 * Body: { id, address, type, date, completed, imageUrl, workerName }
 */
app.post('/api/save-service', async (req, res) => {
  try {
    const { id, address, type, date, completed, imageUrl, workerName } = req.body;

    if (!address || !type || !date) {
      return res.status(400).json({ error: 'Missing required fields: address, type, date' });
    }

    // Prepare record for Airtable
    const airtableRecord = {
      'Stop ID': id || `stop-${Date.now()}`,
      'Address': address,
      'Service Type': type,
      'Date': date,
      'Completed': completed === true || completed === 'true',
      'Image URL': imageUrl || '',
      'Worker Name': workerName || ''
    };

    // Create or update record in Airtable
    const result = await base(airtableTableName).create([
      {
        fields: airtableRecord
      }
    ]);

    const recordId = result[0].id;

    res.json({
      success: true,
      recordId,
      message: 'Service stop saved successfully',
      fields: airtableRecord
    });
  } catch (err) {
    console.error('Save service error:', err);
    res.status(500).json({ error: err.message });
  }
});

/**
 * POST /api/optimize-route
 * Optimize route using Google Maps Directions API
 * Body: { stops: [ { id, address, lat, lng }, ... ] }
 */
app.post('/api/optimize-route', async (req, res) => {
  try {
    const { stops } = req.body;

    if (!stops || !Array.isArray(stops) || stops.length === 0) {
      return res.status(400).json({ error: 'stops array is required and must not be empty' });
    }

    if (!googleMapsApiKey) {
      // Return stops in original order if no Google Maps API key
      return res.json({
        success: true,
        optimizedStops: stops,
        message: 'Google Maps API key not configured; returning stops in original order',
        duration: 0,
        distance: 0
      });
    }

    // For MVP, return stops sorted by lat/lng (simple optimization)
    // In production, use Google Maps Directions API for true route optimization
    const optimizedStops = [...stops].sort((a, b) => {
      const distA = Math.sqrt(a.lat * a.lat + a.lng * a.lng);
      const distB = Math.sqrt(b.lat * b.lat + b.lng * b.lng);
      return distA - distB;
    });

    res.json({
      success: true,
      optimizedStops,
      duration: 'N/A - Manual calculation needed',
      distance: 'N/A - Manual calculation needed',
      note: 'For production route optimization, integrate with Google Maps Directions API'
    });
  } catch (err) {
    console.error('Route optimization error:', err);
    res.status(500).json({ error: err.message });
  }
});

/**
 * GET /api/health
 * Health check endpoint
 */
app.get('/api/health', (req, res) => {
  res.json({ status: 'OK', timestamp: new Date().toISOString() });
});

/**
 * GET /api/test-airtable
 * Test Airtable connection and create sample records
 */
app.get('/api/test-airtable', async (req, res) => {
  try {
    const today = new Date().toISOString().split('T')[0];
    const testRecords = [
      {
        'Stop ID': 'stop-001',
        'Address': '123 King St W, Toronto, ON',
        'Service Type': 'Residential',
        'Date': today,
        'Completed': false,
        'Image URL': '',
        'Worker Name': 'John Doe'
      },
      {
        'Stop ID': 'stop-002',
        'Address': '456 Queen St W, Toronto, ON',
        'Service Type': 'Commercial',
        'Date': today,
        'Completed': false,
        'Image URL': '',
        'Worker Name': 'Jane Smith'
      },
      {
        'Stop ID': 'stop-003',
        'Address': '789 Bay St, Toronto, ON',
        'Service Type': 'Residential',
        'Date': today,
        'Completed': true,
        'Image URL': '',
        'Worker Name': 'Mike Johnson'
      },
      {
        'Stop ID': 'stop-004',
        'Address': '321 Bloor St W, Toronto, ON',
        'Service Type': 'Commercial',
        'Date': today,
        'Completed': false,
        'Image URL': '',
        'Worker Name': 'Sarah Wilson'
      },
      {
        'Stop ID': 'stop-005',
        'Address': '654 Yonge St, Toronto, ON',
        'Service Type': 'Residential',
        'Date': today,
        'Completed': false,
        'Image URL': '',
        'Worker Name': 'Tom Brown'
      },
      {
        'Stop ID': 'stop-006',
        'Address': '987 College St, Toronto, ON',
        'Service Type': 'Residential',
        'Date': today,
        'Completed': true,
        'Image URL': '',
        'Worker Name': 'Emily Davis'
      },
      {
        'Stop ID': 'stop-007',
        'Address': '147 Carlton St, Toronto, ON',
        'Service Type': 'Commercial',
        'Date': today,
        'Completed': false,
        'Image URL': '',
        'Worker Name': 'Chris Martin'
      },
      {
        'Stop ID': 'stop-008',
        'Address': '258 Dundas St W, Toronto, ON',
        'Service Type': 'Residential',
        'Date': today,
        'Completed': false,
        'Image URL': '',
        'Worker Name': 'Lisa Anderson'
      }
    ];

    const createdRecords = await base(airtableTableName).create(
      testRecords.map(fields => ({ fields }))
    );

    res.json({
      success: true,
      message: `Created ${createdRecords.length} test records`,
      records: createdRecords.map(r => ({
        id: r.id,
        fields: r.fields
      }))
    });
  } catch (err) {
    console.error('Test Airtable error:', err);
    res.status(500).json({ error: err.message });
  }
});

/**
 * GET /api/services
 * Get all service stops from Airtable
 */
app.get('/api/services', async (req, res) => {
  try {
    const records = await base(airtableTableName).select().all();
    const services = records.map(r => ({
      id: r.id,
      ...r.fields
    }));

    res.json({
      success: true,
      count: services.length,
      services
    });
  } catch (err) {
    console.error('Get services error:', err);
    res.status(500).json({ error: err.message });
  }
});

// Error handling middleware
app.use((err, req, res, next) => {
  console.error('Unhandled error:', err);
  res.status(500).json({
    error: err.message || 'Internal server error',
    stack: process.env.NODE_ENV === 'development' ? err.stack : undefined
  });
});

// 404 handler
app.use((req, res) => {
  res.status(404).json({ error: 'Not found' });
});

// Start server
app.listen(PORT, () => {
  console.log(`CurbIn backend server running on http://localhost:${PORT}`);
  console.log(`Upload directory: ${uploadDir}`);
  console.log(`Airtable Base ID: ${airtableBaseId}`);
  console.log(`Airtable Table: ${airtableTableName}`);
});

module.exports = app;
