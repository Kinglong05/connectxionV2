// ============================================
// REAL-TIME CHAT WITH SOCKET.IO (COMPLETE)
// ============================================

let socket;
const userId = typeof CONFIG !== 'undefined' ? CONFIG.userId : 0;
const friendId = typeof CONFIG !== 'undefined' ? CONFIG.friendId : 0;
const senderName = typeof CONFIG !== 'undefined' ? CONFIG.senderName : 'User';

let isTyping = false;
let typingTimeout;
let replyToMessageId = null;

function connectSocket() {
    const socketUrl = (typeof CONFIG !== 'undefined' && CONFIG.socketUrl) 
        ? CONFIG.socketUrl 
        : window.location.protocol + "//" + window.location.hostname + ":3000";
    
    socket = io(socketUrl);

    socket.on('connect', () => {
        console.log('✅ Connected to real-time server');
        const statusEl = document.getElementById('connectionStatus');
        if (statusEl) {
            statusEl.textContent = '⚡';
            statusEl.style.color = 'var(--accent)';
            statusEl.title = 'Connected';
        }
        socket.emit('join', userId);
        socket.emit('get_online_users');
    });

    socket.on('disconnect', () => {
        const statusEl = document.getElementById('connectionStatus');
        if (statusEl) {
            statusEl.textContent = '❌';
            statusEl.style.color = 'var(--danger)';
            statusEl.title = 'Disconnected';
        }
    });

    socket.on('user_status', (data) => {
        if (data.userId == friendId) {
            updateHeaderStatus(data.status);
        }
    });

    socket.on('online_users_list', (onlineUserIds) => {
        const isOnline = onlineUserIds.includes(Number(friendId));
        updateHeaderStatus(isOnline ? 'online' : 'offline');
    });

    // Incoming messages
    socket.on('receive_message', (data) => {
        console.log('📥 Message received:', data);
        if (data.sender_id == friendId) {
            addMessageToChat(data);
            markMessagesAsRead();
        } else {
            showToast(`New message from ${data.sender_name || 'Friend'}`, 'info');
        }
    });

    // Message updates (edit, delete, react)
    socket.on('message_update', (data) => {
        console.log('🔄 Message update received:', data);
        const { type, message_id, data: updateData } = data;
        const messageElement = document.querySelector(`.message[data-id="${message_id}"]`);

        if (!messageElement) return;

        if (type === 'edit') {
            const textElement = messageElement.querySelector('.message-text');
            if (textElement) {
                textElement.textContent = updateData.new_message;
                if (!messageElement.querySelector('.edited-indicator')) {
                    const edited = document.createElement('span');
                    edited.className = 'edited-indicator';
                    edited.textContent = ' (edited)';
                    textElement.parentElement.appendChild(edited);
                }
            }
        } else if (type === 'delete') {
            const bubble = messageElement.querySelector('.message-bubble');
            if (bubble) {
                bubble.innerHTML = `<span class="message-text unsent-message"><i>${updateData.unsent_by} unsent a message</i></span>`;
                messageElement.querySelector('.message-actions')?.remove();
                messageElement.querySelector('.reply-indicator')?.remove();
                messageElement.querySelector('.message-reactions')?.remove();
            }
        } else if (type === 'react') {
            updateMessageReactions(message_id, updateData.reactions);
        }
    });

    socket.on('typing_status', (data) => {
        if (data.sender_id == friendId) {
            showTypingIndicator(data.is_typing);
        }
    });

    // Real-time Read Receipts
    socket.on('messages_read', (data) => {
        console.log('👀 Messages read by friend:', data);
        if (data.reader_id == friendId) {
            document.querySelectorAll('.message-own .message-status').forEach(el => {
                el.textContent = '✓✓';
                el.classList.add('read');
            });
        }
    });

    // Real-time Pin Updates
    socket.on('message_pin_update', (data) => {
        console.log('📌 Pin update:', data);
        const { message_id, is_pinned } = data;
        updatePinnedBar(message_id, is_pinned);
    });

    socket.on('user_status', (data) => {
        if (data.userId == friendId) {
            updateFriendStatus(data.status);
        }
    });
}

