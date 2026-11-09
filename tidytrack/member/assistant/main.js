let roomCode = null; // for meetings if needed

function toggleAssistant() {
    const chat = document.getElementById('tsmAssistantChat');
    chat.classList.toggle('tsm-chat-hidden');
    if (!chat.classList.contains('tsm-chat-hidden')) {
        addBotMessage("Hi! I'm TSM Assistant 👋<br>I know everything about your tasks, meetings, messages, attendance, timesheet, leave, and more.<br><br>Ask me anything or type 'send to [name]: [message]' to send a message!");
        fetchAlerts();
        setInterval(fetchAlerts, 30000); // check every 30s
    }
}

document.getElementById('tsmAssistantBubble').onclick = toggleAssistant;

function addBotMessage(text) {
    const div = document.createElement('div');
    div.className = 'message bot-message';
    div.innerHTML = text;
    document.getElementById('chatMessages').appendChild(div);
    scrollToBottom();
}

function addUserMessage(text) {
    const div = document.createElement('div');
    div.className = 'message user-message';
    div.textContent = text;
    document.getElementById('chatMessages').appendChild(div);
    scrollToBottom();
}

function scrollToBottom() {
    const chat = document.getElementById('chatMessages');
    chat.scrollTop = chat.scrollHeight;
}

document.getElementById('sendBtn').onclick = sendMessage;
document.getElementById('userInput').addEventListener('keypress', e => {
    if (e.key === 'Enter') sendMessage();
});

async function sendMessage() {
    const input = document.getElementById('userInput');
    const text = input.value.trim();
    if (!text) return;
    addUserMessage(text);
    input.value = '';

    const res = await fetch('assistant/process.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({message: text})
    });
    const data = await res.json();
    addBotMessage(data.response);
}

async function fetchAlerts() {
    const res = await fetch('assistant/alerts.php');
    const alerts = await res.json();
    if (alerts.length > 0) {
        alerts.forEach(a => addBotMessage(`<strong>🔔 Alert:</strong> ${a}`));
    }
}

// Auto open on first visit
if (!localStorage.getItem('tsmAssistantSeen')) {
    setTimeout(toggleAssistant, 3000);
    localStorage.setItem('tsmAssistantSeen', 'true');
}