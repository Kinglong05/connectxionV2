import express from 'express';
import { createServer } from 'http';
import { Server, Socket } from 'socket.io';
import cors from 'cors';
import dotenv from 'dotenv';
import mysql from 'mysql2/promise';

dotenv.config();

const app = express();
app.use(cors());
app.use(express.json());

const httpServer = createServer(app);
const io = new Server(httpServer, {
    cors: {
        origin: process.env.ALLOWED_ORIGINS?.split(',') || "*",
        methods: ["GET", "POST"]
    }
});

// Database Connection Pool (PlanetScale Compatible)
const db = mysql.createPool({
    host: process.env.DB_HOST,
    user: process.env.DB_USER,
    password: process.env.DB_PASS,
    database: process.env.DB_NAME,
    ssl: {
        rejectUnauthorized: false
    }
});

interface ChatMessage {
    sender_id: number;
    receiver_id?: number;
    room_id?: number;
    message: string;
    message_type: 'text' | 'image' | 'voice' | 'file';
}

io.on('connection', (socket: Socket) => {
    console.log(`👤 User connected: ${socket.id}`);

    socket.on('join_room', (roomId: string) => {
        socket.join(roomId);
        console.log(`🚪 User joined room: ${roomId}`);
    });

    socket.on('send_message', async (data: ChatMessage) => {
        try {
            // 1. Persistence Layer
            let insertId: number;
            if (data.room_id) {
                // Group Message
                const [result]: any = await db.execute(
                    'INSERT INTO group_messages (room_id, user_id, message, message_type, created_at) VALUES (?, ?, ?, ?, NOW())',
                    [data.room_id, data.sender_id, data.message, data.message_type]
                );
                insertId = result.insertId;

                // Broadcast to Room
                io.to(data.room_id.toString()).emit('receive_message', {
                    ...data,
                    id: insertId,
                    created_at: new Date()
                });
            } else if (data.receiver_id) {
                // Private Message
                const [result]: any = await db.execute(
                    'INSERT INTO messages (sender_id, receiver_id, message, message_type, created_at) VALUES (?, ?, ?, ?, NOW())',
                    [data.sender_id, data.receiver_id, data.message, data.message_type]
                );
                insertId = result.insertId;

                // Emit to Specific User (if online)
                io.to(`user_${data.receiver_id}`).emit('receive_message', {
                    ...data,
                    id: insertId,
                    created_at: new Date()
                });
            }

        } catch (error) {
            console.error('❌ Error saving message:', error);
            socket.emit('error', { message: 'Failed to send message' });
        }
    });

    // Helper to join user-specific room for private messages
    socket.on('auth_user', (userId: number) => {
        socket.join(`user_${userId}`);
        console.log(`🔑 User authenticated for private messaging: ${userId}`);
    });

    socket.on('typing', (data: { room_id: string, username: string, is_typing: boolean }) => {
        socket.to(data.room_id).emit('user_typing', data);
    });

    socket.on('disconnect', () => {
        console.log('👋 User disconnected');
    });
});

const PORT = process.env.PORT || 3000;
httpServer.listen(PORT, () => {
    console.log(`🚀 Real-time server running on port ${PORT}`);
});