function addMessageToChat(message) {
    const container = document.getElementById('messagesContainer');
    const isOwn = (message.sender_id == userId);
    const messageClass = isOwn ? 'message-own' : 'message-other';
    const messageId = message.message_id || 'new-' + Date.now();

    // Remove empty state
    const emptyState = container.querySelector('.empty-state');
    if (emptyState) emptyState.remove();

    // Template for message HTML
    const messageHtml = `
    <div class="message-group">
        <div class="message ${messageClass}" data-id="${messageId}">
            <div class="message-wrapper">
                <div class="message-actions">
                    <button class="msg-action react" onclick="showReactionPicker(${messageId}, event)" title="Add reaction">😊</button>
                    <button class="msg-action pin" onclick="togglePin(${messageId}, true)" title="Pin message">📌</button>
                    <button class="msg-action reply" onclick="setReply(${messageId}, '${escapeHtml(message.message || 'Media')}', '${isOwn ? 'You' : (message.sender_name || 'Friend')}')" title="Reply">↩️</button>
                    ${isOwn ? `
                        <button class="msg-action edit" onclick="editMessage(${messageId}, '${escapeHtml(message.message)}')" title="Edit message">✏️</button>
                        <button class="msg-action delete" onclick="deleteMessage(${messageId})" title="Unsend message">🗑️</button>
                    ` : ''}
                </div>
                <div class="message-sender">${isOwn ? 'You' : (message.sender_name || 'Friend')}</div>
        <div class="message-bubble">
            ${renderMessageContent(message, messageId)}
        </div>
                <div class="message-meta">
                    <span class="message-time">${message.time || new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}</span>
                    ${isOwn ? '<span class="message-status">✓</span>' : ''}
                </div>
            </div>
        </div>
    </div>
    `;

    container.insertAdjacentHTML('beforeend', messageHtml);
    scrollToBottom();
}

function renderMessageContent(msg, messageId) {
    if (msg.message_type === 'image') {
        return `<div class='message-image-container'>
                    <img src='${escapeHtml(msg.file_path || msg.message)}' class='message-image' onclick='showImagePreview("${escapeHtml(msg.file_path || msg.message)}")' loading='lazy'>
                </div>`;
    } else if (msg.message_type === 'file') {
        const fileName = msg.file_name || (msg.message && msg.message.replace('[File] ', '')) || 'Attachment';
        return `<a href='${escapeHtml(msg.file_path || '#')}' class='message-file' download>
                    <span class='file-icon'>📎</span>
                    <span class='file-name'>${escapeHtml(fileName)}</span>
                </a>`;
    } else if (msg.message_type === 'voice') {
        const audioUrl = escapeHtml(msg.file_path || msg.message);
        return `<div class="message-voice">
                    <button class="voice-play" onclick="playVoiceMessage('${audioUrl}', this, '${messageId}')" data-message-id="${messageId}" title="PLAY">▶️</button>
                    <div class="voice-wave-container" data-message-id="${messageId}">
                        ${Array(30).fill('<div class="voice-wave-bar"></div>').join('')}
                    </div>
                    <span class="voice-duration" id="voice-time-${messageId}">00:00/--:--</span>
                </div>`;
    } else {
        // Text message
        const editedStr = msg.is_edited ? ' <span class="edited-indicator">(edited)</span>' : '';
        return `<span class="message-text">${escapeHtml(msg.message || '')}</span>${editedStr}`;
    }
}

// ============================================
// MESSAGE ACTIONS
// ============================================

// ============================================
// MESSAGE ACTIONS (SYSTEM-BASED)
// ============================================

let currentEditId = null;
let currentDeleteId = null;

function setReply(messageId, text, sender) {
    replyToMessageId = messageId;
    document.getElementById('replyToInput').value = messageId;
    const replyBar = document.getElementById('replyPreview');
    if (replyBar) {
        replyBar.querySelector('.reply-preview-text').textContent = text;
        replyBar.querySelector('.reply-preview-header').textContent = 'Replying to ' + sender;
        replyBar.style.display = 'flex';
    }
    document.getElementById('messageInput').focus();
}

function cancelReply() {
    replyToMessageId = null;
    document.getElementById('replyToInput').value = '';
    const replyPreview = document.getElementById('replyPreview');
    if (replyPreview) replyPreview.style.display = 'none';
}

