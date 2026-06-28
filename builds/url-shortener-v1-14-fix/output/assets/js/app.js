const API_BASE = 'api/';

function updateTime() {
    const timeDisplay = document.getElementById('timeDisplay');
    if (timeDisplay) {
        const now = new Date();
        timeDisplay.textContent = now.toLocaleString();
    }
}

updateTime();
setInterval(updateTime, 1000);

const elements = {
    form: document.getElementById('shortenForm'),
    urlInput: document.getElementById('urlInput'),
    customAlias: document.getElementById('customAlias'),
    shortenBtn: document.getElementById('shortenBtn'),
    result: document.getElementById('result'),
    shortUrlDisplay: document.getElementById('shortUrlDisplay'),
    copyBtn: document.getElementById('copyBtn'),
    qrCodeContainer: document.getElementById('qrCodeContainer'),
    qrCodeImage: document.getElementById('qrCodeImage'),
    shortenAnotherBtn: document.getElementById('shortenAnotherBtn'),
    error: document.getElementById('error'),
    errorText: document.getElementById('errorText'),
    loading: document.getElementById('loading')
};

elements.form.addEventListener('submit', async (e) => {
    e.preventDefault();

    const url = elements.urlInput.value.trim();
    const customAlias = elements.customAlias.value.trim();

    if (!url) {
        showError('Please enter a URL');
        return;
    }

    if (!isValidUrl(url)) {
        showError('Please enter a valid URL (must start with http:// or https://)');
        return;
    }

    hideError();
    hideResult();
    showLoading();

    try {
        const response = await fetch(API_BASE + 'shorten.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({ url, customAlias })
        });

        const data = await response.json();

        if (!response.ok) {
            throw new Error(data.error || 'Failed to shorten URL');
        }

        if (data.success) {
            showResult(data.shortUrl, data.qrCode);
        } else {
            throw new Error(data.error || 'Unknown error');
        }

    } catch (error) {
        showError(error.message);
    } finally {
        hideLoading();
    }
});

elements.copyBtn.addEventListener('click', async () => {
    const shortUrl = elements.shortUrlDisplay.value;

    try {
        if (navigator.clipboard && window.isSecureContext) {
            await navigator.clipboard.writeText(shortUrl);
        } else {
            elements.shortUrlDisplay.select();
            document.execCommand('copy');
        }

        const originalText = elements.copyBtn.textContent;
        elements.copyBtn.textContent = 'Copied!';
        elements.copyBtn.classList.add('copied');

        setTimeout(() => {
            elements.copyBtn.textContent = originalText;
            elements.copyBtn.classList.remove('copied');
        }, 2000);

    } catch (error) {
        showError('Failed to copy to clipboard');
    }
});

elements.shortenAnotherBtn.addEventListener('click', () => {
    elements.form.reset();
    hideResult();
    hideError();
    elements.urlInput.focus();
});

function isValidUrl(string) {
    try {
        const url = new URL(string);
        return url.protocol === 'http:' || url.protocol === 'https:';
    } catch {
        return false;
    }
}

function showResult(shortUrl, qrCode) {
    elements.shortUrlDisplay.value = shortUrl;
    elements.result.classList.remove('hidden');

    if (qrCode) {
        elements.qrCodeImage.src = qrCode;
        elements.qrCodeContainer.classList.remove('hidden');
    } else {
        elements.qrCodeContainer.classList.add('hidden');
    }
}

function hideResult() {
    elements.result.classList.add('hidden');
}

function showError(message) {
    elements.errorText.textContent = message;
    elements.error.classList.remove('hidden');
}

function hideError() {
    elements.error.classList.add('hidden');
}

function showLoading() {
    elements.loading.classList.remove('hidden');
    elements.shortenBtn.disabled = true;
}

function hideLoading() {
    elements.loading.classList.add('hidden');
    elements.shortenBtn.disabled = false;
}
