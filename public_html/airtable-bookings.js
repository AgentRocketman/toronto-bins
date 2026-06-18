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

// Geocode a postal address into a lat/lng using the secure /api/geocode.php endpoint.
// Returns { lat, lng } or null. Cached in sessionStorage per address.
async function geocodeAddressForMap(address) {
  if (!address) return null;
  const cacheKey = 'gc:' + address;
  try {
    const cached = sessionStorage.getItem(cacheKey);
    if (cached) return JSON.parse(cached);
  } catch (_) {}
  try {
    const res = await fetch('/api/geocode.php?address=' + encodeURIComponent(address));
    if (!res.ok) return null;
    const data = await res.json();
    if (data && data.lat && data.lng) {
      sessionStorage.setItem(cacheKey, JSON.stringify({ lat: data.lat, lng: data.lng }));
      return { lat: data.lat, lng: data.lng };
    }
  } catch (e) { console.warn('geocode failed', e); }
  return null;
}

async function saveBookingToAirtable(bookingData) {
  const AIRTABLE_API_KEY = getAirtableApiKey();
  
  if (!AIRTABLE_API_KEY) {
    throw new Error('Airtable API key required. Please provide it when prompted.');
  }
  
  const bookingId = generateBookingId();
  const todayISO = new Date().toISOString().split('T')[0];
  
  // Pull bin-placement data (may be null). Stored as a single JSON string in the
  // "Bin Placement" column on both Bookings and Orders, so we don't need a schema
  // with 9 separate columns. The driver page parses this back when rendering stops.
  const bp = bookingData.binPlacement || null;
  const binFields = {};
  if (bp && bp.hasPin) {
    binFields['Bin Placement'] = JSON.stringify(bp);
  }

  // Geocode the customer's address so the routing page can show the marker at the
  // right spot on the map (previously every booking fell back to downtown Toronto).
  // Prefer cameraLatLng from the bin-placement record if we have it (it's closer to
  // the customer's property than the geocoder result for a generic address).
  let addressLatLng = null;
  if (bp && bp.cameraLatLng && bp.cameraLatLng.lat && bp.cameraLatLng.lng) {
    addressLatLng = { lat: bp.cameraLatLng.lat, lng: bp.cameraLatLng.lng };
  } else if (bookingData.address) {
    addressLatLng = await geocodeAddressForMap(bookingData.address);
  }
  const geoFields = {};
  if (addressLatLng) {
    geoFields['Lat'] = addressLatLng.lat;
    geoFields['Lng'] = addressLatLng.lng;
  }

  // 1. Save Booking record
  const bookingRecord = {
    fields: Object.assign({
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
    }, geoFields, binFields),
    typecast: true
  };

  try {
    // Helper: extract the offending field name from an Airtable UNKNOWN_FIELD_NAME error
    function extractUnknownField(msg) {
      const m = msg && msg.match(/Unknown field name:\s*["']?([^"']+)["']?/i);
      return m ? m[1] : null;
    }

    // POST with retry: strip any rejected field names one at a time until it accepts.
    async function postWithSchemaRetry(fields, maxAttempts = 6) {
      let attempt = 0;
      let currentFields = { ...fields };
      while (attempt < maxAttempts) {
        attempt++;
        const res = await fetch(`https://api.airtable.com/v0/${AIRTABLE_BASE_ID}/${AIRTABLE_TABLE_ID}`, {
          method: 'POST',
          headers: { 'Authorization': `Bearer ${AIRTABLE_API_KEY}`, 'Content-Type': 'application/json' },
          body: JSON.stringify({ fields: currentFields, typecast: true })
        });
        if (res.ok) return res;
        const error = await res.json().catch(() => ({}));
        const msg = error?.error?.message || '';
        const offending = extractUnknownField(msg);
        if (offending && currentFields.hasOwnProperty(offending)) {
          console.warn(`Bookings table missing field "${offending}" — retrying without it.`);
          delete currentFields[offending];
          continue;
        }
        // Not an unknown-field error — surface it.
        throw new Error(`Airtable error: ${msg || 'Unknown error'}`);
      }
      throw new Error('Airtable error: too many missing fields, giving up.');
    }

    const bookingResponse = await postWithSchemaRetry(bookingRecord.fields);

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
      // Add bin placement + geocoded lat/lng onto every Order row so the driver
      // page can both pin the bin location and map the marker at the correct
      // address without needing to look up the Booking record.
      const fullFields = Object.assign({}, fields, geoFields, binFields);
      // Schema-tolerant retry: strip any UNKNOWN_FIELD_NAME and re-POST.
      function extractUnknownField(msg) {
        const m = msg && msg.match(/Unknown field name:\s*["']?([^"']+)["']?/i);
        return m ? m[1] : null;
      }
      let attempt = 0;
      let currentFields = { ...fullFields };
      while (attempt < 6) {
        attempt++;
        try {
          const res = await fetch(`https://api.airtable.com/v0/${AIRTABLE_BASE_ID}/${ORDERS_TABLE_ID}`, {
            method: 'POST',
            headers: { 'Authorization': `Bearer ${AIRTABLE_API_KEY}`, 'Content-Type': 'application/json' },
            body: JSON.stringify({ fields: currentFields, typecast: true })
          });
          if (res.ok) {
            orderCount++;
            console.log('✅ Order saved:', currentFields['Order ID'], currentFields['Service Type'], currentFields['Service Date'] || currentFields['Day of Week']);
            return;
          }
          const err = await res.json().catch(() => ({}));
          const msg = err?.error?.message || '';
          const offending = extractUnknownField(msg);
          if (offending && currentFields.hasOwnProperty(offending)) {
            console.warn(`Orders table missing field "${offending}" — retrying without it.`);
            delete currentFields[offending];
            continue;
          }
          console.warn('Failed to save order:', currentFields['Order ID'], err);
          return;
        } catch (err) {
          console.warn('Error saving order:', currentFields['Order ID'], err);
          return;
        }
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
    let bookings = JSON.parse(localStorage.getItem('getmybin_bookings') || '[]');
    bookings.push(booking);
    localStorage.setItem('getmybin_bookings', JSON.stringify(bookings));
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