// Custom Edit Logic
function editMessage(messageId, currentText) {
    currentEditId = messageId;
    const modal = document.getElementById('editModal');
    const input = document.getElementById('editMessageInput');
    if (modal && input) {
        input.value = currentText;
        modal.classList.add('show');
        input.focus();
    }
}

function hideEditModal() {
    document.getElementById('editModal')?.classList.remove('show');
    currentEditId = null;
}

function saveEdit() {
    if (!currentEditId) return;
    const newText = document.getElementById('editMessageInput').value.trim();
    if (newText === "") {
        showToast('Message cannot be empty', 'warning');
        return;
    }

    const formData = new FormData();
    formData.append('message_id', currentEditId);
    formData.append('message', newText);

    fetch('api.php?action=edit_message', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            const msgEl = document.querySelector(`.message[data-id="${currentEditId}"] .message-text`);
            if (msgEl) msgEl.textContent = newText;
            showToast('Message updated', 'success');
            hideEditModal();
        } else {
            showToast(data.error || 'Failed to update message', 'error');
        }
    });
}

// Custom Delete Logic
function deleteMessage(messageId) {
    currentDeleteId = messageId;
    document.getElementById('deleteModal')?.classList.add('show');
}

function hideDeleteModal() {
    document.getElementById('deleteModal')?.classList.remove('show');
    currentDeleteId = null;
}

function confirmDelete() {
    if (!currentDeleteId) return;
    
    const formData = new FormData();
    formData.append('message_id', currentDeleteId);

    fetch('api.php?action=delete_message', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            const msgEl = document.querySelector(`.message[data-id="${currentDeleteId}"]`);
            if (msgEl) {
                msgEl.querySelector('.message-bubble').innerHTML = `<span class="message-text unsent-message"><i>You unsent a message</i></span>`;
                msgEl.querySelector('.message-actions')?.remove();
            }
            showToast('Message unsent', 'success');
            hideDeleteModal();
        } else {
            showToast(data.error || 'Failed to unsend message', 'error');
        }
    });
}

function showReactionPicker(messageId, event) {
    event.stopPropagation();
    // Remove existing pickers
    document.querySelectorAll('.reaction-picker-mini').forEach(p => p.remove());

    const emojis = ['👍', '❤️', '😂', '😮', '😢', '🔥', '👏', '💯'];
    const picker = document.createElement('div');
    picker.className = 'reaction-picker-mini';

    emojis.forEach(emoji => {
        const btn = document.createElement('button');
        btn.textContent = emoji;
        btn.onclick = () => {
            addReaction(messageId, emoji);
            picker.style.opacity = '0';
            picker.style.transform = 'scale(0.8)';
            setTimeout(() => picker.remove(), 200);
        };
        picker.appendChild(btn);
    });

    document.body.appendChild(picker);

    // Dynamic Positioning
    const rect = event.currentTarget.getBoundingClientRect();
    const pickerWidth = 320; // Approx
    let left = event.clientX - (pickerWidth / 2);
    let top = event.clientY - 60;

    // Bounds check
    if (left < 10) left = 10;
    if (left + pickerWidth > window.innerWidth) left = window.innerWidth - pickerWidth - 10;

    picker.style.left = left + 'px';
    picker.style.top = top + 'px';

    const closePicker = (e) => {
        if (!picker.contains(e.target)) {
            picker.style.opacity = '0';
            picker.style.transform = 'scale(0.8)';
            setTimeout(() => picker.remove(), 200);
            document.removeEventListener('click', closePicker);
        }
    };
    setTimeout(() => document.addEventListener('click', closePicker), 10);
}

function addReaction(messageId, emoji) {
    const formData = new FormData();
    formData.append('message_id', messageId);
    formData.append('reaction', emoji);

    fetch('api.php?action=react', {
        method: 'POST',
        body: formData
    })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                updateMessageReactions(messageId, data.reactions);
            }
        });
}

