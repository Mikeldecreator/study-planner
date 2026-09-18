/**
 * ============================================================================
 * STUDY PLANNER — STUDY AI ASSISTANT CLIENT (FOUNDATION 7B.2)
 *
 * Dedicated conversational interface grounded in real-time academic context.
 * Communicates with POST /api/ai-chat.php.
 * Strictly read-only: does not modify tasks, courses, or schedule data.
 * ============================================================================
 */

(function () {
  'use strict';

  // Storage key for retaining active tab conversation in session memory
  const STORAGE_KEY = 'study_planner_ai_conversation_v1';
  const MAX_PROMPT_LENGTH = 1000;

  // DOM Elements
  let chatScroll = null;
  let chatMessages = null;
  let emptyState = null;
  let loadingIndicator = null;
  let chatForm = null;
  let promptInput = null;
  let sendBtn = null;
  let charCounter = null;
  let quickChips = null;
  let clearChatBtn = null;

  // State
  let isSubmitting = false;
  let conversationHistory = [];

  /**
   * Safe HTML entity encoder to prevent XSS
   */
  function escapeHtml(str) {
    if (str == null) return '';
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  /**
   * Format current time (e.g. "2:45 PM")
   */
  function formatCurrentTime() {
    const now = new Date();
    return now.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
  }

  /**
   * Safe Markdown parser
   * Converts markdown tokens to safe, styled HTML markup
   */
  function formatMarkdown(text) {
    if (!text) return '';

    // Step 1: Escape all HTML entities
    let safe = escapeHtml(text);

    // Step 2: Extract code blocks before processing line breaks
    const codeBlocks = [];
    safe = safe.replace(/```([a-zA-Z0-9_-]*)\r?\n([\s\S]*?)```/g, function (_, lang, code) {
      const idx = codeBlocks.length;
      codeBlocks.push(
        '<pre class="my-3 p-3 bg-gray-900 text-gray-100 rounded-xl text-xs font-mono overflow-x-auto border border-gray-800 leading-normal"><code>' +
        code.trim() +
        '</code></pre>'
      );
      return '___CODE_BLOCK_' + idx + '___';
    });

    // Helper for inline tokens
    function parseInline(str) {
      return str
        // inline code: `code`
        .replace(/`([^`]+)`/g, '<code class="px-1.5 py-0.5 rounded bg-gray-100 dark:bg-white/10 text-emerald-800 dark:text-emerald-300 text-xs font-mono font-medium">$1</code>')
        // bold: **bold** or __bold__
        .replace(/\*\*([^*]+)\*\*/g, '<strong class="font-semibold text-gray-900 dark:text-white">$1</strong>')
        .replace(/__([^_]+)__/g, '<strong class="font-semibold text-gray-900 dark:text-white">$1</strong>')
        // italic: *italic* or _italic_
        .replace(/\*([^*]+)\*/g, '<em class="italic">$1</em>')
        .replace(/_([^_]+)_/g, '<em class="italic">$1</em>');
    }

    // Step 3: Process line by line
    const lines = safe.split(/\r?\n/);
    const out = [];
    let inUl = false;
    let inOl = false;
    let paragraphLines = [];

    function flushParagraph() {
      if (paragraphLines.length > 0) {
        out.push('<p class="mb-3 leading-relaxed text-sm text-gray-800 dark:text-gray-200">' + parseInline(paragraphLines.join(' ')) + '</p>');
        paragraphLines = [];
      }
    }

    function closeLists() {
      if (inUl) {
        out.push('</ul>');
        inUl = false;
      }
      if (inOl) {
        out.push('</ol>');
        inOl = false;
      }
    }

    for (let i = 0; i < lines.length; i++) {
      const rawLine = lines[i];
      const trimmed = rawLine.trim();

      // Check code block placeholder
      if (trimmed.indexOf('___CODE_BLOCK_') === 0 && trimmed.slice(-3) === '___') {
        flushParagraph();
        closeLists();
        out.push(trimmed);
        continue;
      }

      // Empty line -> break paragraph and close lists
      if (trimmed === '') {
        flushParagraph();
        closeLists();
        continue;
      }

      // Horizontal Divider: --- or *** or ___
      if (/^(\*{3,}|-{3,}|_{3,})$/.test(trimmed)) {
        flushParagraph();
        closeLists();
        out.push('<hr class="my-4 border-gray-200/80 dark:border-white/10" />');
        continue;
      }

      // Headings
      const h3Match = trimmed.match(/^###\s+(.*)$/);
      if (h3Match) {
        flushParagraph();
        closeLists();
        out.push('<h3 class="text-sm sm:text-base font-bold text-gray-900 dark:text-white mt-4 mb-2 flex items-center gap-1.5">' + parseInline(h3Match[1]) + '</h3>');
        continue;
      }

      const h2Match = trimmed.match(/^##\s+(.*)$/);
      if (h2Match) {
        flushParagraph();
        closeLists();
        out.push('<h2 class="text-base sm:text-lg font-bold text-gray-900 dark:text-white mt-5 mb-2">' + parseInline(h2Match[1]) + '</h2>');
        continue;
      }

      const h1Match = trimmed.match(/^#\s+(.*)$/);
      if (h1Match) {
        flushParagraph();
        closeLists();
        out.push('<h1 class="text-lg sm:text-xl font-bold text-gray-900 dark:text-white mt-5 mb-2">' + parseInline(h1Match[1]) + '</h1>');
        continue;
      }

      // Blockquote: > text
      const quoteMatch = trimmed.match(/^&gt;\s?(.*)$/);
      if (quoteMatch) {
        flushParagraph();
        closeLists();
        out.push('<blockquote class="border-l-4 border-emerald-500 pl-3 py-1.5 my-2.5 italic text-gray-600 dark:text-gray-300 bg-emerald-50/40 dark:bg-emerald-950/20 rounded-r text-sm">' + parseInline(quoteMatch[1]) + '</blockquote>');
        continue;
      }

      // Unordered list item: * item or - item
      const ulMatch = rawLine.match(/^(\s*)[*-]\s+(.*)$/);
      if (ulMatch) {
        flushParagraph();
        if (inOl) {
          out.push('</ol>');
          inOl = false;
        }
        if (!inUl) {
          out.push('<ul class="space-y-1.5 my-2.5">');
          inUl = true;
        }
        const indentClass = ulMatch[1].length >= 2 ? 'ml-5' : 'ml-1';
        out.push(
          '<li class="' + indentClass + ' flex items-start gap-2.5 text-sm text-gray-700 dark:text-gray-300 leading-relaxed">' +
            '<span class="w-1.5 h-1.5 rounded-full bg-emerald-500 mt-2 shrink-0"></span>' +
            '<div class="flex-1">' + parseInline(ulMatch[2]) + '</div>' +
          '</li>'
        );
        continue;
      }

      // Ordered list item: 1. item
      const olMatch = rawLine.match(/^(\s*)(\d+)\.\s+(.*)$/);
      if (olMatch) {
        flushParagraph();
        if (inUl) {
          out.push('</ul>');
          inUl = false;
        }
        if (!inOl) {
          out.push('<ol class="space-y-1.5 my-2.5">');
          inOl = true;
        }
        const num = olMatch[2];
        const indentClass = olMatch[1].length >= 2 ? 'ml-5' : 'ml-1';
        out.push(
          '<li class="' + indentClass + ' flex items-start gap-2.5 text-sm text-gray-700 dark:text-gray-300 leading-relaxed">' +
            '<span class="inline-flex items-center justify-center w-5 h-5 rounded-full bg-emerald-100 dark:bg-emerald-950 text-emerald-800 dark:text-emerald-300 text-[11px] font-bold shrink-0 mt-0.5">' + num + '</span>' +
            '<div class="flex-1">' + parseInline(olMatch[3]) + '</div>' +
          '</li>'
        );
        continue;
      }

      // Regular line inside a paragraph
      closeLists();
      paragraphLines.push(trimmed);
    }

    flushParagraph();
    closeLists();

    let htmlResult = out.join('\n');

    // Step 4: Restore code blocks
    codeBlocks.forEach(function (block, idx) {
      htmlResult = htmlResult.replace('___CODE_BLOCK_' + idx + '___', block);
    });

    return htmlResult;
  }

  /**
   * Scroll chat viewport smoothly to bottom
   */
  function scrollToBottom(smooth) {
    if (!chatScroll) return;
    requestAnimationFrame(function () {
      chatScroll.scrollTo({
        top: chatScroll.scrollHeight,
        behavior: smooth ? 'smooth' : 'auto'
      });
    });
  }

  /**
   * Append a message element to the chat stream
   */
  function appendMessageToDOM(msg, shouldScroll) {
    if (!chatMessages) return;

    const isUser = msg.role === 'user';
    const msgId = 'msg-' + Date.now() + '-' + Math.random().toString(36).substr(2, 5);
    const wrapper = document.createElement('div');
    wrapper.id = msgId;
    wrapper.className = isUser ? 'flex justify-end' : 'flex items-start gap-3';

    if (isUser) {
      wrapper.innerHTML =
        '<div class="max-w-[88%] sm:max-w-[78%] space-y-1">' +
          '<div class="flex items-center justify-end gap-2 px-1 text-[11px] text-gray-400 dark:text-gray-500">' +
            '<span>You</span>' +
            '<span>&bull;</span>' +
            '<span>' + escapeHtml(msg.time || formatCurrentTime()) + '</span>' +
          '</div>' +
          '<div class="bg-emerald-700 text-white rounded-2xl rounded-tr-sm px-4 py-3 shadow-sm text-sm leading-relaxed whitespace-pre-wrap break-words">' +
            escapeHtml(msg.content) +
          '</div>' +
        '</div>';
    } else {
      const formattedHtml = formatMarkdown(msg.content);
      const isError = msg.isError === true;
      const bubbleClass = isError
        ? 'bg-amber-50 dark:bg-amber-950/40 border border-amber-200 dark:border-amber-800/50 rounded-2xl rounded-tl-sm p-4 text-amber-900 dark:text-amber-200 shadow-sm'
        : 'bg-white dark:bg-[#141C18] border border-gray-200/80 dark:border-white/10 rounded-2xl rounded-tl-sm p-4 sm:p-5 shadow-sm text-gray-800 dark:text-gray-100';

      wrapper.innerHTML =
        '<div class="w-8 h-8 rounded-xl bg-gradient-to-tr from-emerald-700 to-teal-600 text-white flex items-center justify-center shrink-0 shadow-sm mt-0.5">' +
          '<i data-lucide="sparkles" class="w-4 h-4"></i>' +
        '</div>' +
        '<div class="max-w-[92%] sm:max-w-[85%] space-y-1.5 flex-1 min-w-0">' +
          '<div class="flex items-center justify-between gap-2 px-1">' +
            '<div class="flex items-center gap-2">' +
              '<span class="text-xs font-bold text-gray-900 dark:text-white">Study AI</span>' +
              '<span class="inline-flex items-center gap-1 text-[10px] font-medium text-emerald-700 dark:text-emerald-400 bg-emerald-50 dark:bg-emerald-950/60 px-1.5 py-0.5 rounded border border-emerald-200/50 dark:border-emerald-800/40">' +
                '<i data-lucide="check" class="w-2.5 h-2.5"></i> Grounded' +
              '</span>' +
            '</div>' +
            '<span class="text-[11px] text-gray-400 dark:text-gray-500">' + escapeHtml(msg.time || formatCurrentTime()) + '</span>' +
          '</div>' +
          '<div class="' + bubbleClass + ' ai-rendered-content break-words">' +
            formattedHtml +
          '</div>' +
          (!isError ?
            '<div class="flex items-center gap-3 px-1 pt-0.5">' +
              '<button type="button" class="copy-response-btn text-[11px] text-gray-400 hover:text-emerald-600 dark:hover:text-emerald-400 flex items-center gap-1 transition-colors">' +
                '<i data-lucide="copy" class="w-3 h-3"></i>' +
                '<span>Copy</span>' +
              '</button>' +
            '</div>' : ''
          ) +
        '</div>';

      // Wire copy button
      if (!isError) {
        const copyBtn = wrapper.querySelector('.copy-response-btn');
        if (copyBtn) {
          copyBtn.addEventListener('click', function () {
            navigator.clipboard.writeText(msg.content).then(function () {
              const label = copyBtn.querySelector('span');
              if (label) label.textContent = 'Copied!';
              setTimeout(function () {
                if (label) label.textContent = 'Copy';
              }, 2000);
            }).catch(function () {
              // Fallback if clipboard API fails
            });
          });
        }
      }
    }

    chatMessages.appendChild(wrapper);

    // Refresh Lucide icons inside newly appended element
    if (window.lucide && typeof window.lucide.createIcons === 'function') {
      window.lucide.createIcons();
    }

    if (shouldScroll) {
      scrollToBottom(true);
    }
  }

  /**
   * Save session history to sessionStorage
   */
  function saveHistoryToStorage() {
    try {
      sessionStorage.setItem(STORAGE_KEY, JSON.stringify(conversationHistory));
    } catch (e) {
      // Ignore quota or private mode errors
    }
  }

  /**
   * Load session history from sessionStorage
   */
  function loadHistoryFromStorage() {
    try {
      const data = sessionStorage.getItem(STORAGE_KEY);
      if (data) {
        const parsed = JSON.parse(data);
        if (Array.isArray(parsed) && parsed.length > 0) {
          conversationHistory = parsed;
          if (emptyState) emptyState.classList.add('hidden');
          if (quickChips) quickChips.classList.remove('hidden');

          conversationHistory.forEach(function (msg) {
            appendMessageToDOM(msg, false);
          });
          scrollToBottom(false);
        }
      }
    } catch (e) {
      // Ignore parse errors
    }
  }

  /**
   * Clear active conversation
   */
  function clearConversation() {
    if (isSubmitting) return;

    conversationHistory = [];
    try {
      sessionStorage.removeItem(STORAGE_KEY);
    } catch (e) {}

    if (chatMessages) {
      chatMessages.innerHTML = '';
    }

    if (emptyState) {
      emptyState.classList.remove('hidden');
    }

    if (quickChips) {
      quickChips.classList.add('hidden');
    }

    if (promptInput) {
      promptInput.value = '';
      promptInput.style.height = 'auto';
      updateInputState();
      promptInput.focus();
    }
  }

  /**
   * Update character counter and submit button state
   */
  function updateInputState() {
    if (!promptInput || !sendBtn) return;
    const len = promptInput.value.length;

    if (charCounter) {
      charCounter.textContent = len + '/' + MAX_PROMPT_LENGTH;
      if (len > MAX_PROMPT_LENGTH) {
        charCounter.classList.add('text-red-500', 'font-bold');
        charCounter.classList.remove('text-gray-400');
      } else {
        charCounter.classList.remove('text-red-500', 'font-bold');
        charCounter.classList.add('text-gray-400');
      }
    }

    const trimmed = promptInput.value.trim();
    sendBtn.disabled = isSubmitting || trimmed.length === 0 || len > MAX_PROMPT_LENGTH;
  }

  /**
   * Auto-grow textarea to accommodate content smoothly
   */
  function autoResizeTextarea() {
    if (!promptInput) return;
    promptInput.style.height = 'auto';
    const newHeight = Math.min(promptInput.scrollHeight, 144);
    promptInput.style.height = Math.max(newHeight, 42) + 'px';
  }

  /**
   * Send a question to the Study AI endpoint
   */
  async function sendMessage(questionText) {
    const message = (questionText || (promptInput ? promptInput.value : '')).trim();

    if (!message || isSubmitting) return;
    if (message.length > MAX_PROMPT_LENGTH) return;

    // Reset composer
    if (promptInput) {
      promptInput.value = '';
      promptInput.style.height = 'auto';
      updateInputState();
    }

    // Hide empty state and show quick chips bar
    if (emptyState) emptyState.classList.add('hidden');
    if (quickChips) quickChips.classList.remove('hidden');

    // Add user message to history & DOM
    const userMsg = {
      role: 'user',
      content: message,
      time: formatCurrentTime()
    };
    conversationHistory.push(userMsg);
    appendMessageToDOM(userMsg, true);
    saveHistoryToStorage();

    // Lock UI and show thinking indicator
    isSubmitting = true;
    updateInputState();
    if (promptInput) promptInput.disabled = true;
    if (loadingIndicator) {
      loadingIndicator.classList.remove('hidden');
      scrollToBottom(true);
    }

    try {
      const response = await fetch('../api/ai-chat.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json'
        },
        credentials: 'same-origin',
        body: JSON.stringify({ message: message })
      });

      const data = await response.json().catch(function () { return null; });

      if (response.status === 401) {
        // Unauthenticated -> Session expired
        const errorMsg = {
          role: 'assistant',
          content: '⚠️ **Session Expired**: Your session has timed out. Redirecting to login...',
          isError: true,
          time: formatCurrentTime()
        };
        conversationHistory.push(errorMsg);
        appendMessageToDOM(errorMsg, true);
        setTimeout(function () {
          window.location.href = 'login.php';
        }, 1500);
        return;
      }

      if (response.status === 429) {
        // Rate limited
        const rateLimitMsg = {
          role: 'assistant',
          content: '⏳ **Study AI Rate Limit**: The AI provider is temporarily busy (Free Tier quota). Please wait 10–15 seconds before submitting another question.',
          isError: true,
          time: formatCurrentTime()
        };
        conversationHistory.push(rateLimitMsg);
        appendMessageToDOM(rateLimitMsg, true);
        saveHistoryToStorage();
        return;
      }

      if (!response.ok || !data || data.ok !== true) {
        const errDetail = (data && data.error) ? data.error : 'An unexpected error occurred while communicating with the Study AI assistant.';
        const apiErrorMsg = {
          role: 'assistant',
          content: '⚠️ **Unable to Generate Response**: ' + errDetail,
          isError: true,
          time: formatCurrentTime()
        };
        conversationHistory.push(apiErrorMsg);
        appendMessageToDOM(apiErrorMsg, true);
        saveHistoryToStorage();
        return;
      }

      // Successful grounded response
      const aiResponseMsg = {
        role: 'assistant',
        content: data.answer || 'No response returned from the assistant.',
        time: formatCurrentTime()
      };
      conversationHistory.push(aiResponseMsg);
      appendMessageToDOM(aiResponseMsg, true);
      saveHistoryToStorage();

    } catch (networkError) {
      const netMsg = {
        role: 'assistant',
        content: '⚠️ **Network Error**: Unable to reach the server. Please check your internet connection and try again.',
        isError: true,
        time: formatCurrentTime()
      };
      conversationHistory.push(netMsg);
      appendMessageToDOM(netMsg, true);
      saveHistoryToStorage();
    } finally {
      // Release UI lock
      isSubmitting = false;
      if (loadingIndicator) loadingIndicator.classList.add('hidden');
      if (promptInput) {
        promptInput.disabled = false;
        updateInputState();
        promptInput.focus();
      }
      scrollToBottom(true);
    }
  }

  /**
   * Initialize DOM listeners and state
   */
  function init() {
    chatScroll = document.getElementById('ai-chat-scroll');
    chatMessages = document.getElementById('ai-chat-messages');
    emptyState = document.getElementById('ai-empty-state');
    loadingIndicator = document.getElementById('ai-loading-indicator');
    chatForm = document.getElementById('ai-chat-form');
    promptInput = document.getElementById('ai-prompt-input');
    sendBtn = document.getElementById('ai-send-btn');
    charCounter = document.getElementById('ai-char-counter');
    quickChips = document.getElementById('ai-quick-chips');
    clearChatBtn = document.getElementById('clear-chat-btn');

    // 1. Textarea input handling
    if (promptInput) {
      promptInput.addEventListener('input', function () {
        autoResizeTextarea();
        updateInputState();
      });

      promptInput.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && !e.shiftKey) {
          e.preventDefault();
          if (!sendBtn.disabled) {
            sendMessage();
          }
        }
      });
    }

    // 2. Chat form submit
    if (chatForm) {
      chatForm.addEventListener('submit', function (e) {
        e.preventDefault();
        sendMessage();
      });
    }

    // 3. Clear chat button
    if (clearChatBtn) {
      clearChatBtn.addEventListener('click', function () {
        if (conversationHistory.length > 0) {
          if (confirm('Start a new chat and clear the current conversation history?')) {
            clearConversation();
          }
        }
      });
    }

    // 4. Suggested prompt cards (in empty state)
    document.querySelectorAll('.suggested-prompt-card').forEach(function (card) {
      card.addEventListener('click', function () {
        const prompt = card.getAttribute('data-prompt');
        if (prompt) {
          sendMessage(prompt);
        }
      });
    });

    // 5. Quick chip buttons (above composer)
    document.querySelectorAll('.quick-chip-btn').forEach(function (btn) {
      btn.addEventListener('click', function () {
        const prompt = btn.getAttribute('data-prompt');
        if (prompt) {
          sendMessage(prompt);
        }
      });
    });

    // 6. Custom window event from sidebar "study-ai:open"
    window.addEventListener('study-ai:open', function () {
      if (promptInput) promptInput.focus();
    });

    // 7. Restore conversation from session storage if exists
    loadHistoryFromStorage();
    updateInputState();

    // 8. Focus input on load
    if (promptInput) {
      setTimeout(function () { promptInput.focus(); }, 100);
    }
  }

  // Initialize once DOM is ready
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

})();
