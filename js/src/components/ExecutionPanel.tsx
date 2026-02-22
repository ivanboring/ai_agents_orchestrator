import { useState, useCallback, useEffect, useRef } from 'react';

interface StepEvent {
    type: string;
    time: number;
    agent_name?: string;
    tool_name?: string;
    tool_results?: string;
    text_response?: string;
}

interface StepSummary {
    step_number: number;
    step_id: string;
    agent_name: string;
    status: 'running' | 'complete';
    events: StepEvent[];
}

interface ExecutionPanelProps {
    executeUrl: string;
    executionStepsUrl: string;
    executionReady: boolean;
    isExecuting: boolean;
    isAgentRunning: boolean;
    onExecutionStarted?: () => void;
    onExecutionFinished?: () => void;
}

/** Human-friendly event type labels. */
function eventLabel(type: string): string {
    switch (type) {
        case 'agent_started': return 'Agent started';
        case 'agent_iteration': return 'Iteration';
        case 'tool_selected': return 'Tool selected';
        case 'tool_started': return 'Running tool';
        case 'tool_finished': return 'Tool finished';
        case 'text_generated': return 'Response generated';
        default: return type;
    }
}

/** Extracts a short tool name from a full plugin ID like tool__ai_agents_field_manager__add_field. */
function shortToolName(name: string): string {
    const parts = name.split('__');
    return parts.length >= 3 ? parts.slice(2).join(' → ') : parts[parts.length - 1];
}

export function ExecutionPanel({ executeUrl, executionStepsUrl, executionReady, isExecuting, isAgentRunning, onExecutionStarted, onExecutionFinished }: ExecutionPanelProps) {
    const [status, setStatus] = useState<'idle' | 'starting' | 'running' | 'error'>('idle');
    const [errorMessage, setErrorMessage] = useState('');
    const [steps, setSteps] = useState<StepSummary[]>([]);
    const [expandedSteps, setExpandedSteps] = useState<Set<number>>(new Set());
    const timelineRef = useRef<HTMLDivElement>(null);

    const handleExecute = useCallback(async () => {
        setStatus('starting');
        setErrorMessage('');

        try {
            const res = await fetch(executeUrl, { method: 'POST' });
            const data = await res.json();

            if (!res.ok) {
                setStatus('error');
                setErrorMessage(data.error || 'Failed to start execution.');
                return;
            }

            setStatus('running');
            onExecutionStarted?.();
        } catch {
            setStatus('error');
            setErrorMessage('Network error — could not reach the server.');
        }
    }, [executeUrl]);

    // Poll for execution step progress while executing.
    useEffect(() => {
        if (!isExecuting && status !== 'running') return;

        let cancelled = false;

        const poll = async () => {
            try {
                const res = await fetch(executionStepsUrl);
                if (res.ok && !cancelled) {
                    const data = await res.json();
                    if (Array.isArray(data.steps)) {
                        setSteps(data.steps);

                        // Auto-expand the currently running step.
                        const running = data.steps.find((s: StepSummary) => s.status === 'running');
                        if (running) {
                            setExpandedSteps(prev => {
                                const next = new Set(prev);
                                next.add(running.step_number);
                                return next;
                            });
                        }
                    }

                    // Backend says execution is done — stop polling and jump back.
                    if (data.is_executing === false && data.steps?.length > 0) {
                        cancelled = true;
                        clearInterval(interval);
                        setTimeout(() => onExecutionFinished?.(), 2000);
                    }
                }
            } catch { /* ignore */ }
        };

        poll(); // Initial fetch.
        const interval = setInterval(poll, 3000);

        return () => {
            cancelled = true;
            clearInterval(interval);
        };
    }, [isExecuting, status, executionStepsUrl]);

    // Auto-scroll to bottom when steps update.
    useEffect(() => {
        if (timelineRef.current) {
            timelineRef.current.scrollTop = timelineRef.current.scrollHeight;
        }
    }, [steps]);

    const toggleStep = (stepNumber: number) => {
        setExpandedSteps(prev => {
            const next = new Set(prev);
            if (next.has(stepNumber)) next.delete(stepNumber);
            else next.add(stepNumber);
            return next;
        });
    };

    const canExecute = executionReady && !isExecuting && !isAgentRunning && status !== 'starting';
    const showTimeline = steps.length > 0;

    return (
        <div className="execution-panel">
            <div className="panel-header">
                <h3>Execution</h3>
            </div>
            <div className="panel-body">
                {/* Execute button area */}
                {!showTimeline && !isExecuting && status !== 'running' && (
                    <>
                        {status === 'error' ? (
                            <div className="execution-status">
                                <div className="panel-empty-icon">⚠️</div>
                                <p>{errorMessage}</p>
                                {canExecute && (
                                    <button type="button" className="execution-btn" onClick={handleExecute}>
                                        Retry
                                    </button>
                                )}
                            </div>
                        ) : executionReady ? (
                            <div className="execution-status">
                                <div className="panel-empty-icon">✅</div>
                                <p>Plan is ready for execution.</p>
                                <button type="button" className="execution-btn" onClick={handleExecute} disabled={!canExecute}>
                                    {status === 'starting' ? 'Starting…' : '⚡ Execute Plan'}
                                </button>
                            </div>
                        ) : (
                            <div className="panel-body--empty">
                                <div className="panel-empty-icon">⚡</div>
                                <p>Execution output will appear here</p>
                                {isAgentRunning && <p className="execution-hint">Agent is still planning…</p>}
                            </div>
                        )}
                    </>
                )}

                {/* Execution timeline */}
                {(showTimeline || isExecuting || status === 'running') && (
                    <div className="execution-timeline" ref={timelineRef}>
                        {(isExecuting || status === 'running') && steps.length === 0 && (
                            <div className="execution-status">
                                <div className="execution-spinner" />
                                <p>Starting execution…</p>
                            </div>
                        )}
                        {steps.map((step) => {
                            const isExpanded = expandedSteps.has(step.step_number);
                            const isRunning = step.status === 'running';

                            return (
                                <div key={step.step_id} className={`execution-step ${isRunning ? 'execution-step--running' : 'execution-step--complete'}`}>
                                    <button
                                        type="button"
                                        className="execution-step__header"
                                        onClick={() => toggleStep(step.step_number)}
                                    >
                                        <span className="execution-step__indicator">
                                            {isRunning ? <span className="execution-step__spinner" /> : '✅'}
                                        </span>
                                        <span className="execution-step__title">
                                            <strong>Step {step.step_number}</strong>
                                            <span className="execution-step__agent">{step.agent_name}</span>
                                        </span>
                                        <span className={`execution-step__chevron ${isExpanded ? 'execution-step__chevron--open' : ''}`}>
                                            ▸
                                        </span>
                                    </button>

                                    {isExpanded && (
                                        <div className="execution-step__events">
                                            {step.events
                                                .filter(e => e.type !== 'agent_iteration')
                                                .map((event, i) => (
                                                    <div key={i} className={`execution-event execution-event--${event.type}`}>
                                                        <span className="execution-event__label">{eventLabel(event.type)}</span>
                                                        {event.tool_name && (
                                                            <span className="execution-event__tool">{shortToolName(event.tool_name)}</span>
                                                        )}
                                                        {event.tool_results && (
                                                            <span className="execution-event__result">{event.tool_results}</span>
                                                        )}
                                                        {event.text_response && (
                                                            <span className="execution-event__result">{event.text_response}</span>
                                                        )}
                                                    </div>
                                                ))}
                                        </div>
                                    )}
                                </div>
                            );
                        })}
                    </div>
                )}
            </div>
        </div>
    );
}