function updateMessageReactions(messageId, reactions) {
    const messageElement = document.querySelector(`.message[data-id="${messageId}"]`);
    if (!messageElement) return;

    let reactionsContainer = messageElement.querySelector('.message-reactions');
    if (!reactionsContainer) {
        reactionsContainer = document.createElement('div');
        reactionsContainer.className = 'message-reactions';
        messageElement.querySelector('.message-bubble').appendChild(reactionsContainer);
    }

    if (!reactions || reactions.length === 0) {
        reactionsContainer.remove();
        return;
    }

    // Group reactions by emoji
    const grouped = reactions.reduce((acc, r) => {
        acc[r.reaction] = (acc[r.reaction] || 0) + 1;
        return acc;
    }, {});

    reactionsContainer.innerHTML = '';
    for (const [emoji, count] of Object.entries(grouped)) {
        const reactionEl = document.createElement('div');
        reactionEl.className = 'reaction reaction-pop';
        reactionEl.innerHTML = `<span>${emoji}</span> <span class="count">${count}</span>`;
        reactionEl.onclick = () => addReaction(messageId, emoji);
        reactionsContainer.appendChild(reactionEl);
    }
}

function markMessagesAsRead() {
    if (socket) {
        socket.emit('mark_read', { user_id: userId, sender_id: friendId });
    }
}

function togglePin(messageId, isPinned) {
    if (!socket) return;
    
    socket.emit('pin_message', {
        message_id: messageId,
        receiver_id: friendId,
        is_pinned: isPinned,
        is_group: false
    });
    
    // Update local UI
    updatePinnedBar(messageId, isPinned);
    showToast(isPinned ? 'Message pinned' : 'Message unpinned', 'success');
}

function updatePinnedBar(messageId, isPinned) {
    const bar = document.getElementById('pinnedBar');
    const textEl = document.getElementById('pinnedText');
    const contentEl = document.getElementById('pinnedContent');
    
    if (!isPinned) {
        bar.classList.remove('show');
        return;
    }

    const msgEl = document.querySelector(`.message[data-id="${messageId}"]`);
    let text = 'Media attachment';
    if (msgEl) {
        const textSpan = msgEl.querySelector('.message-text');
        if (textSpan) text = textSpan.textContent;
    }

    textEl.textContent = text;
    contentEl.onclick = () => scrollToMessage(messageId);
    bar.querySelector('.unpin-btn').onclick = () => togglePin(messageId, false);
    bar.classList.add('show');
}

function scrollToMessage(messageId) {
    const el = document.querySelector(`.message[data-id="${messageId}"]`);
    if (el) {
        el.scrollIntoView({ behavior: 'smooth', block: 'center' });
        el.classList.add('highlight');
        setTimeout(() => el.classList.remove('highlight'), 2000);
    } else {
        showToast('Message not found in history', 'warning');
    }
}

function updateFriendStatus(status) {
    const statusText = document.querySelector('.chat-user-status');
    const avatar = document.querySelector('.chat-avatar');
    if (status === 'online') {
        avatar?.classList.add('online');
        if (statusText) statusText.innerHTML = '<span class="status-dot online"></span> ONLINE';
    } else {
        avatar?.classList.remove('online');
        if (statusText) statusText.innerHTML = '<span class="status-dot"></span> OFFLINE';
    }
}

function sendTypingStatus(isTypingValue) {
    if (socket) {
        socket.emit('typing', { sender_id: userId, receiver_id: friendId, is_typing: isTypingValue });
    }
}

function showTypingIndicator(show) {
    const el = document.getElementById('typingIndicator');
    if (el) el.style.display = show ? 'flex' : 'none';
}

function loadMessages(isSilent = false) {
    if (!isSilent) showToast('Loading messages...', 'info');
    fetch(`load_messages.php?id=${friendId}`)
        .then(res => res.text())
        .then(html => {
            document.getElementById('messagesContainer').innerHTML = html;
            scrollToBottom();
        });
}

function updateHeaderStatus(status) {
    const avatar = document.querySelector('.chat-user .chat-avatar');
    const statusText = document.querySelector('.chat-user-status');
    if (avatar) {
        if (status === 'online') avatar.classList.add('online');
        else avatar.classList.remove('online');
    }
    if (statusText) {
        statusText.innerHTML = `<span class="status-dot"></span> ${status.toUpperCase()}`;
    }
}

