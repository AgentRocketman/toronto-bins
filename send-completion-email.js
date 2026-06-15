/**
 * CurbIn Completion Email Endpoint
 * Sends service completion confirmation emails to customers
 * Hosted on agentrocketman.com
 */

const nodemailer = require('nodemailer');
const axios = require('axios');
require('dotenv').config();

// Airtable configuration
const AIRTABLE_API_KEY = process.env.AIRTABLE_API_KEY || 'patxbDkv88pOMXmYx.c7e5fd7974954e3a674087090835d11dd69504f3912f0ef86c3c59f1e91febdd';
const AIRTABLE_BASE_ID = process.env.AIRTABLE_BASE_ID || 'apptYNRJTXwItvied';
const AIRTABLE_BOOKINGS_TABLE_ID = process.env.AIRTABLE_BOOKINGS_TABLE_ID || 'tblKMhGnYjsH0z7Lj';

// Hostinger SMTP configuration
const SMTP_HOST = 'smtp.hostinger.com';
const SMTP_PORT = 465;
const SMTP_USER = process.env.SMTP_USER || 'support@agentrocketman.com';
const SMTP_PASSWORD = process.env.SMTP_PASSWORD || 'AgentEmail1!';

// Create Airtable API client
const airtableHeaders = {
  Authorization: `Bearer ${AIRTABLE_API_KEY}`,
  'Content-Type': 'application/json',
};

/**
 * Lookup customer email by address in Airtable Bookings table
 * @param {string} address - The service address
 * @returns {Promise<{email: string, customerName: string} | null>}
 */
async function lookupCustomerByAddress(address) {
  try {
    // Normalize address for comparison (trim, lowercase)
    const normalizedAddress = address.trim().toLowerCase();

    const response = await axios.get(
      `https://api.airtable.com/v0/${AIRTABLE_BASE_ID}/${AIRTABLE_BOOKINGS_TABLE_ID}`,
      {
        headers: airtableHeaders,
        params: {
          filterByFormula: `LOWER(TRIM({Address})) = LOWER(TRIM("${normalizedAddress}"))`,
          maxRecords: 1,
        },
      }
    );

    if (response.data.records && response.data.records.length > 0) {
      const record = response.data.records[0];
      return {
        email: record.fields.Email,
        customerName: record.fields['Customer Name'],
      };
    }

    return null;
  } catch (error) {
    console.error('Airtable lookup error:', error.message);
    throw error;
  }
}

/**
 * Generate HTML email content
 * @param {Object} params - Email parameters
 * @param {boolean} useInlineImage - Whether to use inline image (cid) or external URL
 * @returns {string} - HTML email body
 */
function generateEmailHTML(params, useInlineImage = false) {
  const {
    customerName,
    address,
    serviceType,
    workerName,
    completedDateTime,
    imageUrl,
  } = params;

  const serviceTypeLabel = serviceType === 'roll-out' ? 'Roll Out' : 'Roll In';
  const completedDate = new Date(completedDateTime).toLocaleString('en-US', {
    weekday: 'long',
    year: 'numeric',
    month: 'long',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
    timeZone: 'America/Toronto',
  });

  return `
    <!DOCTYPE html>
    <html>
      <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <style>
          body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            background-color: #f5f5f5;
            margin: 0;
            padding: 20px;
          }
          .container {
            max-width: 600px;
            margin: 0 auto;
            background-color: white;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            overflow: hidden;
          }
          .header {
            background: linear-gradient(135deg, #A4D233 0%, #3b82f6 100%);
            color: white;
            padding: 30px 20px;
            text-align: center;
          }
          .header h1 {
            margin: 0;
            font-size: 28px;
            font-weight: 600;
          }
          .content {
            padding: 30px 20px;
          }
          .greeting {
            font-size: 16px;
            color: #333;
            margin-bottom: 20px;
          }
          .details {
            background-color: #f9f9f9;
            border-left: 4px solid #A4D233;
            padding: 15px;
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
            color: #555;
            min-width: 120px;
          }
          .detail-value {
            color: #333;
            flex: 1;
          }
          .photo-section {
            text-align: center;
            margin: 25px 0;
          }
          .photo-section img {
            max-width: 100%;
            height: auto;
            border-radius: 8px;
            max-height: 400px;
          }
          .footer {
            background-color: #f5f5f5;
            padding: 20px;
            text-align: center;
            font-size: 12px;
            color: #888;
            border-top: 1px solid #e0e0e0;
          }
          .cta-button {
            display: inline-block;
            background-color: #A4D233;
            color: #333;
            padding: 12px 30px;
            border-radius: 4px;
            text-decoration: none;
            margin-top: 15px;
            font-weight: 600;
          }
        </style>
      </head>
      <body>
        <div class="container">
          <div class="header">
            <h1>✓ Service Completed</h1>
          </div>
          <div class="content">
            <p class="greeting">Hi ${customerName},</p>
            <p>Your bin service has been completed successfully! Here are the details of your service:</p>

            <div class="details">
              <div class="detail-row">
                <div class="detail-label">Address:</div>
                <div class="detail-value">${address}</div>
              </div>
              <div class="detail-row">
                <div class="detail-label">Service Type:</div>
                <div class="detail-value">${serviceTypeLabel}</div>
              </div>
              <div class="detail-row">
                <div class="detail-label">Service Person:</div>
                <div class="detail-value">${workerName}</div>
              </div>
              <div class="detail-row">
                <div class="detail-label">Completed:</div>
                <div class="detail-value">${completedDate}</div>
              </div>
            </div>

            ${
              imageUrl
                ? `
            <div class="photo-section">
              <p style="color: #666; font-size: 14px; margin-top: 0;">Service completion photo:</p>
              <img src="${useInlineImage ? 'cid:binphoto' : imageUrl}" alt="Service completion photo">
            </div>
            `
                : ''
            }

            <p style="color: #666; font-size: 14px; margin-top: 25px;">
              If you have any questions or concerns about your service, please don't hesitate to contact us.
            </p>
          </div>
          <div class="footer">
            <p>&copy; ${new Date().getFullYear()} CurbIn Services. All rights reserved.</p>
            <p>support@agentrocketman.com</p>
          </div>
        </div>
      </body>
    </html>
  `;
}

