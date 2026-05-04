const express = require('express');
const http = require('http');
const { Server } = require('socket.io');
const mysql = require('mysql2/promise');
const cors = require('cors');
const webpush = require('web-push');
require('dotenv').config();

// VAPID Keys for Push Notifications
if (process.env.VAPID_PUBLIC_KEY && process.env.VAPID_PRIVATE_KEY) {
  try {
    webpush.setVapidDetails(
      process.env.VAPID_EMAIL || 'mailto:admin@connectxion.com',
      process.env.VAPID_PUBLIC_KEY,
      process.env.VAPID_PRIVATE_KEY
    );
    console.log('✅ VAPID Details Set');
  } catch (err) {
    console.error('⚠️ VAPID Setup Error:', err.message);
  }
} else {
  console.warn('⚠️ Push Notifications disabled: VAPID keys missing in Environment Variables.');
}

const app = express();
const server = http.createServer(app);

// Health Check for Render/Deployment
app.get('/health', (req, res) => {
  res.status(200).send('OK');
});

// Root Route (Welcome Message)
app.get('/', (req, res) => {
  res.status(200).send('<h1>ConnectXion Signaling Server is LIVE 🚀</h1>');
});

// Middleware
app.use(cors());
app.use(express.json());

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

function filterProfanity(text) {
  if (typeof text !== 'string') return text;
  let filtered = text;
  const sortedWords = [...badWords].sort((a, b) => b.length - a.length);
  
  sortedWords.forEach(word => {
    const reg = new RegExp('\\b' + word.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '\\b', 'gi');
    filtered = filtered.replace(reg, '*'.repeat(word.length));
  });
  return filtered;
}

const io = new Server(server, {
  cors: {
    origin: "*",
    methods: ["GET", "POST"]
  }
});

// Database Connection Pool (With auto-clean for Host)
const rawHost = process.env.DB_HOST || 'localhost';
const cleanHost = rawHost.trim().replace(/^https?:\/\//, ''); // Remove spaces and http://

// DNS Debug Test
const dns = require('dns');
console.log(`🔍 Debug: Looking up DNS for [${cleanHost}]...`);
dns.lookup(cleanHost, (err, address, family) => {
  if (err) {
    console.error(`❌ DNS Lookup Failed for [${cleanHost}]:`, err.message);
  } else {
    console.log(`✅ DNS Lookup Success: [${cleanHost}] resolved to [${address}] (IPv${family})`);
  }
});

const dbConfig = {
  host: cleanHost,
  user: (process.env.DB_USER || 'root').trim(),
  password: (process.env.DB_PASSWORD || '').trim(),
  database: (process.env.DB_NAME || 'connectxion').trim(),
  waitForConnections: true,
  connectionLimit: 10,
  queueLimit: 0
};

console.log('📡 Connecting to DB:', { ...dbConfig, host: cleanHost, password: '****' });
const db = mysql.createPool(dbConfig);

// Test Connection
db.getConnection()
  .then(conn => {
    console.log('✅ MySQL Connected to Pool');
    conn.release();
  })
  .catch(err => {
    console.error('❌ MySQL Connection Failed:', err.message);
  });

// API Endpoints
app.post('/api/new-message', (req, res) => {
  const { sender_id, receiver_id, message, sender_name, message_id, message_type = 'text' } = req.body;
  io.to(`user_${receiver_id}`).emit('receive_message', { 
    ...req.body, 
    time: new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) 
  });

  // Also send Push Notification
  sendPush(receiver_id, `New message from ${sender_name}`, message, 'icons/icon-192.png', `chat.php?id=${sender_id}`);
  
  res.status(200).json({ success: true });
});

app.post('/api/new-group-message', (req, res) => {
  const { sender_id, group_id, message, sender_name, message_id, message_type = 'text' } = req.body;
  io.to(`group_${group_id}`).emit('receive_group_message', { 
    ...req.body, 
    time: new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) 
  });

  // Also send Push Notification (we could fetch all group members, but let's just use the General logic for now)
  // For group messages, we'd need a way to notify everyone EXCEPT the sender.
  // This requires a DB call to get member IDs.
  notifyGroupMembers(group_id, sender_id, `Group: ${sender_name}`, message);

  res.status(200).json({ success: true });
});