function scrollToBottom() {
    const container = document.getElementById('messagesContainer');
    if (container) container.scrollTop = container.scrollHeight;
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function showToast(message, type = 'info') {
    const toast = document.createElement('div');
    toast.className = `toast ${type}`;
    toast.textContent = message;
    document.body.appendChild(toast);
    setTimeout(() => toast.remove(), 3000);
}

// ============================================
// INITIALIZATION
// ============================================

window.onload = () => {
    loadMessages(true);
    connectSocket();

    // Form submit
    const sendForm = document.getElementById('sendForm');
    if (sendForm) {
        sendForm.addEventListener('submit', function (e) {
            e.preventDefault();
            const input = document.getElementById('messageInput');
            const messageText = input.value.trim();
            if (!messageText) return;

            const messageData = {
                sender_id: userId,
                receiver_id: friendId,
                message: messageText,
                reply_to: replyToMessageId,
                sender_name: senderName
            };

            socket.emit('send_message', messageData);
            addMessageToChat(messageData); // Optimistic UI

            input.value = '';
            cancelReply();
            sendTypingStatus(false);
        });
    }

    // Typing listener
    const messageInput = document.getElementById('messageInput');
    if (messageInput) {
        messageInput.addEventListener('input', () => {
            if (!isTyping) {
                isTyping = true;
                sendTypingStatus(true);
            }
            clearTimeout(typingTimeout);
            typingTimeout = setTimeout(() => {
                isTyping = false;
                sendTypingStatus(false);
            }, 3000);
        });
    }

    // Auto-scroll on resize
    window.addEventListener('resize', scrollToBottom);
};

// ============================================
// VOICE & ATTACHMENTS
// ============================================

let mediaRecorder;
let audioChunks = [];
let recordingInterval;
let recordingStartTime;

function toggleVoiceRecording() {
    if (mediaRecorder && mediaRecorder.state === 'recording') {
        stopVoiceRecording();
    } else {
        startVoiceRecording();
    }
}

async function startVoiceRecording() {
    try {
        const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
        mediaRecorder = new MediaRecorder(stream);
        audioChunks = [];

        mediaRecorder.ondataavailable = (event) => audioChunks.push(event.data);
        mediaRecorder.onstop = sendVoiceMessage;

        mediaRecorder.start();
        recordingStartTime = Date.now();
        document.getElementById('voiceRecordingIndicator').style.display = 'flex';
        document.getElementById('voiceBtn').classList.add('recording');
        
        recordingInterval = setInterval(() => {
            const seconds = Math.floor((Date.now() - recordingStartTime) / 1000);
            const mins = Math.floor(seconds / 60).toString().padStart(2, '0');
            const secs = (seconds % 60).toString().padStart(2, '0');
            document.getElementById('voiceTimer').textContent = `${mins}:${secs}`;
        }, 1000);

    } catch (err) {
        showToast('Microphone access denied', 'error');
    }
}

function stopVoiceRecording() {
    if (mediaRecorder && mediaRecorder.state === 'recording') {
        mediaRecorder.stop();
        mediaRecorder.stream.getTracks().forEach(track => track.stop());
        clearInterval(recordingInterval);
        document.getElementById('voiceRecordingIndicator').style.display = 'none';
        document.getElementById('voiceBtn').classList.remove('recording');
    }
}

function sendVoiceMessage() {
    const audioBlob = new Blob(audioChunks, { type: 'audio/webm' });
    const duration = Math.floor((Date.now() - recordingStartTime) / 1000);
    
    if (duration < 1) {
        showToast('Recording too short', 'warning');
        return;
    }

    const formData = new FormData();
    formData.append('voice', audioBlob, 'voice.webm');
    formData.append('receiver_id', friendId);
    formData.append('duration', duration);
    if (replyToMessageId) formData.append('reply_to', replyToMessageId);

    showToast('Sending voice...', 'info');

    fetch('send_voice.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            const messageData = {
                sender_id: userId,
                receiver_id: friendId,
                message: data.file_path,
                message_type: 'voice',
                message_id: data.message_id,
                time: new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
            };
            addMessageToChat(messageData);
            cancelReply();
        } else {
            showToast(data.error || 'Failed to send voice', 'error');
        }
    });
}

function showAttachMenu() {
    document.getElementById('fileInput').click();
}

