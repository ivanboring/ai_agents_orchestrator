<?php

declare(strict_types=1);

namespace Drupal\ai_agents_orchestrator\Plugin\QueueWorker;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\Queue\Attribute\QueueWorker;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai_agents\PluginInterfaces\AiAgentInterface;
use Drupal\ai_agents\PluginManager\AiAgentManager;
use Drupal\ai_agents_orchestrator\Service\PlanDocumentService;
use Drupal\ai_agents_orchestrator\Service\PlanExecutionService;
use Drupal\ai_agents_orchestrator\Service\ThreadFileStatusStorage;
use Drupal\user\Entity\User;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Queue worker that executes plan steps one at a time.
 *
 * Each queue item processes one step: it runs the step's agent, writes the
 * result back into the JSONL plan, and re-queues itself for the next step
 * unless the plan is finished or flagged for human review.
 */
#[QueueWorker(
    id: 'ai_agents_orchestrator_plan_executor',
    title: new TranslatableMarkup('Plan Executor'),
    cron: ['time' => 120],
)]
class PlanExecutorWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface
{

    /**
     * The tool plugin ID for the human review flag tool.
     */
    protected const HUMAN_REVIEW_TOOL_ID = 'orchestrator_flag_human_review';

    /**
     * Constructor.
     *
     * @param array $configuration
     *   Plugin configuration.
     * @param string $plugin_id
     *   The plugin ID.
     * @param mixed $plugin_definition
     *   The plugin definition.
     * @param \Drupal\ai_agents_orchestrator\Service\PlanDocumentService $planDocumentService
     *   The plan document service.
     * @param \Drupal\ai_agents_orchestrator\Service\PlanExecutionService $planExecutionService
     *   The plan execution service.
     * @param \Drupal\ai_agents\PluginManager\AiAgentManager $agentsManager
     *   The AI agents plugin manager.
     * @param \Drupal\ai\AiProviderPluginManager $providerManager
     *   The AI provider plugin manager.
     * @param \Drupal\Core\Session\AccountSwitcherInterface $accountSwitcher
     *   The account switcher service.
     * @param \Drupal\ai_agents_orchestrator\Service\ThreadFileStatusStorage $statusStorage
     *   The file-based status storage.
     * @param \Psr\Log\LoggerInterface $logger
     *   The logger.
     */
    public function __construct(
        array $configuration,
        $plugin_id,
        $plugin_definition,
        protected readonly PlanDocumentService $planDocumentService,
        protected readonly PlanExecutionService $planExecutionService,
        protected readonly AiAgentManager $agentsManager,
        protected readonly AiProviderPluginManager $providerManager,
        protected readonly AccountSwitcherInterface $accountSwitcher,
        protected readonly ThreadFileStatusStorage $statusStorage,
        protected readonly LoggerInterface $logger,
    ) {
        parent::__construct($configuration, $plugin_id, $plugin_definition);
    }

    /**
     * {@inheritdoc}
     */
    public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static
    {
        return new static(
            $configuration,
            $plugin_id,
            $plugin_definition,
            $container->get('ai_agents_orchestrator.plan_document'),
            $container->get('ai_agents_orchestrator.plan_execution'),
            $container->get('plugin.manager.ai_agents'),
            $container->get('ai.provider'),
            $container->get('account_switcher'),
            $container->get('ai_agents_orchestrator.thread_file_status_storage'),
            $container->get('logger.factory')->get('ai_agents_orchestrator'),
        );
    }

    /**
     * {@inheritdoc}
     */
    public function processItem($data): void
    {
        $threadId = $data['thread_id'] ?? '';
        $uid = (int) ($data['uid'] ?? 0);

        if (empty($threadId) || empty($uid)) {
            $this->logger->error('Plan executor queue item missing thread_id or uid.');
            return;
        }

        // Switch to the user that owns the thread.
        $account = User::load($uid);
        if ($account === NULL) {
            $this->logger->error('Plan executor: user @uid not found.', ['@uid' => $uid]);
            return;
        }

        $this->accountSwitcher->switchTo($account);

        try {
            $this->executeStep($threadId, $uid);
        } finally {
            $this->accountSwitcher->switchBack();
        }
    }

