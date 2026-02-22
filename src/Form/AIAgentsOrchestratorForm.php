<?php

declare(strict_types=1);

namespace Drupal\ai_agents_orchestrator\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

/**
 * Form for the AI Agents Orchestrator page.
 */
class AIAgentsOrchestratorForm extends FormBase
{
    /**
     * {@inheritdoc}
     */
    public function getFormId(): string
    {
        return 'ai_agents_orchestrator_form';
    }

    /**
     * {@inheritdoc}
     */
    public function buildForm(array $form, FormStateInterface $form_state): array
    {
        $form['#attached']['library'][] = 'ai_agents_orchestrator/orchestrator';
        $form['#attached']['drupalSettings']['aiAgentsOrchestrator'] = [
            'chatUrl' => Url::fromRoute('ai_agents_orchestrator.chat')->toString(),
            'planStatusUrl' => Url::fromRoute('ai_agents_orchestrator.plan_status')->toString(),
            'pollUrl' => Url::fromRoute('ai_agents_orchestrator.poll', ['thread_id' => '__THREAD_ID__'])->toString(),
            'savePlanUrl' => Url::fromRoute('ai_agents_orchestrator.save_plan')->toString(),
            'chatHistoryUrl' => Url::fromRoute('ai_agents_orchestrator.load_chat_history')->toString(),
            'threadsUrl' => Url::fromRoute('ai_agents_orchestrator.list_threads')->toString(),
            'threadMetadataUrl' => Url::fromRoute('ai_agents_orchestrator.update_thread_metadata')->toString(),
            'deleteThreadUrl' => Url::fromRoute('ai_agents_orchestrator.delete_thread')->toString(),
            'executeUrl' => Url::fromRoute('ai_agents_orchestrator.execute_plan')->toString(),
            'agentsUrl' => Url::fromRoute('ai_agents_orchestrator.list_agents')->toString(),
        ];

        // React app mount point.
        $form['app'] = [
            '#type' => 'markup',
            '#markup' => '<div id="orchestrator-app-root"></div>',
        ];

        return $form;
    }

    /**
     * {@inheritdoc}
     */
    public function submitForm(array &$form, FormStateInterface $form_state): void
    {
        // No submit handling — React app communicates via AJAX.
    }
}
