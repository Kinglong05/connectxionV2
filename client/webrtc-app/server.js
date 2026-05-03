const express = require("express");
const http = require("http");
const { Server } = require("socket.io");

const app = express();
const server = http.createServer(app);
const io = new Server(server, {
    cors: {
        origin: "*",
        methods: ["GET", "POST"]
    }
});

app.use(express.static("public"));
app.use(express.json());

// Store online users
const onlineUsers = new Map();

io.on("connection", (socket) => {
    console.log("User connected:", socket.id);
    
    socket.on("join-room", (roomId) => {
        socket.join(roomId);
        console.log(`${socket.id} joined room ${roomId}`);
        
        // Store user's room
        if (roomId.startsWith('user_')) {
            const userId = roomId.replace('user_', '');
            onlineUsers.set(userId, socket.id);
            socket.userId = userId;
            
            // Broadcast online status to friends
            socket.broadcast.emit('user-online', { user_id: userId });
        }
    });
    
    // Handle new chat message
    socket.on("send-message", (data) => {
        console.log("New message:", data);
        
        // Send to receiver's private room
        const receiverRoom = `user_${data.receiver_id}`;
        io.to(receiverRoom).emit("new-message", data);
        
        // Also send to the chat room
        const chatRoom = `chat_${data.sender_id}_${data.receiver_id}`;
        io.to(chatRoom).emit("new-message", data);
        
        // Send delivery confirmation
        socket.emit("message-status", {
            message_id: data.message_id,
            status: "delivered"
        });
    });
    
    // Handle typing indicator
    socket.on("typing", (data) => {
        const receiverRoom = `user_${data.receiver_id}`;
        io.to(receiverRoom).emit("typing", {
            user_id: data.user_id,
            typing: data.typing
        });
    });
    
    // Handle read receipts
    socket.on("messages-read", (data) => {
        const senderRoom = `user_${data.sender_id}`;
        io.to(senderRoom).emit("messages-read", {
            reader_id: data.reader_id,
            sender_id: data.sender_id
        });
    });
    
    // Handle disconnect
    socket.on("disconnect", () => {
        if (socket.userId) {
            onlineUsers.delete(socket.userId);
            socket.broadcast.emit("user-offline", { user_id: socket.userId });
        }
        console.log("User disconnected:", socket.id);
    });
});

// API endpoint for PHP to notify about new messages
app.post("/api/new-message", (req, res) => {
    const data = req.body;
    const receiverRoom = `user_${data.receiver_id}`;
    io.to(receiverRoom).emit("new-message", data);
    res.json({ success: true });
});

const PORT = 3000;
server.listen(PORT, () => {
    console.log(`WebSocket server running at http://localhost:${PORT}`);
});