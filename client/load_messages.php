<?php
// load_messages.php - FIXED VERSION WITH WORKING REACTIONS
require_once 'db.php';
requireLogin();

$user_id = $_SESSION['user_id'];
$friend_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
$limit = 50;

if (!$friend_id) {
    echo '<div class="empty-state"><p>Please select a chat</p></div>';
    exit;
}

// Check if friend exists
$check = dbGetRow($conn, "SELECT user_id FROM users WHERE user_id = ?", "i", $friend_id);
if (!$check) {
    echo '<div class="empty-state"><p>User not found</p></div>';
    exit;
}

// Mark messages as read using prepared statement
$stmt = $conn->prepare("
    UPDATE messages 
    SET is_read = 1, read_status = 'read', read_at = NOW() 
    WHERE sender_id = ? AND receiver_id = ? AND is_read = 0
");
$stmt->bind_param("ii", $friend_id, $user_id);
$stmt->execute();
$stmt->close();

// Get total messages count for pagination
$count_row = dbGetRow($conn, "
    SELECT COUNT(*) as total 
    FROM messages 
    WHERE (sender_id = ? AND receiver_id = ?)
       OR (sender_id = ? AND receiver_id = ?)
", "iiii", $user_id, $friend_id, $friend_id, $user_id);
$total_messages = $count_row['total'] ?? 0;

// FIXED: Get messages with reactions data using GROUP_CONCAT
$sql = "
    SELECT m.*, 
           u.username as sender_name,
           u.avatar as sender_avatar,
           GROUP_CONCAT(
               CONCAT(mr.reaction, ':', COALESCE(u2.username, ''))
               SEPARATOR '|'
           ) as reactions_data
    FROM messages m
    JOIN users u ON u.user_id = m.sender_id
    LEFT JOIN message_reactions mr ON m.message_id = mr.message_id
    LEFT JOIN users u2 ON mr.user_id = u2.user_id
    WHERE (m.sender_id = ? AND m.receiver_id = ?)
       OR (m.sender_id = ? AND m.receiver_id = ?)
    GROUP BY m.message_id
    ORDER BY m.created_at ASC
    LIMIT ? OFFSET ?
";

$result = prepareAndExecute($conn, $sql, "iiiiii", $user_id, $friend_id, $friend_id, $user_id, $limit, $offset)->get_result();

if (!$result || $result->num_rows === 0) {
    if ($offset === 0) {
        echo '<div class="empty-state"><p>No messages yet. Start the conversation!</p></div>';
    }
    exit;
}

// Get reply messages info
$reply_ids = [];
$result->data_seek(0);
while ($msg = $result->fetch_assoc()) {
    if (!empty($msg['reply_to'])) {
        $reply_ids[] = $msg['reply_to'];
    }
}
$result->data_seek(0);

$reply_messages = [];
if (!empty($reply_ids)) {
    $ids_str = implode(',', array_unique($reply_ids));
    $reply_result = $conn->query("
        SELECT m.message_id, m.message, m.message_type, m.file_path, 
               u.username as sender_name, u.user_id as sender_id
        FROM messages m
        JOIN users u ON u.user_id = m.sender_id
        WHERE m.message_id IN ($ids_str)
    ");
    
    if ($reply_result) {
        while ($r = $reply_result->fetch_assoc()) {
            $reply_messages[$r['message_id']] = $r;
        }
    }
}

// Helper functions
function formatDate($timestamp) {
    $date = date('Y-m-d', strtotime($timestamp));
    $today = date('Y-m-d');
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    
    if ($date == $today) return 'Today';
    if ($date == $yesterday) return 'Yesterday';
    return date('F j, Y', strtotime($timestamp));
}

function getFileIcon($file_path) {
    $extension = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
    
    $icons = [
        'pdf' => '📕', 'doc' => '📘', 'docx' => '📘',
        'xls' => '📗', 'xlsx' => '📗', 'ppt' => '📙',
        'pptx' => '📙', 'txt' => '📄', 'zip' => '📦',
        'rar' => '📦', '7z' => '📦', 'mp3' => '🎵',
        'wav' => '🎵', 'mp4' => '🎬', 'mov' => '🎬',
        'avi' => '🎬', 'jpg' => '🖼️', 'jpeg' => '🖼️',
        'png' => '🖼️', 'gif' => '🖼️', 'webp' => '🖼️'
    ];
    
    return $icons[$extension] ?? '📎';
}

function formatFileSize($bytes) {
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1048576) return round($bytes / 1024, 1) . ' KB';
    if ($bytes < 1073741824) return round($bytes / 1048576, 1) . ' MB';
    return round($bytes / 1073741824, 1) . ' GB';
}

function formatDuration($seconds) {
    $minutes = floor($seconds / 60);
    $seconds = $seconds % 60;
    return sprintf("%02d:%02d", $minutes, $seconds);
}

$current_date = '';

while ($msg = $result->fetch_assoc()) {
    $msg_date = date('Y-m-d', strtotime($msg['created_at']));
    
    // Date separator
    if ($msg_date != $current_date) {
        $current_date = $msg_date;
        echo '<div class="date-separator"><span>' . formatDate($msg['created_at']) . '</span></div>';
    }
    
    $is_own = ($msg['sender_id'] == $user_id);
    $class = $is_own ? 'message-own' : 'message-other';
    $message_id = $msg['message_id'];
    
    // Parse reactions data
    $reactions = [];
    if (!empty($msg['reactions_data'])) {
        $reaction_parts = explode('|', $msg['reactions_data']);
        foreach ($reaction_parts as $part) {
            if (!empty($part)) {
                $parts = explode(':', $part);
                if (count($parts) >= 2) {
                    $emoji = $parts[0];
                    $username = $parts[1];
                    
                    if (!isset($reactions[$emoji])) {
                        $reactions[$emoji] = ['count' => 0, 'users' => []];
                    }
                    $reactions[$emoji]['count']++;
                    if (!empty($username)) {
                        $reactions[$emoji]['users'][] = $username;
                    }
                }
            }
        }
    }
    
    // Check if message is deleted (unsent)
    $is_deleted = isset($msg['deleted']) && $msg['deleted'] == 1;
    
    echo "<div class='message-group'>";
    // ADDED: data-id attribute on message div for real-time tracking
    echo "<div class='message $class' data-id='$message_id'>";
    echo "<div class='message-wrapper'>";
    
    // Message actions - FIXED: Show for all non-deleted messages, not just own
    if (!$is_deleted) {
        echo "<div class='message-actions'>";
        // REACTION BUTTON - This is the key part! Show for all messages
        echo "<button class='msg-action react' onclick='showReactionPicker($message_id, event)' title='Add reaction'>😊</button>";
        echo "<button class='msg-action pin' onclick='togglePin($message_id, true)' title='Pin message'>📌</button>";
        
        $safe_message = addslashes(htmlspecialchars($msg['message'] ?? 'Media message'));
        $sender_name = addslashes(htmlspecialchars($msg['sender_name']));
        
        echo "<button class='msg-action reply' onclick='setReply($message_id, \"$safe_message\", \"$sender_name\")' title='Reply'>↩️</button>";
        
        // Only show edit/delete for user's own messages
        if ($is_own) {
            echo "<button class='msg-action edit' onclick='editMessage($message_id, \"$safe_message\")' title='Edit message'>✏️</button>";
            echo "<button class='msg-action delete' onclick='deleteMessage($message_id)' title='Unsend message'>🗑️</button>";
        }
        echo "</div>";
    }
    
    // Reply indicator (only for non-deleted messages)
    if (!$is_deleted && !empty($msg['reply_to']) && isset($reply_messages[$msg['reply_to']])) {
        $reply = $reply_messages[$msg['reply_to']];
        $reply_sender = $reply['sender_id'] == $user_id ? 'You' : htmlspecialchars($reply['sender_name']);
        $reply_content = '';
        
        if ($reply['message_type'] == 'image') {
            $reply_content = '📷 Photo';
        } elseif ($reply['message_type'] == 'file') {
            $reply_content = '📎 ' . basename($reply['file_path']);
        } elseif ($reply['message_type'] == 'voice') {
            $reply_content = '🎤 Voice message';
        } else {
            $reply_content = substr($reply['message'], 0, 50) . (strlen($reply['message']) > 50 ? '...' : '');
        }
        
        echo "<div class='reply-indicator' onclick='scrollToMessage(" . $msg['reply_to'] . ")'>";
        echo "<div class='reply-sender'>↪ Replying to " . $reply_sender . "</div>";
        echo "<div class='reply-content'>" . htmlspecialchars($reply_content) . "</div>";
        echo "</div>";
    }
    
    // Message sender name
    echo "<div class='message-sender'>";
    if ($is_deleted) {
        // For unsent messages, show who unsent it
        if ($is_own) {
            echo "You";
        } else {
            echo htmlspecialchars($msg['sender_name']);
        }
    } else {
        if ($is_own) {
            echo "You";
        } else {
            echo htmlspecialchars($msg['sender_name']);
        }
    }
    echo "</div>";
    
    echo "<div class='message-bubble'>";
    
    // Message content
    if ($is_deleted) {
        // This is an unsent message
        if ($is_own) {
            $unsent_text = "You unsent a message";
        } else {
            $unsent_text = htmlspecialchars($msg['sender_name']) . " unsent a message";
        }
        echo "<span class='message-text unsent-message'><i>" . $unsent_text . "</i></span>";
    } else {
        $message_type = $msg['message_type'] ?? 'text';
        
        // Text message
        if (!empty($msg['message']) && $message_type == 'text') {
            $message_text = htmlspecialchars($msg['message']);
            echo "<span class='message-text'>" . nl2br($message_text) . "</span>";
            // Check if edited
            if (isset($msg['edited']) && $msg['edited'] == 1) {
                echo "<span class='edited-indicator'>(edited)</span>";
            }
        }
        
        // Image file
        if ($message_type == 'image' && !empty($msg['file_path']) && file_exists($msg['file_path'])) {
            echo "<div class='message-image-container'>";
            echo "<img src='" . htmlspecialchars($msg['file_path']) . "' class='message-image' onclick='showImagePreview(\"" . htmlspecialchars($msg['file_path']) . "\")' loading='lazy'>";
            if (!empty($msg['message']) && $msg['message'] != '📷 Photo') {
                echo "<div class='image-caption'>" . htmlspecialchars($msg['message']) . "</div>";
            }
            echo "</div>";
        }
        
        // File attachment
        if ($message_type == 'file' && !empty($msg['file_path']) && file_exists($msg['file_path'])) {
            $file_name = basename($msg['file_path']);
            $file_icon = getFileIcon($msg['file_path']);
            $file_size = !empty($msg['file_size']) ? formatFileSize($msg['file_size']) : 'Unknown size';
            
            echo "<a href='" . htmlspecialchars($msg['file_path']) . "' class='message-file' download target='_blank'>";
            echo "<span class='file-icon'>" . $file_icon . "</span>";
            echo "<div class='file-info'>";
            echo "<div class='file-name'>" . htmlspecialchars($file_name) . "</div>";
            echo "<div class='file-size'>" . $file_size . "</div>";
            echo "</div>";
            echo "<span class='download-icon'>⬇️</span>";
            echo "</a>";
            
            if (!empty($msg['message']) && $msg['message'] != '📎 ' . $file_name) {
                echo "<div class='file-caption'>" . htmlspecialchars($msg['message']) . "</div>";
            }
        }
        
        // Voice message
        if ($message_type == 'voice' && !empty($msg['file_path']) && file_exists($msg['file_path'])) {
            // Get waveform data from voice_messages table
            $voice_data = dbGetRow($conn, "SELECT waveform_data, duration FROM voice_messages WHERE message_id = ?", "i", $msg['message_id']);
            
            $duration = $voice_data['duration'] ?? 0;
            $duration_formatted = formatDuration($duration);
            $audio_url = htmlspecialchars($msg['file_path']);
            $message_id = $msg['message_id'];
            $waveform_data = $voice_data && $voice_data['waveform_data'] ? json_decode($voice_data['waveform_data'], true) : [];
            
            echo '<div class="message-voice">';
            echo '<button class="voice-play" onclick="playVoiceMessage(\'' . $audio_url . '\', this, ' . $message_id . ')" data-message-id="' . $message_id . '" title="PLAY">▶️</button>';
            
            // Waveform container
            echo '<div class="voice-wave-container" data-message-id="' . $message_id . '">';
            if (!empty($waveform_data)) {
                // Use actual waveform data
                $bars = array_slice($waveform_data, 0, 30);
                foreach ($bars as $value) {
                    $height = max(4, min(40, $value / 2.5));
                    echo '<div class="voice-wave-bar" data-message-id="' . $message_id . '" style="height: ' . $height . 'px;"></div>';
                }
            } else {
                // Default random waveform if no data
                for ($i = 0; $i < 30; $i++) {
                    $height = rand(8, 30);
                    echo '<div class="voice-wave-bar" data-message-id="' . $message_id . '" style="height: ' . $height . 'px;"></div>';
                }
            }
            echo '</div>';
            
            echo '<span class="voice-duration" id="voice-time-' . $message_id . '">00:00/' . $duration_formatted . '</span>';
            echo '</div>';
        }
    }
    
    // Show reactions - FIXED: Show reactions inside the bubble
    if (!$is_deleted && !empty($reactions)) {
        echo "<div class='message-reactions'>";
        foreach ($reactions as $emoji => $data) {
            $user_list = implode(', ', $data['users']);
            echo "<span class='reaction' onclick='addReaction($message_id, \"$emoji\")' title='" . htmlspecialchars($user_list) . "'>";
            echo "$emoji <span class='reaction-count'>" . $data['count'] . "</span>";
            echo "</span>";
        }
        echo "</div>";
    }

    echo "</div>"; // close message-bubble
    
    // Message meta (time and status)
    echo "<div class='message-meta'>";
    echo "<span class='message-time'>" . date('h:i A', strtotime($msg['created_at'])) . "</span>";
    
    if ($is_own && !$is_deleted) {
        $status = $msg['read_status'] ?? 'sent';
        if ($status == 'read') {
            echo "<span class='message-status read' title='Read'>✓✓</span>";
        } elseif ($status == 'delivered') {
            echo "<span class='message-status' title='Delivered'>✓✓</span>";
        } else {
            echo "<span class='message-status' title='Sent'>✓</span>";
        }
    }
    echo "</div>";
    
    echo "</div>"; // close message-wrapper
    echo "</div>"; // close message
    echo "</div>"; // close message-group
}

// Show load more button if there are more messages
if ($offset + $limit < $total_messages) {
    echo "<div class='load-more-container' style='text-align: center; margin: 20px 0;'>";
    echo "<button class='load-more-btn' onclick='loadMoreMessages(" . ($offset + $limit) . ")'>LOAD MORE MESSAGES</button>";
    echo "</div>";
}
?>

<style>
.load-more-btn {
    background: var(--bg-tertiary);
    border: 1px solid var(--border);
    color: var(--text-secondary);
    padding: 12px 30px;
    border-radius: 30px;
    cursor: pointer;
    font-size: 13px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 1px;
    transition: all 0.3s;
}

.load-more-btn:hover {
    background: var(--accent);
    color: white;
    border-color: var(--accent);
    transform: translateY(-2px);
    box-shadow: var(--glow-effect);
}

/* Unsent message style */
.message-text.unsent-message {
    font-style: italic;
    opacity: 0.7;
    color: var(--text-muted);
}

.message-text.unsent-message i {
    font-style: italic;
}

/* Delete icon indicator */
.message-text.unsent-message::before {
    content: '🚫 ';
    font-style: normal;
    opacity: 0.8;
}

/* Voice message styles */
.message-voice {
    display: flex;
    align-items: center;
    gap: 8px;
    min-width: 220px;
    max-width: 280px;
    padding: 5px 0;
}

.voice-play {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    background: var(--accent);
    border: none;
    color: white;
    font-size: 16px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s;
    flex-shrink: 0;
    box-shadow: 0 2px 5px rgba(0,0,0,0.2);
}

.voice-play:hover {
    transform: scale(1.1);
    background: var(--accent);
    box-shadow: 0 3px 8px rgba(0,0,0,0.3);
}

.voice-play.playing {
    background: #ff4444;
}

.voice-wave-container {
    flex: 1;
    height: 40px;
    display: flex;
    align-items: center;
    gap: 2px;
    cursor: pointer;
    padding: 0 2px;
    background: var(--bg-secondary);
    border-radius: 20px;
    overflow: hidden;
}

.voice-wave-bar {
    flex: 1;
    background: var(--accent);
    opacity: 0.5;
    border-radius: 2px;
    min-width: 2px;
    transition: opacity 0.2s, background 0.2s;
}

.voice-wave-bar.active {
    opacity: 1;
    background: var(--accent);
}

.message-own .voice-wave-bar {
    background: #00ff88;
}

.message-own .voice-wave-bar.active {
    background: #00ff88;
}

.voice-wave-container:hover .voice-wave-bar {
    opacity: 0.8;
}

.voice-duration {
    font-size: 12px;
    color: var(--text-muted);
    min-width: 70px;
    text-align: right;
    font-family: monospace;
}
</style>