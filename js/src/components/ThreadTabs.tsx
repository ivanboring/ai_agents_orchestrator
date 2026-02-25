// ThreadTabs — collapsible vertical sidebar for thread switching.
import { useState, useEffect } from 'react';

interface ThreadInfo {
    id: string;
    name: string;
    date: string;
    type: 'planning' | 'direct' | 'component';
    agent_id: string;
    is_executing: boolean;
    execution_ready: boolean;
    last_updated: number;
}

interface AgentOption {
    id: string;
    label: string;
    description: string;
}

interface ThreadTabsProps {
    threads: ThreadInfo[];
    activeThreadId: string;
    onSelect: (threadId: string) => void;
    onCreate: (type: 'planning' | 'direct' | 'component', agentId: string) => void;
    onDelete: (threadId: string) => void;
    isOpen: boolean;
    onToggle: () => void;
    isFullscreen: boolean;
    onToggleFullscreen: () => void;
    agentsUrl: string;
}

type CreateStep = 'idle' | 'pick-type' | 'pick-agent';

function formatDate(dateStr: string): string {
    if (dateStr.length !== 8) return dateStr;
    const y = dateStr.substring(0, 4);
    const m = dateStr.substring(4, 6);
    const d = dateStr.substring(6, 8);
    return `${d}/${m}/${y}`;
}

function formatTime(ts: number): string {
    if (!ts) return '';
    const d = new Date(ts * 1000);
    return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false });
}

