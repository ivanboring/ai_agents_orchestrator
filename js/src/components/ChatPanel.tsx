import React, { useState, useRef, useEffect, useCallback } from 'react';

interface ChatMessage {
    id: string;
    role: 'user' | 'assistant';
    content: string;
    /** True if this is a temporary message replaced each loop iteration. */
    temp?: boolean;
}

interface ProgressItem {
    type: 'tool_started' | 'tool_finished' | 'finished';
    tool_name?: string;
    tool_input?: string;
    tool_feedback_message?: string;
    tool_id?: string;
    time?: number;
}

interface ChatPanelProps {
    chatUrl: string;
    pollUrl: string;
    chatHistoryUrl: string;
    threadName?: string;
    isDirect?: boolean;
    onThreadNamed?: (name: string) => void;
    onAgentRunningChange?: (running: boolean) => void;
}

/** Generate a unique thread ID for progress tracking. */
function generateThreadId(): string {
    const bytes = new Uint8Array(8);
    crypto.getRandomValues(bytes);
    return 'orch_' + Array.from(bytes).map(b => b.toString(16).padStart(2, '0')).join('');
}

const MAX_LOOPS = 50;

export function ChatPanel({ chatUrl, pollUrl, chatHistoryUrl, threadName = '', isDirect = false, onThreadNamed, onAgentRunningChange }: ChatPanelProps) {
    const [messages, setMessages] = useState<ChatMessage[]>([]);
    const [input, setInput] = useState('');
    const [isLoading, setIsLoading] = useState(false);
    const [progressItems, setProgressItems] = useState<ProgressItem[]>([]);
    const [loopCount, setLoopCount] = useState(0);

    const pollIntervalRef = useRef<ReturnType<typeof setInterval> | null>(null);
    const abortRef = useRef<AbortController | null>(null);

    // Load persisted chat history on mount.
    useEffect(() => {
        (async () => {
            try {
                const res = await fetch(chatHistoryUrl);
                if (!res.ok) return;
                const data = await res.json();
                if (Array.isArray(data) && data.length > 0) {
                    setMessages(data.map((m: any, i: number) => ({
                        id: `loaded_${i}`,
                        role: m.role as 'user' | 'assistant',
                        content: m.content,
                    })));
                }
            } catch {
                // Ignore load errors.
            }
        })();
    }, [chatHistoryUrl]);

    const stopPolling = useCallback(() => {
        if (pollIntervalRef.current) {
            clearInterval(pollIntervalRef.current);
            pollIntervalRef.current = null;
        }
    }, []);

    const startPolling = useCallback((threadId: string) => {
        stopPolling();
        const url = pollUrl.replace('__THREAD_ID__', threadId);

        const fetchProgress = async () => {
            try {
                const res = await fetch(url);
                if (!res.ok) return;
                const data = await res.json();
                if (Array.isArray(data.items)) {
                    setProgressItems(data.items);
                }
            } catch {
                // Ignore poll errors.
            }
        };

        fetchProgress();
        pollIntervalRef.current = setInterval(fetchProgress, 1000);
    }, [pollUrl, stopPolling]);

    const handleSubmit = useCallback(async (e: React.FormEvent) => {
        e.preventDefault();
        const text = input.trim();
        if (!text || isLoading) return;

        // Add user message.
        const userMsg: ChatMessage = {
            id: `user_${Date.now()}`,
            role: 'user',
            content: text,
        };
        setMessages(prev => [...prev, userMsg]);
        setInput('');
        setIsLoading(true);
        onAgentRunningChange?.(true);
        setLoopCount(0);

        // Auto-name the thread from the first user message (3–7 word summary).
        if (!threadName && onThreadNamed) {
            const words = text.replace(/[^\w\s]/g, '').trim().split(/\s+/);
            const summary = words.slice(0, 7).join(' ');
            onThreadNamed(summary);
        }

        // Build the request body — same message each loop iteration.
        const requestMessages = [{ role: 'user', content: text }];

        let finished = false;
        let iteration = 0;

        try {
            if (isDirect) {
                // ── Direct agent: single request, no loop, with polling. ──
                // Send the full conversation history so the LLM has context.
                const allMessages = [
                    ...messages.filter(m => !m.temp).map(m => ({ role: m.role, content: m.content })),
                    { role: 'user', content: text },
                ];

                const threadId = generateThreadId();
                startPolling(threadId);

                const controller = new AbortController();
                abortRef.current = controller;

                const res = await fetch(chatUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        thread_id: threadId,
                        messages: allMessages,
                    }),
                    signal: controller.signal,
                });

                stopPolling();

                // Final poll to capture remaining progress events.
                try {
                    const finalUrl = pollUrl.replace('__THREAD_ID__', threadId);
                    const finalRes = await fetch(finalUrl);
                    const finalData = await finalRes.json();
                    if (Array.isArray(finalData.items)) {
                        setProgressItems(finalData.items);
                    }
                } catch { /* ignore */ }

                if (!res.ok) throw new Error(`Agent returned ${res.status}`);

                const data = await res.json();
                const responseText = data.message || data.error || 'No response from agent.';

                setMessages(prev => [...prev, {
                    id: `assistant_${Date.now()}`,
                    role: 'assistant' as const,
                    content: responseText,
                }]);
            } else {
                // ── Planning agent: multi-iteration loop with progress polling. ──
                while (!finished && iteration < MAX_LOOPS) {
                    iteration++;
                    setLoopCount(iteration);
                    setProgressItems([]);

                    const threadId = generateThreadId();
                    startPolling(threadId);

                    const controller = new AbortController();
                    abortRef.current = controller;

                    const res = await fetch(chatUrl, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            thread_id: threadId,
                            messages: requestMessages,
                        }),
                        signal: controller.signal,
                    });

                    stopPolling();

                    // Final poll to capture remaining progress events.
                    try {
                        const finalUrl = pollUrl.replace('__THREAD_ID__', threadId);
                        const finalRes = await fetch(finalUrl);
                        const finalData = await finalRes.json();
                        if (Array.isArray(finalData.items)) {
                            setProgressItems(finalData.items);
                        }
                    } catch {
                        // Ignore.
                    }

                    if (!res.ok) {
                        throw new Error(`Agent returned ${res.status}`);
                    }

                    const data = await res.json();
                    const responseText = data.message || data.error || 'No response from agent.';
                    const executionReady = data.execution_ready === true;

                    if (executionReady && iteration > 1) {
                        // Plan finished — show final response permanently.
                        finished = true;
                        setMessages(prev => {
                            const cleaned = prev.filter(m => !m.temp);
                            return [...cleaned, {
                                id: `assistant_${Date.now()}`,
                                role: 'assistant' as const,
                                content: responseText,
                            }];
                        });
                    } else {
                        // Intermediate — show temporarily, replace each loop.
                        setMessages(prev => {
                            const cleaned = prev.filter(m => !m.temp);
                            return [...cleaned, {
                                id: 'orchestrator_temp',
                                role: 'assistant' as const,
                                content: responseText,
                                temp: true,
                            }];
                        });
                    }
                }

                if (!finished) {
                    setMessages(prev => {
                        const cleaned = prev.filter(m => !m.temp);
                        return [...cleaned, {
                            id: `error_${Date.now()}`,
                            role: 'assistant',
                            content: '⚠️ Agent did not finish planning within the loop limit.',
                        }];
                    });
                }
            } // end else (planning mode)
        } catch (err: any) {
            if (err.name !== 'AbortError') {
                setMessages(prev => {
                    const cleaned = prev.filter(m => !m.temp);
                    return [...cleaned, {
                        id: `error_${Date.now()}`,
                        role: 'assistant',
                        content: '⚠️ Failed to reach the agent. Please try again.',
                    }];
                });
            }
        } finally {
            setIsLoading(false);
            setLoopCount(0);
            onAgentRunningChange?.(false);
            stopPolling();
            abortRef.current = null;
        }
    }, [input, isLoading, isDirect, chatUrl, pollUrl, stopPolling, startPolling, onAgentRunningChange]);

    // Cleanup on unmount.
    useEffect(() => {
        return () => {
            stopPolling();
            abortRef.current?.abort();
        };
    }, [stopPolling]);

    // Build progress display with tool details.
    interface ProgressDisplay {
        name: string;
        status: 'running' | 'done';
        details?: { agent?: string; prompt?: string };
    }

    const displayProgress = progressItems.reduce<ProgressDisplay[]>((acc, item) => {
        const name = item.tool_feedback_message || (item.tool_name ? `Running ${item.tool_name}` : '');
        if (!name) return acc;

        // Parse tool_input for execute_agent to extract agent_id and message.
        let details: ProgressDisplay['details'] = undefined;
        if (item.tool_name === 'orchestrator_execute_agent' && item.tool_input) {
            try {
                const parsed = JSON.parse(item.tool_input);
                details = {
                    agent: parsed.agent_id || undefined,
                    prompt: parsed.message || undefined,
                };
            } catch {
                // Ignore parse errors.
            }
        }

        if (item.type === 'tool_started') {
            const existing = acc.find(a => a.name === name);
            if (!existing) {
                acc.push({ name, status: 'running', details });
            }
        } else if (item.type === 'tool_finished') {
            const finishedName = item.tool_feedback_message || (item.tool_name ? `Running ${item.tool_name}` : '');
            const existing = acc.find(a => a.name === finishedName);
            if (existing) {
                existing.status = 'done';
            }
        }
        return acc;
    }, []);

    const allFinished = progressItems.some(item => item.type === 'finished');

    return (
        <div className="chat-panel">
            <div className="panel-header">
                <h3>Chat {loopCount > 0 && <span className="chat-loop-badge">Loop {loopCount}</span>}</h3>
            </div>

            <div className="chat-messages">
                {messages.length === 0 && !isLoading && (
                    <div className="chat-empty">
                        <div className="chat-empty-icon">💬</div>
                        <p>Send a message to start the conversation</p>
                    </div>
                )}

                {messages.map((message) => (
                    <div
                        key={message.id}
                        className={`chat-message chat-message--${message.role} ${message.temp ? 'chat-message--temp' : ''}`}
                    >
                        <div className="chat-message-role">
                            {message.role === 'user' ? '👤 You' : '🤖 Assistant'}
                            {message.temp && <span className="chat-message-temp-badge">interim</span>}
                        </div>
                        <div className="chat-message-content">
                            {message.content}
                        </div>
                    </div>
                ))}

                {isLoading && (
                    <div className="chat-progress">
                        {displayProgress.length === 0 && (
                            <div className="chat-progress-item chat-progress-item--running">
                                <span className="chat-progress-spinner" />
                                <span>Thinking…</span>
                            </div>
                        )}
                        {displayProgress.map((item, index) => (
                            <div key={index} className={`chat-progress-item chat-progress-item--${item.status}`}>
                                <div className="chat-progress-main">
                                    {item.status === 'running' ? (
                                        <span className="chat-progress-spinner" />
                                    ) : (
                                        <span className="chat-progress-check">✓</span>
                                    )}
                                    <span>{item.name}</span>
                                </div>
                                {item.details?.agent && (
                                    <div className="chat-progress-details">
                                        <div className="chat-progress-detail">
                                            <span className="chat-progress-detail-label">Agent:</span>
                                            <span className="chat-progress-detail-value">{item.details.agent}</span>
                                        </div>
                                        {item.details.prompt && (
                                            <div className="chat-progress-detail">
                                                <span className="chat-progress-detail-label">Prompt:</span>
                                                <span className="chat-progress-detail-value">{item.details.prompt}</span>
                                            </div>
                                        )}
                                    </div>
                                )}
                            </div>
                        ))}
                        {allFinished && (
                            <div className="chat-progress-item chat-progress-item--running">
                                <span className="chat-progress-spinner" />
                                <span>Preparing response…</span>
                            </div>
                        )}
                    </div>
                )}

            </div>

            <form className="chat-input-form" onSubmit={handleSubmit}>
                <textarea
                    className="chat-input"
                    value={input}
                    onChange={(e) => {
                        setInput(e.target.value);
                        e.target.style.height = 'auto';
                        e.target.style.height = Math.min(e.target.scrollHeight, 100) + 'px';
                    }}
                    onKeyDown={(e) => {
                        if (e.key === 'Enter' && !e.shiftKey) {
                            e.preventDefault();
                            if (input.trim() && !isLoading) {
                                handleSubmit(e as unknown as React.FormEvent);
                            }
                        }
                    }}
                    placeholder="Type a message..."
                    disabled={isLoading}
                    rows={2}
                />
                {isLoading ? (
                    <button
                        className="chat-submit chat-submit--cancel"
                        type="button"
                        onClick={() => abortRef.current?.abort()}
                    >
                        Cancel
                    </button>
                ) : (
                    <button
                        className="chat-submit"
                        type="submit"
                        disabled={!input.trim()}
                    >
                        Send
                    </button>
                )}
            </form>
        </div>
    );
}
