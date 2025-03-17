// chat.js
(function () {
  // Create chat container (initially hidden)
  const chatContainer = document.createElement("div");
  chatContainer.className = "grok-chat-container";
  chatContainer.style.display = "none";

  // Default language
  let currentLanguage = "EN";

  // Load chat history from localStorage or initialize empty array
  let chatHistory = JSON.parse(localStorage.getItem("chatHistory")) || [];

  // Chat HTML structure with language toggle
  chatContainer.innerHTML = `
        <div class="chat-header">
            <span>✨</span>
            <div class="language-toggle">
                <span class="lang-option ${currentLanguage === "EN" ? "active" : ""}" data-lang="EN">EN</span>
                <span class="lang-option ${currentLanguage === "DE" ? "active" : ""}" data-lang="DE">DE</span>
            </div>
            <span class="close-btn">[X]</span>
        </div>
        <div class="chat-controls">
          <span class="clear-btn">[Clear]</span>
        </div>
        <div class="chat-messages">
            <div class="message system-message">Hello! How can I assist you today?</div>
        </div>
        <div class="chat-input-container">
            <textarea class="chat-input" rows="1" placeholder="Type your message..."></textarea>
            <button class="send-btn">➤</button>
        </div>
    `;

  const style = document.createElement("style");
  style.textContent = `
        .grok-chat-container {
            position: fixed;
            width: 720px;
            height: 940px;
            background: #2a2a2a;
            border-radius: 8px;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            box-shadow: 0 0 10px rgba(0,0,0,0.3);
            z-index: 1000;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            font-family: 'Courier New', Courier, monospace;
        }

        .chat-header {
            background: #3a3a3a;
            padding: 10px 15px;
            color: #e0e0e0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .chat-controls {
            padding: 5px 15px;
            background: #2a2a2a;
            border-bottom: 1px solid #404040;
            text-align: center;
        }

        .clear-btn {
            cursor: pointer;
            color: #e0e0e0;
            font-size: 14px;
        }

        .clear-btn:hover {
          color: #ff4444;
        }

        .language-toggle {
            display: flex;
            gap: 10px;
        }

        .lang-option {
            cursor: pointer;
            padding: 2px 6px;
            border-radius: 4px;
        }

        .lang-option.active {
            background: #B7B1F2;
            color: #111;
        }

        .lang-option:hover:not(.active) {
            background: #555;
        }

        .close-btn {
            cursor: pointer;
            font-size: 18px;
            padding: 0 5px;
        }

        .close-btn:hover {
            color: #ff4444;
        }

        .chat-messages {
            flex: 1;
            padding: 20px;
            overflow-y: auto;
            color: #e0e0e0;
        }

        .message {
            margin-bottom: 15px;
            max-width: 70%;
        }

        .user-message {
            text-align: left;
        }

        .system-message {
            text-align: justify;
            margin-left: auto;
            color: #fff;
            background-color: #181C14;
            border-radius: 10px;
            padding: 8px;
        }

        .chat-input-container {
            border-top: 1px solid #404040;
            display: flex;
            align-items: center;
            padding: 10px;
            background: #2a2a2a;
        }

        .chat-input {
            flex: 1;
            background: transparent;
            border: none;
            color: #e0e0e0;
            font-size: 14px;
            padding: 5px;
            outline: none;
            resize: none;
        }

        .send-btn {
            background: transparent;
            border: none;
            cursor: pointer;
            color: #e0e0e0;
            font-size: 16px;
            padding: 5px 10px;
        }

        .send-btn:hover {
            color: #4CAF50;
        }

        .chat-icon {
            position: fixed;
            bottom: 20px;
            right: 20px;
            width: 50px;
            height: 50px;
            background: #3a3a3a;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            color: #e0e0e0;
            font-size: 24px;
            z-index: 1000;
            box-shadow: 0 0 5px rgba(0,0,0,0.3);
        }

        .chat-icon:hover {
            background: #4a4a4a;
        }
    `;

  // Create chat icon (visible by default)
  const chatIcon = document.createElement("div");
  chatIcon.className = "chat-icon";
  chatIcon.textContent = "💬";
  chatIcon.style.display = "flex";

  // Append elements to document
  document.head.appendChild(style);
  document.body.appendChild(chatContainer);
  document.body.appendChild(chatIcon);

  // Get DOM elements
  const chatMessages = chatContainer.querySelector(".chat-messages");
  const chatInput = chatContainer.querySelector(".chat-input");
  const sendBtn = chatContainer.querySelector(".send-btn");
  const closeBtn = chatContainer.querySelector(".close-btn");
  const clearBtn = chatContainer.querySelector(".clear-btn");
  const langOptions = chatContainer.querySelectorAll(".lang-option");

  // Function to load chat history when opening the chat
  function loadChatHistory() {
    chatMessages.innerHTML = ""; // Clear existing messages
    chatHistory.forEach((chat) => {
      // Add user message
      const userMessage = document.createElement("div");
      userMessage.classList.add("message", "user-message");
      userMessage.textContent = chat.user_message;
      chatMessages.appendChild(userMessage);

      // Add system response
      const systemMessage = document.createElement("div");
      systemMessage.classList.add("message", "system-message");
      systemMessage.innerHTML = chat.system_response;
      chatMessages.appendChild(systemMessage);
    });

    if (chatHistory.length == 0) {
      initChat();
    }

    chatMessages.scrollTop = chatMessages.scrollHeight;
  }

  function initChat() {
    const welcomeMessage = document.createElement("div");
    welcomeMessage.classList.add("message", "system-message");
    welcomeMessage.textContent =
      currentLanguage === "EN"
        ? "Hello! How can I assist you today?"
        : "Hallo! Wie kann ich Ihnen heute helfen?";
    chatMessages.appendChild(welcomeMessage);
  }

  // Function to clear chat history
  function clearChatHistory() {
    chatHistory = [];
    localStorage.removeItem("chatHistory");
    chatMessages.innerHTML = "";
    initChat();
  }

  // Language toggle event listener
  langOptions.forEach((option) => {
    option.addEventListener("click", () => {
      langOptions.forEach((opt) => opt.classList.remove("active"));
      option.classList.add("active");
      currentLanguage = option.dataset.lang;
      const welcomeMessage = chatMessages.querySelector(".system-message");
      if (welcomeMessage) {
        welcomeMessage.textContent =
          currentLanguage === "EN"
            ? "Hello! How can I assist you today?"
            : "Hallo! Wie kann ich Ihnen heute helfen?";
      }
    });
  });

  // Event Listeners

  // Clear button event listener
  clearBtn.addEventListener("click", () => {
    clearChatHistory();
  });

  chatIcon.addEventListener("click", () => {
    chatContainer.style.display = "flex";
    chatIcon.style.display = "none";
    chatInput.focus();
    loadChatHistory(); // Load history when opening chat
  });

  closeBtn.addEventListener("click", () => {
    chatContainer.style.display = "none";
    chatIcon.style.display = "flex";
  });

  // ESC to close chat
  document.addEventListener("keydown", (e) => {
    if (e.key === "Escape" && chatContainer.style.display === "flex") {
      chatContainer.style.display = "none";
      chatIcon.style.display = "flex";
    }
  });

  // CTRL+I to open chat
  document.addEventListener("keydown", (e) => {
    if (e.ctrlKey && e.key === "i") {
      e.preventDefault();
      chatContainer.style.display = "flex";
      chatIcon.style.display = "none";
      chatInput.focus();
      loadChatHistory(); // Load history when opening chat
    }
  });

  chatInput.addEventListener("input", function () {
    this.style.height = "auto";
    this.style.height = this.scrollHeight + "px";
  });

  sendBtn.addEventListener("click", sendMessage);

  chatInput.addEventListener("keypress", function (e) {
    if (e.key === "Enter" && !e.shiftKey) {
      e.preventDefault();
      sendMessage();
    }
  });

  async function sendMessage() {
    const message = chatInput.value.trim();
    if (message) {
      // Add user message to DOM
      const userMessage = document.createElement("div");
      userMessage.classList.add("message", "user-message");
      userMessage.textContent = message;
      chatMessages.appendChild(userMessage);

      // Add loading dots
      const loadingMessage = document.createElement("div");
      loadingMessage.classList.add("message", "system-message");
      loadingMessage.textContent = "...";
      chatMessages.appendChild(loadingMessage);
      chatMessages.scrollTop = chatMessages.scrollHeight;

      // Animate dots
      let dotCount = 0;
      const dotInterval = setInterval(() => {
        dotCount = (dotCount + 1) % 4;
        loadingMessage.textContent = ".".repeat(dotCount + 1);
      }, 500);

      // Clear input
      chatInput.value = "";
      chatInput.style.height = "auto";

      try {
        // @temp
        const response = await fetch(
          "https://backend.dev.paraplegie.ch/chat/completion",
          {
            method: "POST",
            headers: {
              "Content-Type": "application/json",
            },
            body: JSON.stringify({
              message: message,
              langcode: currentLanguage.toLowerCase(),
            }),
          },
        );

        clearInterval(dotInterval);
        chatMessages.removeChild(loadingMessage);

        if (!response.ok) {
          throw new Error("Network response was not ok");
        }

        const data = await response.json();
        const systemMessage = document.createElement("div");
        systemMessage.classList.add("message", "system-message");
        systemMessage.innerHTML = data.answer || "No response received";
        chatMessages.appendChild(systemMessage);

        // @todo extract this to method
        // Store in chat history
        chatHistory.push({
          user_message: message,
          system_response: data.answer || "No response received",
        });
        if (chatHistory.length > 5) {
          chatHistory.shift();
        }
        localStorage.setItem("chatHistory", JSON.stringify(chatHistory));
        // End Store in chat history
      } catch (error) {
        clearInterval(dotInterval);
        chatMessages.removeChild(loadingMessage);

        const systemMessage = document.createElement("div");
        systemMessage.classList.add("message", "system-message");
        systemMessage.textContent =
          currentLanguage === "EN"
            ? "There was an error with your request."
            : "Es gab einen Fehler bei Ihrer Anfrage.";
        chatMessages.appendChild(systemMessage);

        // @todo extract this to method
        // Store error in chat history
        chatHistory.push({
          user_message: message,
          system_response:
            currentLanguage === "EN"
              ? "There was an error with your request."
              : "Es gab einen Fehler bei Ihrer Anfrage.",
        });
        if (chatHistory.length > 5) {
          chatHistory.shift();
        }
        localStorage.setItem("chatHistory", JSON.stringify(chatHistory));
        // End Store in chat history
      }

      chatMessages.scrollTop = chatMessages.scrollHeight;
    }
  }
})();