function uploadFile(input) {
    if (!input.files || !input.files[0]) return;
    const file = input.files[0];
    const formData = new FormData();
    formData.append('file', file);
    formData.append('receiver_id', friendId);
    if (replyToMessageId) formData.append('reply_to', replyToMessageId);

    showToast('Uploading...', 'info');

    fetch('upload_file.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            const messageData = {
                sender_id: userId,
                receiver_id: friendId,
                message: data.file_path,
                message_type: data.message_type,
                message_id: data.message_id,
                file_name: file.name,
                time: new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
            };
            addMessageToChat(messageData);
            cancelReply();
            showToast('File sent!', 'success');
        } else {
            showToast(data.error || 'Upload failed', 'error');
        }
    });
}

// Voice playback with waveform animation
let currentPlayingAudio = null;
let currentPlayingMessageId = null;

function playVoiceMessage(audioSrc, button, messageId) {
    // Create audio element if it doesn't exist
    let audio = document.getElementById('voice-audio-' + messageId);
    
    if (!audio) {
        audio = new Audio(audioSrc);
        audio.id = 'voice-audio-' + messageId;
        audio.preload = 'none';
        document.body.appendChild(audio);
    }
    
    // If there's a currently playing audio and it's not this one, pause it
    if (currentPlayingAudio && currentPlayingAudio !== audio) {
        currentPlayingAudio.pause();
        const prevButton = document.querySelector(`.voice-play[data-message-id="${currentPlayingMessageId}"]`);
        if (prevButton) {
            prevButton.textContent = '▶️';
            prevButton.classList.remove('playing');
        }
        stopWaveformAnimation(currentPlayingMessageId);
    }
    
    if (audio.paused) {
        // Play audio
        audio.play()
            .then(() => {
                button.textContent = '⏸';
                button.classList.add('playing');
                currentPlayingAudio = audio;
                currentPlayingMessageId = messageId;
                
                // Update duration display
                updateDuration(messageId, audio.currentTime, audio.duration);
                
                // Start waveform animation
                startWaveformAnimation(messageId, audio);
                
                // Update time display
                audio.addEventListener('timeupdate', function() {
                    updateDuration(messageId, audio.currentTime, audio.duration);
                });
                
                audio.onended = function() {
                    button.textContent = '▶️';
                    button.classList.remove('playing');
                    stopWaveformAnimation(messageId);
                    updateDuration(messageId, 0, audio.duration);
                    currentPlayingAudio = null;
                    currentPlayingMessageId = null;
                };
            })
            .catch(err => {
                console.error('Error playing audio:', err);
                showToast('Failed to play voice message', 'error');
            });
    } else {
        // Pause audio
        audio.pause();
        button.textContent = '▶️';
        button.classList.remove('playing');
        stopWaveformAnimation(messageId);
        currentPlayingAudio = null;
        currentPlayingMessageId = null;
    }
}

function startWaveformAnimation(messageId, audio) {
    const waveContainer = document.querySelector(`.voice-wave-container[data-message-id="${messageId}"]`);
    if (!waveContainer) return;
    
    const bars = waveContainer.querySelectorAll('.voice-wave-bar');
    if (bars.length === 0) return;
    
    // Clear any existing interval
    stopWaveformAnimation(messageId);
    
    const intervalId = setInterval(() => {
        if (audio.paused || audio.ended) {
            stopWaveformAnimation(messageId);
            return;
        }
        
        const currentTime = audio.currentTime;
        const duration = audio.duration;
        
        if (duration && duration > 0) {
            const progress = (currentTime / duration) * 100;
            const activeBarIndex = Math.floor((progress / 100) * bars.length);
            
            bars.forEach((bar, index) => {
                if (index <= activeBarIndex) {
                    bar.classList.add('active');
                } else {
                    bar.classList.remove('active');
                }
            });
        }
    }, 50);
    
    // Store interval ID on the container
    waveContainer.dataset.intervalId = intervalId;
}

function stopWaveformAnimation(messageId) {
    const waveContainer = document.querySelector(`.voice-wave-container[data-message-id="${messageId}"]`);
    if (waveContainer && waveContainer.dataset.intervalId) {
        clearInterval(parseInt(waveContainer.dataset.intervalId));
        waveContainer.dataset.intervalId = '';
    }
    
    // Reset all bars for this message only
    if (waveContainer) {
        waveContainer.querySelectorAll('.voice-wave-bar').forEach(bar => {
            bar.classList.remove('active');
        });
    }
}