export function ThreadTabs({ threads, activeThreadId, onSelect, onCreate, onDelete, isOpen, onToggle, isFullscreen, onToggleFullscreen, agentsUrl }: ThreadTabsProps) {
    const [confirmDeleteId, setConfirmDeleteId] = useState<string | null>(null);
    const [createStep, setCreateStep] = useState<CreateStep>('idle');
    const [agents, setAgents] = useState<AgentOption[]>([]);
    const [agentsLoading, setAgentsLoading] = useState(false);
    const [agentFilter, setAgentFilter] = useState('');

    // Fetch agents when entering agent picker step.
    useEffect(() => {
        if (createStep !== 'pick-agent') return;
        setAgentsLoading(true);
        (async () => {
            try {
                const res = await fetch(agentsUrl);
                if (res.ok) {
                    const data = await res.json();
                    if (Array.isArray(data)) setAgents(data);
                }
            } catch { /* ignore */ }
            finally { setAgentsLoading(false); }
        })();
    }, [createStep, agentsUrl]);

    const handleDeleteClick = (e: React.MouseEvent, threadId: string) => {
        e.stopPropagation();
        e.preventDefault();
        setConfirmDeleteId(threadId);
    };

    const handleConfirmDelete = () => {
        if (confirmDeleteId) {
            onDelete(confirmDeleteId);
            setConfirmDeleteId(null);
        }
    };

    const handleCancelDelete = () => {
        setConfirmDeleteId(null);
    };

    const handleNewClick = () => {
        setCreateStep('pick-type');
    };

    const handlePickPlanning = () => {
        setCreateStep('idle');
        onCreate('planning', '');
    };

    const handlePickDirect = () => {
        setCreateStep('pick-agent');
        setAgentFilter('');
    };

    const handlePickComponent = () => {
        setCreateStep('idle');
        onCreate('component', 'sdc_generator');
    };

    const handleSelectAgent = (agentId: string) => {
        setCreateStep('idle');
        onCreate('direct', agentId);
    };

    const handleCancelCreate = () => {
        setCreateStep('idle');
    };

    const filteredAgents = agents.filter(a =>
        a.label.toLowerCase().includes(agentFilter.toLowerCase()) ||
        a.id.toLowerCase().includes(agentFilter.toLowerCase())
    );

    return (
        <div className={`thread-sidebar ${isOpen ? 'thread-sidebar--open' : 'thread-sidebar--collapsed'}`}>
            <div className="thread-sidebar-header">
                {isOpen && <span className="thread-sidebar-title">Threads</span>}
                <button type="button" className="thread-sidebar-toggle thread-sidebar-toggle--large" onClick={onToggle} title={isOpen ? 'Collapse' : 'Expand'}>
                    {isOpen ? '◀' : '▶'}
                </button>
            </div>

            {/* Delete confirmation overlay */}
            {confirmDeleteId && isOpen && (
                <div className="thread-delete-confirm">
                    <p>Delete this thread and all its data?</p>
                    <div className="thread-delete-confirm-actions">
                        <button type="button" className="thread-delete-confirm-yes" onClick={handleConfirmDelete}>Delete</button>
                        <button type="button" className="thread-delete-confirm-no" onClick={handleCancelDelete}>Cancel</button>
                    </div>
                </div>
            )}

            {/* Thread creation flow */}
            {isOpen && createStep === 'pick-type' && (
                <div className="thread-create-picker">
                    <p className="thread-create-title">New Thread</p>
                    <button type="button" className="thread-create-option" onClick={handlePickPlanning}>
                        <span className="thread-create-option-icon">📋</span>
                        <span className="thread-create-option-text">
                            <strong>Planning</strong>
                            <small>Create a plan, review steps, then execute</small>
                        </span>
                    </button>
                    <button type="button" className="thread-create-option" onClick={handlePickDirect}>
                        <span className="thread-create-option-icon">🤖</span>
                        <span className="thread-create-option-text">
                            <strong>Direct Agent</strong>
                            <small>Talk directly to a specific agent</small>
                        </span>
                    </button>
                    <button type="button" className="thread-create-option" onClick={handlePickComponent}>
                        <span className="thread-create-option-icon">🧩</span>
                        <span className="thread-create-option-text">
                            <strong>Component</strong>
                            <small>Generate & review an SDC component</small>
                        </span>
                    </button>
                    <button type="button" className="thread-create-cancel" onClick={handleCancelCreate}>Cancel</button>
                </div>
            )}

            {isOpen && createStep === 'pick-agent' && (
                <div className="thread-create-picker">
                    <p className="thread-create-title">Pick an Agent</p>
                    <input
                        type="text"
                        className="thread-agent-filter"
                        placeholder="Search agents…"
                        value={agentFilter}
                        onChange={e => setAgentFilter(e.target.value)}
                        autoFocus
                    />
                    <div className="thread-agent-list">
                        {agentsLoading && <p className="thread-agent-loading">Loading…</p>}
                        {!agentsLoading && filteredAgents.length === 0 && <p className="thread-agent-loading">No agents found</p>}
                        {filteredAgents.map(agent => (
                            <button
                                key={agent.id}
                                type="button"
                                className="thread-agent-option"
                                onClick={() => handleSelectAgent(agent.id)}
                                title={agent.description || agent.label}
                            >
                                <strong>{agent.label}</strong>
                                {agent.description && <small>{agent.description}</small>}
                            </button>
                        ))}
                    </div>
                    <button type="button" className="thread-create-cancel" onClick={() => setCreateStep('pick-type')}>← Back</button>
                </div>
            )}

            {isOpen && !confirmDeleteId && createStep === 'idle' && (
                <>
                    <button type="button" className="thread-new-btn" onClick={handleNewClick} title="New thread">
                        + New Thread
                    </button>
                    <div className="thread-list">
                        {threads.map(thread => (
                            <div
                                key={thread.id}
                                className={`thread-item ${thread.id === activeThreadId ? 'thread-item--active' : ''}`}
                                onClick={() => onSelect(thread.id)}
                                title={thread.name || 'Thread'}
                            >
                                <div className="thread-item-top">
                                    <span className="thread-item-name">
                                        <span className="thread-type-badge">{thread.type === 'direct' ? '🤖' : thread.type === 'component' ? '🧩' : '📋'}</span>
                                        {thread.name || 'Thread'}
                                    </span>
                                    <button
                                        type="button"
                                        className="thread-item-delete"
                                        onClick={(e) => handleDeleteClick(e, thread.id)}
                                        title="Delete thread"
                                    >
                                        ×
                                    </button>
                                </div>
                                <span className="thread-item-date">{formatDate(thread.date)}{thread.last_updated ? ` · ${formatTime(thread.last_updated)}` : ''}</span>
                            </div>
                        ))}
                    </div>
                </>
            )}

            {!isOpen && (
                <div className="thread-list-collapsed">
                    {threads.map(thread => (
                        <button
                            type="button"
                            key={thread.id}
                            className={`thread-item-icon ${thread.id === activeThreadId ? 'thread-item-icon--active' : ''}`}
                            onClick={() => onSelect(thread.id)}
                            title={thread.name || 'Thread'}
                        >
                            {(thread.name || 'T').charAt(0).toUpperCase()}
                        </button>
                    ))}
                    <button type="button" className="thread-item-icon thread-item-icon--new" onClick={handleNewClick} title="New thread">
                        +
                    </button>
                </div>
            )}

            <div className="thread-sidebar-footer">
                <button
                    type="button"
                    className="thread-sidebar-fullscreen"
                    onClick={onToggleFullscreen}
                    title={isFullscreen ? 'Exit fullscreen' : 'Fullscreen'}
                >
                    {isFullscreen ? '⊡' : '⊞'}
                </button>
            </div>
        </div>
    );
}
