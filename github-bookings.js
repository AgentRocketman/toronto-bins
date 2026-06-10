// GitHub Bookings Integration
// Stores booking data to GitHub repository via GitHub API

const GITHUB_OWNER = 'AgentRocketman';
const GITHUB_REPO = 'toronto-bins';
const GITHUB_BRANCH = 'main';
const BOOKINGS_FILE = 'bookings.csv';

let githubToken = sessionStorage.getItem('github_token');

// Request GitHub token if not set
function ensureGitHubToken() {
  if (!githubToken) {
    githubToken = prompt(
      'GitHub Personal Access Token needed to save bookings.\n\n' +
      'Create one at: https://github.com/settings/tokens\n' +
      'Scopes needed: repo (full control of private repositories)\n\n' +
      'Token (will only be stored for this session):'
    );
    
    if (githubToken) {
      sessionStorage.setItem('github_token', githubToken);
      return true;
    }
    return false;
  }
  return true;
}

async function saveBookingToGitHub(bookingData) {
  if (!ensureGitHubToken()) {
    throw new Error('GitHub token required to save booking');
  }

  try {
    // Fetch current file content
    const getUrl = `https://api.github.com/repos/${GITHUB_OWNER}/${GITHUB_REPO}/contents/${BOOKINGS_FILE}`;
    
    let fileContent = '';
    let sha = null;

    try {
      const getResponse = await fetch(getUrl, {
        headers: { 'Authorization': `token ${githubToken}` }
      });

      if (getResponse.ok) {
        const fileData = await getResponse.json();
        fileContent = atob(fileData.content); // Decode base64
        sha = fileData.sha;
      } else if (getResponse.status !== 404) {
        throw new Error(`GitHub API error: ${getResponse.status}`);
      }
      // 404 means file doesn't exist yet, which is fine
    } catch (err) {
      console.error('Error fetching current file:', err);
      // Continue - we'll create a new file
    }

    // Create CSV header if file is empty
    if (!fileContent) {
      fileContent = 'Timestamp,Booking ID,Name,Email,Phone,Address,Service,Frequency,Amount,Stripe Payment ID\n';
    }

    // Append new booking
    const timestamp = new Date().toISOString();
    const bookingId = 'BK-' + Math.random().toString(36).substr(2, 9).toUpperCase();
    
    const csvRow = [
      timestamp,
      bookingId,
      bookingData.customerName || '',
      bookingData.customerEmail || '',
      bookingData.customerPhone || '',
      bookingData.address || '',
      bookingData.serviceType || '',
      bookingData.frequency || '',
      (bookingData.amount / 100).toFixed(2),
      bookingData.stripePaymentId || 'PENDING'
    ].map(field => `"${String(field).replace(/"/g, '""')}"`).join(',');

    fileContent += csvRow + '\n';

    // Upload to GitHub
    const updateUrl = `https://api.github.com/repos/${GITHUB_OWNER}/${GITHUB_REPO}/contents/${BOOKINGS_FILE}`;
    const payload = {
      message: `Add booking: ${bookingData.customerName} (${bookingId})`,
      content: btoa(fileContent), // Encode to base64
      branch: GITHUB_BRANCH,
      ...(sha && { sha }) // Include sha only if file exists
    };

    const updateResponse = await fetch(updateUrl, {
      method: 'PUT',
      headers: {
        'Authorization': `token ${githubToken}`,
        'Content-Type': 'application/json'
      },
      body: JSON.stringify(payload)
    });

    if (!updateResponse.ok) {
      const error = await updateResponse.json();
      throw new Error(`Failed to save booking: ${error.message}`);
    }

    return { success: true, bookingId };
  } catch (err) {
    console.error('Error saving booking to GitHub:', err);
    throw err;
  }
}

// Fallback: store locally if GitHub fails
function saveBookingLocally(bookingData) {
  const bookings = JSON.parse(localStorage.getItem('curbin_bookings') || '[]');
  const bookingId = 'BK-LOCAL-' + Date.now();
  
  bookings.push({
    ...bookingData,
    bookingId,
    timestamp: new Date().toISOString()
  });
  
  localStorage.setItem('curbin_bookings', JSON.stringify(bookings));
  console.log('Booking saved locally:', bookingId);
  return { bookingId, warning: 'Saved locally (not synced to GitHub)' };
}

// Process payment and save booking
async function processBookingPayment(bookingData) {
  if (!bookingData || !bookingData.amount) {
    throw new Error('Invalid booking data');
  }

  try {
    // Create payment method from card
    const { paymentMethod, error } = await stripe.createPaymentMethod({
      type: 'card',
      card: cardElement,
      billing_details: {
        name: bookingData.customerName,
        email: bookingData.customerEmail,
        phone: bookingData.customerPhone
      }
    });

    if (error) {
      showPaymentError(error.message);
      return false;
    }

    // For now, just confirm the payment method was created
    // In production, you'd send this to a backend to create a Stripe charge
    const result = await stripe.confirmCardPayment(
      // NOTE: This requires a Stripe client secret from your backend
      // For demo, we'll just create a charge-like confirmation
      {
        payment_method: paymentMethod.id,
        confirm: true
      }
    );

    // Save booking data with Stripe payment ID
    bookingData.stripePaymentId = paymentMethod.id;

    let bookingResult;
    try {
      // Try to save to GitHub
      bookingResult = await saveBookingToGitHub(bookingData);
    } catch (githubErr) {
      console.warn('GitHub save failed, saving locally:', githubErr);
      // Fall back to local storage
      bookingResult = saveBookingLocally(bookingData);
    }

    return {
      success: true,
      bookingId: bookingResult.bookingId,
      warning: bookingResult.warning
    };
  } catch (err) {
    console.error('Payment processing error:', err);
    showPaymentError(err.message);
    return false;
  }
}

// Export for manual sync to GitHub if local backup exists
function exportLocalBookings() {
  const bookings = JSON.parse(localStorage.getItem('curbin_bookings') || '[]');
  return JSON.stringify(bookings, null, 2);
}