function updateDuration(messageId, current, total) {
    const durationSpan = document.getElementById('voice-time-' + messageId);
    if (durationSpan) {
        const currentFormatted = formatTime(current);
        const totalFormatted = formatTime(total);
        durationSpan.textContent = currentFormatted + '/' + totalFormatted;
    }
}

function formatTime(seconds) {
    if (isNaN(seconds)) return '00:00';
    const mins = Math.floor(seconds / 60);
    const secs = Math.floor(seconds % 60);
    return (mins < 10 ? '0' + mins : mins) + ':' + (secs < 10 ? '0' + secs : secs);
}

function showImagePreview(src) {
    const modal = document.getElementById('imagePreviewModal');
    const img = document.getElementById('previewImage');
    img.src = src;
    modal.style.display = 'flex';
}

function hideImagePreview() {
    document.getElementById('imagePreviewModal').style.display = 'none';
}

// --- Modals & Other ---
function toggleSearch() {
    const container = document.getElementById('searchContainer');
    const results = document.getElementById('searchResults');
    container.classList.toggle('show');
    if (container.classList.contains('show')) {
        document.getElementById('searchInput').focus();
    } else {
        results.classList.remove('show');
        results.innerHTML = '';
        document.getElementById('searchInput').value = '';
    }
}

function searchMessages(query) {
    const results = document.getElementById('searchResults');
    if (query.length < 2) {
        results.innerHTML = '';
        results.classList.remove('show');
        return;
    }
    fetch(`search_messages.php?q=${encodeURIComponent(query)}&friend_id=${friendId}`)
    .then(res => res.json())
    .then(data => {
        results.innerHTML = '';
        if (data && data.length > 0) {
            results.classList.add('show');
            data.forEach(res => {
                const div = document.createElement('div');
                div.className = 'search-result-item';
                div.innerHTML = `
                    <div class="search-result-header">
                        <strong>${res.sender_name}</strong>
                        <span>${new Date(res.created_at).toLocaleDateString()}</span>
                    </div>
                    <div class="search-result-text">${res.message}</div>
                `;
                div.onclick = () => {
                    scrollToMessage(res.message_id);
                    toggleSearch();
                };
                results.appendChild(div);
            });
        } else {
            results.classList.add('show');
            results.innerHTML = '<div class="search-no-results">No messages found.</div>';
        }
    })
    .catch(err => {
        console.error('Search error:', err);
        results.classList.remove('show');
    });
}

function showUserInfo() {
    showProfile(friendId);
}

function showProfile(id) {
    const modal = document.getElementById('profileModal');
    if (!modal) return;

    fetch(`get_user_info.php?user_id=${id}`)
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            const user = data.user;
            document.getElementById('profileUsername').textContent = user.username;
            document.getElementById('profileBio').textContent = user.bio || "No bio yet.";
            document.getElementById('statFriends').textContent = user.friends_count;
            document.getElementById('statMessages').textContent = user.messages_count;
            document.getElementById('statMemberSince').textContent = user.member_since;
            
            const avatar = document.getElementById('profileAvatar');
            if (user.avatar) {
                avatar.innerHTML = `<img src="${user.avatar}" alt="${user.username}">`;
            } else {
                avatar.textContent = user.username.charAt(0).toUpperCase();
            }

            const status = document.getElementById('profileStatus');
            if (user.is_online) {
                status.textContent = 'ONLINE';
                status.classList.add('online');
            } else {
                status.textContent = 'OFFLINE';
                status.classList.remove('online');
            }

            // Show Edit button if it's the current user
            document.getElementById('editProfileBtn').style.display = (id == userId) ? 'block' : 'none';

            modal.classList.add('show');
        } else {
            showToast(data.error || 'Failed to fetch user info', 'error');
        }
    });
}

function hideProfile() {
    document.getElementById('profileModal')?.classList.remove('show');
}

function showLogoutModal() { document.getElementById('logoutModal').classList.add('show'); }
function hideLogoutModal() { document.getElementById('logoutModal').classList.remove('show'); }
function confirmLogout() { document.getElementById('logoutForm').submit(); }
