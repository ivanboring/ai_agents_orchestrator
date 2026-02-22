import { useState, useCallback, useEffect, useRef } from 'react';
import { ThreadTabs } from './components/ThreadTabs';
import { ChatPanel } from './components/ChatPanel';
import { PlanPanel } from './components/PlanPanel';
import { ExecutionPanel } from './components/ExecutionPanel';

export interface ThreadInfo {
    id: string;
    name: string;
    date: string;
    type: 'planning' | 'direct';
    agent_id: string;
    is_executing: boolean;
    execution_ready: boolean;
}

interface OrchestratorAppProps {
    chatUrl: string;
    planStatusUrl: string;
    pollUrl: string;
    savePlanUrl: string;
    chatHistoryUrl: string;
    threadsUrl: string;
    threadMetadataUrl: string;
    deleteThreadUrl: string;
    executeUrl: string;
    executionStepsUrl: string;
    agentsUrl: string;
}

/** Appends ?thread_id=ID to a URL. */
function withThread(url: string, threadId: string): string {
    const sep = url.includes('?') ? '&' : '?';
    return `${url}${sep}thread_id=${encodeURIComponent(threadId)}`;
}

export function OrchestratorApp({
    chatUrl,
    planStatusUrl,
    pollUrl,
    savePlanUrl,
    chatHistoryUrl,
    threadsUrl,
    threadMetadataUrl,
    deleteThreadUrl,
    executeUrl,
    executionStepsUrl,
    agentsUrl,
}: OrchestratorAppProps) {
    /* ---- Drawer state ---- */
    const [drawerOpen, setDrawerOpen] = useState(false);

    /* ---- Collapsible panels ---- */
    const [sidePanel, setSidePanel] = useState<'plan' | 'execution' | null>(null);
    const [hasPlan, setHasPlan] = useState(false);

    /* ---- Thread state ---- */
    const [isAgentRunning, setIsAgentRunning] = useState(false);
    const [threads, setThreads] = useState<ThreadInfo[]>([]);
    const [activeThreadId, setActiveThreadId] = useState<string | null>(null);
    const [threadsLoaded, setThreadsLoaded] = useState(false);
    const [sidebarOpen, setSidebarOpen] = useState(true);
    const prevAgentRunning = useRef(false);

    // Poll plan status ONLY while the agent is actively running.
    // When steps appear, set hasPlan and auto-open the Plan panel.
    useEffect(() => {
        if (!isAgentRunning || !activeThreadId) return;
        // Skip plan polling for direct agent threads.
        const threadType = threads.find(t => t.id === activeThreadId)?.type;
        if (threadType === 'direct') return;
        const url = withThread(planStatusUrl, activeThreadId);
        let cancelled = false;

        const check = async () => {
            try {
                const res = await fetch(url);
                if (!res.ok) return;
                const data = await res.json();
                if (!cancelled && Array.isArray(data) && data.length > 0) {
                    setHasPlan(true);
                    setSidePanel(prev => prev ?? 'plan');  // auto-open if nothing open
                }
            } catch { /* ignore */ }
        };

        check();
        const id = setInterval(check, 3000);
        return () => { cancelled = true; clearInterval(id); };
    }, [isAgentRunning, activeThreadId, planStatusUrl]);

    // Auto-open Plan panel when the agent starts running.
    useEffect(() => {
        if (isAgentRunning && !prevAgentRunning.current && drawerOpen) {
            // Skip auto-open for direct agent threads.
            const threadType = threads.find(t => t.id === activeThreadId)?.type;
            if (threadType !== 'direct') {
                setSidePanel('plan');
            }
        }
        prevAgentRunning.current = isAgentRunning;
    }, [isAgentRunning, drawerOpen]);

    // Auto-open Execution panel when execution starts on the active thread.
    const handleExecutionStarted = useCallback(() => {
        setSidePanel('execution');
        setHasPlan(true); // plan must exist if execution started
        // Immediately flag the thread as executing so the ExecutionPanel
        // starts polling without waiting for a metadata refresh.
        setThreads(prev => prev.map(t =>
            t.id === activeThreadId ? { ...t, is_executing: true } : t
        ));
    }, [activeThreadId]);

    const handleExecutionFinished = useCallback(() => {
        // Switch back to Plan panel so user sees results.
        setSidePanel('plan');
        // Clear is_executing in local state.
        setThreads(prev => prev.map(t =>
            t.id === activeThreadId ? { ...t, is_executing: false, execution_ready: false } : t
        ));
    }, [activeThreadId]);

    // Load threads on mount.
    useEffect(() => {
        (async () => {
            try {
                const res = await fetch(threadsUrl);
                if (!res.ok) throw new Error('fetch failed');
                const data = await res.json();
                if (Array.isArray(data) && data.length > 0) {
                    setThreads(data);
                    setActiveThreadId(data[0].id);
                } else {
                    const createRes = await fetch(threadsUrl, { method: 'POST' });
                    const created = await createRes.json();
                    if (created.id) {
                        const newThread: ThreadInfo = {
                            id: created.id,
                            name: '',
                            date: created.id.substring(0, 8),
                            type: 'planning',
                            agent_id: '',
                            is_executing: false,
                            execution_ready: false,
                        };
                        setThreads([newThread]);
                        setActiveThreadId(created.id);
                    }
                }
            } catch { /* ignore */ } finally {
                setThreadsLoaded(true);
            }
        })();
    }, [threadsUrl]);

    const handleCreateThread = useCallback(async (type: 'planning' | 'direct' = 'planning', agentId: string = '') => {
        try {
            const res = await fetch(threadsUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ type, agent_id: agentId }),
            });
            const data = await res.json();
            if (data.id) {
                const newThread: ThreadInfo = {
                    id: data.id,
                    name: data.metadata?.name || '',
                    date: data.id.substring(0, 8),
                    type,
                    agent_id: agentId,
                    is_executing: false,
                    execution_ready: false,
                };
                setThreads(prev => [newThread, ...prev]);
                setActiveThreadId(data.id);
            }
        } catch { /* ignore */ }
    }, [threadsUrl]);

    const handleThreadSelect = useCallback((threadId: string) => {
        setActiveThreadId(threadId);
        // Reset — the effect below will re-evaluate and auto-open if needed.
        setHasPlan(false);
        setSidePanel(null);
    }, []);

    // On thread change (including initial load): re-fetch thread metadata
    // and auto-open the appropriate side panel.
    useEffect(() => {
        if (!activeThreadId) return;
        let cancelled = false;

        const currentThread = threads.find(t => t.id === activeThreadId);

        // Skip panel logic for direct agent threads.
        if (currentThread?.type === 'direct') return;

        (async () => {
            // Re-fetch threads to get the latest is_executing state.
            try {
                const threadRes = await fetch(threadsUrl);
                if (threadRes.ok && !cancelled) {
                    const freshThreads: ThreadInfo[] = await threadRes.json();
                    if (Array.isArray(freshThreads)) {
                        setThreads(freshThreads);
                        const fresh = freshThreads.find(t => t.id === activeThreadId);
                        if (fresh?.is_executing) {
                            setHasPlan(true);
                            setSidePanel('execution');
                            return;
                        }
                    }
                }
            } catch { /* ignore */ }

            // Otherwise check if the thread already has plan steps.
            if (cancelled) return;
            const url = withThread(planStatusUrl, activeThreadId);
            try {
                const res = await fetch(url);
                if (!res.ok || cancelled) return;
                const data = await res.json();
                if (!cancelled && Array.isArray(data) && data.length > 0) {
                    setHasPlan(true);
                    setSidePanel('plan');
                }
            } catch { /* ignore */ }
        })();

        return () => { cancelled = true; };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [activeThreadId]);

    const handleDeleteThread = useCallback(async (threadId: string) => {
        try {
            const res = await fetch(withThread(deleteThreadUrl, threadId), { method: 'DELETE' });
            if (!res.ok) return;

            setThreads(prev => {
                const remaining = prev.filter(t => t.id !== threadId);
                if (threadId === activeThreadId) {
                    if (remaining.length > 0) {
                        setActiveThreadId(remaining[0].id);
                    } else {
                        (async () => {
                            const createRes = await fetch(threadsUrl, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ type: 'planning' }) });
                            const created = await createRes.json();
                            if (created.id) {
                                const nt: ThreadInfo = {
                                    id: created.id,
                                    name: '',
                                    date: created.id.substring(0, 8),
                                    type: 'planning',
                                    agent_id: '',
                                    is_executing: false,
                                    execution_ready: false,
                                };
                                setThreads([nt]);
                                setActiveThreadId(created.id);
                            }
                        })();
                    }
                }
                return remaining;
            });
        } catch { /* ignore */ }
    }, [deleteThreadUrl, activeThreadId, threadsUrl]);

    const handleThreadNamed = useCallback((name: string) => {
        if (!activeThreadId) return;
        setThreads(prev => prev.map(t =>
            t.id === activeThreadId ? { ...t, name } : t
        ));
        fetch(withThread(threadMetadataUrl, activeThreadId), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ name: name.substring(0, 80) }),
        }).catch(() => { /* ignore */ });
    }, [activeThreadId, threadMetadataUrl]);

    const handlePlanCleared = useCallback(() => {
        if (activeThreadId) {
            setThreads(prev => prev.map(t =>
                t.id === activeThreadId ? { ...t, name: '' } : t
            ));
        }
        setHasPlan(false);
        setSidePanel(null);
    }, [activeThreadId]);

    const handleStepsChange = useCallback((count: number) => {
        setHasPlan(count > 0);
    }, []);

    // Keyboard shortcuts: Escape to close, Ctrl+Shift+K to toggle.
    useEffect(() => {
        const handleKey = (e: KeyboardEvent) => {
            if (e.key === 'Escape' && drawerOpen) {
                setDrawerOpen(false);
            }
            if (e.ctrlKey && e.shiftKey && e.key === 'K') {
                e.preventDefault();
                setDrawerOpen(prev => !prev);
            }
        };
        window.addEventListener('keydown', handleKey);
        return () => window.removeEventListener('keydown', handleKey);
    }, [drawerOpen]);

    /* ---- Render ---- */

    // Floating trigger button (always visible when drawer closed).
    if (!drawerOpen) {
        return (
            <button
                type="button"
                className="orchestrator-fab"
                onClick={() => setDrawerOpen(true)}
                title="Open AI Orchestrator (Ctrl+Shift+K)"
            >
                ⚡
            </button>
        );
    }

    if (!threadsLoaded || !activeThreadId) {
        return (
            <>
                <div className="orchestrator-backdrop" onClick={() => setDrawerOpen(false)} />
                <div className="orchestrator-drawer">
                    <p style={{ padding: '1rem', color: '#adb5bd' }}>Loading…</p>
                </div>
            </>
        );
    }

    // Build per-thread URLs.
    const threadChatUrl = withThread(chatUrl, activeThreadId);
    const threadPlanStatusUrl = withThread(planStatusUrl, activeThreadId);
    const threadSavePlanUrl = withThread(savePlanUrl, activeThreadId);
    const threadChatHistoryUrl = withThread(chatHistoryUrl, activeThreadId);
    const currentThread = threads.find(t => t.id === activeThreadId);
    const currentThreadName = currentThread?.name || '';

    return (
        <>
            <div className="orchestrator-backdrop" onClick={() => setDrawerOpen(false)} />
            <div className="orchestrator-drawer">
                {/* Header */}
                <div className="orchestrator-drawer-header">
                    <span className="orchestrator-drawer-title">⚡ AI Orchestrator</span>
                    <button
                        type="button"
                        className="orchestrator-drawer-close"
                        onClick={() => setDrawerOpen(false)}
                        title="Close"
                    >✕</button>
                </div>

                {/* Thread sidebar + Chat */}
                <div className="orchestrator-drawer-body">
                    <ThreadTabs
                        threads={threads}
                        activeThreadId={activeThreadId}
                        onSelect={handleThreadSelect}
                        onCreate={handleCreateThread}
                        onDelete={handleDeleteThread}
                        isOpen={sidebarOpen}
                        onToggle={() => setSidebarOpen(o => !o)}
                        isFullscreen={false}
                        onToggleFullscreen={() => { }}
                        agentsUrl={agentsUrl}
                    />
                    <div className="orchestrator-drawer-chat">
                        <ChatPanel
                            key={activeThreadId}
                            chatUrl={threadChatUrl}
                            pollUrl={pollUrl}
                            chatHistoryUrl={threadChatHistoryUrl}
                            threadName={currentThreadName}
                            isDirect={currentThread?.type === 'direct'}
                            onThreadNamed={handleThreadNamed}
                            onAgentRunningChange={setIsAgentRunning}
                        />
                    </div>
                </div>

                {/* Footer: Plan / Execution toggle tabs (only when plan exists) */}
                {hasPlan && !sidePanel && currentThread?.type !== 'direct' && (
                    <div className="orchestrator-drawer-footer">
                        <button
                            type="button"
                            className={`orchestrator-drawer-tab ${sidePanel === 'plan' ? 'orchestrator-drawer-tab--active' : ''}`}
                            onClick={() => setSidePanel(p => p === 'plan' ? null : 'plan')}
                        >
                            📋 Plan
                        </button>
                        <button
                            type="button"
                            className={`orchestrator-drawer-tab ${sidePanel === 'execution' ? 'orchestrator-drawer-tab--active' : ''}`}
                            onClick={() => setSidePanel(p => p === 'execution' ? null : 'execution')}
                        >
                            ⚡ Execute
                        </button>
                    </div>
                )}
            </div>

            {/* Right-side panel */}
            {sidePanel && (
                <div className="orchestrator-side-panel">
                    <div className="orchestrator-side-panel-header">
                        <span>{sidePanel === 'plan' ? '📋 Plan' : '⚡ Execution'}</span>
                        <button
                            type="button"
                            className="orchestrator-drawer-close"
                            onClick={() => setSidePanel(null)}
                        >✕</button>
                    </div>
                    <div className="orchestrator-side-panel-body">
                        {sidePanel === 'plan' && (
                            <PlanPanel
                                key={`plan-${activeThreadId}`}
                                planStatusUrl={threadPlanStatusUrl}
                                savePlanUrl={threadSavePlanUrl}
                                executeUrl={withThread(executeUrl, activeThreadId)}
                                isAgentRunning={isAgentRunning}
                                onPlanCleared={handlePlanCleared}
                                onStepsChange={handleStepsChange}
                                onExecuteClicked={handleExecutionStarted}
                            />
                        )}
                        {sidePanel === 'execution' && (
                            <ExecutionPanel
                                key={`exec-${activeThreadId}`}
                                executeUrl={withThread(executeUrl, activeThreadId)}
                                executionStepsUrl={withThread(executionStepsUrl, activeThreadId)}
                                executionReady={currentThread?.execution_ready ?? false}
                                isExecuting={currentThread?.is_executing ?? false}
                                isAgentRunning={isAgentRunning}
                                onExecutionStarted={handleExecutionStarted}
                                onExecutionFinished={handleExecutionFinished}
                            />
                        )}
                    </div>
                </div>
            )}
        </>
    );
}