app.post('/api/send-push', async (req, res) => {
  const { user_id, title, body, icon, url } = req.body;
  
  try {
    const [rows] = await db.execute('SELECT * FROM push_subscriptions WHERE user_id = ?', [user_id]);
    
    if (rows.length === 0) {
      return res.status(404).json({ success: false, error: 'No subscriptions found' });
    }

    const payload = JSON.stringify({ title, body, icon, url });
    
    const results = await Promise.allSettled(rows.map(sub => {
      const pushSubscription = {
        endpoint: sub.endpoint,
        keys: {
          p256dh: sub.p256dh_key,
          auth: sub.auth_key
        }
      };
      return webpush.sendNotification(pushSubscription, payload);
    }));

    res.status(200).json({ success: true, results });
  } catch (err) {
    console.error('❌ Push API Error:', err.message);
    res.status(500).json({ success: false, error: err.message });
  }
});

// Helper Functions
async function sendPush(user_id, title, body, icon = 'icons/icon-192.png', url = 'home.php') {
  try {
    const [rows] = await db.execute('SELECT * FROM push_subscriptions WHERE user_id = ?', [user_id]);
    if (rows.length === 0) return;

    const payload = JSON.stringify({ title, body, icon, url });
    rows.forEach(sub => {
      const pushSubscription = {
        endpoint: sub.endpoint,
        keys: { p256dh: sub.p256dh_key, auth: sub.auth_key }
      };
      webpush.sendNotification(pushSubscription, payload).catch(err => {
        if (err.statusCode === 410 || err.statusCode === 404) {
          // Subscription has expired or is no longer valid, delete it
          db.execute('DELETE FROM push_subscriptions WHERE id = ?', [sub.id]);
        }
      });
    });
  } catch (err) {
    console.error('❌ sendPush Error:', err.message);
  }
}

async function notifyGroupMembers(group_id, exclude_user_id, title, body) {
  try {
    const [members] = await db.execute('SELECT user_id FROM chat_room_members WHERE room_id = ? AND user_id != ?', [group_id, exclude_user_id]);
    members.forEach(m => {
      sendPush(m.user_id, title, body, 'icons/icon-192.png', `group_chat.php?room_id=${group_id}`);
    });
  } catch (err) {
    console.error('❌ notifyGroupMembers Error:', err.message);
  }
}

io.on('connection', (socket) => {
  console.log('👤 Socket connected:', socket.id);

  socket.on('join', (userId) => {
    socket.join(`user_${userId}`);
    console.log(`User ${userId} joined their private room`);
  });

  socket.on('join_group', (groupId) => {
    socket.join(`group_${groupId}`);
    console.log(`User joined group ${groupId}`);
  });

  socket.on('send_group_message', async (data) => {
    const { sender_id, group_id, message, message_type = 'text', sender_name } = data;
    const filteredMessage = filterProfanity(message);
    
    try {
      const [result] = await db.execute(
        'INSERT INTO group_messages (user_id, room_id, message, message_type, created_at) VALUES (?, ?, ?, ?, NOW())',
        [sender_id, group_id, filteredMessage, message_type]
      );
      
      const messageData = {
        ...data,
        message_id: result.insertId,
        id: result.insertId,
        message: filteredMessage,
        time: new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
      };
      
      io.to(`group_${group_id}`).emit('receive_group_message', messageData);
      
      // Also send Push Notifications to group members
      notifyGroupMembers(group_id, sender_id, `Group: ${sender_name}`, filteredMessage);
    } catch (err) {
      console.error('❌ Group Message Error:', err.message);
    }
  });

  socket.on('group_typing', (data) => {
    socket.to(`group_${data.group_id}`).emit('group_typing_status', data);
  });

  socket.on('disconnect', () => {
    console.log('👋 User disconnected');
  });
});

const PORT = process.env.PORT || 10000; // Render uses 10000 by default
server.listen(PORT, '0.0.0.0', () => {
  console.log(`🚀 Server is LIVE on port ${PORT}`);
});
