<?php
$chatbotRole = $_SESSION['role'] ?? 'user';
$chatbotGreeting = $chatbotRole === 'donor'
    ? 'Hi! I can help with donor registration, eligibility, requests, and matching.'
    : 'Hi! I can help with registration, donation eligibility, request tracking, and matching.';
?>

<style>
    .medimatch-chatbot {
        --chatbot-red: #dc3545;
        position: fixed;
        right: 1.25rem;
        bottom: 1.25rem;
        z-index: 1050;
        font-size: 0.95rem;
    }

    .medimatch-chatbot-toggle {
        width: 3.5rem;
        height: 3.5rem;
        border: 0;
        border-radius: 50%;
        color: #fff;
        background: var(--chatbot-red);
        box-shadow: 0 0.5rem 1.5rem rgba(220, 53, 69, 0.35);
    }

    .medimatch-chatbot-panel {
        display: none;
        width: min(22rem, calc(100vw - 2rem));
        margin-bottom: 0.75rem;
        overflow: hidden;
        border: 1px solid rgba(0, 0, 0, 0.08);
        border-radius: 1rem;
        background: #fff;
        box-shadow: 0 1rem 3rem rgba(15, 23, 42, 0.2);
    }

    .medimatch-chatbot.is-open .medimatch-chatbot-panel {
        display: block;
    }

    .medimatch-chatbot-header {
        padding: 1rem 1.1rem;
        color: #fff;
        background: linear-gradient(135deg, #b42333, #dc3545);
    }

    .medimatch-chatbot-messages {
        display: flex;
        flex-direction: column;
        gap: 0.65rem;
        max-height: 16rem;
        padding: 1rem;
        overflow-y: auto;
        background: #f8f9fa;
    }

    .medimatch-chatbot-message {
        max-width: 92%;
        padding: 0.65rem 0.8rem;
        border-radius: 0.8rem;
        line-height: 1.4;
    }

    .medimatch-chatbot-message.bot {
        align-self: flex-start;
        color: #343a40;
        background: #fff;
        border: 1px solid #e9ecef;
    }

    .medimatch-chatbot-message.user {
        align-self: flex-end;
        color: #fff;
        background: var(--chatbot-red);
    }

    .medimatch-chatbot-quick {
        display: flex;
        flex-wrap: wrap;
        gap: 0.4rem;
        padding: 0 1rem 0.8rem;
        background: #f8f9fa;
    }

    .medimatch-chatbot-quick button {
        border: 1px solid #dee2e6;
        border-radius: 2rem;
        padding: 0.35rem 0.6rem;
        color: #495057;
        background: #fff;
        font-size: 0.78rem;
    }

    .medimatch-chatbot-quick button:hover,
    .medimatch-chatbot-quick button:focus {
        color: var(--chatbot-red);
        border-color: var(--chatbot-red);
    }

    .medimatch-chatbot-form {
        display: flex;
        gap: 0.5rem;
        padding: 0.8rem;
        border-top: 1px solid #e9ecef;
        background: #fff;
    }

    .medimatch-chatbot-form input {
        min-width: 0;
    }

    @media (max-width: 575.98px) {
        .medimatch-chatbot {
            right: 0.75rem;
            bottom: 0.75rem;
        }
    }
</style>

<div class="medimatch-chatbot" id="medimatchChatbot">
    <div class="medimatch-chatbot-panel" role="dialog" aria-label="MediMatch help chatbot" aria-hidden="true">
        <div class="medimatch-chatbot-header d-flex align-items-center justify-content-between">
            <div>
                <strong><i class="bi bi-chat-heart-fill me-2"></i>MediMatch Help</strong>
                <div class="small opacity-75">Quick answers, no external service</div>
            </div>
            <button type="button" class="btn btn-sm text-white" id="medimatchChatbotClose" aria-label="Close chatbot">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>
        <div class="medimatch-chatbot-messages" id="medimatchChatbotMessages" aria-live="polite">
            <div class="medimatch-chatbot-message bot"><?php echo htmlspecialchars($chatbotGreeting, ENT_QUOTES, 'UTF-8'); ?></div>
        </div>
        <div class="medimatch-chatbot-quick" aria-label="Common questions">
            <button type="button" data-chat-question="How do I register?">Registration</button>
            <button type="button" data-chat-question="Am I eligible to donate?">Eligibility</button>
            <button type="button" data-chat-question="How can I track my request?">Track request</button>
            <button type="button" data-chat-question="How does matching work?">Matching</button>
        </div>
        <form class="medimatch-chatbot-form" id="medimatchChatbotForm">
            <input type="text" class="form-control form-control-sm" id="medimatchChatbotInput" placeholder="Ask a question..." autocomplete="off" maxlength="180">
            <button type="submit" class="btn btn-danger btn-sm" aria-label="Send question"><i class="bi bi-send-fill"></i></button>
        </form>
    </div>
    <button type="button" class="medimatch-chatbot-toggle" id="medimatchChatbotToggle" aria-label="Open MediMatch help" aria-expanded="false">
        <i class="bi bi-chat-dots-fill fs-5"></i>
    </button>
</div>

<script>
(function () {
    const chatbot = document.getElementById('medimatchChatbot');
    const panel = chatbot.querySelector('.medimatch-chatbot-panel');
    const messages = document.getElementById('medimatchChatbotMessages');
    const input = document.getElementById('medimatchChatbotInput');
    const toggle = document.getElementById('medimatchChatbotToggle');
    const close = document.getElementById('medimatchChatbotClose');
    const form = document.getElementById('medimatchChatbotForm');

    const answers = [
        {
            terms: ['register', 'registration', 'sign up', 'account'],
            answer: 'Choose Register on the login page, select Patient or Donor, complete your details, and submit the form. You can then log in with your registered email and password.'
        },
        {
            terms: ['eligible', 'eligibility', 'donate', 'donation', 'age', 'health'],
            answer: 'Donors should be healthy, meet the applicable age and medical requirements, and provide accurate information. The hospital or blood bank makes the final eligibility decision after verification.'
        },
        {
            terms: ['track', 'tracking', 'status', 'request', 'pending', 'approved', 'fulfilled'],
            answer: 'Log in to your Patient Dashboard to view request status and priority information. Statuses can include Pending, Approved, Fulfilled, or Rejected; contact your hospital for case-specific updates.'
        },
        {
            terms: ['match', 'matching', 'matched', 'compatibility', 'how does'],
            answer: 'The matching system compares the patient request with donor information such as blood group, organ type, availability, and medical requirements. Priority and verification are also considered before allocation.'
        },
        {
            terms: ['password', 'login', 'log in', 'forgot'],
            answer: 'Use the Login page with your registered email and password. If access still fails, contact the system administrator or your hospital for account assistance.'
        },
        {
            terms: ['help', 'contact', 'support', 'hospital', 'blood bank'],
            answer: 'For medical advice, urgent requests, or a delayed case, contact your assigned hospital or blood bank directly. This chatbot provides general system guidance only.'
        }
    ];

    function setOpen(isOpen) {
        chatbot.classList.toggle('is-open', isOpen);
        toggle.setAttribute('aria-expanded', String(isOpen));
        panel.setAttribute('aria-hidden', String(!isOpen));
        if (isOpen) input.focus();
    }

    function addMessage(text, type) {
        const message = document.createElement('div');
        message.className = 'medimatch-chatbot-message ' + type;
        message.textContent = text;
        messages.appendChild(message);
        messages.scrollTop = messages.scrollHeight;
    }

    function getAnswer(question) {
        const normalizedQuestion = question.toLowerCase();
        const match = answers.find(function (item) {
            return item.terms.some(function (term) {
                return normalizedQuestion.indexOf(term) !== -1;
            });
        });
        return match ? match.answer : 'I can help with registration, donation eligibility, request tracking, matching, login, and support. Try asking about one of those topics.';
    }

    function ask(question) {
        const cleanQuestion = question.trim();
        if (!cleanQuestion) return;
        addMessage(cleanQuestion, 'user');
        addMessage(getAnswer(cleanQuestion), 'bot');
        input.value = '';
    }

    toggle.addEventListener('click', function () {
        setOpen(!chatbot.classList.contains('is-open'));
    });
    close.addEventListener('click', function () { setOpen(false); });
    form.addEventListener('submit', function (event) {
        event.preventDefault();
        ask(input.value);
    });
    chatbot.querySelectorAll('[data-chat-question]').forEach(function (button) {
        button.addEventListener('click', function () { ask(button.dataset.chatQuestion); });
    });
})();
</script>
