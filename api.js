// Simple Node.js API for CurbIn service routing
// Requires: Express, Multer, Airtable client

const express = require('express');
const multer = require('multer');
const path = require('path');
const fs = require('fs');
const Airtable = require('airtable');

const app = express();
app.use(express.json());

// Airtable setup
const AIRTABLE_API_KEY = process.env.AIRTABLE_API_KEY || '';
const AIRTABLE_BASE_ID = process.env.AIRTABLE_BASE_ID || '';
const airtable = new Airtable({ apiKey: AIRTABLE_API_KEY });
const base = airtable.base(AIRTABLE_BASE_ID);

// File upload setup
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
    const name = `${req.body.stopId}_${timestamp}${ext}`;
    cb(null, name);
  }
});

const upload = multer({ 
  storage: storage,
  limits: { fileSize: 10 * 1024 * 1024 } // 10MB max
});

// Upload endpoint
app.post('/api/upload', upload.single('file'), (req, res) => {
  if (!req.file) {
    return res.status(400).json({ success: false, error: 'No file uploaded' });
  }

  const imageUrl = `/bin-pics/${req.file.filename}`;
  
  // Optionally sync to Airtable
  syncServiceStop({
    id: req.body.stopId,
    date: req.body.date,
    imageUrl: imageUrl
  });

  res.json({ success: true, imageUrl: imageUrl });
});

// Save service stop to Airtable
app.post('/api/save-service', express.json(), async (req, res) => {
  try {
    const { id, address, type, date, completed, imageUrl, workerName } = req.body;

    const record = await base('Service Stops').create({
      'Stop ID': id,
      'Address': address,
      'Service Type': type === 'rollout' ? 'Roll Out' : 'Roll In',
      'Date': date,
      'Completed': completed,
      'Image URL': imageUrl || '',
      'Worker Name': workerName || ''
    });

    res.json({ success: true, recordId: record.id });
  } catch (error) {
    console.error('Airtable error:', error);
    res.status(500).json({ success: false, error: error.message });
  }
});

// Helper to sync service stop
async function syncServiceStop(data) {
  try {
    if (!AIRTABLE_API_KEY || !AIRTABLE_BASE_ID) {
      console.log('Airtable not configured, skipping sync');
      return;
    }

    await base('Service Stops').create({
      'Stop ID': data.id,
      'Image URL': data.imageUrl,
      'Updated At': new Date().toISOString()
    });
  } catch (error) {
    console.warn('Airtable sync warning:', error.message);
  }
}

// Health check
app.get('/api/health', (req, res) => {
  res.json({ status: 'ok' });
});

// Serve bin-pics folder
app.use('/bin-pics', express.static(uploadDir));

const PORT = process.env.PORT || 3000;
app.listen(PORT, () => {
  console.log(`CurbIn Service API running on port ${PORT}`);
});
