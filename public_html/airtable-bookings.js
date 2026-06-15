// Airtable Bookings Integration
const AIRTABLE_BASE_ID = 'apptYNRJTXwItvied'; // Curbin base
const AIRTABLE_TABLE_ID = 'tblKMhGnYjsH0z7Lj'; // Bookings table
const AIRTABLE_API_KEY = 'patxbDkv88pOMXmYx.c7e5fd7974954e3a674087090835d11dd69504f3912f0ef86c3c59f1e91febdd'; // Embedded API key for automatic booking saves

function getAirtableApiKey() {
  // Always return the embedded API key
  return AIRTABLE_API_KEY;
}

function generateBookingId() {
  // 5-char alphanumeric (A-Z, 0-9) = 60M+ combos — unique enough, easy to read over phone
  const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // no I/O/1/0 to avoid confusion
  let id = '';
  const rnd = crypto.getRandomValues(new Uint8Array(5));
  for (let i = 0; i < 5; i++) id += chars[rnd[i] % chars.length];
  return id;
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
    
    // 2. Save Order record(s)
    const ORDERS_TABLE_ID = 'tblGhNRi3ENwVpNty';
    let orderCount = 0;
    
    if (bookingData.frequency === 'recurring') {
      // Single recurring order record
      const dayOfWeekMap = {
        'Monday': 'Monday',
        'Tuesday': 'Tuesday',
        'Wednesday': 'Wednesday',
        'Thursday': 'Thursday',
        'Friday': 'Friday',
        'Saturday': 'Saturday',
        'Sunday': 'Sunday'
      };
      
      // Get day of week from address schedule (if available)
      const dayOfWeek = bookingData.dayOfWeek || 'Tuesday'; // Default to Tuesday
      
      const recurringOrder = {
        fields: {
          'Order ID': bookingId + '-R',
          'Booking ID': bookingId,
          'Service Type': bookingData.serviceType || '',
          'Frequency': 'Recurring',
          'Day of Week': dayOfWeek,
          'Status': 'Active',
          'Created At': todayISO
        }
      };
      
      try {
        const orderResponse = await fetch(`https://api.airtable.com/v0/${AIRTABLE_BASE_ID}/${ORDERS_TABLE_ID}`, {
          method: 'POST',
          headers: {
            'Authorization': `Bearer ${AIRTABLE_API_KEY}`,
            'Content-Type': 'application/json'
          },
          body: JSON.stringify(recurringOrder)
        });
        
        if (orderResponse.ok) {
          orderCount = 1;
          console.log('✅ Recurring order created:', recurringOrder.fields['Order ID']);
        } else {
          const error = await orderResponse.json();
          console.warn('Failed to save recurring order:', error);
        }
      } catch (err) {
        console.warn('Error saving recurring order:', err);
      }
    } else if (bookingData.selectedDates && bookingData.selectedDates.length > 0) {
      // Multiple ad hoc order records (one per date)
      const ordersToSave = bookingData.selectedDates.map(dateStr => ({
        fields: {
          'Order ID': bookingId + '-' + dateStr.replace(/-/g, '').slice(4),
          'Booking ID': bookingId,
          'Service Date': dateStr,
          'Service Type': bookingData.serviceType || '',
          'Frequency': 'Ad Hoc',
          'Status': 'Pending',
          'Created At': todayISO
        }
      }));
      
      console.log(`Saving ${ordersToSave.length} ad hoc order records...`);
      
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
            console.log('✅ Ad hoc order saved:', orderRecord.fields['Order ID']);
          } else {
            const error = await orderResponse.json();
            console.warn('Failed to save ad hoc order:', error);
          }
        } catch (err) {
          console.warn('Error saving ad hoc order:', err);
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
