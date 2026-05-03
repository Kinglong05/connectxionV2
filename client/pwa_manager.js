/**
 * ConnectXion PWA Manager
 * Handles Service Worker registration and Install prompt
 */

if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('sw.js')
            .then(reg => {
                console.log('✅ Service Worker registered:', reg.scope);
                
                // Request push permission after registration
                requestNotificationPermission();
            })
            .catch(err => console.error('❌ Service Worker registration failed:', err));
    });
}

// Handle Install Prompt
let deferredPrompt;
window.addEventListener('beforeinstallprompt', (e) => {
    // Prevent the mini-infobar from appearing on mobile
    e.preventDefault();
    // Stash the event so it can be triggered later.
    deferredPrompt = e;
    console.log('✨ PWA Install prompt ready');
    
    // You could show a custom "Install" button here
    showInstallPromotion();
});

function showInstallPromotion() {
    // Logic to show a banner or button to the user
    // For now, we'll just log it. In a real app, you'd show a UI element.
    console.log('🎁 Showing install promotion');
}

async function requestNotificationPermission() {
    if (!('Notification' in window)) {
        console.log('This browser does not support notifications.');
        return;
    }

    if (Notification.permission === 'granted') {
        console.log('🔔 Notifications already granted');
        subscribeUserToPush();
    } else if (Notification.permission !== 'denied') {
        const permission = await Notification.requestPermission();
        if (permission === 'granted') {
            console.log('🔔 Notifications granted');
            subscribeUserToPush();
        }
    }
}

async function subscribeUserToPush() {
    try {
        const registration = await navigator.serviceWorker.ready;
        const subscription = await registration.pushManager.subscribe({
            userVisibleOnly: true,
            applicationServerKey: urlBase64ToUint8Array('BFNreAfmhTobh-au7OPtT700AJ8lg4AdxOdtgzECQdbPFbGbBRPyhUh_IgB1bNy0fR8kQd8lAi07FaCQpYjTwMo')
        });

        console.log('📡 User is subscribed:', JSON.stringify(subscription));

        // Send subscription to server
        await fetch('save_subscription.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(subscription)
        });
    } catch (err) {
        console.error('❌ Failed to subscribe the user:', err);
    }
}

// Helper to convert VAPID key
function urlBase64ToUint8Array(base64String) {
    const padding = '='.repeat((4 - base64String.length % 4) % 4);
    const base64 = (base64String + padding)
        .replace(/\-/g, '+')
        .replace(/_/g, '/');
    const rawData = window.atob(base64);
    const outputArray = new Uint8Array(rawData.length);
    for (let i = 0; i < rawData.length; ++i) {
        outputArray[i] = rawData.charCodeAt(i);
    }
    return outputArray;
}
