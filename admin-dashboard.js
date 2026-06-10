// Admin Dashboard
const AIRTABLE_BASE_ID = 'apptYNRJTXwItvied';
const BOOKINGS_TABLE_ID = 'tblKMhGnYjsH0z7Lj';
const ORDERS_TABLE_ID = 'tblGhNRi3ENwVpNty';

let allOrders = [];
let allBookings = {};
let selectedOrderId = null;

function getAirtableApiKey() {
  let key = sessionStorage.getItem('airtable_api_key');
  if (key) return key;
  key = prompt('Enter your Airtable API key:');
  if (key) sessionStorage.setItem('airtable_api_key', key);
  return key;
}

async function fetchBookings() {
  const apiKey = getAirtableApiKey();
  if (!apiKey) return {};

  try {
    const response = await fetch(`https://api.airtable.com/v0/${AIRTABLE_BASE_ID}/${BOOKINGS_TABLE_ID}`, {
      headers: { 'Authorization': `Bearer ${apiKey}` }
    });
    const data = await response.json();
    const bookings = {};
    data.records.forEach(record => {
      bookings[record.fields['Booking ID']] = record.fields;
    });
    return bookings;
  } catch (err) {
    console.error('Error fetching bookings:', err);
    return {};
  }
}

async function fetchOrders() {
  const apiKey = getAirtableApiKey();
  if (!apiKey) return [];

  try {
    const response = await fetch(`https://api.airtable.com/v0/${AIRTABLE_BASE_ID}/${ORDERS_TABLE_ID}`, {
      headers: { 'Authorization': `Bearer ${apiKey}` }
    });
    const data = await response.json();
    return data.records.map(record => ({
      id: record.id,
      ...record.fields
    }));
  } catch (err) {
    console.error('Error fetching orders:', err);
    return [];
  }
}

function expandRecurringOrders(orders) {
  // Generate dates for recurring orders
  const expanded = [];
  const today = new Date();
  const weeks = 12;

  orders.forEach(order => {
    if (order.Frequency === 'Recurring' && order['Day of Week']) {
      // Generate next 12 weeks of this day
      const dayMap = { 'Monday': 1, 'Tuesday': 2, 'Wednesday': 3, 'Thursday': 4, 'Friday': 5, 'Saturday': 6, 'Sunday': 0 };
      const targetDay = dayMap[order['Day of Week']];

      let current = new Date(today);
      for (let i = 0; i < weeks; i++) {
        // Find next occurrence of target day
        while (current.getDay() !== targetDay) {
          current.setDate(current.getDate() + 1);
        }
        
        expanded.push({
          'Order ID': order['Order ID'] + '-' + formatDate(current),
          'Booking ID': order['Booking ID'],
          'Service Date': formatDate(current),
          'Service Type': order['Service Type'],
          'Frequency': 'Recurring',
          'Day of Week': order['Day of Week'],
          'Status': order['Status'],
          'isExpanded': true,
          'originalOrderId': order['Order ID']
        });

        current.setDate(current.getDate() + 7); // Next week
      }
    } else if (order.Frequency === 'Ad Hoc') {
      expanded.push(order);
    }
  });

  return expanded.sort((a, b) => {
    const dateA = new Date(a['Service Date']);
    const dateB = new Date(b['Service Date']);
    return dateA - dateB;
  });
}

function formatDate(date) {
  return date.getFullYear() + '-' + String(date.getMonth() + 1).padStart(2, '0') + '-' + String(date.getDate()).padStart(2, '0');
}

function formatDateNice(dateStr) {
  const d = new Date(dateStr + 'T00:00:00');
  return d.toLocaleDateString('en-CA', { month: 'short', day: 'numeric' });
}

async function loadData() {
  document.getElementById('loading').style.display = 'block';
  document.getElementById('orders-table').style.display = 'none';
  document.getElementById('empty').style.display = 'none';

  allBookings = await fetchBookings();
  const orders = await fetchOrders();
  allOrders = expandRecurringOrders(orders);

  document.getElementById('loading').style.display = 'none';

  if (allOrders.length === 0) {
    document.getElementById('empty').style.display = 'block';
  } else {
    document.getElementById('orders-table').style.display = 'table';
    renderOrders(allOrders);
    updateStats();
  }
}

