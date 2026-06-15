#!/usr/bin/env node
/**
 * CurbIn Completion Email Service
 * Production-ready Node.js backend for sending service completion notifications
 *
 * Usage: npm install && npm start
 * Environment: See .env.example
 */

const express = require('express');
const nodemailer = require('nodemailer');
const Airtable = require('airtable');
require('dotenv').config();

// Configuration
const PORT = process.env.PORT || 3000;
const SMTP_HOST = process.env.SMTP_HOST || 'smtp.hostinger.com';
const SMTP_PORT = process.env.SMTP_PORT || 465;
const SMTP_USER = process.env.SMTP_USER || 'support@agentrocketman.com';
const SMTP_PASSWORD = process.env.SMTP_PASSWORD || 'AgentEmail1!';
const MAIL_FROM_NAME = 'CurbIn Service';

const AIRTABLE_API_KEY = process.env.AIRTABLE_API_KEY;
const AIRTABLE_BASE_ID = process.env.AIRTABLE_BASE_ID;
const AIRTABLE_BOOKINGS_TABLE = process.env.AIRTABLE_BOOKINGS_TABLE || 'tblKMhGnYjsH0z7Lj';

// ============================================================================
// Airtable Client
// ============================================================================
const base = new Airtable({ apiKey: AIRTABLE_API_KEY }).base(AIRTABLE_BASE_ID);

async function findCustomerByAddress(address) {
  // Exact match first
  const records = await base(AIRTABLE_BOOKINGS_TABLE)
    .select({
      filterByFormula: `LOWER(TRIM({Address})) = LOWER(TRIM("${address.replace(/"/g, '\\"')}"))`,
      maxRecords: 1,
    })
    .firstPage();

  if (records && records.length > 0) {
    return records[0];
  }

  // Fallback: normalized comparison
  const allRecords = await base(AIRTABLE_BOOKINGS_TABLE)
    .select({ pageSize: 100 })
    .all();

  const normalized = address.trim().toLowerCase();
  for (const rec of allRecords) {
    const recAddr = (rec.get('Address') || '').trim().toLowerCase();
    if (recAddr === normalized) {
      return rec;
    }
  }

  return null;
}

// ============================================================================
// Nodemailer Transport
// ============================================================================
const transporter = nodemailer.createTransport({
  host: SMTP_HOST,
  port: SMTP_PORT,
  secure: true,
  auth: {
    user: SMTP_USER,
    pass: SMTP_PASSWORD,
  },
});

// ============================================================================
// Helper: Email HTML Builder
// ============================================================================
function escape(str) {
  if (!str) return '';
  return String(str)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#x27;');
}

function serviceLabel(type) {
  const t = (type || '').toLowerCase();
  if (t.includes('out')) return 'Roll Out';
  if (t.includes('in')) return 'Roll In';
  return type;
}

function formatDateTime(isoString) {
  if (!isoString) return 'N/A';
  try {
    const date = new Date(isoString);
    return date.toLocaleString('en-CA', {
      timeZone: 'America/Toronto',
      weekday: 'short',
      year: 'numeric',
      month: 'short',
      day: 'numeric',
      hour: '2-digit',
      minute: '2-digit',
      second: '2-digit',
    });
  } catch {
    return isoString;
  }
}

function buildEmailHtml({
  customerName,
  address,
  actionLabel,
  workerName,
  completedDateTime,
  imageUrl,
}) {
  const safeCustomer = escape(customerName);
  const safeAddress = escape(address);
  const safeWorker = escape(workerName || 'CurbIn Team');
  const safeDateTime = escape(formatDateTime(completedDateTime));

  return `<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Service Completed</title>
  <style>
    body {
      font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
      background-color: #f5f5f5;
      margin: 0;
      padding: 0;
    }
    .container {
      max-width: 600px;
      margin: 20px auto;
      background: white;
      border-radius: 8px;
      box-shadow: 0 2px 8px rgba(0,0,0,0.1);
      overflow: hidden;
    }
    .header {
      background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
      color: white;
      padding: 40px 20px;
      text-align: center;
    }
    .header h1 {
      margin: 0;
      font-size: 28px;
      font-weight: 700;
    }
    .content {
      padding: 30px 20px;
      color: #333;
    }
    .greeting {
      font-size: 16px;
      margin-bottom: 20px;
      line-height: 1.5;
    }
    .details {
      background: #f9f9f9;
      border-left: 4px solid #667eea;
      padding: 16px;
      margin: 20px 0;
      border-radius: 4px;
    }
    .detail-row {
      display: flex;
      margin-bottom: 12px;
      font-size: 14px;
    }
    .detail-label {
      font-weight: 600;
      color: #666;
      min-width: 140px;
      flex-shrink: 0;
    }
    .detail-value {
      color: #333;
      flex: 1;
      word-break: break-word;
    }
    .photo-section {
      margin: 30px 0;
      text-align: center;
    }
    .photo-section p {
      color: #666;
      font-size: 14px;
      margin-top: 0;
    }
    .photo-section img {
      max-width: 100%;
      height: auto;
      border-radius: 8px;
      max-height: 400px;
      margin-top: 10px;
    }
    .footer {
      background: #f5f5f5;
      padding: 20px;
      text-align: center;
      font-size: 12px;
      color: #888;
      border-top: 1px solid #e0e0e0;
    }
  </style>
</head>
<body>
  <div class="container">
    <div class="header">
      <h1>✓ Service Completed</h1>
    </div>
    <div class="content">
      <p class="greeting">Hi ${safeCustomer},</p>
      <p>Your bin service has been completed successfully.</p>

      <div class="details">
        <div class="detail-row">
          <div class="detail-label">Service:</div>
          <div class="detail-value">${escape(actionLabel)}</div>
        </div>
        <div class="detail-row">
          <div class="detail-label">Address:</div>
          <div class="detail-value">${safeAddress}</div>
        </div>
        <div class="detail-row">
          <div class="detail-label">Service Person:</div>
          <div class="detail-value">${safeWorker}</div>
        </div>
        <div class="detail-row">
          <div class="detail-label">Completed:</div>
          <div class="detail-value">${safeDateTime}</div>
        </div>
      </div>

      ${
        imageUrl
          ? `<div class="photo-section">
               <p>Completion Photo:</p>
               <img src="${escape(imageUrl)}" alt="Service completion photo" style="max-width:100%;border-radius:8px;">
             </div>`
          : ''
      }

      <p style="font-size: 14px; color: #666; margin-top: 25px;">
        If you have any questions, please reach out to our team.
      </p>
    </div>
    <div class="footer">
      <p>&copy; ${new Date().getFullYear()} CurbIn Services. All rights reserved.</p>
      <p style="margin: 5px 0;">support@agentrocketman.com</p>
    </div>
  </div>
</body>
</html>`;
}

