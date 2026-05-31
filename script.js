// ============================================================
//  script.js — Frontend Logic
//  Sri Lanka Travel Chatbot (with Gemini AI indicator)
// ============================================================

const messagesEl  = document.getElementById('messages');
const userInputEl = document.getElementById('userInput');
const sendBtn     = document.getElementById('sendBtn');
const learnPanel  = document.getElementById('learnPanel');
const learnInput  = document.getElementById('learnInput');

let sessionId = sessionStorage.getItem('chatSession') || '';
let lastUnknownQuestion = '';
let isWaiting = false;

// ============================================================
//  Welcome message on load
// ============================================================
window.addEventListener('DOMContentLoaded', () => {
    addBotMessage(
        "Ayubowan! 🌺 Welcome to the Sri Lanka Travel Assistant!\n\n" +
        "I can help you with:\n" +
        "• 🗺️ Destinations (Sigiriya, Ella, Galle...)\n" +
        "• 🧳 Tour packages & itineraries\n" +
        "• 🏨 Hotel recommendations\n" +
        "• 🛂 Visa & entry requirements\n" +
        "• 💰 Budget & travel tips\n\n" +
        "Powered by Google Gemini AI ✨ — ask me anything!",
        false
    );
});

// ============================================================
//  Send message (form submit)
// ============================================================
function sendMessage(event) {
    event.preventDefault();
    const text = userInputEl.value.trim();
    if (!text || isWaiting) return;

    if (learnPanel.style.display !== 'none') {
        learnInput.value = text;
        submitLearning();
        userInputEl.value = '';
        return;
    }

    addUserMessage(text);
    userInputEl.value = '';
    sendToBackend(text);
}

function sendQuick(text) {
    if (isWaiting) return;
    addUserMessage(text);
    sendToBackend(text);
}

// ============================================================
//  Send to PHP backend
// ============================================================
async function sendToBackend(message) {
    isWaiting = true;
    sendBtn.disabled = true;
    hideLearning();

    const typingId = showTyping();

    try {
        const response = await fetch('chat.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({ message, session_id: sessionId })
        });

        const data = await response.json();
        removeTyping(typingId);

        if (data.session_id) {
            sessionId = data.session_id;
            sessionStorage.setItem('chatSession', sessionId);
        }

        // Show AI badge if Gemini was used
        addBotMessage(data.reply || 'Sorry, something went wrong.', data.used_ai === true);

        // If unknown intent reached Gemini, offer learning anyway
        if (data.intent === 'unknown') {
            lastUnknownQuestion = message;
            showLearning();
        }

    } catch (error) {
        removeTyping(typingId);
        addBotMessage("⚠️ Couldn't connect to the server. Make sure PHP is running and your Gemini API key is set in config.php.", false);
    }

    isWaiting = false;
    sendBtn.disabled = false;
    userInputEl.focus();
}

// ============================================================
//  Machine Learning panel
// ============================================================
function showLearning() {
    learnPanel.style.display = 'block';
    learnInput.value = '';
    learnInput.focus();
}
function hideLearning() {
    learnPanel.style.display = 'none';
    learnInput.value = '';
    lastUnknownQuestion = '';
}
async function submitLearning() {
    const answer = learnInput.value.trim();
    if (!answer) { hideLearning(); return; }
    try {
        const res  = await fetch('learn.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({ question: lastUnknownQuestion, answer })
        });
        const data = await res.json();
        addBotMessage(data.message || 'Got it, I will remember that!', false);
    } catch {
        addBotMessage('Sorry, I could not save that answer right now.', false);
    }
    hideLearning();
}
learnInput.addEventListener('keydown', e => { if (e.key === 'Enter') submitLearning(); });

// ============================================================
//  Message rendering
// ============================================================
function addUserMessage(text) {
    const div = document.createElement('div');
    div.className = 'message user';
    div.innerHTML = `
        <div class="msg-avatar">👤</div>
        <div>
            <div class="bubble">${escapeHtml(text)}</div>
            <span class="msg-time">${getTime()}</span>
        </div>`;
    messagesEl.appendChild(div);
    scrollBottom();
}

function addBotMessage(text, usedAI = false) {
    const div = document.createElement('div');
    div.className = 'message bot';
    const formatted = escapeHtml(text).replace(/\*(.*?)\*/g, '<strong>$1</strong>');
    const aiBadge   = usedAI
        ? `<span class="ai-badge">✨ Gemini AI</span>`
        : '';
    div.innerHTML = `
        <div class="msg-avatar">🌺</div>
        <div>
            <div class="bubble">${formatted}${aiBadge}</div>
            <span class="msg-time">${getTime()}</span>
        </div>`;
    messagesEl.appendChild(div);
    scrollBottom();
}

function showTyping() {
    const id  = 'typing-' + Date.now();
    const div = document.createElement('div');
    div.className = 'message bot typing';
    div.id = id;
    div.innerHTML = `
        <div class="msg-avatar">🌺</div>
        <div class="bubble">
            <div class="dot"></div><div class="dot"></div><div class="dot"></div>
        </div>`;
    messagesEl.appendChild(div);
    scrollBottom();
    return id;
}
function removeTyping(id) {
    const el = document.getElementById(id);
    if (el) el.remove();
}

function clearChat() {
    messagesEl.innerHTML = '';
    hideLearning();
    addBotMessage("Chat cleared! How can I help plan your Sri Lanka adventure? 🌴", false);
}

function scrollBottom() { messagesEl.scrollTo({ top: messagesEl.scrollHeight, behavior: 'smooth' }); }
function getTime() { return new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }); }
function escapeHtml(text) {
    return text.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}