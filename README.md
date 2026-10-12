# AI Chatbot — React + PHP + MySQL

A starter full-stack AI chatbot project with:
- React frontend (Vite)
- PHP REST API
- MySQL conversation/message storage
- OpenAI-compatible Chat Completions API integration
- Environment-based API key configuration

## Requirements
- PHP 8.1+ with `curl` and `pdo_mysql` extensions
- MySQL 8+ or MariaDB
- Node.js 18+
- An API key for an OpenAI-compatible chat API

## 1. Database setup
Create the database and tables by importing `database/schema.sql` into MySQL.

## 2. Configure the PHP backend
Copy `backend/.env.example` to `backend/.env` and fill in your database settings and API key.

Do not commit `backend/.env` to GitHub. The included `.gitignore` excludes it.

Run the PHP development server from the project root:
```bash
php -S localhost:8000 -t backend
```

The API will be available at `http://localhost:8000/api.php`.

## 3. Run the React frontend
In a second terminal:
```bash
cd frontend
npm install
npm run dev
```
Open the local URL Vite prints (usually `http://localhost:5173`).

## API behavior
- `GET api.php?action=conversations` — list recent conversations
- `POST api.php?action=chat` — send `{ "message": "...", "conversation_id": 1 }`
- `GET api.php?action=messages&conversation_id=1` — fetch conversation messages

The backend creates a new conversation if `conversation_id` is omitted.

## Security notes
- This is a learning starter, not production-ready.
- Add authentication and authorization before allowing multiple users.
- Use HTTPS in production and configure CORS for your real frontend origin.
- Set request limits, rate limiting, logging, and stronger input validation before deployment.
- Keep API keys on the server only; never place them in React/Vite environment variables.
- Never upload real secrets or `.env` files to GitHub.