// ============================================================================
// Express App
// ============================================================================
const app = express();
app.use(express.json({ limit: '1mb' }));

// Health check
app.get('/health', (_req, res) => res.json({ ok: true }));

// Main endpoint
app.post('/api/send-completion-email', async (req, res) => {
  const {
    stopId,
    address,
    serviceType,
    completed,
    imageUrl,
    workerName,
    completedDateTime,
  } = req.body || {};

  // 1) Validate input
  const errors = [];
  if (!address || typeof address !== 'string' || !address.trim()) {
    errors.push('address is required');
  }
  if (!serviceType || typeof serviceType !== 'string' || !serviceType.trim()) {
    errors.push('serviceType is required');
  }
  if (errors.length) {
    return res.status(400).json({
      success: false,
      emailSent: false,
      error: 'Validation failed',
      details: errors,
    });
  }

  // Only send when the stop is actually marked complete
  const isCompleted =
    completed === true ||
    completed === 'true' ||
    completed === 1 ||
    completed === '1';
  if (!isCompleted) {
    return res.status(200).json({
      success: true,
      emailSent: false,
      reason: 'Stop is not marked completed; no email sent.',
    });
  }

  try {
    // 2) Find customer in Airtable by address
    const record = await findCustomerByAddress(address.trim());

    if (!record) {
      return res.status(404).json({
        success: false,
        emailSent: false,
        error: `No booking found for address: ${address}`,
      });
    }

    const email = record.get('Email');
    const customerName = record.get('Customer Name');

    if (!email) {
      return res.status(422).json({
        success: false,
        emailSent: false,
        error: 'Matching booking has no Email on file.',
      });
    }

    // 3) Build + send the email
    const actionLabel = serviceLabel(serviceType);
    const html = buildEmailHtml({
      customerName,
      address: address.trim(),
      actionLabel,
      workerName,
      completedDateTime,
      imageUrl,
    });

    const info = await transporter.sendMail({
      from: `"${MAIL_FROM_NAME}" <${SMTP_USER}>`,
      to: email,
      subject: `CurbIn: ${actionLabel} completed for ${address.trim()}`,
      html,
      text:
        `Your ${actionLabel} service has been completed.\n` +
        `Address: ${address.trim()}\n` +
        `Completed by: ${workerName || 'CurbIn Team'}\n` +
        `Completed at: ${completedDateTime || 'N/A'}\n` +
        (imageUrl ? `Bin photo: ${imageUrl}\n` : ''),
    });

    // 4) Success
    return res.status(200).json({
      success: true,
      emailSent: true,
      stopId: stopId || null,
      to: email,
      messageId: info.messageId,
    });
  } catch (err) {
    // 5) Graceful error handling
    console.error('[send-completion-email] error:', err);
    return res.status(500).json({
      success: false,
      emailSent: false,
      error: 'Failed to process completion email.',
      details: err && err.message ? err.message : String(err),
    });
  }
});

// 404 fallthrough
app.use((_req, res) => res.status(404).json({ success: false, error: 'Not found' }));

// Error handler
app.use((err, _req, res, _next) => {
  console.error('[unhandled]', err);
  res.status(500).json({ success: false, error: 'Internal server error' });
});

// Verify SMTP at boot
transporter.verify().then(
  () => console.log('[SMTP] ready'),
  (e) => console.warn('[SMTP] verify failed:', e.message)
);

// Start server
app.listen(PORT, () =>
  console.log(`🚀 CurbIn email service listening on :${PORT}`)
);

module.exports = app;
