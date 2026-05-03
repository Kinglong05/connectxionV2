// ============================================
// REAL-TIME HUB SCRIPT
// ============================================

let socket;
const userId = typeof HUB_CONFIG !== 'undefined' ? HUB_CONFIG.userId : 0;

function connectSocket() {
    socket = io(window.location.protocol + "//" + window.location.hostname + ":3000");

    socket.on('connect', () => {
        console.log('✅ Connected to real-time hub');
        socket.emit('join', userId);
        socket.emit('get_online_users');
    });

    socket.on('online_users_list', (onlineUserIds) => {
        console.log('👥 Online users:', onlineUserIds);
        onlineUserIds.forEach(id => {
            const chatItem = document.querySelector(`.chat-item[onclick="openChat(${id})"]`);
            if (chatItem) {
                chatItem.querySelector('.chat-avatar')?.classList.add('online');
            }
        });
    });

    socket.on('receive_message', (data) => {
        console.log('📥 Private message received:', data);
        updateChatListItem(data);
        showToast(`New message from ${data.sender_name || 'User ' + data.sender_id}`, 'info');
        updateTotalUnreadBadge(1);
    });

    socket.on('receive_group_message', (data) => {
        console.log('📥 Group message received:', data);
        updateGroupNavBadge(1);
        showToast(`New squad message in group #${data.group_id}`, 'accent');
    });

    socket.on('message_update', (data) => {
        const { type, message_id, sender_id, data: updateData } = data;
        if (type === 'edit' || type === 'delete') {
            const chatItem = document.querySelector(`.chat-item[onclick="openChat(${sender_id})"]`);
            if (chatItem) {
                const preview = chatItem.querySelector('.message-preview');
                if (preview) {
                    if (type === 'edit') {
                        preview.innerHTML = `<span class="message-status">✓✓</span> ${updateData.new_message.substring(0, 25)}...`;
                    } else {
                        preview.innerHTML = `<i>Message unsent</i>`;
                    }
                }
            }
        }
    });

    socket.on('user_status', (data) => {
        const chatItem = document.querySelector(`.chat-item[onclick="openChat(${data.userId})"]`);
        if (chatItem) {
            const avatar = chatItem.querySelector('.chat-avatar');
            if (data.status === 'online') {
                avatar.classList.add('online');
            } else {
                avatar.classList.remove('online');
            }
        }
    });
}

function updateChatListItem(data) {
    let chatItem = document.querySelector(`.chat-item[onclick="openChat(${data.sender_id})"]`);
    if (chatItem) {
        // Update preview text
        const preview = chatItem.querySelector('.message-preview');
        if (preview) {
            let msgText = data.message || 'Media';
            if (data.message_type === 'image') msgText = '📷 Photo';
            if (data.message_type === 'file') msgText = '📎 File';
            if (data.message_type === 'voice') msgText = '🎤 Voice';
            
            preview.innerHTML = msgText.substring(0, 25) + (msgText.length > 25 ? '...' : '');
        }

        // Update time
        const time = chatItem.querySelector('.chat-time');
        if (time) {
            time.innerText = 'NOW';
        }

        // Increment unread badge
        let badge = chatItem.querySelector('.unread-badge');
        if (badge) {
            let count = parseInt(badge.innerText) || 0;
            badge.innerText = count + 1;
        } else {
            const chatInfo = chatItem.querySelector('.chat-message');
            if (chatInfo) {
                const badgeSpan = document.createElement('span');
                badgeSpan.className = 'unread-badge';
                badgeSpan.innerText = '1';
                chatInfo.appendChild(badgeSpan);
            }
        }

        // Move to top
        const chatList = document.querySelector('.chats-list');
        if (chatList) chatList.prepend(chatItem);

        chatItem.classList.add('active');
        setTimeout(() => chatItem.classList.remove('active'), 2000);
    }
}

function updateTotalUnreadBadge(increment) {
    const badge = document.querySelector('.nav-item[title="CHAT HUB"] .nav-badge');
    if (badge) {
        let count = parseInt(badge.innerText) || 0;
        badge.innerText = count + increment;
    } else {
        const navItem = document.querySelector('.nav-item[title="CHAT HUB"]');
        if (navItem) {
            const badgeSpan = document.createElement('span');
            badgeSpan.className = 'nav-badge';
            badgeSpan.innerText = increment;
            navItem.appendChild(badgeSpan);
        }
    }
}

function updateGroupNavBadge(increment) {
    const navItem = document.querySelector('.nav-item[title="GROUPS"]');
    if (!navItem) return;

    let badge = navItem.querySelector('.nav-badge');
    if (badge) {
        let count = parseInt(badge.innerText) || 0;
        badge.innerText = count + increment;
    } else {
        const badgeSpan = document.createElement('span');
        badgeSpan.className = 'nav-badge';
        badgeSpan.innerText = increment;
        navItem.appendChild(badgeSpan);
    }
}

function showToast(message, type = 'info') {
    const toast = document.createElement('div');
    toast.className = `toast ${type}`;
    toast.innerText = message;
    document.body.appendChild(toast);
    setTimeout(() => {
        toast.style.opacity = '0';
        setTimeout(() => toast.remove(), 500);
    }, 4000);
}

// Initialize
window.addEventListener('load', () => {
    connectSocket();
});
