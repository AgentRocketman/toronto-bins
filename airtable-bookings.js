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
  const todayISO = new Date().toISOString().split('T')[0];
  
  // 1. Save Booking record
  const bookingRecord = {
    fields: {
      'Booking ID': bookingId,
      'Customer Name': bookingData.customerName || '',
      'Email': bookingData.customerEmail || '',
      'Phone': bookingData.customerPhone || '',
      'Address': bookingData.address || '',
      'Service Type': bookingData.serviceType || '',
      'Amount': bookingData.amount ? bookingData.amount / 100 : 0, // Convert cents to dollars
      'Stripe Payment ID': bookingData.stripePaymentId || '',
      'Created At': todayISO
    }
  };

  try {
    const bookingResponse = await fetch(`https://api.airtable.com/v0/${AIRTABLE_BASE_ID}/${AIRTABLE_TABLE_ID}`, {
      method: 'POST',
      headers: {
        'Authorization': `Bearer ${AIRTABLE_API_KEY}`,
        'Content-Type': 'application/json'
      },
      body: JSON.stringify(bookingRecord)
    });

    if (!bookingResponse.ok) {
      const error = await bookingResponse.json();
      console.error('Airtable API error:', error);
      throw new Error(`Airtable error: ${error.error?.message || 'Unknown error'}`);
    }

    const bookingResult = await bookingResponse.json();
    console.log('✅ Booking saved to Airtable:', bookingResult);
    
    // 2. Save Order records for each service date
    const ORDERS_TABLE_ID = 'tblGhNRi3ENwVpNty';
    let orderCount = 0;
    
    if (bookingData.selectedDates && bookingData.selectedDates.length > 0) {
      const ordersToSave = bookingData.selectedDates.map(dateStr => ({
        fields: {
          'Order ID': bookingId + '-' + dateStr,
          'Booking ID': bookingId,
          'Service Date': dateStr,
          'Service Type': bookingData.serviceType || '',
          'Status': 'Pending',
          'Created At': todayISO
        }
      }));
      
      console.log(`Saving ${ordersToSave.length} order records...`);
      
      for (const orderRecord of ordersToSave) {
        try {
          const orderResponse = await fetch(`https://api.airtable.com/v0/${AIRTABLE_BASE_ID}/${ORDERS_TABLE_ID}`, {
            method: 'POST',
            headers: {
              'Authorization': `Bearer ${AIRTABLE_API_KEY}`,
              'Content-Type': 'application/json'
            },
            body: JSON.stringify(orderRecord)
          });
          
          if (orderResponse.ok) {
            orderCount++;
            console.log('✅ Order saved:', orderRecord.fields['Order ID']);
          } else {
            const error = await orderResponse.json();
            console.warn('Failed to save order:', error);
          }
        } catch (err) {
          console.warn('Error saving order:', err);
        }
      }
    }
    
    return {
      bookingId: bookingId,
      recordId: bookingResult.id,
      ordersCount: orderCount,
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
