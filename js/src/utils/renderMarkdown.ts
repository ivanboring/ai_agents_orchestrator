/**
 * Minimal, safe Markdown-to-HTML renderer.
 *
 * Converts common markdown patterns to HTML without allowing arbitrary markup.
 * All raw HTML tags in the input are escaped, so no custom markup can slip through.
 */

/** Escape HTML entities so raw tags in the source are rendered as text. */
function escapeHtml(text: string): string {
    return text
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

/**
 * Convert inline markdown syntax within a single line.
 * Handles: bold, italic, inline code, links.
 */
function inlineMarkdown(line: string): string {
    return line
        // Inline code (must come first to avoid bold/italic processing inside backticks)
        .replace(/`([^`]+)`/g, '<code>$1</code>')
        // Bold+italic
        .replace(/\*\*\*(.+?)\*\*\*/g, '<strong><em>$1</em></strong>')
        // Bold
        .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
        // Italic
        .replace(/\*(.+?)\*/g, '<em>$1</em>')
        // Links [text](url)
        .replace(/\[([^\]]+)]\(([^)]+)\)/g, '<a href="$2" target="_blank" rel="noopener noreferrer">$1</a>');
}

/**
 * Convert a markdown string to safe HTML.
 */
export function renderMarkdown(md: string): string {
    // First, escape all HTML in the raw input.
    const escaped = escapeHtml(md);
    const lines = escaped.split('\n');
    const out: string[] = [];
    let inList = false;
    let inCodeBlock = false;

    for (let i = 0; i < lines.length; i++) {
        const line = lines[i];

        // Fenced code blocks (``` or ~~~)
        if (/^(`{3}|~{3})/.test(line)) {
            if (inCodeBlock) {
                out.push('</code></pre>');
                inCodeBlock = false;
            } else {
                if (inList) { out.push('</ul>'); inList = false; }
                out.push('<pre><code>');
                inCodeBlock = true;
            }
            continue;
        }

        if (inCodeBlock) {
            out.push(line);
            continue;
        }

        // Blank line — close any open list.
        if (line.trim() === '') {
            if (inList) { out.push('</ul>'); inList = false; }
            continue;
        }

        // Horizontal rule (---, ***, ___)
        if (/^(-{3,}|\*{3,}|_{3,})$/.test(line.trim())) {
            if (inList) { out.push('</ul>'); inList = false; }
            out.push('<hr>');
            continue;
        }

        // Headings (# through ####)
        const headingMatch = line.match(/^(#{1,4})\s+(.+)$/);
        if (headingMatch) {
            if (inList) { out.push('</ul>'); inList = false; }
            const level = headingMatch[1].length;
            out.push(`<h${level}>${inlineMarkdown(headingMatch[2])}</h${level}>`);
            continue;
        }

        // Unordered list items (-, *, +)
        const listMatch = line.match(/^[\s]*[-*+]\s+(.+)$/);
        if (listMatch) {
            if (!inList) { out.push('<ul>'); inList = true; }
            out.push(`<li>${inlineMarkdown(listMatch[1])}</li>`);
            continue;
        }

        // Ordered list items (1. 2. etc.)
        const orderedMatch = line.match(/^[\s]*\d+\.\s+(.+)$/);
        if (orderedMatch) {
            if (!inList) { out.push('<ul>'); inList = true; }
            out.push(`<li>${inlineMarkdown(orderedMatch[1])}</li>`);
            continue;
        }

        // Regular paragraph line.
        if (inList) { out.push('</ul>'); inList = false; }
        out.push(`<p>${inlineMarkdown(line)}</p>`);
    }

    if (inList) out.push('</ul>');
    if (inCodeBlock) out.push('</code></pre>');

    return out.join('\n');
}
