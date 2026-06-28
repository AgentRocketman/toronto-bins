// Mission Control - Authentication Check

async function checkAuth() {
    try {
        const response = await fetch('/mission-control/api/auth.php', {
            method: 'GET',
            credentials: 'same-origin'
        });

        const data = await response.json();

        if (!data.authenticated) {
            window.location.href = '/mission-control/';
            return false;
        }

        return true;
    } catch (error) {
        console.error('Auth check failed:', error);
        window.location.href = '/mission-control/';
        return false;
    }
}

// Run auth check on protected pages
if (window.location.pathname !== '/mission-control/' &&
    window.location.pathname !== '/mission-control/index.html') {
    checkAuth();
}