    /**
     * Executes the next pending step in the plan.
     *
     * @param string $threadId
     *   The thread ID.
     * @param int $uid
     *   The user ID.
     */
    protected function executeStep(string $threadId, int $uid): void
    {
        $this->planDocumentService->setActiveThread($threadId);

        // Check if human review is pending in metadata.
        $metadata = $this->planDocumentService->readMetadata();
        if (!empty($metadata['human_review_needed'])) {
            $this->logger->info('Plan executor: thread @id has pending human review, halting.', [
                '@id' => $threadId,
            ]);
            $this->markHalted($threadId);
            return;
        }

        // Read the plan and find the next pending step.
        $steps = $this->planDocumentService->readAll();
        if (empty($steps)) {
            $this->markFinished($threadId);
            return;
        }

        $pendingStep = NULL;
        $pendingIndex = NULL;
        $previousResults = [];

        foreach ($steps as $index => $step) {
            if ($step['result'] === NULL) {
                $pendingStep = $step;
                $pendingIndex = $index;
                break;
            }

            // Collect previous results for context.
            $previousResults[] = [
                'name' => $step['name'] ?? '',
                'result' => $step['result'],
            ];
        }

        // No pending steps — all done.
        if ($pendingStep === NULL) {
            $this->markFinished($threadId);
            return;
        }

        // Build the enriched prompt with context from previous steps.
        $prompt = $this->buildEnrichedPrompt($pendingStep, $previousResults);

        // Execute the step's agent.
        $agentId = $pendingStep['agent'] ?? '';
        if (empty($agentId)) {
            $this->planDocumentService->updateStep($pendingStep['id'], [
                'result' => '[ERROR] No agent specified for this step.',
            ]);
            $this->logger->error('Plan executor: step @id has no agent.', [
                '@id' => $pendingStep['id'],
            ]);
            $this->markHalted($threadId);
            return;
        }

        // Build the step debug file path and register it on the storage.
        $runnerId = $threadId . '_' . bin2hex(random_bytes(4));
        $stepFilePath = 'public://ai_planning/' . $uid . '/' . $threadId . '/steps/' . ($pendingIndex + 1) . '_' . $pendingStep['id'] . '.yml';
        $this->statusStorage->registerPath($runnerId, $stepFilePath);
        $this->statusStorage->startStatusUpdate($runnerId);

        try {
            $agentResult = $this->runAgent($agentId, $prompt, $runnerId);
        }
        catch (\Throwable $e) {
            $this->statusStorage->unregisterPath($runnerId);
            $errorMessage = '[ERROR] ' . $e->getMessage();
            $this->planDocumentService->updateStep($pendingStep['id'], [
                'result' => $errorMessage,
            ]);
            $this->logger->error('Plan executor: exception during step @id: @message', [
                '@id' => $pendingStep['id'],
                '@message' => $e->getMessage(),
            ]);
            $this->markHalted($threadId);
            return;
        }

        // Unregister the path now that the agent has finished.
        $this->statusStorage->unregisterPath($runnerId);

        // Write the textual result back to the JSONL.
        $this->planDocumentService->updateStep($pendingStep['id'], [
            'result' => $agentResult['response'],
        ]);

        $this->logger->info('Plan executor: step @id (@name) executed. Agent: @agent.', [
            '@id' => $pendingStep['id'],
            '@name' => $pendingStep['name'] ?? '',
            '@agent' => $agentId,
        ]);

        // Check if the agent flagged for human review by inspecting tool results.
        if ($agentResult['human_review_flagged']) {
            $this->logger->info('Plan executor: agent flagged human review during step @id, halting.', [
                '@id' => $pendingStep['id'],
            ]);
            $this->markHalted($threadId);
            return;
        }

        // Re-read the plan to check for remaining steps (agent may have
        // modified the plan during execution).
        $steps = $this->planDocumentService->readAll();
        $hasMorePending = FALSE;
        foreach ($steps as $step) {
            if ($step['result'] === NULL) {
                $hasMorePending = TRUE;
                break;
            }
        }

        if ($hasMorePending) {
            // Queue the next step.
            $this->planExecutionService->queueNext($threadId, $uid);
        } else {
            // All steps executed.
            $this->markFinished($threadId);
        }
    }

    /**
     * Builds a prompt enriched with context from previous step results.
     *
     * @param array $step
     *   The current step to execute.
     * @param array $previousResults
     *   Array of previous step results [{name, result}].
     *
     * @return string
     *   The enriched prompt.
     */
    protected function buildEnrichedPrompt(array $step, array $previousResults): string
    {
        $parts = [];

        if (!empty($previousResults)) {
            $parts[] = 'Context from previous steps:';
            foreach ($previousResults as $i => $prev) {
                $parts[] = '- Step ' . ($i + 1) . ' (' . $prev['name'] . '): ' . $prev['result'];
            }
            $parts[] = '';
        }

        $parts[] = 'Task:';
        $parts[] = $step['prompt'] ?? '';

        return implode("\n", $parts);
    }

