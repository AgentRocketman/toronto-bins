// Email Service - Hostinger SMTP via /api/send-confirmation.php

async function sendConfirmationEmail(bookingData, bookingId) {
  try {
    // Format service dates for the email
    let serviceDatesText = '';
    if (bookingData.frequency === 'adhoc' && bookingData.selectedDates && bookingData.selectedDates.length > 0) {
      serviceDatesText = bookingData.selectedDates.map(d => {
        const date = new Date(d + 'T00:00:00');
        return date.toLocaleDateString('en-CA', { weekday: 'short', month: 'short', day: 'numeric' });
      }).join(', ');
    } else if (bookingData.frequency === 'recurring') {
      serviceDatesText = 'Every week (recurring)';
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
        bookingId: bookingId,
        serviceDates: serviceDatesText
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
