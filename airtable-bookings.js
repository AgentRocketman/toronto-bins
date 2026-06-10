// Airtable Bookings Integration
// API key is stored in sessionStorage to avoid hardcoding secrets
const AIRTABLE_BASE_ID = 'apptYNRJTXwItvied'; // Curbin base
const AIRTABLE_TABLE_ID = 'tblKMhGnYjsH0z7Lj'; // Bookings table

function getAirtableApiKey() {
  // Try to get from sessionStorage
  let key = sessionStorage.getItem('airtable_api_key');
  if (key) return key;
  
  // If not found, prompt user
  key = prompt('Enter your Airtable API key (won\'t be saved permanently):');
  if (key) {
    sessionStorage.setItem('airtable_api_key', key);
  }
  return key;
}

function generateBookingId() {
  return 'BK-' + Date.now() + '-' + Math.random().toString(36).substr(2, 9).toUpperCase();
}

async function saveBookingToAirtable(bookingData) {
  const AIRTABLE_API_KEY = getAirtableApiKey();
  
  if (!AIRTABLE_API_KEY) {
    throw new Error('Airtable API key required. Please provide it when prompted.');
  }
  
  const bookingId = generateBookingId();
  
  // Prepare record for Airtable
  const record = {
    fields: {
      'Booking ID': bookingId,
      'Customer Name': bookingData.customerName || '',
      'Email': bookingData.customerEmail || '',
      'Phone': bookingData.customerPhone || '',
      'Address': bookingData.address || '',
      'Service Type': bookingData.serviceType || '',
      'Amount': bookingData.amount ? bookingData.amount / 100 : 0, // Convert cents to dollars
      'Stripe Payment ID': bookingData.stripePaymentId || '',
      'Created At': new Date().toISOString().split('T')[0] // YYYY-MM-DD format
    }
  };

  try {
    const response = await fetch(`https://api.airtable.com/v0/${AIRTABLE_BASE_ID}/${AIRTABLE_TABLE_ID}`, {
      method: 'POST',
      headers: {
        'Authorization': `Bearer ${AIRTABLE_API_KEY}`,
        'Content-Type': 'application/json'
      },
      body: JSON.stringify(record)
    });

    if (!response.ok) {
      const error = await response.json();
      console.error('Airtable API error:', error);
      throw new Error(`Airtable error: ${error.error?.message || 'Unknown error'}`);
    }

    const result = await response.json();
    console.log('✅ Booking saved to Airtable:', result);
    
    return {
      bookingId: bookingId,
      recordId: result.id,
      success: true
    };
  } catch (err) {
    console.error('Failed to save to Airtable:', err);
    throw err;
  }
}

// Fallback: save to localStorage if Airtable fails
function saveBookingLocally(bookingData) {
  const bookingId = generateBookingId();
  const booking = {
    bookingId: bookingId,
    ...bookingData,
    savedAt: new Date().toISOString()
  };

  try {
    let bookings = JSON.parse(localStorage.getItem('curbin_bookings') || '[]');
    bookings.push(booking);
    localStorage.setItem('curbin_bookings', JSON.stringify(bookings));
    console.log('✅ Booking saved to localStorage:', booking);
    
    return {
      bookingId: bookingId,
      success: true
    };
  } catch (err) {
    console.error('Failed to save to localStorage:', err);
    throw err;
  }
}