    /**
     * Runs an agent and returns the response and human review flag status.
     *
     * @param string $agentId
     *   The agent plugin ID.
     * @param string $prompt
     *   The message to send.
     * @param string $runnerId
     *   The runner ID for progress tracking (must match the registered storage
     *   path so the event subscriber writes debug data to the correct file).
     *
     * @return array
     *   Associative array with keys:
     *   - 'response': string — the agent's textual response.
     *   - 'human_review_flagged': bool — whether the agent called the
     *     orchestrator_flag_human_review tool.
     */
    protected function runAgent(string $agentId, string $prompt, string $runnerId): array
    {
        try {
            $agent = $this->agentsManager->createInstance($agentId);

            $default = $this->providerManager->getDefaultProviderForOperationType('chat_with_tools');
            if (!is_array($default) || empty($default['provider_id']) || empty($default['model_id'])) {
                return [
                    'response' => '[ERROR] No default provider configured for chat_with_tools.',
                    'human_review_flagged' => FALSE,
                ];
            }

            $providerOption = $default['provider_id'] . '__' . $default['model_id'];
            $provider = $this->providerManager->loadProviderFromSimpleOption($providerOption);
            if ($provider === NULL) {
                return [
                    'response' => '[ERROR] Could not load the default AI provider.',
                    'human_review_flagged' => FALSE,
                ];
            }

            $modelName = $this->providerManager->getModelNameFromSimpleOption($providerOption);

            // Build the chat history for the agent: include the compressed
            // orchestrator conversation (all user messages and final assistant
            // responses — no intermediate loop messages) followed by the
            // enriched step prompt as the final user message.
            $messages = [];
            $chatHistory = $this->planDocumentService->readChatHistory();
            foreach ($chatHistory as $entry) {
                $role = $entry['role'] ?? 'user';
                $content = $entry['content'] ?? '';
                if (!empty($content)) {
                    $messages[] = new ChatMessage($role, $content);
                }
            }
            // Append the step prompt as the final user message.
            $messages[] = new ChatMessage('user', $prompt);

            $agent->setRunnerId($runnerId);
            $agent->setChatHistory($messages);
            $agent->setAiProvider($provider);
            $agent->setModelName($modelName);
            $agent->setAiConfiguration([]);
            $agent->setCreateDirectly(TRUE);
            $agent->setProgressThreadId($runnerId);

            $canSolve = $agent->determineSolvability();

            $response = match ($canSolve) {
                AiAgentInterface::JOB_SOLVABLE => (string) $agent->solve(),
                AiAgentInterface::JOB_SHOULD_ANSWER_QUESTION => (string) $agent->answerQuestion(),
                AiAgentInterface::JOB_NEEDS_ANSWERS => implode("\n", $agent->askQuestion()),
                AiAgentInterface::JOB_INFORMS => (string) $agent->inform(),
                AiAgentInterface::JOB_NOT_SOLVABLE => '[ERROR] The agent determined the task is not solvable.',
                default => '[ERROR] Unknown agent solvability status.',
            };

            // Check if the agent called the human review flag tool.
            $humanReviewFlagged = !empty(
                $agent->getToolResultsByPluginId(self::HUMAN_REVIEW_TOOL_ID)
            );

            return [
                'response' => $response,
                'human_review_flagged' => $humanReviewFlagged,
            ];
        } catch (\Exception $e) {
            $this->logger->error('Plan executor: error running agent @id: @message', [
                '@id' => $agentId,
                '@message' => $e->getMessage(),
            ]);
            return [
                'response' => '[ERROR] ' . $e->getMessage(),
                'human_review_flagged' => FALSE,
            ];
        }
    }

    /**
     * Marks the plan as finished executing.
     *
     * @param string $threadId
     *   The thread ID.
     */
    protected function markFinished(string $threadId): void
    {
        $this->planDocumentService->setActiveThread($threadId);
        $metadata = $this->planDocumentService->readMetadata();
        $metadata['is_executing'] = FALSE;
        $metadata['execution_ready'] = FALSE;
        $this->planDocumentService->writeMetadata($metadata);

        $this->logger->info('Plan executor: thread @id finished execution.', [
            '@id' => $threadId,
        ]);
    }

    /**
     * Marks the plan as halted (human review or error).
     *
     * @param string $threadId
     *   The thread ID.
     */
    protected function markHalted(string $threadId): void
    {
        $this->planDocumentService->setActiveThread($threadId);
        $metadata = $this->planDocumentService->readMetadata();
        $metadata['is_executing'] = FALSE;
        $this->planDocumentService->writeMetadata($metadata);

        $this->logger->info('Plan executor: thread @id halted.', [
            '@id' => $threadId,
        ]);
    }

}
