<?php
require_once 'db.php';
require_once 'profanity_filter.php';
requireLogin();

$user_id = $_SESSION['user_id'];
$room_id = isset($_GET['room_id']) ? (int)$_GET['room_id'] : 0;
$offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
$limit = 200;

if (!$room_id) {
    echo '<div class="empty-state"><p>Invalid group</p></div>';
    exit;
}

// Check if user is member
$check = $conn->prepare("SELECT * FROM chat_room_members WHERE room_id = ? AND user_id = ?");
$check->bind_param("ii", $room_id, $user_id);
$check->execute();
$result = $check->get_result();

if ($result->num_rows === 0) {
    echo '<div class="empty-state"><p>You are not a member of this group (Room: '.$room_id.', User: '.$user_id.')</p></div>';
    $check->close();
    exit;
}
$check->close();

// Get messages
$sql = "
    SELECT gm.*, u.username, u.avatar
    FROM group_messages gm
    LEFT JOIN users u ON u.user_id = gm.user_id
    WHERE gm.room_id = $room_id AND gm.is_deleted = 0
    ORDER BY gm.created_at ASC
    LIMIT $limit OFFSET $offset
";

$result = $conn->query($sql);

if (!$result || $result->num_rows === 0) {
    if ($offset === 0) {
        echo '<div class="empty-state"><p>No messages yet. Start the conversation! (Room: '.$room_id.', User: '.$user_id.')</p></div>';
    }
    exit;
}

// Get total messages count
$count_result = $conn->query("SELECT COUNT(*) as total FROM group_messages WHERE room_id = $room_id AND is_deleted = 0");
$total_messages = $count_result->fetch_assoc()['total'];

// Helper functions
function formatDate($timestamp) {
    $date = date('Y-m-d', strtotime($timestamp));
    $today = date('Y-m-d');
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    
    if ($date == $today) return 'Today';
    if ($date == $yesterday) return 'Yesterday';
    return date('F j, Y', strtotime($timestamp));
}

$current_date = '';

while ($msg = $result->fetch_assoc()) {
    $msg_date = date('Y-m-d', strtotime($msg['created_at']));
    
    // Date separator
    if ($msg_date != $current_date) {
        $current_date = $msg_date;
        echo '<div class="date-separator"><span>' . formatDate($msg['created_at']) . '</span></div>';
    }
    
    $is_own = ($msg['user_id'] == $user_id);
    $class = $is_own ? 'message-own' : 'message-other';
    
    $username = $msg['username'] ?? 'Deleted User';
    echo "<div class='message-wrapper $class' data-id='{$msg['id']}'>";
    echo "<div class='message'>";
    
    echo "<div class='message-header'>";
    echo "<span class='message-sender'>" . htmlspecialchars($username) . "</span>";
    echo "<span class='message-time'>" . date('h:i A', strtotime($msg['created_at'])) . " [ID: #{$msg['id']}]</span>";
    echo "</div>";
    
    echo "<div class='message-bubble'>";
    
    // Check if edited
    $edited = isset($msg['is_edited']) && $msg['is_edited'] == 1 ? ' <span class="edited-indicator">(edited)</span>' : '';
    
    // Message content
    if ($msg['message_type'] == 'text') {
        echo "<span class='message-text'>" . nl2br(htmlspecialchars(filterProfanity($msg['message']))) . $edited . "</span>";
    } elseif ($msg['message_type'] == 'image') {
        echo "<div class='message-image-container'>";
        echo "<img src='" . htmlspecialchars($msg['file_path']) . "' class='message-image' onclick='showImagePreview(\"" . htmlspecialchars($msg['file_path']) . "\")' loading='lazy'>";
        echo "</div>";
    } elseif ($msg['message_type'] == 'file') {
        echo "<a href='" . htmlspecialchars($msg['file_path']) . "' class='message-file' download>";
        echo "<span class='file-icon'>📎</span>";
        echo "<span class='file-name'>" . htmlspecialchars($msg['file_name']) . "</span>";
        echo "</a>";
    } elseif ($msg['message_type'] == 'voice') {
        $audio_url = htmlspecialchars($msg['file_path']);
        $message_id = $msg['id'];
        echo '<div class="message-voice">';
        echo '<button class="voice-play" onclick="playVoiceMessage(\'' . $audio_url . '\', this, ' . $message_id . ')" data-message-id="' . $message_id . '" title="PLAY">▶️</button>';
        echo '<div class="voice-wave-container" data-message-id="' . $message_id . '">';
        for ($i = 0; $i < 30; $i++) {
            $height = rand(8, 30);
            echo '<div class="voice-wave-bar" data-message-id="' . $message_id . '" style="height: ' . $height . 'px;"></div>';
        }
        echo '</div>';
        echo '<span class="voice-duration" id="voice-time-' . $message_id . '">00:00/--:--</span>';
        echo '</div>';
    }
    
    // Get reactions for this message
    $reactions = [];
    $react_sql = "
        SELECT gr.*, u.username 
        FROM group_message_reactions gr 
        JOIN users u ON u.user_id = gr.user_id 
        WHERE gr.message_id = {$msg['id']}
    ";
    $react_res = $conn->query($react_sql);
    if ($react_res) {
        while ($r = $react_res->fetch_assoc()) {
            $emoji = $r['reaction'];
            if (!isset($reactions[$emoji])) {
                $reactions[$emoji] = ['count' => 0, 'users' => []];
            }
            $reactions[$emoji]['count']++;
            $reactions[$emoji]['users'][] = $r['username'];
        }
    }

    // Show reactions inside the bubble
    if (!empty($reactions)) {
        echo "<div class='message-reactions'>";
        foreach ($reactions as $emoji => $data) {
            $user_list = implode(', ', $data['users']);
            echo "<span class='reaction' onclick='addGroupReaction({$msg['id']}, \"$emoji\")' title='" . htmlspecialchars($user_list) . "'>";
            echo "$emoji <span class='reaction-count'>" . $data['count'] . "</span>";
            echo "</span>";
        }
        echo "</div>";
    }
    
    echo "</div>"; // close message-bubble
    
    // Message Actions
    echo "<div class='message-actions'>";
    echo "<button class='msg-action react' onclick='showReactionPicker({$msg['id']}, event)' title='REACT'>😊</button>";
    echo "<button class='msg-action reply' onclick='setReply({$msg['id']}, \"" . addslashes($msg['message']) . "\", \"" . addslashes($msg['username']) . "\")' title='REPLY'>↩️</button>";
    if ($is_own) {
        echo "<button class='msg-action edit' onclick='editMessage({$msg['id']}, \"" . addslashes($msg['message']) . "\")' title='EDIT'>✏️</button>";
        echo "<button class='msg-action delete' onclick='deleteMessage({$msg['id']})' title='DELETE'>🗑️</button>";
    }
    echo "</div>";
    
    echo "</div>"; // close message
    echo "</div>"; // close message-wrapper
}

// Show load more button
if ($offset + $limit < $total_messages) {
    echo "<div class='load-more-container'>";
    echo "<button class='load-more-btn' onclick='loadMoreMessages(" . ($offset + $limit) . ")'>LOAD MORE</button>";
    echo "</div>";
}
?>