import { useState, useEffect, useRef, useCallback } from 'react';

interface PlanStep {
    id: string;
    name: string;
    agent: string;
    prompt: string;
    reason: string;
    result: string | null;
}

interface PlanPanelProps {
    planStatusUrl: string;
    savePlanUrl: string;
    executeUrl: string;
    isAgentRunning?: boolean;
    onPlanCleared?: () => void;
    onStepsChange?: (count: number) => void;
    onExecuteClicked?: () => void;
}

/** Convert snake_case agent IDs to readable names, e.g. 'content_type_manager' → 'Content Type Manager'. */
function humanizeAgentName(id: string): string {
    return id.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
}

/* ---- Inline editable field ---- */

interface EditableFieldProps {
    label: string;
    value: string;
    onSave: (value: string) => void;
    disabled?: boolean;
    multiline?: boolean;
}

function EditableField({ label, value, onSave, disabled = false, multiline = false }: EditableFieldProps) {
    const [editing, setEditing] = useState(false);
    const [draft, setDraft] = useState(value);
    const inputRef = useRef<HTMLInputElement | HTMLTextAreaElement>(null);

    useEffect(() => { setDraft(value); }, [value]);
    useEffect(() => { if (editing) inputRef.current?.focus(); }, [editing]);

    const commit = () => {
        setEditing(false);
        const trimmed = draft.trim();
        if (trimmed && trimmed !== value) {
            onSave(trimmed);
        } else {
            setDraft(value);
        }
    };

    const handleKeyDown = (e: React.KeyboardEvent) => {
        if (e.key === 'Enter' && !multiline) { e.preventDefault(); commit(); }
        if (e.key === 'Escape') { setDraft(value); setEditing(false); }
    };

    const displayValue = label === 'Agent' ? humanizeAgentName(value) : value;

    if (editing && !disabled) {
        return (
            <div className="plan-step-detail plan-step-detail--editing">
                <span className="plan-step-label">{label}:</span>
                {multiline ? (
                    <textarea
                        ref={inputRef as React.RefObject<HTMLTextAreaElement>}
                        className="plan-step-edit-input plan-step-edit-textarea"
                        value={draft}
                        onChange={e => setDraft(e.target.value)}
                        onBlur={commit}
                        onKeyDown={handleKeyDown}
                        rows={3}
                    />
                ) : (
                    <input
                        ref={inputRef as React.RefObject<HTMLInputElement>}
                        className="plan-step-edit-input"
                        type="text"
                        value={draft}
                        onChange={e => setDraft(e.target.value)}
                        onBlur={commit}
                        onKeyDown={handleKeyDown}
                    />
                )}
            </div>
        );
    }

    return (
        <div
            className={`plan-step-detail ${!disabled ? 'plan-step-detail--editable' : ''}`}
            onClick={() => !disabled && setEditing(true)}
        >
            <span className="plan-step-label">{label}:</span>
            <span className="plan-step-value">{displayValue}</span>
            {!disabled && <span className="plan-step-edit-icon">✏️</span>}
        </div>
    );
}

