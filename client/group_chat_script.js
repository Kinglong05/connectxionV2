// ============================================
// REAL-TIME GROUP CHAT (COMPLETE)
// v1.2 - Synchronized Profanity Filter & Fixed Persistence
// ============================================
console.log('🚀 Group Chat Script v1.2 Loaded');

let socket;
const userId = typeof CONFIG !== 'undefined' ? CONFIG.userId : 0;
const roomId = typeof CONFIG !== 'undefined' ? CONFIG.roomId : 0;
const username = typeof CONFIG !== 'undefined' ? CONFIG.username : 'User';

let isTyping = false;
let typingTimeout;
let replyToMessageId = null;

function connectSocket() {
    const socketUrl = (typeof CONFIG !== 'undefined' && CONFIG.socketUrl) 
        ? CONFIG.socketUrl 
        : window.location.protocol + "//" + window.location.hostname + ":3000";
        
    console.log('🔗 Attempting to connect to:', socketUrl);
    
    socket = io(socketUrl, {
        reconnection: true,
        reconnectionAttempts: 10,
        reconnectionDelay: 1000,
        timeout: 10000
    });

    socket.on('connect', () => {
        console.log('✅ Connected to real-time server (ID: ' + socket.id + ')');
        socket.emit('join', userId);
        socket.emit('join_group', roomId);
    });

    socket.on('connect_error', (err) => {
        console.warn('⚠️ Socket connection error:', err.message);
    });

    socket.on('disconnect', (reason) => {
        console.log('❌ Socket disconnected:', reason);
    });

    // Incoming group messages
    socket.on('receive_group_message', (data) => {
        console.log('📥 Group message received:', data);
        if (data.group_id == roomId && data.sender_id != userId) {
            addGroupMessageToUI(data);
        }
    });

    // Group message updates (edit, delete, react)
    socket.on('group_message_update', (data) => {
        console.log('🔄 Group update received:', data);
        const { type, message_id, data: updateData } = data;
        const messageElement = document.querySelector(`.message-wrapper[data-id="${message_id}"]`);

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
            }
        } else if (type === 'react') {
            updateGroupMessageReactions(message_id, updateData.reactions);
        }
    });

    socket.on('group_typing_status', (data) => {
        if (data.group_id == roomId && data.user_id != userId) {
            showGroupTypingIndicator(data.is_typing, data.sender_name);
        }
    });
}

function addGroupMessageToUI(msg) {
    const container = document.getElementById('messagesContainer');
    const isOwn = (msg.sender_id == userId);
    const messageId = msg.message_id || msg.id;

    // Remove empty state
    const emptyState = container.querySelector('.empty-state');
    if (emptyState) emptyState.remove();

    const messageWrapper = document.createElement('div');
    messageWrapper.className = `message-wrapper ${isOwn ? 'message-own' : 'message-other'}`;
    messageWrapper.dataset.id = messageId;

    const messageHTML = `
        <div class="message">
            <div class="message-header">
                <span class="message-sender" onclick="showProfile(${msg.sender_id})">${isOwn ? 'YOU' : (msg.sender_name || msg.username)}</span>
                <span class="message-time">${msg.time || new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}</span>
            </div>
            <div class="message-bubble">
                ${renderMessageContent(msg, messageId)}
                ${renderReactions(msg.reactions)}
            </div>
        </div>
        <div class="message-actions">
            <button class="msg-action react" onclick="showReactionPicker(${messageId}, event)" title="REACT">😊</button>
            <button class="msg-action reply" onclick="setReply(${messageId}, '${escapeHtml(msg.message)}', '${isOwn ? 'YOU' : msg.sender_name}')" title="REPLY">↩️</button>
            ${isOwn ? `
                <button class="msg-action edit" onclick="editMessage(${messageId}, '${escapeHtml(msg.message)}')" title="EDIT">✏️</button>
                <button class="msg-action delete" onclick="deleteMessage(${messageId})" title="DELETE">🗑️</button>
            ` : ''}
        </div>
    `;

    messageWrapper.innerHTML = messageHTML;
    container.appendChild(messageWrapper);
    scrollToBottom();
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

function renderReactions(reactions) {
    if (!reactions || Object.keys(reactions).length === 0) return '';
    let html = '<div class="message-reactions">';
    for (const [emoji, data] of Object.entries(reactions)) {
        html += `<span class="reaction" title="Reacted by ${data.count} users">${emoji} <span class="count">${data.count}</span></span>`;
    }
    html += '</div>';
    return html;
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
        const filteredText = filterProfanity(msg.message || '');
        return `<span class="message-text">${escapeHtml(filteredText)}</span>${editedStr}`;
    }
}

