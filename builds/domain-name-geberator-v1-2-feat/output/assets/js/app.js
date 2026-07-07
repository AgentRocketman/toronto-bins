class DomainGenerator {
    constructor() {
        this.currentGenerationId = null;
        this.pollingInterval = null;
        this.favorites = this.loadFavorites();
        this.lastGenerationTime = 0;
        this.rateLimitMs = 3000;

        this.init();
    }

    init() {
        this.cacheElements();
        this.attachEventListeners();
        this.updateCharCounter();
        this.displayFavorites();
    }

    cacheElements() {
        this.elements = {
            businessDescription: document.getElementById('business-description'),
            charCount: document.getElementById('char-count'),
            generateBtn: document.getElementById('generate-btn'),
            regenerateBtn: document.getElementById('regenerate-btn'),
            errorMessage: document.getElementById('error-message'),
            resultsSection: document.getElementById('results-section'),
            resultsContainer: document.getElementById('results-container'),
            favoritesSection: document.getElementById('favorites-section'),
            favoritesContainer: document.getElementById('favorites-container'),
            btnText: document.querySelector('.btn-text'),
            btnLoader: document.querySelector('.btn-loader')
        };
    }

    attachEventListeners() {
        this.elements.businessDescription.addEventListener('input', () => {
            this.updateCharCounter();
            this.hideError();
        });

        this.elements.generateBtn.addEventListener('click', (e) => {
            e.preventDefault();
            this.generateDomains();
        });

        this.elements.regenerateBtn.addEventListener('click', (e) => {
            e.preventDefault();
            this.generateDomains();
        });

        // Allow Enter key to trigger generation
        this.elements.businessDescription.addEventListener('keypress', (e) => {
            if (e.key === 'Enter' && e.ctrlKey) {
                e.preventDefault();
                this.generateDomains();
            }
        });

        document.querySelectorAll('input[name="tld"]').forEach(radio => {
            radio.addEventListener('change', () => {
                if (this.currentGenerationId) {
                    this.generateDomains();
                }
            });
        });
    }

    updateCharCounter() {
        const length = this.elements.businessDescription.value.length;
        this.elements.charCount.textContent = length;
    }

    validateInput() {
        const description = this.elements.businessDescription.value.trim();

        if (description.length < 10) {
            this.showError('Description required (minimum 10 characters)');
            return false;
        }

        if (description.length > 500) {
            this.showError('Description too long (maximum 500 characters)');
            return false;
        }

        const now = Date.now();
        if (now - this.lastGenerationTime < this.rateLimitMs) {
            this.showError('Please wait a moment before generating again');
            return false;
        }

        return true;
    }

    async generateDomains() {
        console.log('Generate button clicked');

        if (!this.validateInput()) {
            console.log('Validation failed');
            return;
        }

        this.lastGenerationTime = Date.now();
        this.setLoading(true);
        this.hideError();
        this.stopPolling();

        const description = this.elements.businessDescription.value.trim();
        const tld = document.querySelector('input[name="tld"]:checked').value;

        console.log('Generating domains:', { description, tld });

        try {
            const response = await fetch('api/generate.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ description, tld })
            });

            console.log('Response status:', response.status);

            const data = await response.json();
            console.log('Response data:', data);

            if (!response.ok) {
                throw new Error(data.error || 'Generation failed');
            }

            if (!data.success || !data.domains || !Array.isArray(data.domains)) {
                throw new Error('Invalid response format from server');
            }

            this.currentGenerationId = data.generation_id;
            this.displayResults(data.domains, tld);
            this.startPolling();

            console.log('Domain generation successful, polling started');

        } catch (error) {
            this.showError(error.message || 'Failed to generate domains. Please try again.');
            console.error('Generation error:', error);
        } finally {
            this.setLoading(false);
        }
    }

    displayResults(domains, tld) {
        this.elements.resultsContainer.innerHTML = '';
        this.elements.resultsSection.style.display = 'block';

        domains.forEach((domain, index) => {
            const domainFull = `${domain}.${tld}`;
            const item = this.createDomainItem(domainFull, index);
            this.elements.resultsContainer.appendChild(item);
        });
    }

    createDomainItem(domain, index) {
        const div = document.createElement('div');
        div.className = 'domain-item';
        div.dataset.domain = domain;
        div.dataset.index = index;

        const isFavorite = this.favorites.includes(domain);

        div.innerHTML = `
            <div class="domain-info">
                <span class="domain-name">${this.escapeHtml(domain)}</span>
            </div>
            <div class="domain-actions">
                <div class="status-indicator status-checking" data-status="checking">
                    <span class="spinner"></span>
                </div>
                <button class="favorite-btn ${isFavorite ? 'active' : ''}"
                        data-domain="${this.escapeHtml(domain)}"
                        title="${isFavorite ? 'Remove from favorites' : 'Add to favorites'}">
                    ${isFavorite ? '★' : '☆'}
                </button>
            </div>
        `;

        const favoriteBtn = div.querySelector('.favorite-btn');
        favoriteBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            this.toggleFavorite(domain, favoriteBtn);
        });

        return div;
    }

    startPolling() {
        this.stopPolling();

        this.pollingInterval = setInterval(() => {
            this.checkAvailability();
        }, 2000);

        this.checkAvailability();
    }

    stopPolling() {
        if (this.pollingInterval) {
            clearInterval(this.pollingInterval);
            this.pollingInterval = null;
        }
    }

    async checkAvailability() {
        if (!this.currentGenerationId) {
            this.stopPolling();
            return;
        }

        try {
            const response = await fetch(`api/poll-results.php?generation_id=${this.currentGenerationId}`);
            const data = await response.json();

            console.log('Polling response:', data);

            if (!response.ok) {
                throw new Error(data.error || 'Failed to check availability');
            }

            if (data.results && Array.isArray(data.results)) {
                this.updateDomainStatuses(data.results);
                console.log('Updated domain statuses:', data.results.length, 'domains');
            }

            if (data.status === 'complete' || data.status === 'error') {
                console.log('Availability checking complete, stopping polling');
                this.stopPolling();
            }

        } catch (error) {
            console.error('Polling error:', error);
        }
    }

    updateDomainStatuses(results) {
        results.forEach(result => {
            const domainItem = document.querySelector(`.domain-item[data-domain="${result.domain}"]`);
            if (!domainItem) return;

            const statusIndicator = domainItem.querySelector('.status-indicator');
            statusIndicator.className = 'status-indicator';

            switch (result.status) {
                case 'available':
                    statusIndicator.classList.add('status-available');
                    statusIndicator.innerHTML = '✓';
                    statusIndicator.dataset.status = 'available';
                    break;
                case 'taken':
                    statusIndicator.classList.add('status-taken');
                    statusIndicator.innerHTML = '✗';
                    statusIndicator.dataset.status = 'taken';
                    break;
                case 'error':
                    statusIndicator.classList.add('status-error');
                    statusIndicator.innerHTML = '?';
                    statusIndicator.dataset.status = 'error';
                    break;
                default:
                    statusIndicator.classList.add('status-checking');
                    statusIndicator.innerHTML = '<span class="spinner"></span>';
                    statusIndicator.dataset.status = 'checking';
            }
        });
    }

    toggleFavorite(domain, button) {
        const index = this.favorites.indexOf(domain);

        if (index > -1) {
            this.favorites.splice(index, 1);
            button.classList.remove('active');
            button.innerHTML = '☆';
            button.title = 'Add to favorites';
        } else {
            this.favorites.push(domain);
            button.classList.add('active');
            button.innerHTML = '★';
            button.title = 'Remove from favorites';
        }

        this.saveFavorites();
        this.displayFavorites();
    }

    loadFavorites() {
        try {
            const stored = localStorage.getItem('domain_favorites');
            return stored ? JSON.parse(stored) : [];
        } catch (error) {
            console.error('Failed to load favorites:', error);
            return [];
        }
    }

    saveFavorites() {
        try {
            localStorage.setItem('domain_favorites', JSON.stringify(this.favorites));
        } catch (error) {
            console.error('Failed to save favorites:', error);
        }
    }

    displayFavorites() {
        const container = this.elements.favoritesContainer;

        if (this.favorites.length === 0) {
            this.elements.favoritesSection.style.display = 'none';
            return;
        }

        this.elements.favoritesSection.style.display = 'block';
        container.innerHTML = '';

        this.favorites.forEach(domain => {
            const item = document.createElement('div');
            item.className = 'favorite-item';

            item.innerHTML = `
                <div class="favorite-info">
                    <div class="favorite-domain">${this.escapeHtml(domain)}</div>
                </div>
                <button class="remove-favorite-btn" data-domain="${this.escapeHtml(domain)}" title="Remove from favorites">
                    ✗
                </button>
            `;

            const removeBtn = item.querySelector('.remove-favorite-btn');
            removeBtn.addEventListener('click', () => {
                this.removeFavorite(domain);
            });

            container.appendChild(item);
        });
    }

    removeFavorite(domain) {
        const index = this.favorites.indexOf(domain);
        if (index > -1) {
            this.favorites.splice(index, 1);
            this.saveFavorites();
            this.displayFavorites();

            const favoriteBtn = document.querySelector(`.favorite-btn[data-domain="${domain}"]`);
            if (favoriteBtn) {
                favoriteBtn.classList.remove('active');
                favoriteBtn.innerHTML = '☆';
                favoriteBtn.title = 'Add to favorites';
            }
        }
    }

    setLoading(loading) {
        this.elements.generateBtn.disabled = loading;

        if (loading) {
            this.elements.btnText.style.display = 'none';
            this.elements.btnLoader.style.display = 'inline-block';
        } else {
            this.elements.btnText.style.display = 'inline';
            this.elements.btnLoader.style.display = 'none';
        }
    }

    showError(message) {
        this.elements.errorMessage.textContent = message;
        this.elements.errorMessage.style.display = 'block';
    }

    hideError() {
        this.elements.errorMessage.style.display = 'none';
    }

    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
}

document.addEventListener('DOMContentLoaded', () => {
    new DomainGenerator();
});