export function PlanPanel({ planStatusUrl, savePlanUrl, executeUrl, isAgentRunning = false, onPlanCleared, onStepsChange, onExecuteClicked }: PlanPanelProps) {
    const [steps, setSteps] = useState<PlanStep[]>([]);
    const [error, setError] = useState<string | null>(null);
    const [saving, setSaving] = useState(false);
    const [confirmingClear, setConfirmingClear] = useState(false);
    const [planName, setPlanName] = useState('');
    const [executing, setExecuting] = useState(false);
    const intervalRef = useRef<ReturnType<typeof setInterval> | null>(null);

    useEffect(() => {
        const fetchPlan = async () => {
            try {
                const res = await fetch(planStatusUrl);
                if (!res.ok) {
                    setError(`Failed to fetch plan: ${res.status}`);
                    return;
                }
                const data = await res.json();
                if (Array.isArray(data)) {
                    setSteps(data);
                    setError(null);
                    onStepsChange?.(data.length);
                }
            } catch {
                setError('Could not reach plan endpoint.');
            }
        };

        fetchPlan();
        const pollMs = isAgentRunning ? 2000 : 10000;
        intervalRef.current = setInterval(fetchPlan, pollMs);

        return () => {
            if (intervalRef.current) {
                clearInterval(intervalRef.current);
            }
        };
    }, [planStatusUrl, isAgentRunning]);

    // Fetch plan metadata for the plan name.
    useEffect(() => {
        const metaUrl = planStatusUrl.replace('plan-status', 'plan-metadata');
        (async () => {
            try {
                const res = await fetch(metaUrl);
                if (!res.ok) return;
                const data = await res.json();
                if (data.name) setPlanName(data.name);
            } catch { /* ignore */ }
        })();
    }, [planStatusUrl, steps.length]);

    const persistSteps = useCallback(async (newSteps: PlanStep[]) => {
        setSaving(true);
        try {
            await fetch(savePlanUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ steps: newSteps }),
            });
        } catch {
            // Ignore save errors — next poll will sync.
        } finally {
            setSaving(false);
        }
    }, [savePlanUrl]);

    const moveStep = useCallback((index: number, direction: 'up' | 'down') => {
        const target = direction === 'up' ? index - 1 : index + 1;
        if (target < 0 || target >= steps.length) return;

        const newSteps = [...steps];
        [newSteps[index], newSteps[target]] = [newSteps[target], newSteps[index]];
        setSteps(newSteps);
        persistSteps(newSteps);
    }, [steps, persistSteps]);

    const removeStep = useCallback((index: number) => {
        const newSteps = steps.filter((_, i) => i !== index);
        setSteps(newSteps);
        persistSteps(newSteps);
    }, [steps, persistSteps]);

    const updateStepField = useCallback((index: number, field: keyof PlanStep, value: string) => {
        const newSteps = [...steps];
        newSteps[index] = { ...newSteps[index], [field]: value };
        setSteps(newSteps);
        persistSteps(newSteps);
    }, [steps, persistSteps]);

    const handleExecute = useCallback(async () => {
        setExecuting(true);
        try {
            const res = await fetch(executeUrl, { method: 'POST' });
            const data = await res.json();
            if (!res.ok) {
                console.error('Execute failed:', data.error);
            } else {
                onExecuteClicked?.();
            }
        } catch (err) {
            console.error('Execute error:', err);
        } finally {
            setExecuting(false);
        }
    }, [executeUrl]);

    const handleClearPlan = useCallback(() => {
        if (!confirmingClear) {
            setConfirmingClear(true);
            return;
        }
        const empty: PlanStep[] = [];
        setSteps(empty);
        persistSteps(empty);
        setPlanName('');
        setConfirmingClear(false);

        // Clear chat history on the backend.
        const chatHistoryUrl = planStatusUrl.replace('plan-status', 'chat-history');
        fetch(chatHistoryUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ messages: [] }),
        }).catch(() => { /* ignore */ });

        onPlanCleared?.();
    }, [confirmingClear, persistSteps, planStatusUrl, onPlanCleared]);

    const cancelClear = useCallback(() => {
        setConfirmingClear(false);
    }, []);

    const allStepsComplete = steps.length > 0 && steps.every(s => s.result !== null && s.result !== undefined && s.result !== '');
    const buttonsDisabled = steps.length === 0 || saving || isAgentRunning || executing;
    const executeDisabled = buttonsDisabled || allStepsComplete;
    const controlsDisabled = saving || isAgentRunning;

    if (error) {
        return (
            <div className="plan-panel">
                <div className="panel-header">
                    <h3>Plan</h3>
                </div>
                <div className="panel-body panel-body--error">
                    <p className="plan-error">⚠️ {error}</p>
                </div>
            </div>
        );
    }

    if (steps.length === 0) {
        return (
            <div className="plan-panel">
                <div className="panel-header">
                    <h3>Plan</h3>
                </div>
                <div className="panel-body panel-body--empty">
                    <div className="panel-empty-icon">📋</div>
                    <p>No plan steps yet. Chat with the agent to create a plan.</p>
                </div>
            </div>
        );
    }

    return (
        <div className="plan-panel">
            <div className="panel-header">
                <h3>{planName ? `Plan: ${planName}` : 'Plan'} <span className="plan-step-count">({steps.length} steps)</span></h3>
            </div>
            <div className="panel-body plan-steps-list">
                {steps.map((step, index) => (
                    <div
                        key={step.id}
                        className={`plan-step-card ${step.result ? 'plan-step-card--done' : 'plan-step-card--pending'}`}
                    >
                        <div className="plan-step-header">
                            <div className="plan-step-header-left">
                                <span className="plan-step-number">{index + 1}</span>
                                <span className="plan-step-status">{step.result ? '✅' : '⏳'}</span>
                                <span className="plan-step-name">{step.name}</span>
                            </div>
                            <div className="plan-step-actions">
                                <button
                                    className="plan-step-btn"
                                    title="Move up"
                                    disabled={index === 0 || controlsDisabled}
                                    onClick={() => moveStep(index, 'up')}
                                >↑</button>
                                <button
                                    className="plan-step-btn"
                                    title="Move down"
                                    disabled={index === steps.length - 1 || controlsDisabled}
                                    onClick={() => moveStep(index, 'down')}
                                >↓</button>
                                <button
                                    className="plan-step-btn plan-step-btn--delete"
                                    title="Remove step"
                                    disabled={controlsDisabled}
                                    onClick={() => removeStep(index)}
                                >✕</button>
                            </div>
                        </div>
                        <div className="plan-step-details">
                            <EditableField
                                label="Agent"
                                value={step.agent}
                                onSave={(val) => updateStepField(index, 'agent', val)}
                                disabled={controlsDisabled || !!step.result}
                            />
                            <EditableField
                                label="Prompt"
                                value={step.prompt}
                                onSave={(val) => updateStepField(index, 'prompt', val)}
                                disabled={controlsDisabled || !!step.result}
                                multiline
                            />
                            <div className="plan-step-detail">
                                <span className="plan-step-label">Reason:</span>
                                <span className="plan-step-value">{step.reason}</span>
                            </div>
                            {step.result && (
                                <div className="plan-step-detail plan-step-result">
                                    <span className="plan-step-label">Result:</span>
                                    <span className="plan-step-value">{step.result}</span>
                                </div>
                            )}
                        </div>
                    </div>
                ))}
            </div>
            <div className="plan-footer">
                {confirmingClear ? (
                    <div className="plan-footer-confirm">
                        <span className="plan-footer-confirm-text">Clear all steps?</span>
                        <button
                            className="plan-clear-btn plan-clear-btn--confirm"
                            onClick={handleClearPlan}
                        >
                            Yes, clear
                        </button>
                        <button
                            className="plan-clear-btn plan-clear-btn--cancel"
                            onClick={cancelClear}
                        >
                            Cancel
                        </button>
                    </div>
                ) : (
                    <div className="plan-footer-buttons">
                        <button
                            className="plan-clear-btn"
                            disabled={buttonsDisabled}
                            onClick={handleClearPlan}
                        >
                            Clear Plan
                        </button>
                        <button
                            className="chat-submit plan-execute-btn"
                            disabled={executeDisabled}
                            onClick={handleExecute}
                        >
                            {executing ? 'Starting…' : '⚡ Execute'}
                        </button>
                    </div>
                )}
            </div>
        </div>
    );
}