// ============================================
// GROUP ACTIONS
// ============================================

let currentEditId = null;
let currentDeleteId = null;

function setReply(messageId, text, sender) {
    replyToMessageId = messageId;
    const replyInput = document.getElementById('replyToInput');
    if (replyInput) replyInput.value = messageId;
    const preview = document.getElementById('replyPreview');
    if (preview) {
        preview.style.display = 'flex';
        preview.querySelector('.reply-preview-text').textContent = text;
        preview.querySelector('.reply-preview-header').textContent = 'Replying to ' + sender;
    }
    document.getElementById('messageInput').focus();
}

function cancelReply() {
    replyToMessageId = null;
    const replyInput = document.getElementById('replyToInput');
    if (replyInput) replyInput.value = '';
    const preview = document.getElementById('replyPreview');
    if (preview) preview.style.display = 'none';
}

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
    if (!newText) return;

    const formData = new FormData();
    formData.append('message_id', currentEditId);
    formData.append('message', newText);

    fetch('edit_group_message.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            hideEditModal();
        } else {
            showToast(data.error || 'Failed to edit', 'error');
        }
    });
}

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

    fetch('delete_group_message.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            hideDeleteModal();
        }
    });
}

function showReactionPicker(messageId, event) {
    event.stopPropagation();
    document.querySelectorAll('.reaction-picker-mini').forEach(p => p.remove());

    const emojis = ['👍', '❤️', '😂', '😮', '😢', '🔥', '👏', '💯'];
    const picker = document.createElement('div');
    picker.className = 'reaction-picker-mini';

    emojis.forEach(emoji => {
        const btn = document.createElement('button');
        btn.textContent = emoji;
        btn.onclick = () => {
            addGroupReaction(messageId, emoji);
            picker.style.opacity = '0';
            picker.style.transform = 'scale(0.8)';
            setTimeout(() => picker.remove(), 200);
        };
        picker.appendChild(btn);
    });

    document.body.appendChild(picker);

    // Position logic
    const rect = event.currentTarget.getBoundingClientRect();
    const pickerWidth = 320;
    let left = event.clientX - (pickerWidth / 2);
    let top = event.clientY - 60;

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

function addGroupReaction(messageId, emoji) {
    const formData = new FormData();
    formData.append('message_id', messageId);
    formData.append('reaction', emoji);

    fetch('add_group_reaction.php', {
        method: 'POST',
        body: formData
    })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                updateGroupMessageReactions(messageId, data.reactions);
            }
        });
}

function updateGroupMessageReactions(messageId, reactions) {
    const messageElement = document.querySelector(`.message-wrapper[data-id="${messageId}"]`);
    if (!messageElement) return;

    let reactionsContainer = messageElement.querySelector('.message-reactions');
    if (!reactionsContainer) {
        reactionsContainer = document.createElement('div');
        reactionsContainer.className = 'message-reactions';
        messageElement.querySelector('.message-bubble').appendChild(reactionsContainer);
    }

    if (!reactions || (Array.isArray(reactions) && reactions.length === 0) || (typeof reactions === 'object' && Object.keys(reactions).length === 0)) {
        reactionsContainer.remove();
        return;
    }

    // Group reactions by emoji
    let grouped = {};
    if (Array.isArray(reactions)) {
        grouped = reactions.reduce((acc, r) => {
            acc[r.reaction] = (acc[r.reaction] || 0) + 1;
            return acc;
        }, {});
    } else {
        grouped = reactions;
    }

    reactionsContainer.innerHTML = '';
    for (const [emoji, count] of Object.entries(grouped)) {
        const displayCount = typeof count === 'object' ? count.count : count;
        const reactionEl = document.createElement('div');
        reactionEl.className = 'reaction reaction-pop';
        reactionEl.innerHTML = `${emoji} <span class="count">${displayCount}</span>`;
        reactionEl.onclick = () => addGroupReaction(messageId, emoji);
        reactionsContainer.appendChild(reactionEl);
    }
}

