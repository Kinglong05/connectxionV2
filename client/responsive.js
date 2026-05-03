/**
 * ConnectXion Mobile Responsiveness Controller
 */

document.addEventListener('DOMContentLoaded', () => {
    // 1. Inject Hamburger Menu into Headers if not present
    const headers = document.querySelectorAll('.chat-header, .chats-header, .content-header');
    headers.forEach(header => {
        if (!header.querySelector('.mobile-menu-toggle')) {
            const toggle = document.createElement('div');
            toggle.className = 'mobile-menu-toggle';
            toggle.innerHTML = '☰';
            toggle.style.display = 'none'; // Controlled by CSS media queries
            
            // Insert at the beginning
            header.insertBefore(toggle, header.firstChild);
            
            toggle.addEventListener('click', toggleSidebar);
        }
    });

    // 2. Create Mobile Overlay
    if (!document.querySelector('.mobile-overlay')) {
        const overlay = document.createElement('div');
        overlay.className = 'mobile-overlay';
        document.body.appendChild(overlay);
        overlay.addEventListener('click', toggleSidebar);
    }

    function toggleSidebar() {
        const sidebars = document.querySelectorAll('.chats-sidebar, .sidebar, .nav-sidebar, .chats-sidebar-gaming');
        const overlay = document.querySelector('.mobile-overlay');

        sidebars.forEach(sidebar => sidebar.classList.toggle('active'));
        if (overlay) overlay.classList.toggle('active');
        
        // Prevent body scroll when menu is open
        const anyActive = Array.from(sidebars).some(s => s.classList.contains('active'));
        document.body.style.overflow = anyActive ? 'hidden' : '';
    }
});
