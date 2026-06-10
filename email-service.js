// Email Service - SendGrid Integration
const SENDGRID_API_KEY = 'SG.'; // User will provide this

function setSendGridKey(apiKey) {
  sessionStorage.setItem('sendgrid_api_key', apiKey);
}

function getSendGridKey() {
  let key = sessionStorage.getItem('sendgrid_api_key');
  if (key && !key.startsWith('SG.')) {
    // First time - prompt user
    key = prompt('Enter your SendGrid API key (starts with SG.):');
    if (key) {
      setSendGridKey(key);
    }
  }
  return key;
}

async function sendConfirmationEmail(bookingData, bookingId) {
  const apiKey = getSendGridKey();
  if (!apiKey) {
    console.warn('SendGrid API key not set - skipping email');
    return { success: false, reason: 'No API key' };
  }

  try {
    // Format service dates
    let serviceDatesText = '';
    if (bookingData.frequency === 'adhoc' && bookingData.selectedDates && bookingData.selectedDates.length > 0) {
      serviceDatesText = bookingData.selectedDates.map(d => {
        const date = new Date(d + 'T00:00:00');
        return date.toLocaleDateString('en-CA', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });
      }).join('<br>');
    } else if (bookingData.frequency === 'recurring') {
      serviceDatesText = 'Every ' + (bookingData.dayOfWeek || 'week') + ' (recurring subscription)';
    }

    // Customer confirmation email
    const customerEmail = {
      personalizations: [{
        to: [{ email: bookingData.customerEmail }],
        subject: 'Your CurbIn Booking Confirmation'
      }],
      from: { email: 'support@curbin.ca', name: 'CurbIn' },
      content: [{
        type: 'text/html',
        value: `
          <div style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; max-width: 600px; margin: 0 auto;">
            <h1 style="color: #0d9488; margin-bottom: 1rem;">✅ Booking Confirmed!</h1>
            
            <p>Hi ${bookingData.customerName},</p>
            <p>Your CurbIn booking has been confirmed. We'll handle your bins on collection day.</p>
            
            <div style="background: #f0fdf9; padding: 1.5rem; border-radius: 8px; margin: 1.5rem 0;">
              <h3 style="margin-top: 0; color: #0f766e;">Booking Details</h3>
              <p><strong>Booking ID:</strong> ${bookingId}</p>
              <p><strong>Address:</strong> ${bookingData.address}</p>
              <p><strong>Service Type:</strong> ${bookingData.serviceType === 'both' ? 'Roll Out + Roll In' : bookingData.serviceType === 'rollout' ? 'Roll Out' : 'Roll In'}</p>
              <p><strong>Service Dates:</strong><br>${serviceDatesText}</p>
              <p><strong>Amount Paid:</strong> $${(bookingData.amount / 100).toFixed(2)}</p>
            </div>
            
            <p>We'll contact you at <strong>${bookingData.customerPhone || 'the phone number you provided'}</strong> if we need any details.</p>
            
            <p style="color: #64748b; font-size: 0.9rem; margin-top: 2rem;">
              Questions? Reply to this email or contact us at support@curbin.ca
            </p>
          </div>
        `
      }]
    };

    // Send customer email
    const customerResponse = await fetch('https://api.sendgrid.com/v3/mail/send', {
      method: 'POST',
      headers: {
        'Authorization': `Bearer ${apiKey}`,
        'Content-Type': 'application/json'
      },
      body: JSON.stringify(customerEmail)
    });

    if (!customerResponse.ok) {
      const error = await customerResponse.text();
      console.error('SendGrid customer email error:', error);
      return { success: false, reason: 'Customer email failed' };
    }

    console.log('✅ Customer confirmation email sent');

    // Admin notification email
    const adminEmail = {
      personalizations: [{
        to: [{ email: 'support@curbin.ca' }],
        subject: `New Booking: ${bookingData.customerName} (${bookingId})`
      }],
      from: { email: 'support@curbin.ca', name: 'CurbIn Booking System' },
      content: [{
        type: 'text/html',
        value: `
          <div style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;">
            <h2>📌 New Booking Received</h2>
            
            <h3>Customer Info</h3>
            <p><strong>Name:</strong> ${bookingData.customerName}</p>
            <p><strong>Email:</strong> ${bookingData.customerEmail}</p>
            <p><strong>Phone:</strong> ${bookingData.customerPhone || 'N/A'}</p>
            
            <h3>Service Details</h3>
            <p><strong>Booking ID:</strong> ${bookingId}</p>
            <p><strong>Address:</strong> ${bookingData.address}</p>
            <p><strong>Service Type:</strong> ${bookingData.serviceType === 'both' ? 'Roll Out + Roll In' : bookingData.serviceType === 'rollout' ? 'Roll Out' : 'Roll In'}</p>
            <p><strong>Plan:</strong> ${bookingData.frequency === 'adhoc' ? 'Ad Hoc (' + (bookingData.selectedDates?.length || 0) + ' dates)' : 'Recurring'}</p>
            <p><strong>Amount Paid:</strong> $${(bookingData.amount / 100).toFixed(2)}</p>
            
            <p style="margin-top: 2rem; padding: 1rem; background: #f8fafc; border-left: 4px solid #0d9488;">
              View full details in Airtable: https://airtable.com/appXXXXXX/tblXXXXXX
            </p>
          </div>
        `
      }]
    };

    // Send admin email
    const adminResponse = await fetch('https://api.sendgrid.com/v3/mail/send', {
      method: 'POST',
      headers: {
        'Authorization': `Bearer ${apiKey}`,
        'Content-Type': 'application/json'
      },
      body: JSON.stringify(adminEmail)
    });

    if (!adminResponse.ok) {
      const error = await adminResponse.text();
      console.error('SendGrid admin email error:', error);
      // Don't fail the whole thing if admin email fails
    } else {
      console.log('✅ Admin notification email sent');
    }

    return { success: true };
  } catch (err) {
    console.error('Email service error:', err);
    return { success: false, reason: err.message };
  }
}