function showLogoutModal() { document.getElementById('logoutModal').classList.add('show'); }
function hideLogoutModal() { document.getElementById('logoutModal').classList.remove('show'); }
function confirmLogout() { document.getElementById('logoutForm').submit(); }

function showAttachMenu() {
    document.getElementById('fileInput').click();
}

function uploadFile(input) {
    if (!input.files || !input.files[0]) return;
    const file = input.files[0];
    const formData = new FormData();
    formData.append('file', file);
    formData.append('room_id', roomId);

    showToast('Uploading file...', 'info');
    fetch('upload_group_file.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            showToast('File uploaded!', 'success');
        } else {
            showToast(data.error || 'Upload failed', 'error');
        }
    });
}

function toggleMemberList() {
    const sidebar = document.getElementById('memberSidebar');
    sidebar.classList.toggle('show-mobile');
}

// ============================================
// INITIALIZATION & FORM HANDLING
// ============================================

function loadGroupMessages(isSilent = false) {
    if (!isSilent) showToast('Loading history...', 'info');
    fetch(`load_group_messages.php?room_id=${roomId}&v=${Date.now()}`)
        .then(res => res.text())
        .then(html => {
            const container = document.getElementById('messagesContainer');
            if (container) {
                container.innerHTML = html;
                scrollToBottom();
            }
        })
        .catch(err => {
            console.error('Error loading messages:', err);
            if (!isSilent) showToast('Failed to load history', 'error');
        });
}

function scrollToBottom() {
    const container = document.getElementById('messagesContainer');
    if (container) {
        setTimeout(() => {
            container.scrollTop = container.scrollHeight;
        }, 50);
    }
}

function filterProfanity(text) {
    if (typeof text !== 'string' || !text) return text || '';
    const badWords = [
        'fuck', 'shit', 'asshole', 'bitch', 'cunt', 'dick', 'pussy', 'motherfucker', 'bastard', 'slut', 'whore', 'faggot', 'nigger', 'piss', 'cock', 'twat', 'wank', 'ass', 'damn', 'hell', 'arse', 'balls', 'crap', 'darn',
        'douchebag', 'dumbass', 'jackass', 'asshat', 'asswipe', 'bullshit', 'cocksucker', 'dickhead', 'dipshit', 'fucktard', 'nutbag', 'prick', 'pussywhip', 'shithead', 'shitstain', 'skank', 'whorebag', 'bitchass',
        'anal', 'anus', 'blowjob', 'boner', 'clit', 'clitoris', 'cumbucket', 'cumdump', 'cumslut', 'dildo', 'fap', 'fellate', 'fellatio', 'foreskin', 'handjob', 'jizz', 'jizm', 'labia', 'masturbate', 'nipple', 'penis', 'porn', 'porno', 'scrotum', 'semen', 'shlong', 'screw', 'scrote', 'smegma', 'snatch', 'taint', 'testicle', 'tits', 'titties', 'vagina', 'vulva', 'wang', 'wanker',
        'chink', 'gook', 'kike', 'spic', 'wetback', 'cracker', 'coon', 'abbo', 'raghead', 'sandnigger', 'slanteye', 'zipperhead',
        'putang ina', 'putangina', 'tangina', 'tang ina', 'gago', 'tarantado', 'kupal', 'hayop', 'bwisit', 'pakyu', 'pakshet', 'punyeta', 'leche', 'bobo', 'tite', 'kiki', 'puke', 'bayag', 'kantot', 'iyot', 'pekpek', 'bilat', 'burat', 'etits', 'jakol', 'manyak', 'ulol', 'ulul', 'vovo', 'inutil', 'tanga', 'bungol', 'sira ulo', 'baliw', 'luka-luka', 'sinto-sinto', 'gunggong', 'bangag', 'adik', 'hampas lupa', 'buwisit', 'yawa', 'yudipota',
        'puta', 'cabron', 'cojones', 'joder', 'mierda', 'pinche', 'verga', 'carajo', 'coño', 'gilipollas', 'capullo', 'hijueputa', 'marica',
        'cyka', 'blyat', 'kurwa', 'perkele', 'faen', 'fitta', 'satan', 'helvete', 'scheisse', 'fick dich', 'merde', 'vaffanculo',
        'fuk', 'fack', 'fuack', 'phuk', 'fak', 'phuck', 'fck', 'fvck', 'sh1t', 'sh!t', 'shiiit', 'phuk', 'b1tch', 'b!tch', 'c0ck', 'd1ck', 'p0rn', 'pr0n', 'n1gga', 'nigg@', 'f@g', 'f4g', 'wh0re', 'wh03'
    ];
    let filtered = text;
    const sortedWords = [...badWords].sort((a, b) => b.length - a.length);
    sortedWords.forEach(word => {
        const reg = new RegExp('\\b' + word.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '\\b', 'gi');
        filtered = filtered.replace(reg, '*'.repeat(word.length));
    });
    return filtered;
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
    toast.innerHTML = `<span>${message}</span>`;
    document.body.appendChild(toast);
    setTimeout(() => {
        toast.style.opacity = '0';
        toast.style.transform = 'translateX(20px)';
        setTimeout(() => toast.remove(), 300);
    }, 3000);
}

