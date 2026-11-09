<?php $userName = $_SESSION['name'] ?? 'Member'; ?>

<link rel="stylesheet" href="assistant/style.css?v=<?php echo time(); ?>">

<div id="tsmBotBubble" class="tsm-bot-bubble">
    <img src="assistant/bot-avatar.png" alt="TSM Assistant">
    <div class="online-dot"></div>
</div>

<div id="tsmBotWindow">
    <div class="bot-header">
        <div class="bot-info">
            <img src="assistant/bot-avatar.png" width="40" alt="Bot">
            <div>
                <div class="bot-name">TSM Assistant</div>
                <div class="bot-status">Online • Ready to help</div>
            </div>
        </div>
        <button class="bot-close">×</button>
    </div>
    <div class="bot-messages" id="botMessages">
        <div class="bot-msg">
            Hello <strong><?php echo htmlspecialchars($userName); ?></strong>!<br>
            I'm your personal assistant<br><br>
            Ask me anything — tasks, messages, leave, meetings...
        </div>
    </div>
    <div class="bot-quick">
        <button data-msg="my tasks">My Tasks</button>
        <button data-msg="messages">Messages</button>
        <button data-msg="who is my leader">My Leader</button>
    </div>
    <form id="botForm" class="bot-input">
        <input type="text" id="botInput" placeholder="Type a message..." autocomplete="off">
        <button type="submit">Send</button>
    </form>
</div>

<script src="assistant/popup.js?v=<?php echo time(); ?>"></script>