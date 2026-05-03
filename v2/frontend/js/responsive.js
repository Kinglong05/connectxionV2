/* ConnectXion v2.0 - Responsive Logic */
document.addEventListener('DOMContentLoaded', () => {
    const chatHeader = document.querySelector('.chat-header');
    const sidebar = document.querySelector('.sidebar');
    
    if (chatHeader && sidebar && !chatHeader.querySelector('.mobile-menu-toggle')) {
        const toggle = document.createElement('div');
        toggle.className = 'mobile-menu-toggle';
        toggle.innerHTML = '☰';
        chatHeader.prepend(toggle);
        
        toggle.addEventListener('click', (e) => {
            e.stopPropagation();
            sidebar.classList.toggle('active');
        });

        // Close sidebar when clicking main chat
        document.querySelector('.main-chat').addEventListener('click', () => {
            sidebar.classList.remove('active');
        });
        
        // Close sidebar when clicking a chat item
        document.querySelectorAll('.chat-item').forEach(item => {
            item.addEventListener('click', () => {
                sidebar.classList.remove('active');
            });
        });
    }
});