function showEmojiPicker() { document.getElementById('emojiModal')?.classList.add('show'); }
function hideEmojiPicker() { document.getElementById('emojiModal')?.classList.remove('show'); }

function insertEmoji(emoji) {
    const input = document.getElementById('messageInput');
    if (input) {
        input.value += emoji;
        input.focus();
        input.dispatchEvent(new Event('input'));
    }
    hideEmojiPicker();
}

function copyInviteLink() {
    const link = document.getElementById('inviteLink').textContent.trim();
    navigator.clipboard.writeText(link).then(() => {
        showToast('✅ LINK COPIED!', 'success');
    });
}

function showGroupTypingIndicator(isTyping, senderName) {
    const container = document.getElementById('messagesContainer');
    let indicator = document.getElementById('groupTypingIndicator');
    if (!isTyping) {
        if (indicator) indicator.remove();
        return;
    }
    if (!indicator) {
        indicator = document.createElement('div');
        indicator.id = 'groupTypingIndicator';
        indicator.className = 'typing-indicator-msg';
        container.appendChild(indicator);
    }
    indicator.innerHTML = `<span class="typing-dots"><span>.</span><span>.</span><span>.</span></span> ${senderName} is typing`;
    scrollToBottom();
}

window.addEventListener('DOMContentLoaded', () => {
    loadGroupMessages(true);
    connectSocket();

    const sendForm = document.getElementById('sendForm');
    const messageInput = document.getElementById('messageInput');
    const sendBtn = document.getElementById('sendBtn');

    if (sendForm) {
        sendForm.addEventListener('submit', (e) => {
            e.preventDefault();
            const message = messageInput.value.trim();
            if (!message) return;

            const filteredMessage = filterProfanity(message);
            const messageData = {
                sender_id: userId,
                group_id: roomId,
                message: filteredMessage,
                sender_name: username,
                reply_to: replyToMessageId,
                message_type: 'text'
            };

            if (socket && socket.connected) {
                socket.emit('send_group_message', messageData);
                addGroupMessageToUI({
                    ...messageData,
                    id: 'temp-' + Date.now(),
                    time: new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }),
                    reactions: {}
                });
            } else {
                showToast('Connecting to server...', 'warning');
                const formData = new FormData(sendForm);
                fetch('send_group_message.php', {
                    method: 'POST',
                    body: formData,
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        loadGroupMessages(true);
                    } else {
                        showToast(data.error || 'Failed to send', 'error');
                    }
                })
                .catch(err => {
                    showToast('Send failed: ' + err.message, 'error');
                });
            }

            messageInput.value = '';
            cancelReply();
            if (sendBtn) sendBtn.disabled = true;
            if (socket) {
                socket.emit('group_typing', { group_id: roomId, user_id: userId, is_typing: false, sender_name: username });
            }
        });
    }

    if (messageInput) {
        messageInput.addEventListener('input', () => {
            if (sendBtn) sendBtn.disabled = (messageInput.value.trim() === '');
            if (!isTyping && messageInput.value.trim() !== '') {
                isTyping = true;
                if (socket) socket.emit('group_typing', { group_id: roomId, user_id: userId, is_typing: true, sender_name: username });
            }
            clearTimeout(typingTimeout);
            typingTimeout = setTimeout(() => {
                isTyping = false;
                if (socket) socket.emit('group_typing', { group_id: roomId, user_id: userId, is_typing: false, sender_name: username });
            }, 3000);
        });
    }

    window.addEventListener('click', (e) => {
        if (e.target.classList.contains('modal')) e.target.classList.remove('show');
    });

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') document.querySelectorAll('.modal.show').forEach(m => m.classList.remove('show'));
    });
});

