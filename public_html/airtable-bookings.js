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
      'Stripe Subscription ID': bookingData.stripeSubscriptionId || '',
      'Stripe Customer ID': bookingData.stripeCustomerId || '',
      'Billing Type': bookingData.frequency === 'recurring' ? 'Recurring Subscription' : 'One-Time Charge',
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
    // BUSINESS LOGIC:
    //   - "Service date" in booking = city pickup day (bins emptied ~7am)
    //   - Roll Out must happen EVENING BEFORE pickup → order date = pickup date - 1
    //   - Roll In must happen AFTERNOON OF pickup → order date = pickup date
    //   - "Both" creates TWO orders per pickup date (Roll Out day before + Roll In same day)
    const ORDERS_TABLE_ID = 'tblGhNRi3ENwVpNty';
    let orderCount = 0;

    // Helper: shift a YYYY-MM-DD string back by 1 day
    function dayBefore(dateStr) {
      const d = new Date(dateStr + 'T12:00:00'); // noon to avoid DST edge
      d.setDate(d.getDate() - 1);
      return d.toISOString().split('T')[0];
    }

    // Helper: get previous day name (e.g. Tuesday → Monday)
    const DAYS = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
    function dayOfWeekBefore(dayName) {
      const idx = DAYS.indexOf(dayName);
      return DAYS[(idx + 6) % 7]; // wrap Saturday → Friday, Sunday → Saturday
    }

    // Determine which service types to create orders for
    const svcType = bookingData.serviceType || 'rollout';
    const isNightZone = bookingData.isNightZone || false;
    const serviceJobs = [];
    if (svcType === 'both' || svcType === 'rollout') serviceJobs.push('Roll Out');
    if (svcType === 'both' || svcType === 'rollin')  serviceJobs.push('Roll In');

    // Helper: save a single order record to Airtable
    async function saveOrder(fields) {
      try {
        const res = await fetch(`https://api.airtable.com/v0/${AIRTABLE_BASE_ID}/${ORDERS_TABLE_ID}`, {
          method: 'POST',
          headers: { 'Authorization': `Bearer ${AIRTABLE_API_KEY}`, 'Content-Type': 'application/json' },
          body: JSON.stringify({ fields })
        });
        if (res.ok) {
          orderCount++;
          console.log('✅ Order saved:', fields['Order ID'], fields['Service Type'], fields['Service Date'] || fields['Day of Week']);
        } else {
          const err = await res.json();
          console.warn('Failed to save order:', fields['Order ID'], err);
        }
      } catch (err) {
        console.warn('Error saving order:', fields['Order ID'], err);
      }
    }

    if (bookingData.frequency === 'recurring') {
      const pickupDay = bookingData.dayOfWeek || 'Tuesday';

      for (const job of serviceJobs) {
        const suffix = job === 'Roll Out' ? 'RO' : 'RI';
        // Night zone: Roll Out happens same evening as collection (not day before)
        const workDay = (job === 'Roll Out' && !isNightZone) ? dayOfWeekBefore(pickupDay) : pickupDay;

        await saveOrder({
          'Order ID': bookingId + '-' + suffix,
          'Booking ID': bookingId,
          'Service Type': job,
          'Frequency': 'Recurring',
          'Day of Week': workDay,
          'Status': 'Active',
          'Created At': todayISO
        });
      }

    } else if (bookingData.selectedDates && bookingData.selectedDates.length > 0) {

      for (const pickupDate of bookingData.selectedDates) {
        for (const job of serviceJobs) {
          const suffix = job === 'Roll Out' ? 'RO' : 'RI';
          // Night zone: Roll Out happens same evening as collection (not day before)
          const workDate = (job === 'Roll Out' && !isNightZone) ? dayBefore(pickupDate) : pickupDate;
          const dateTag = workDate.replace(/-/g, '').slice(4); // MMDD

          await saveOrder({
            'Order ID': bookingId + '-' + dateTag + suffix,
            'Booking ID': bookingId,
            'Service Date': workDate,
            'Service Type': job,
            'Frequency': 'Ad Hoc',
            'Status': 'Pending',
            'Created At': todayISO
          });
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
