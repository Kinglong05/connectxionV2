# 🚀 ConnectXion v2.0 - Deployment Guide

This guide walkthrough the steps to deploy the modernized ConnectXion stack to the cloud.

## 1. ☁️ Database Setup (PlanetScale)
1. Create an account at [PlanetScale](https://planetscale.com/).
2. Create a new database named `connectxion`.
3. Go to **Settings** -> **Database Settings** and enable **"Automatically manage primary keys"**.
4. Generate **Connection Strings** and select **PHP (PDO)**.
5. Save the `HOST`, `DATABASE`, `USERNAME`, and `PASSWORD` for your `.env`.

## 2. 🖼️ File Storage Setup (Cloudinary)
1. Create an account at [Cloudinary](https://cloudinary.com/).
2. Go to your **Dashboard** and copy your **Cloud Name**, **API Key**, and **API Secret**.

## 3. 🌐 Backend Deployment (Render - PHP)
1. Push your `v2/backend` and `v2/frontend` folders to a GitHub repository.
2. Create a new **Web Service** on Render.
3. Connect your repository.
4. Set **Build Command**: `(empty)`
5. Set **Start Command**: `php -S 0.0.0.0:$PORT` (or use a Dockerfile for Apache).
6. Go to **Environment Variables** and add:
   - `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS` (from PlanetScale)
   - `CLOUDINARY_CLOUD_NAME`, `CLOUDINARY_API_KEY`, `CLOUDINARY_API_SECRET`
   - `NODE_URL` (Set this AFTER deploying the Node server)

## 4. ⚡ Real-Time Server Deployment (Render - Node.js)
1. Create a new **Web Service** on Render.
2. Connect your repository (pointing to the `v2/server` directory).
3. Set **Build Command**: `npm install && npm run build`
4. Set **Start Command**: `npm start`
5. Go to **Environment Variables** and add your PlanetScale credentials.

## 🧪 Testing the Deployment
1. Open your Render PHP URL.
2. Create a new account.
3. Open two different browsers and login as different users.
4. Join a group and test the instant messaging.
5. Upload an image to verify Cloudinary integration.

---
**Senior Engineer Note:** Ensure `ALLOWED_ORIGINS` in your .env includes your Render URLs to prevent CORS errors!
