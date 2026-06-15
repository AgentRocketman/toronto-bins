// Email Service - Hostinger SMTP via /api/send-confirmation.php

async function sendConfirmationEmail(bookingData, bookingId) {
  try {
    const svcType = bookingData.serviceType || 'rollout';
    const hasRollOut = svcType === 'both' || svcType === 'rollout';
    const hasRollIn  = svcType === 'both' || svcType === 'rollin';

    // Helper: shift date back 1 day
    function dayBefore(dateStr) {
      const d = new Date(dateStr + 'T12:00:00');
      d.setDate(d.getDate() - 1);
      return d.toISOString().split('T')[0];
    }

    // Helper: format date nicely
    function fmtDate(dateStr) {
      return new Date(dateStr + 'T12:00:00').toLocaleDateString('en-CA', {
        weekday: 'long', month: 'long', day: 'numeric'
      });
    }

    // Helper: get previous day name
    const DAYS = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
    function prevDay(dayName) {
      const idx = DAYS.indexOf(dayName);
      return DAYS[(idx + 6) % 7];
    }

    // Build schedule breakdown for the email
    let scheduleLines = [];

    if (bookingData.frequency === 'recurring') {
      const pickupDay = bookingData.dayOfWeek || 'Tuesday';
      if (hasRollOut) {
        scheduleLines.push('🗑️ Roll Out: Every ' + prevDay(pickupDay) + ' evening');
      }
      if (hasRollIn) {
        scheduleLines.push('♻️ Roll In: Every ' + pickupDay + ' afternoon');
      }
    } else if (bookingData.selectedDates && bookingData.selectedDates.length > 0) {
      for (const pickupDate of bookingData.selectedDates) {
        if (hasRollOut) {
          const roDate = dayBefore(pickupDate);
          scheduleLines.push('🗑️ Roll Out: ' + fmtDate(roDate) + ' (evening)');
        }
        if (hasRollIn) {
          scheduleLines.push('♻️ Roll In: ' + fmtDate(pickupDate) + ' (afternoon)');
        }
      }
    }

    const res = await fetch('/api/send-confirmation.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        customerEmail: bookingData.customerEmail,
        customerName: bookingData.customerName,
        customerPhone: bookingData.customerPhone || '',
        address: bookingData.address,
        serviceType: bookingData.serviceType,
        frequency: bookingData.frequency,
        amount: bookingData.amount,
        subtotal: bookingData.subtotal || null,
        hstAmount: bookingData.hstAmount || null,
        totalWithTax: bookingData.totalWithTax || null,
        bookingId: bookingId,
        scheduleLines: scheduleLines
      })
    });

    const data = await res.json();

    if (data.success) {
      console.log('✅ Confirmation email sent:', data.message);
    } else {
      console.warn('⚠️ Confirmation email failed:', data.error);
    }

    return data;
  } catch (err) {
    console.error('Email service error:', err);
    return { success: false, reason: err.message };
  }
}
