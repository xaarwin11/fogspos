<link rel="manifest" href="/manifest.json">
<meta name="theme-color" content="#6B4226">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<link rel="apple-touch-icon" href="/assets/img/favicon.png">
<link rel="icon" type="image/png" href="/assets/img/favicon.png">

<script>
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', () => {
            navigator.serviceWorker.register('/sw.js')
                .then(() => console.log('Offline Engine Active!'))
                .catch(err => console.error('Service Worker Failed:', err));
        });
    }
</script>