// Mission Control - Shared App Logic

const API_BASE = '/mission-control/api';

// Toast notification helper
function showToast(message, type = 'success') {
    const toast = document.createElement('div');
    toast.className = `toast ${type}`;
    toast.textContent = message;
    document.body.appendChild(toast);

    setTimeout(() => {
        toast.remove();
    }, 3000);
}

// API request helper
async function apiRequest(endpoint, options = {}) {
    try {
        const response = await fetch(`${API_BASE}${endpoint}`, {
            ...options,
            headers: {
                'Content-Type': 'application/json',
                ...options.headers
            }
        });

        const data = await response.json();

        if (!response.ok) {
            if (response.status === 401 && data.redirect) {
                window.location.href = data.redirect;
                return null;
            }
            throw new Error(data.error || 'Request failed');
        }

        return data;
    } catch (error) {
        showToast(error.message, 'error');
        throw error;
    }
}

// Format currency
function formatCurrency(amount) {
    return '$' + parseFloat(amount).toFixed(2);
}

// Calculate progress percentage based on stage
function getStageProgress(status) {
    const stages = {
        'draft': 0,
        'scout': 16.67,
        'tester': 33.33,
        'reviewer': 50,
        'architect': 66.67,
        'innovator': 83.33,
        'builder': 100,
        'complete': 100,
        'paused': 0,
        'failed': 0
    };
    return stages[status] || 0;
}

// Calculate budget fill class
function getBudgetFillClass(percent) {
    if (percent >= 100) return 'danger';
    if (percent >= 90) return 'warning';
    return '';
}

// Modal helper
class Modal {
    constructor(id) {
        this.element = document.getElementById(id);
        // Wire up ALL .close-btn elements inside this modal (the X header AND any Cancel buttons)
        this.closeBtns = this.element ? this.element.querySelectorAll('.close-btn') : [];

        this.closeBtns.forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.preventDefault();
                this.close();
            });
        });

        this.element?.addEventListener('click', (e) => {
            if (e.target === this.element) {
                this.close();
            }
        });
    }

    open() {
        if (this.element) {
            this.element.classList.add('active');
        }
    }

    close() {
        if (this.element) {
            this.element.classList.remove('active');
        }
    }
}

// SSE Event Stream helper
class EventStream {
    constructor(endpoint, handlers) {
        this.endpoint = endpoint;
        this.handlers = handlers;
        this.eventSource = null;
        this.reconnectAttempts = 0;
        this.maxReconnectAttempts = 5;
    }

    connect() {
        this.eventSource = new EventSource(`${API_BASE}${this.endpoint}`);

        this.eventSource.addEventListener('status', (e) => {
            const data = JSON.parse(e.data);
            if (this.handlers.onStatus) {
                this.handlers.onStatus(data);
            }
            this.reconnectAttempts = 0;
        });

        this.eventSource.addEventListener('approval', (e) => {
            const data = JSON.parse(e.data);
            if (this.handlers.onApproval) {
                this.handlers.onApproval(data);
            }
        });

        this.eventSource.onerror = (e) => {
            console.error('SSE error:', e);
            this.eventSource.close();

            if (this.reconnectAttempts < this.maxReconnectAttempts) {
                this.reconnectAttempts++;
                setTimeout(() => {
                    console.log(`Reconnecting... (attempt ${this.reconnectAttempts})`);
                    this.connect();
                }, 3000);
            }
        };
    }

    disconnect() {
        if (this.eventSource) {
            this.eventSource.close();
            this.eventSource = null;
        }
    }
}

// Logout handler
async function handleLogout() {
    try {
        await apiRequest('/auth.php', { method: 'DELETE' });
        window.location.href = '/mission-control/';
    } catch (error) {
        console.error('Logout failed:', error);
    }
}

// Add logout handler to all logout links
document.addEventListener('DOMContentLoaded', () => {
    const logoutLinks = document.querySelectorAll('a[href="#logout"]');
    logoutLinks.forEach(link => {
        link.addEventListener('click', (e) => {
            e.preventDefault();
            handleLogout();
        });
    });
});
