import { useEffect, useRef, useState } from 'react';

const API = '/api.php';

export default function App() {
  const [conversations, setConversations] = useState([]);
  const [conversationId, setConversationId] = useState(null);
  const [messages, setMessages] = useState([]);
  const [input, setInput] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const endRef = useRef(null);

  useEffect(() => { loadConversations(); }, []);
  useEffect(() => { endRef.current?.scrollIntoView({ behavior: 'smooth' }); }, [messages, busy]);

  async function loadConversations() {
    try {
      const response = await fetch(`${API}?action=conversations`);
      const data = await response.json();
      if (response.ok) setConversations(data.conversations || []);
    } catch {
      setError('Could not connect to the backend. Start the PHP server and try again.');
    }
  }

  async function openConversation(id) {
    setError('');
    setConversationId(Number(id));
    try {
      const response = await fetch(`${API}?action=messages&conversation_id=${id}`);
      const data = await response.json();
      if (!response.ok) throw new Error(data.error || 'Could not load messages.');
      setMessages((data.messages || []).map((m) => ({ role: m.role, content: m.content })));
    } catch (err) {
      setError(err.message);
    }
  }

  function newChat() {
    setConversationId(null);
    setMessages([]);
    setInput('');
    setError('');
  }

  async function sendMessage(event) {
    event.preventDefault();
    const text = input.trim();
    if (!text || busy) return;

    setError('');
    setMessages((current) => [...current, { role: 'user', content: text }]);
    setInput('');
    setBusy(true);

    try {
      const response = await fetch(`${API}?action=chat`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ message: text, conversation_id: conversationId }),
      });
      const data = await response.json();
      if (!response.ok) throw new Error(data.error || 'The AI request failed.');
      setConversationId(data.conversation_id);
      setMessages((current) => [...current, { role: 'assistant', content: data.reply }]);
      await loadConversations();
    } catch (err) {
      setError(err.message);
      setMessages((current) => current.filter((m, index) => !(index === current.length - 1 && m.role === 'user' && m.content === text)));
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="app-shell">
      <aside className="sidebar">
        <div className="brand"><span className="brand-mark">✦</span><div><strong>Oviro AI</strong><small>Personal assistant</small></div></div>
        <button className="new-chat" onClick={newChat}>＋ New chat</button>
        <p className="section-label">RECENT CHATS</p>
        <div className="conversation-list">
          {conversations.map((chat) => (
            <button className={`conversation ${Number(conversationId) === Number(chat.id) ? 'active' : ''}`} key={chat.id} onClick={() => openConversation(chat.id)}>
              <span>▤</span><span className="conversation-title">{chat.title}</span>
            </button>
          ))}
          {conversations.length === 0 && <p className="empty-history">Your saved chats will appear here.</p>}
        </div>
        <div className="sidebar-footer"><span className="status-dot" /> PHP + MySQL connected app</div>
      </aside>

      <main className="main-panel">
        <header className="topbar"><span>AI Assistant</span><span className="model-pill">● AI Chat</span></header>
        <section className="chat-area">
          {messages.length === 0 ? (
            <div className="welcome">
              <div className="welcome-icon">✦</div>
              <p className="eyebrow">YOUR AI WORKSPACE</p>
              <h1>What can I help you<br /><em>with today?</em></h1>
              <p className="welcome-copy">Ask a question, explore an idea, or get help with your next project.</p>
              <div className="suggestions">
                {['Explain PHP prepared statements', 'Help me design a MySQL schema', 'Give me React project ideas'].map((suggestion) => (
                  <button key={suggestion} onClick={() => setInput(suggestion)}>{suggestion}<span>↗</span></button>
                ))}
              </div>
            </div>
          ) : (
            <div className="messages">
              {messages.map((message, index) => (
                <article className={`message ${message.role}`} key={`${index}-${message.role}`}>
                  <div className="avatar">{message.role === 'assistant' ? '✦' : 'You'}</div>
                  <div className="message-body"><div className="message-label">{message.role === 'assistant' ? 'Oviro AI' : 'YOU'}</div><p>{message.content}</p></div>
                </article>
              ))}
              {busy && <article className="message assistant"><div className="avatar">✦</div><div className="message-body"><div className="message-label">Oviro AI</div><p className="typing">Thinking<span>.</span><span>.</span><span>.</span></p></div></article>}
              <div ref={endRef} />
            </div>
          )}
        </section>

        {error && <div className="error-banner" role="alert">{error}</div>}
        <div className="composer-wrap">
          <form className="composer" onSubmit={sendMessage}>
            <textarea value={input} onChange={(e) => setInput(e.target.value)} onKeyDown={(e) => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendMessage(e); } }} placeholder="Message Oviro AI..." rows="1" maxLength="8000" />
            <button className="send-button" type="submit" disabled={busy || !input.trim()} aria-label="Send message">↑</button>
          </form>
          <p className="disclaimer">AI can make mistakes. Check important information. Press Enter to send · Shift + Enter for a new line.</p>
        </div>
      </main>
    </div>
  );
}
