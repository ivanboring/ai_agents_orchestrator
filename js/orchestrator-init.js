/**
 * @file
 * Init script that bridges Drupal settings to the React app.
 *
 * Creates the mount point dynamically so the widget works on every page,
 * not just the dedicated orchestrator form.
 */
(function (Drupal, drupalSettings) {
    'use strict';

    Drupal.behaviors.aiAgentsOrchestrator = {
        attach: function (context) {
            // Only run once on full document attach.
            if (context !== document) return;

            var settings = drupalSettings.aiAgentsOrchestrator;
            if (!settings) return;

            // Create mount point if it doesn't exist yet.
            var root = document.getElementById('orchestrator-app-root');
            if (!root) {
                root = document.createElement('div');
                root.id = 'orchestrator-app-root';
                document.body.appendChild(root);
            }

            // Pass Drupal settings as data attributes for the React app.
            var attrs = [
                ['data-chat-url', 'chatUrl'],
                ['data-plan-status-url', 'planStatusUrl'],
                ['data-poll-url', 'pollUrl'],
                ['data-save-plan-url', 'savePlanUrl'],
                ['data-chat-history-url', 'chatHistoryUrl'],
                ['data-threads-url', 'threadsUrl'],
                ['data-thread-metadata-url', 'threadMetadataUrl'],
                ['data-delete-thread-url', 'deleteThreadUrl'],
                ['data-execute-url', 'executeUrl'],
                ['data-agents-url', 'agentsUrl'],
            ];
            attrs.forEach(function (pair) {
                if (settings[pair[1]]) {
                    root.setAttribute(pair[0], settings[pair[1]]);
                }
            });
        }
    };

})(Drupal, drupalSettings);
