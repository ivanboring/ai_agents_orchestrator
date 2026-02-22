import React from 'react';
import ReactDOM from 'react-dom/client';
import { OrchestratorApp } from './OrchestratorApp';
import './styles.css';

/**
 * Mount the orchestrator app.
 *
 * The init script (orchestrator-init.js) creates the root element and adds
 * data-attributes from drupalSettings. This IIFE may load before the Drupal
 * behavior fires, so we wait for the element to appear.
 */
function mount() {
    const rootElement = document.getElementById('orchestrator-app-root');

    if (!rootElement) {
        // Element not ready yet — retry on next frame.
        requestAnimationFrame(mount);
        return;
    }

    // Avoid double-mounting.
    if (rootElement.dataset.mounted) return;
    rootElement.dataset.mounted = 'true';

    const chatUrl = rootElement.getAttribute('data-chat-url') || '/admin/config/ai/agents/orchestrate/chat';
    const planStatusUrl = rootElement.getAttribute('data-plan-status-url') || '/admin/config/ai/agents/orchestrate/plan-status';
    const pollUrl = rootElement.getAttribute('data-poll-url') || '/admin/config/ai/agents/orchestrate/poll/__THREAD_ID__';
    const savePlanUrl = rootElement.getAttribute('data-save-plan-url') || '/admin/config/ai/agents/orchestrate/save-plan';
    const chatHistoryUrl = rootElement.getAttribute('data-chat-history-url') || '/admin/config/ai/agents/orchestrate/chat-history';
    const threadsUrl = rootElement.getAttribute('data-threads-url') || '/admin/config/ai/agents/orchestrate/threads';
    const threadMetadataUrl = rootElement.getAttribute('data-thread-metadata-url') || '/admin/config/ai/agents/orchestrate/thread-metadata';
    const deleteThreadUrl = rootElement.getAttribute('data-delete-thread-url') || '/admin/config/ai/agents/orchestrate/thread';
    const executeUrl = rootElement.getAttribute('data-execute-url') || '/admin/config/ai/agents/orchestrate/execute';
    const executionStepsUrl = rootElement.getAttribute('data-execution-steps-url') || '/admin/config/ai/agents/orchestrate/execution-steps';
    const agentsUrl = rootElement.getAttribute('data-agents-url') || '/admin/config/ai/agents/orchestrate/agents';

    const root = ReactDOM.createRoot(rootElement);
    root.render(
        <React.StrictMode>
            <OrchestratorApp
                chatUrl={chatUrl}
                planStatusUrl={planStatusUrl}
                pollUrl={pollUrl}
                savePlanUrl={savePlanUrl}
                chatHistoryUrl={chatHistoryUrl}
                threadsUrl={threadsUrl}
                threadMetadataUrl={threadMetadataUrl}
                deleteThreadUrl={deleteThreadUrl}
                executeUrl={executeUrl}
                executionStepsUrl={executionStepsUrl}
                agentsUrl={agentsUrl}
            />
        </React.StrictMode>
    );
}

// Start mounting when the DOM is ready.
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', mount);
} else {
    mount();
}
