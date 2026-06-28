const API_BASE = 'api/';

const elements = {
    loading: document.getElementById('loading'),
    error: document.getElementById('error'),
    errorText: document.getElementById('errorText'),
    emptyState: document.getElementById('emptyState'),
    analyticsTable: document.getElementById('analyticsTable'),
    analyticsBody: document.getElementById('analyticsBody'),
    totalCount: document.getElementById('totalCount')
};

async function loadAnalytics() {
    showLoading();
    hideError();
    hideEmptyState();
    hideTable();

    try {
        const response = await fetch(API_BASE + 'stats.php');
        const result = await response.json();

        if (!response.ok) {
            throw new Error(result.error || 'Failed to load analytics');
        }

        if (result.success) {
            if (result.data.length === 0) {
                showEmptyState();
            } else {
                renderTable(result.data);
            }
        } else {
            throw new Error(result.error || 'Unknown error');
        }

    } catch (error) {
        showError(error.message);
    } finally {
        hideLoading();
    }
}

function renderTable(data) {
    elements.totalCount.textContent = data.length;

    elements.analyticsBody.innerHTML = data.map(item => {
        const created = formatDateTime(item.created);
        const lastAccess = item.lastAccess ? formatDateTime(item.lastAccess) : 'Never';
        const customBadge = item.customAlias ? '<span class="badge badge-custom">Custom</span>' : '';
        const shortUrl = window.location.origin + '/' + item.code;

        return `
            <tr>
                <td>
                    <span class="short-code">${escapeHtml(item.code)}</span>
                    ${customBadge}
                </td>
                <td class="url-cell" title="${escapeHtml(item.url)}">
                    ${escapeHtml(item.url)}
                </td>
                <td class="text-center">
                    <span class="click-count">${item.count}</span>
                </td>
                <td>
                    <span class="date-time">${created}</span>
                </td>
                <td>
                    <span class="date-time">${lastAccess}</span>
                </td>
                <td class="text-center">
                    <button class="btn btn-secondary copy-row-btn" onclick="copyShortUrl('${escapeHtml(shortUrl)}')">
                        Copy
                    </button>
                </td>
            </tr>
        `;
    }).join('');

    showTable();
}

function formatDateTime(isoString) {
    const date = new Date(isoString);
    const now = new Date();
    const diffMs = now - date;
    const diffMins = Math.floor(diffMs / 60000);
    const diffHours = Math.floor(diffMs / 3600000);
    const diffDays = Math.floor(diffMs / 86400000);

    if (diffMins < 1) return 'Just now';
    if (diffMins < 60) return `${diffMins}m ago`;
    if (diffHours < 24) return `${diffHours}h ago`;
    if (diffDays < 7) return `${diffDays}d ago`;

    return date.toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric',
        year: date.getFullYear() !== now.getFullYear() ? 'numeric' : undefined
    });
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

async function copyShortUrl(url) {
    try {
        if (navigator.clipboard && window.isSecureContext) {
            await navigator.clipboard.writeText(url);
        } else {
            const input = document.createElement('input');
            input.value = url;
            document.body.appendChild(input);
            input.select();
            document.execCommand('copy');
            document.body.removeChild(input);
        }

        const allBtns = document.querySelectorAll('.copy-row-btn');
        allBtns.forEach(btn => {
            if (btn.textContent === 'Copied!') {
                btn.textContent = 'Copy';
                btn.classList.remove('copied');
            }
        });

        const clickedBtn = event.target;
        const originalText = clickedBtn.textContent;
        clickedBtn.textContent = 'Copied!';
        clickedBtn.classList.add('copied');

        setTimeout(() => {
            clickedBtn.textContent = originalText;
            clickedBtn.classList.remove('copied');
        }, 2000);

    } catch (error) {
        console.error('Copy failed:', error);
    }
}

function showLoading() {
    elements.loading.classList.remove('hidden');
}

function hideLoading() {
    elements.loading.classList.add('hidden');
}

function showError(message) {
    elements.errorText.textContent = message;
    elements.error.classList.remove('hidden');
}

function hideError() {
    elements.error.classList.add('hidden');
}

function showEmptyState() {
    elements.emptyState.classList.remove('hidden');
}

function hideEmptyState() {
    elements.emptyState.classList.add('hidden');
}

function showTable() {
    elements.analyticsTable.classList.remove('hidden');
}

function hideTable() {
    elements.analyticsTable.classList.add('hidden');
}

function updateDateTime() {
    const now = new Date();
    const dateTimeStr = now.toLocaleString('en-US', {
        weekday: 'short',
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        hour12: true
    });
    document.getElementById('dateTime').textContent = dateTimeStr;
}

updateDateTime();
setInterval(updateDateTime, 1000);

loadAnalytics();
