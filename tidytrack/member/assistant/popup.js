document.addEventListener("DOMContentLoaded", () => {
    const bubble = document.getElementById("tsmBotBubble");
    const chat   = document.getElementById("tsmBotWindow");
    const close  = chat.querySelector(".bot-close");
    const input  = document.getElementById("botInput");
    const form   = document.getElementById("botForm");
    const area   = document.getElementById("botMessages");

    if (!bubble || !chat) {
        console.log("TSM Bot: Elements not found - check bot.php");
        return;
    }

    // Open / Close
    bubble.onclick = () => chat.classList.add("open");
    close.onclick  = () => chat.classList.remove("open");

    // Quick buttons
    document.querySelectorAll("[data-msg]").forEach(btn => {
        btn.onclick = () => {
            input.value = btn.dataset.msg;
            form.dispatchEvent(new Event("submit"));
        };
    });

    // Send message
    form.onsubmit = async (e) => {
        e.preventDefault();
        let msg = input.value.trim();
        if (!msg) return;

        add(msg, "user-msg");
        input.value = "";

        let res = await fetch("assistant/process.php", {
            method: "POST",
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            body: "message=" + encodeURIComponent(msg)
        });

        let data = await res.json();
        add(data.response || "I didn't understand that.", "bot-msg");
    };

    function add(html, type) {
        let div = document.createElement("div");
        div.className = "bot-msg " + type;
        div.innerHTML = html;
        area.appendChild(div);
        area.scrollTop = area.scrollHeight;
    }

    // Auto open on help page
    if (location.href.includes("member_help.php")) {
        setTimeout(() => bubble.click(), 1000);
    }
});