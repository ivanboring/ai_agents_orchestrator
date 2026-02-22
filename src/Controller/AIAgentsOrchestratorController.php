<?php

declare(strict_types=1);

namespace Drupal\ai_agents_orchestrator\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai_agents\Enum\AiAgentStatusItemTypes;
use Drupal\ai_agents\PluginInterfaces\AiAgentInterface;
use Drupal\ai_agents\PluginManager\AiAgentManager;
use Drupal\ai_agents\Service\AgentStatus\Interfaces\AiAgentStatusPollerServiceInterface;
use Drupal\ai_agents\Service\AgentStatus\Interfaces\AiAgentStatusStorageInterface;
use Drupal\ai_agents_orchestrator\Service\PlanDocumentService;
use Drupal\ai_agents_orchestrator\Service\PlanExecutionService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller for AI Agents Orchestrator.
 */
class AIAgentsOrchestratorController extends ControllerBase
{

    /**
     * The agent to use for chat.
     */
    protected const AGENT_ID = 'planning_agent';

    /**
     * Constructor.
     *
     * @param \Symfony\Component\HttpFoundation\Request $currentRequest
     *   The current request.
     * @param \Drupal\ai_agents\PluginManager\AiAgentManager $agentsManager
     *   The AI agents plugin manager.
     * @param \Drupal\ai\AiProviderPluginManager $providerManager
     *   The AI provider plugin manager.
     * @param \Drupal\ai_agents_orchestrator\Service\PlanDocumentService $planDocumentService
     *   The plan document service.
     * @param \Drupal\ai_agents_orchestrator\Service\PlanExecutionService $planExecutionService
     *   The plan execution service.
     * @param \Drupal\ai_agents\Service\AgentStatus\Interfaces\AiAgentStatusPollerServiceInterface $statusPoller
     *   The agent status poller service.
     * @param \Drupal\ai_agents\Service\AgentStatus\Interfaces\AiAgentStatusStorageInterface $statusStorage
     *   The status storage service.
     */
    final public function __construct(
        protected Request $currentRequest,
        protected AiAgentManager $agentsManager,
        protected AiProviderPluginManager $providerManager,
        protected PlanDocumentService $planDocumentService,
        protected PlanExecutionService $planExecutionService,
        protected AiAgentStatusPollerServiceInterface $statusPoller,
        protected AiAgentStatusStorageInterface $statusStorage,
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public static function create(ContainerInterface $container): static
    {
        return new static(
            $container->get('request_stack')->getCurrentRequest(),
            $container->get('plugin.manager.ai_agents'),
            $container->get('ai.provider'),
            $container->get('ai_agents_orchestrator.plan_document'),
            $container->get('ai_agents_orchestrator.plan_execution'),
            $container->get('ai_agents.agent_status_poller'),
            $container->get('ai_agents.private_temp_status_storage'),
        );
    }

    /**
     * Sets the active thread from the request query parameter.
     *
     * Call this at the top of every endpoint that needs thread context.
     */
    protected function activateThread(): void
    {
        $threadId = $this->currentRequest->query->get('thread_id', '');
        if ($threadId !== '') {
            $this->planDocumentService->setActiveThread($threadId);
        }
    }

    // -----------------------------------------------------------------------
    // Chat.
    // -----------------------------------------------------------------------

    /**
     * Chat endpoint — runs the planning agent and returns the final response.
     *
     * Accepts messages as JSON POST. Enables progress tracking so the frontend
     * can poll for real-time status updates via the poll endpoint.
     *
     * @return \Symfony\Component\HttpFoundation\JsonResponse
     *   JSON with thread_id and message.
     */
    public function chat(): JsonResponse
    {
        $this->activateThread();

        // Parse the request body.
        $body = json_decode($this->currentRequest->getContent(), TRUE) ?? [];
        $incoming_messages = $body['messages'] ?? [];

        // Build ChatMessage array from the incoming history.
        $chat_messages = [];
        foreach ($incoming_messages as $msg) {
            $role = $msg['role'] ?? 'user';
            $content = '';
            if (is_string($msg['content'] ?? NULL)) {
                $content = $msg['content'];
            } elseif (is_array($msg['parts'] ?? NULL)) {
                foreach ($msg['parts'] as $part) {
                    if (($part['type'] ?? '') === 'text') {
                        $content .= $part['text'] ?? '';
                    }
                }
            }
            if ($content !== '') {
                $chat_messages[] = new ChatMessage($role, $content);
            }
        }

        if (empty($chat_messages)) {
            return new JsonResponse(['error' => 'No messages provided.'], 400);
        }

        // Use client-provided thread ID so the frontend can poll immediately.
        $poll_thread_id = $body['thread_id'] ?? 'orch_' . bin2hex(random_bytes(8));

        // Clean up any previous progress data.
        try {
            $this->statusStorage->startStatusUpdate($poll_thread_id);
        } catch (\Exception $e) {
            // Ignore cleanup errors.
        }


        $response_text = '';
        try {
            // Load the default provider.
            $default = $this->providerManager->getDefaultProviderForOperationType('chat_with_tools');
            if (!is_array($default) || empty($default['provider_id']) || empty($default['model_id'])) {
                throw new \RuntimeException('No default provider configured for chat_with_tools.');
            }

            $provider_option = $default['provider_id'] . '__' . $default['model_id'];
            $provider = $this->providerManager->loadProviderFromSimpleOption($provider_option);
            $model_name = $this->providerManager->getModelNameFromSimpleOption($provider_option);

            if ($provider === NULL) {
                throw new \RuntimeException('Could not load the default AI provider.');
            }

            // Determine which agent to use based on thread type.
            $metadata = $this->planDocumentService->readMetadata();
            $isDirect = (($metadata['type'] ?? 'planning') === 'direct' && !empty($metadata['agent_id']));

            if ($isDirect) {
                // ── Direct agent mode: use the standard agent runner. ──
                $agentId = $metadata['agent_id'];
                $agent = $this->agentsManager->createInstance($agentId);
                $agent->setChatHistory($chat_messages);
                $agent->setProgressThreadId($poll_thread_id);
                $agent->setAiProvider($provider);
                $agent->setModelName($model_name);
                $agent->setAiConfiguration([]);
                $agent->determineSolvability();
                $response_text = (string) $agent->solve();
            } else {
                // ── Planning agent mode: full agent loop. ──
                /** @var \Drupal\ai_agents\PluginInterfaces\AiAgentInterface $agent */
                $agent = $this->agentsManager->createInstance(self::AGENT_ID);
                $agent->setRunnerId($poll_thread_id);
                $agent->setChatHistory($chat_messages);
                $agent->setAiProvider($provider);
                $agent->setModelName($model_name);
                $agent->setAiConfiguration([]);
                $agent->setCreateDirectly(TRUE);

                // Enable progress tracking.
                $agent->setProgressThreadId($poll_thread_id);

                // Run the agent.
                $can_solve = $agent->determineSolvability();

                switch ($can_solve) {
                    case AiAgentInterface::JOB_SOLVABLE:
                        $response_text = (string) $agent->solve();
                        break;

                    case AiAgentInterface::JOB_SHOULD_ANSWER_QUESTION:
                        $response_text = (string) $agent->answerQuestion();
                        break;

                    case AiAgentInterface::JOB_NEEDS_ANSWERS:
                        $response_text = implode("\n", $agent->askQuestion());
                        break;

                    case AiAgentInterface::JOB_INFORMS:
                        $response_text = (string) $agent->inform();
                        break;

                    case AiAgentInterface::JOB_NOT_SOLVABLE:
                        $response_text = 'The agent determined this task is not solvable with the current tools and context.';
                        break;
                }
            }
        } catch (\Exception $e) {
            return new JsonResponse([
                'thread_id' => $poll_thread_id,
                'error' => $e->getMessage(),
            ], 500);
        }

        // Persist the exchange to the chat history file.
        // The frontend loops the same message multiple times — deduplicate so
        // only one user entry + the final assistant response are stored.
        $last_user = end($chat_messages);
        $history = $this->planDocumentService->readChatHistory();
        $user_text = $last_user ? $last_user->getText() : '';

        // Check if the last entries are already this same user message (loop
        // iteration 2+). If so, just replace the trailing assistant response.
        $last_history = end($history);
        $second_last = count($history) >= 2 ? $history[count($history) - 2] : null;

        if (
            $second_last
            && ($second_last['role'] ?? '') === 'user'
            && ($second_last['content'] ?? '') === $user_text
            && ($last_history['role'] ?? '') === 'assistant'
        ) {
            // Replace the previous assistant response with the latest one.
            $history[count($history) - 1] = [
                'role' => 'assistant',
                'content' => $response_text ?: 'No response from agent.',
            ];
        } else {
            // First iteration — add both user + assistant.
            if ($last_user) {
                $history[] = ['role' => 'user', 'content' => $user_text];
            }
            $history[] = ['role' => 'assistant', 'content' => $response_text ?: 'No response from agent.'];
        }
        $this->planDocumentService->writeChatHistory($history);

        $metadata = $this->planDocumentService->readMetadata();

        return new JsonResponse([
            'thread_id' => $poll_thread_id,
            'message' => $response_text ?: 'No response from agent.',
            'execution_ready' => $metadata['execution_ready'] ?? FALSE,
        ]);
    }

    // -----------------------------------------------------------------------
    // Progress polling.
    // -----------------------------------------------------------------------

    /**
     * Polls agent progress for a given thread.
     *
     * @param string $thread_id
     *   The thread identifier.
     *
     * @return \Symfony\Component\HttpFoundation\JsonResponse
     *   JSON with items array and finished flag.
     */
    public function poll(string $thread_id): JsonResponse
    {
        try {
            $status_update = $this->statusPoller->getLatestStatusUpdates($thread_id);
            $items = [];
            $finished = FALSE;

            foreach ($status_update->getItems() as $event) {
                $type = $event->getType();
                $data = $event->toArray();

                switch ($type) {
                    case AiAgentStatusItemTypes::ToolStarted:
                        $items[] = [
                            'type' => 'tool_started',
                            'tool_name' => $data['tool_name'] ?? '',
                            'tool_input' => $data['tool_input'] ?? '',
                            'tool_feedback_message' => $data['tool_feedback_message'] ?? '',
                            'tool_id' => $data['tool_id'] ?? '',
                            'time' => $data['time'] ?? 0,
                        ];
                        break;

                    case AiAgentStatusItemTypes::ToolFinished:
                        $items[] = [
                            'type' => 'tool_finished',
                            'tool_name' => $data['tool_name'] ?? '',
                            'tool_id' => $data['tool_id'] ?? '',
                            'tool_feedback_message' => $data['tool_feedback_message'] ?? '',
                            'time' => $data['time'] ?? 0,
                        ];
                        break;

                    case AiAgentStatusItemTypes::Finished:
                        $finished = TRUE;
                        $items[] = [
                            'type' => 'finished',
                            'time' => $data['time'] ?? 0,
                        ];
                        break;
                }
            }

            return new JsonResponse([
                'thread_id' => $thread_id,
                'items' => $items,
                'finished' => $finished,
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'thread_id' => $thread_id,
                'items' => [],
                'finished' => FALSE,
            ]);
        }
    }

    // -----------------------------------------------------------------------
    // Plan steps & metadata.
    // -----------------------------------------------------------------------

    /**
     * Returns the current plan steps as JSON.
     */
    public function planStatus(): JsonResponse
    {
        $this->activateThread();
        $steps = $this->planDocumentService->readAll();
        return new JsonResponse($steps);
    }

    /**
     * Saves the plan steps (overwrites the entire plan).
     */
    public function savePlan(): JsonResponse
    {
        $this->activateThread();
        $body = json_decode($this->currentRequest->getContent(), TRUE) ?? [];
        $steps = $body['steps'] ?? [];

        $this->planDocumentService->writeAll($steps);

        return new JsonResponse(['status' => 'ok']);
    }

    /**
     * Returns the plan metadata as JSON.
     */
    public function planMetadata(): JsonResponse
    {
        $this->activateThread();
        $metadata = $this->planDocumentService->readMetadata();
        return new JsonResponse($metadata);
    }

    // -----------------------------------------------------------------------
    // Chat history.
    // -----------------------------------------------------------------------

    /**
     * Loads the persisted chat history.
     */
    public function loadChatHistory(): JsonResponse
    {
        $this->activateThread();
        $history = $this->planDocumentService->readChatHistory();
        return new JsonResponse($history);
    }

    /**
     * Saves the chat history (full overwrite).
     */
    public function saveChatHistory(): JsonResponse
    {
        $this->activateThread();
        $body = json_decode($this->currentRequest->getContent(), TRUE) ?? [];
        $messages = $body['messages'] ?? [];

        $this->planDocumentService->writeChatHistory($messages);

        return new JsonResponse(['status' => 'ok']);
    }

    // -----------------------------------------------------------------------
    // Thread management.
    // -----------------------------------------------------------------------

    /**
     * Lists all threads for the current user.
     */
    public function listThreads(): JsonResponse
    {
        $threads = $this->planDocumentService->listThreads();
        return new JsonResponse($threads);
    }

    /**
     * Creates a new thread.
     */
    public function createThread(): JsonResponse
    {
        $body = json_decode($this->currentRequest->getContent(), TRUE) ?? [];
        $type = ($body['type'] ?? 'planning') === 'direct' ? 'direct' : 'planning';
        $agentId = (string) ($body['agent_id'] ?? '');

        // Resolve agent label for direct threads to use as thread name.
        $agentLabel = '';
        if ($type === 'direct' && $agentId !== '') {
            try {
                $definitions = $this->agentsManager->getDefinitions();
                if (isset($definitions[$agentId])) {
                    $agentLabel = (string) ($definitions[$agentId]['label'] ?? $agentId);
                }
            } catch (\Exception $e) {
                // Fall back to agent ID.
                $agentLabel = $agentId;
            }
        }

        $threadId = $this->planDocumentService->createThread($type, $agentId);

        // Set the thread name to the agent label for direct threads.
        if ($agentLabel !== '') {
            $metadata = $this->planDocumentService->readMetadata();
            $metadata['name'] = $agentLabel;
            $this->planDocumentService->writeMetadata($metadata);
        }

        $metadata = $this->planDocumentService->readMetadata();

        return new JsonResponse([
            'id' => $threadId,
            'metadata' => $metadata,
        ]);
    }

    /**
     * Updates the thread metadata (e.g. name).
     */
    public function updateThreadMetadata(): JsonResponse
    {
        $this->activateThread();
        $body = json_decode($this->currentRequest->getContent(), TRUE) ?? [];

        $metadata = $this->planDocumentService->readMetadata();
        if (isset($body['name'])) {
            $metadata['name'] = (string) $body['name'];
        }
        $this->planDocumentService->writeMetadata($metadata);

        return new JsonResponse(['status' => 'ok', 'metadata' => $metadata]);
    }

    /**
     * Deletes a thread and all its data.
     */
    public function deleteThread(): JsonResponse
    {
        $threadId = $this->currentRequest->query->get('thread_id', '');
        if ($threadId === '') {
            return new JsonResponse(['error' => 'No thread_id provided.'], 400);
        }

        $deleted = $this->planDocumentService->deleteThread($threadId);

        return new JsonResponse([
            'status' => $deleted ? 'ok' : 'not_found',
            'deleted' => $deleted,
        ]);
    }

    // -----------------------------------------------------------------------
    // Plan execution.
    // -----------------------------------------------------------------------

    /**
     * Starts plan execution via the queue.
     *
     * @return \Symfony\Component\HttpFoundation\JsonResponse
     *   JSON with status.
     */
    public function executePlan(): JsonResponse
    {
        $this->activateThread();
        $metadata = $this->planDocumentService->readMetadata();
        $uid = (int) ($metadata['uid'] ?? $this->currentUser()->id());
        $threadId = $metadata['thread_id'] ?? $this->currentRequest->query->get('thread_id', '');

        try {
            $this->planExecutionService->startExecution($threadId, $uid);
        } catch (\RuntimeException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        }

        return new JsonResponse([
            'status' => 'ok',
            'message' => 'Execution started.',
        ]);
    }

    // -----------------------------------------------------------------------
    // Execution steps progress.
    // -----------------------------------------------------------------------

    /**
     * Returns summarised execution step progress.
     *
     * Reads the .yml step files written during plan execution and returns
     * compact progress data for the frontend timeline.
     *
     * @return \Symfony\Component\HttpFoundation\JsonResponse
     *   JSON with steps array.
     */
    public function executionSteps(): JsonResponse
    {
        $this->activateThread();
        $steps = $this->planDocumentService->listStepFiles();
        $meta = $this->planDocumentService->readMetadata();

        return new JsonResponse([
            'steps' => $steps,
            'is_executing' => $meta['is_executing'] ?? FALSE,
        ]);
    }

    // -----------------------------------------------------------------------
    // Agent listing.
    // -----------------------------------------------------------------------

    /**
     * Returns a JSON list of available agents.
     *
     * Excludes internal agents (planning_agent, orchestrator).
     */
    public function listAgents(): JsonResponse
    {
        $definitions = $this->agentsManager->getDefinitions();
        $agents = [];

        foreach ($definitions as $id => $definition) {
            if (!isset($definition['custom_type']) || $definition['custom_type'] !== 'config') {
                continue;
            }
            if (in_array($definition['id'], ['planning_agent', 'orchestrator'])) {
                continue;
            }
            $agents[] = [
                'id' => $id,
                'label' => (string) ($definition['label'] ?? $id),
                'description' => (string) ($definition['description'] ?? ''),
            ];
        }

        usort($agents, fn($a, $b) => strcmp($a['label'], $b['label']));

        return new JsonResponse($agents);
    }

}