/**
 * Send completion email via Hostinger SMTP
 * @param {Object} emailData - Email data
 * @returns {Promise<boolean>}
 */
async function sendCompletionEmail(emailData) {
  try {
    const transporter = nodemailer.createTransport({
      host: SMTP_HOST,
      port: SMTP_PORT,
      secure: true, // TLS
      auth: {
        user: SMTP_USER,
        pass: SMTP_PASSWORD,
      },
    });

    // Attempt to fetch and inline image
    let useInlineImage = false;
    const mailOptions = {
      from: `CurbIn <${SMTP_USER}>`,
      to: emailData.email,
      subject: `Your Bin Service Completed - ${emailData.address}`,
      replyTo: SMTP_USER,
      attachments: [],
    };

    // Try to fetch image and attach inline if imageUrl exists
    if (emailData.imageUrl) {
      try {
        console.log(`Fetching image from: ${emailData.imageUrl}`);
        const imageResponse = await axios.get(emailData.imageUrl, {
          responseType: 'arraybuffer',
          timeout: 10000,
        });

        // Determine image content type
        const contentType = imageResponse.headers['content-type'] || 'image/jpeg';
        
        // Add attachment with CID for inline display
        mailOptions.attachments.push({
          filename: 'binphoto',
          content: imageResponse.data,
          cid: 'binphoto',
          contentType: contentType,
        });

        useInlineImage = true;
        console.log('Image fetched and prepared for inline attachment');
      } catch (imageError) {
        console.warn(
          `Failed to fetch image for inline attachment: ${imageError.message}. Falling back to external URL.`
        );
        // useInlineImage remains false, HTML will use external URL as fallback
      }
    }

    const htmlContent = generateEmailHTML(emailData, useInlineImage);
    mailOptions.html = htmlContent;

    const result = await transporter.sendMail(mailOptions);
    console.log('Email sent successfully:', result.messageId);
    return true;
  } catch (error) {
    console.error('Email send error:', error.message);
    throw error;
  }
}

/**
 * Main endpoint handler
 * POST /api/send-completion-email
 */
async function handleSendCompletionEmail(req, res) {
  try {
    // Validate input
    const { stopId, address, serviceType, completed, imageUrl, workerName, completedDateTime } = req.body;

    if (!address || !workerName || !completedDateTime) {
      return res.status(400).json({
        success: false,
        emailSent: false,
        error: 'Missing required fields: address, workerName, completedDateTime',
      });
    }

    if (!['roll-out', 'roll-in'].includes(serviceType?.toLowerCase())) {
      return res.status(400).json({
        success: false,
        emailSent: false,
        error: 'Invalid serviceType. Must be "roll-out" or "roll-in"',
      });
    }

    // Lookup customer by address
    console.log(`Looking up customer for address: ${address}`);
    const customer = await lookupCustomerByAddress(address);

    if (!customer || !customer.email) {
      console.warn(`No customer found for address: ${address}`);
      return res.status(404).json({
        success: false,
        emailSent: false,
        error: 'Customer email not found for this address',
      });
    }

    console.log(`Found customer: ${customer.customerName} (${customer.email})`);

    // Convert relative image URLs to absolute
    let absoluteImageUrl = imageUrl;
    if (imageUrl && !imageUrl.startsWith('http')) {
      // Relative URL - convert to absolute
      absoluteImageUrl = `https://agentrocketman.com${imageUrl}`;
      console.log(`Converting relative image URL to absolute: ${absoluteImageUrl}`);
    }

    // Prepare email data
    const emailData = {
      email: customer.email,
      customerName: customer.customerName,
      address,
      serviceType: serviceType.toLowerCase(),
      workerName,
      completedDateTime,
      imageUrl: absoluteImageUrl,
    };

    // Send email
    const emailSent = await sendCompletionEmail(emailData);

    return res.status(200).json({
      success: true,
      emailSent: emailSent,
      message: `Completion email sent to ${customer.email}`,
      customerName: customer.customerName,
    });
  } catch (error) {
    console.error('Endpoint error:', error);
    return res.status(500).json({
      success: false,
      emailSent: false,
      error: error.message || 'Internal server error',
    });
  }
}

module.exports = { handleSendCompletionEmail, sendCompletionEmail, lookupCustomerByAddress };
