/**
 * ConnectXion v2.0 - Frontend Controller
 * Modernized for Production & Cloud Deployment
 */

class ConnectXion {
    constructor(config) {
        this.config = config;
        this.socket = null;
        this.currentUser = config.user;
        this.currentRoom = null;
        
        this.init();
    }

    init() {
        this.connectSocket();
        this.setupEventListeners();
    }

    connectSocket() {
        // Point to the Node.js server on Render
        this.socket = io(this.config.nodeUrl, {
            auth: {
                token: this.config.token
            },
            reconnection: true,
            reconnectionAttempts: 5
        });

        this.socket.on('connect', () => {
            console.log('✅ Connected to Real-time Cloud Server');
            if (this.currentRoom) this.joinRoom(this.currentRoom);
        });

        this.socket.on('receive_message', (data) => {
            this.renderMessage(data);
        });

        this.socket.on('user_typing', (data) => {
            this.handleTypingIndicator(data);
        });
    }

    joinRoom(roomId) {
        this.currentRoom = roomId;
        this.socket.emit('join_room', roomId);
    }

    sendMessage(message, type = 'text') {
        const messageData = {
            sender_id: this.currentUser.id,
            room_id: this.currentRoom,
            message: message,
            message_type: type
        };

        // Emit to real-time server
        this.socket.emit('send_message', messageData);
        
        // Optimistic UI render
        this.renderMessage({
            ...messageData,
            sender_name: 'You',
            is_own: true,
            created_at: new Date()
        });
    }

    renderMessage(msg) {
        const container = document.getElementById('messages-container');
        if (!container) return;

        const div = document.createElement('div');
        div.className = `message ${msg.is_own ? 'own' : 'other'}`;
        div.innerHTML = `
            <div class="bubble">
                <span class="text">${this.escapeHtml(msg.message)}</span>
                <span class="time">${new Date(msg.created_at).toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'})}</span>
            </div>
        `;
        container.appendChild(div);
        this.scrollToBottom();
    }

    scrollToBottom() {
        const container = document.getElementById('messages-container');
        if (container) container.scrollTop = container.scrollHeight;
    }

    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    handleTypingIndicator(data) {
        const el = document.getElementById('typing-indicator');
        if (!el) return;
        el.textContent = data.is_typing ? `${data.username} is typing...` : '';
    }

    setupEventListeners() {
        const form = document.getElementById('chat-form');
        if (form) {
            form.addEventListener('submit', (e) => {
                e.preventDefault();
                const input = document.getElementById('message-input');
                if (input.value.trim()) {
                    this.sendMessage(input.value.trim());
                    input.value = '';
                }
            });
        }
    }
}