function renderOrders(orders) {
  const tbody = document.getElementById('orders-body');
  tbody.innerHTML = '';

  orders.forEach(order => {
    const booking = allBookings[order['Booking ID']] || {};
    const row = document.createElement('tr');
    
    const statusClass = {
      'Pending': 'status-pending',
      'Completed': 'status-completed',
      'Active': 'status-active',
      'Cancelled': 'status-cancelled'
    }[order['Status']] || 'status-pending';

    const serviceDate = order['Service Date'] ? formatDateNice(order['Service Date']) : 'Recurring';
    
    row.innerHTML = `
      <td><strong>${order['Order ID'].substring(0, 20)}</strong></td>
      <td>
        <div><strong>${booking['Customer Name'] || 'N/A'}</strong></div>
        <div style="font-size:0.8rem;color:var(--slate-light)">${booking['Email'] || ''}</div>
      </td>
      <td style="font-size:0.85rem">${booking['Address'] || 'N/A'}</td>
      <td><span class="date-badge">${serviceDate}</span></td>
      <td>${order['Frequency'] || 'N/A'}</td>
      <td>${order['Service Type'] || 'N/A'}</td>
      <td><strong>$${(booking['Amount'] || 0).toFixed(2)}</strong></td>
      <td><span class="status-badge ${statusClass}">${order['Status']}</span></td>
      <td>
        <div class="actions">
          <button class="action-btn" onclick="editOrder('${order['Order ID']}', '${order['Status']}')">Edit</button>
        </div>
      </td>
    `;
    tbody.appendChild(row);
  });
}

function updateStats() {
  const pending = allOrders.filter(o => o['Status'] === 'Pending').length;
  const completed = allOrders.filter(o => o['Status'] === 'Completed' && isThisMonth(o['Service Date'])).length;
  const active = allOrders.filter(o => o['Status'] === 'Active').length;
  
  let totalRevenue = 0;
  Object.values(allBookings).forEach(booking => {
    totalRevenue += (booking['Amount'] || 0);
  });

  document.getElementById('pending-count').textContent = pending;
  document.getElementById('completed-count').textContent = completed;
  document.getElementById('active-count').textContent = active;
  document.getElementById('total-revenue').textContent = '$' + totalRevenue.toFixed(2);
}

function isThisMonth(dateStr) {
  if (!dateStr) return false;
  const date = new Date(dateStr);
  const now = new Date();
  return date.getMonth() === now.getMonth() && date.getFullYear() === now.getFullYear();
}

function applyFilters() {
  const statusFilter = document.getElementById('status-filter').value;
  const typeFilter = document.getElementById('type-filter').value;
  const dateFilter = document.getElementById('date-filter').value;

  let filtered = allOrders.filter(order => {
    if (statusFilter && order['Status'] !== statusFilter) return false;
    if (typeFilter && order['Frequency'] !== typeFilter) return false;
    if (dateFilter && order['Service Date'] && order['Service Date'] < dateFilter) return false;
    return true;
  });

  renderOrders(filtered);
}

function editOrder(orderId, currentStatus) {
  selectedOrderId = orderId;
  document.getElementById('modal-order-id').textContent = orderId;
  document.getElementById('modal-status').value = currentStatus;
  document.getElementById('status-modal').classList.add('open');
}

function closeModal() {
  document.getElementById('status-modal').classList.remove('open');
  selectedOrderId = null;
}

async function updateOrderStatus() {
  if (!selectedOrderId) return;

  const newStatus = document.getElementById('modal-status').value;
  const apiKey = getAirtableApiKey();
  if (!apiKey) return;

  // Find the order record ID in Airtable
  const order = allOrders.find(o => o['Order ID'] === selectedOrderId);
  if (!order || !order.id) {
    alert('Order not found');
    return;
  }

  try {
    // We need the actual Airtable record ID, which we lost. For now, we'd need to refetch.
    // This is a limitation of the API - we should store the record ID when fetching.
    alert('Status update feature coming soon - for now, update directly in Airtable');
    closeModal();
  } catch (err) {
    console.error('Error updating order:', err);
    alert('Failed to update order status');
  }
}

function refreshData() {
  loadData();
}

function exportData() {
  let csv = 'Order ID,Customer,Email,Address,Service Date,Type,Service,Amount,Status\n';
  
  allOrders.forEach(order => {
    const booking = allBookings[order['Booking ID']] || {};
    csv += `"${order['Order ID']}","${booking['Customer Name'] || ''}","${booking['Email'] || ''}","${booking['Address'] || ''}","${order['Service Date'] || ''}","${order['Frequency'] || ''}","${order['Service Type'] || ''}","${booking['Amount'] || 0}","${order['Status'] || ''}"\n`;
  });

  const blob = new Blob([csv], { type: 'text/csv' });
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = 'curbin-orders-' + new Date().toISOString().split('T')[0] + '.csv';
  a.click();
}

// Load on page load
document.addEventListener('DOMContentLoaded', loadData);
