// Minimal markdown renderer for the summarizer demo.
//
// Model output is untrusted (a prompt injection in the README, or a hostile model), so every
// character that means something in HTML is escaped first; the markdown transforms below only
// add a fixed set of tags. Links keep only http:, https: and mailto: targets.

const SAFE_HREF = /^(https?:|mailto:)/i;

function escapeHtml(text) {
    return text
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function renderMarkdown(text) {
    if (!text) return '';

    return escapeHtml(String(text))
        // Code blocks
        .replace(/```(\w*)\n([\s\S]*?)```/g, '<pre><code>$2</code></pre>')
        // Inline code
        .replace(/`([^`]+)`/g, '<code>$1</code>')
        // Headers
        .replace(/^### (.+)$/gm, '<h3>$1</h3>')
        .replace(/^## (.+)$/gm, '<h2>$1</h2>')
        .replace(/^# (.+)$/gm, '<h1>$1</h1>')
        // Bold and italic
        .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
        .replace(/\*(.+?)\*/g, '<em>$1</em>')
        // Links: unsafe schemes keep only their label
        .replace(/\[([^\]]+)\]\(([^)\s]+)\)/g, (match, label, href) =>
            SAFE_HREF.test(href) ? `<a href="${href}" rel="noopener noreferrer">${label}</a>` : label)
        // Unordered lists
        .replace(/^[*-] (.+)$/gm, '<li>$1</li>')
        // Wrap consecutive <li> in <ul>
        .replace(/((?:<li>.*<\/li>\n?)+)/g, '<ul>$1</ul>')
        // Paragraphs (double newline)
        .replace(/\n\n/g, '</p><p>')
        // Single newlines → <br> within paragraphs
        .replace(/\n/g, '<br>')
        // Wrap in paragraph
        .replace(/^(?!<[huploa])(.+)/gm, '<p>$1</p>');
}

if (typeof module !== 'undefined') {
    module.exports = { escapeHtml, renderMarkdown };
}