window.addEventListener('resize', scrollToBottom);

// ============================================
// VOICE MESSAGING
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
        console.error('Mic error:', err);
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
    formData.append('room_id', roomId);
    formData.append('duration', duration);
    if (replyToMessageId) formData.append('reply_to', replyToMessageId);

    showToast('Sending voice...', 'info');

    fetch('send_group_voice.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            const messageData = {
                sender_id: userId,
                group_id: roomId,
                message: data.file_path,
                message_type: 'voice',
                message_id: data.message_id,
                sender_name: username,
                time: new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }),
                reactions: {}
            };
            
            if (socket && socket.connected) {
                socket.emit('send_group_message', messageData);
            }
            
            addGroupMessageToUI(messageData);
            cancelReply();
        } else {
            showToast(data.error || 'Failed to send voice', 'error');
        }
    })
    .catch(err => {
        console.error('Send voice error:', err);
        showToast('Connection error', 'error');
    });
}

// Voice playback logic
let currentPlayingAudio = null;
let currentPlayingMessageId = null;

function playVoiceMessage(audioSrc, button, messageId) {
    let audio = document.getElementById('voice-audio-' + messageId);
    
    if (!audio) {
        audio = new Audio(audioSrc);
        audio.id = 'voice-audio-' + messageId;
        audio.preload = 'none';
        document.body.appendChild(audio);
    }
    
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
        audio.play()
            .then(() => {
                button.textContent = '⏸';
                button.classList.add('playing');
                currentPlayingAudio = audio;
                currentPlayingMessageId = messageId;
                
                updateDuration(messageId, audio.currentTime, audio.duration);
                startWaveformAnimation(messageId, audio);
                
                audio.ontimeupdate = () => updateDuration(messageId, audio.currentTime, audio.duration);
                
                audio.onended = () => {
                    button.textContent = '▶️';
                    button.classList.remove('playing');
                    stopWaveformAnimation(messageId);
                    updateDuration(messageId, 0, audio.duration);
                    currentPlayingAudio = null;
                    currentPlayingMessageId = null;
                };
            })
            .catch(err => {
                console.error('Playback error:', err);
                showToast('Playback failed', 'error');
            });
    } else {
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
    
    stopWaveformAnimation(messageId);
    
    const intervalId = setInterval(() => {
        if (audio.paused || audio.ended) {
            stopWaveformAnimation(messageId);
            return;
        }
        const progress = (audio.currentTime / audio.duration) * 100;
        const activeIndex = Math.floor((progress / 100) * bars.length);
        bars.forEach((bar, idx) => {
            bar.classList.toggle('active', idx <= activeIndex);
        });
    }, 50);
    waveContainer.dataset.intervalId = intervalId;
}

function stopWaveformAnimation(messageId) {
    const waveContainer = document.querySelector(`.voice-wave-container[data-message-id="${messageId}"]`);
    if (waveContainer && waveContainer.dataset.intervalId) {
        clearInterval(parseInt(waveContainer.dataset.intervalId));
        waveContainer.dataset.intervalId = '';
        waveContainer.querySelectorAll('.voice-wave-bar').forEach(bar => bar.classList.remove('active'));
    }
}

function updateDuration(messageId, current, total) {
    const el = document.getElementById('voice-time-' + messageId);
    if (el) {
        const fmt = (s) => {
            if (isNaN(s)) return '--:--';
            const m = Math.floor(s / 60);
            const sec = Math.floor(s % 60);
            return `${m.toString().padStart(2, '0')}:${sec.toString().padStart(2, '0')}`;
        };
        el.textContent = `${fmt(current)}/${fmt(total)}`;
    }
}