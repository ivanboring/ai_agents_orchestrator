<?php

declare(strict_types=1);

namespace Drupal\ai_agents_orchestrator\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Serialization\Json;
use Drupal\ai\Service\FunctionCalling\FunctionCallPluginManager;
use Drupal\ai_agents\Enum\AiAgentStatusItemTypes;
use Drupal\ai_agents\Event\AgentFinishedExecutionEvent;
use Drupal\ai_agents\Event\AgentRequestEvent;
use Drupal\ai_agents\Event\AgentResponseEvent;
use Drupal\ai_agents\Event\AgentStartedExecutionEvent;
use Drupal\ai_agents\Event\AgentStatusBaseInterface;
use Drupal\ai_agents\Event\AgentToolFinishedExecutionEvent;
use Drupal\ai_agents\Event\AgentToolPreExecuteEvent;
use Drupal\ai_agents\Service\AgentStatus\UpdateItems\AiAgentChatHistory;
use Drupal\ai_agents\Service\AgentStatus\UpdateItems\AiAgentFinishedExecution;
use Drupal\ai_agents\Service\AgentStatus\UpdateItems\AiAgentIterationExecution;
use Drupal\ai_agents\Service\AgentStatus\UpdateItems\AiAgentStartedExecution;
use Drupal\ai_agents\Service\AgentStatus\UpdateItems\AiProviderRequest;
use Drupal\ai_agents\Service\AgentStatus\UpdateItems\AiProviderResponse;
use Drupal\ai_agents\Service\AgentStatus\UpdateItems\SystemMessage;
use Drupal\ai_agents\Service\AgentStatus\UpdateItems\TextGenerated;
use Drupal\ai_agents\Service\AgentStatus\UpdateItems\ToolFinishedExecution;
use Drupal\ai_agents\Service\AgentStatus\UpdateItems\ToolSelected;
use Drupal\ai_agents\Service\AgentStatus\UpdateItems\ToolStartedExecution;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Event subscriber that captures agent status events to file-based storage.
 *
 * Delegates all persistence to ThreadFileStatusStorage. The queue worker
 * registers a file path on the storage before running an agent; this
 * subscriber then writes every event directly to that file as it happens.
 */
class StepDebugCollector implements EventSubscriberInterface
{

    /**
     * Constructor.
     *
     * @param \Drupal\ai_agents_orchestrator\Service\ThreadFileStatusStorage $storage
     *   The file-based status storage.
     * @param \Drupal\Component\Datetime\TimeInterface $time
     *   The time service.
     * @param \Drupal\ai\Service\FunctionCalling\FunctionCallPluginManager $functionCallPluginManager
     *   The function call plugin manager.
     */
    public function __construct(
        protected readonly ThreadFileStatusStorage $storage,
        protected readonly TimeInterface $time,
        protected readonly FunctionCallPluginManager $functionCallPluginManager,
    ) {
    }

    // -----------------------------------------------------------------------
    // Event subscriber.
    // -----------------------------------------------------------------------

    /**
     * {@inheritdoc}
     */
    public static function getSubscribedEvents(): array
    {
        return [
            AgentStartedExecutionEvent::EVENT_NAME => ['onAgentStartedExecution', 0],
            AgentFinishedExecutionEvent::EVENT_NAME => ['onAgentFinishedExecution', 0],
            AgentResponseEvent::EVENT_NAME => ['onAgentRespondedExecution', 0],
            AgentToolFinishedExecutionEvent::EVENT_NAME => ['onAgentToolFinishedExecution', 0],
            AgentToolPreExecuteEvent::EVENT_NAME => ['onAgentPreToolExecuteEvent', 0],
            AgentRequestEvent::EVENT_NAME => ['onAgentRequestExecution', 0],
        ];
    }

    /**
     * Agent started / iteration event.
     */
    public function onAgentStartedExecution(AgentStartedExecutionEvent $event): void
    {
        if (!$this->shouldLog($event)) {
            return;
        }

        $threadId = $event->getThreadId();

        if ($event->getLoopCount() === 0 && $this->shouldLogType($event, AiAgentStatusItemTypes::Started)) {
            $this->storage->storeStatusUpdateItem($threadId, new AiAgentStartedExecution(
                time: $this->time->getCurrentMicroTime(),
                agent_id: $event->getAgentId(),
                agent_name: $event->getAgent()->getAiAgentEntity()->label(),
                agent_runner_id: $event->getAgentRunnerId(),
                calling_agent_id: $event->getCallerId(),
            ));
        }

        if ($this->shouldLogType($event, AiAgentStatusItemTypes::Iteration)) {
            $this->storage->storeStatusUpdateItem($threadId, new AiAgentIterationExecution(
                time: $this->time->getCurrentMicroTime(),
                agent_id: $event->getAgentId(),
                agent_name: $event->getAgent()->getAiAgentEntity()->label(),
                agent_runner_id: $event->getAgentRunnerId(),
                loop_count: $event->getLoopCount(),
                calling_agent_id: $event->getCallerId(),
            ));
        }
    }

    /**
     * Agent finished event.
     */
    public function onAgentFinishedExecution(AgentFinishedExecutionEvent $event): void
    {
        if (!$this->shouldLog($event) || !$this->shouldLogType($event, AiAgentStatusItemTypes::Finished)) {
            return;
        }

        $this->storage->storeStatusUpdateItem($event->getThreadId(), new AiAgentFinishedExecution(
            time: $this->time->getCurrentMicroTime(),
            agent_id: $event->getAgentId(),
            agent_name: $event->getAgent()->getAiAgentEntity()->label(),
            agent_runner_id: $event->getAgentRunnerId(),
            calling_agent_id: $event->getCallerId(),
        ));
    }

    /**
     * Agent request event (chat history, system message, provider request).
     */
    public function onAgentRequestExecution(AgentRequestEvent $event): void
    {
        if (!$this->shouldLog($event)) {
            return;
        }

        $threadId = $event->getThreadId();
        $combined_ms = $this->time->getCurrentMicroTime();

        if ($this->shouldLogType($event, AiAgentStatusItemTypes::ChatHistory)) {
            $chat_history = [];
            foreach ($event->getChatHistory() as $message) {
                $chat_history[] = $message->toArray();
            }
            $this->storage->storeStatusUpdateItem($threadId, new AiAgentChatHistory(
                time: $combined_ms,
                agent_id: $event->getAgentId(),
                agent_name: $event->getAgent()->getAiAgentEntity()->label(),
                agent_runner_id: $event->getAgentRunnerId(),
                loop_count: $event->getLoopCount(),
                chat_history: $chat_history,
                calling_agent_id: $event->getCallerId(),
            ));
        }

        if ($this->shouldLogType($event, AiAgentStatusItemTypes::SystemMessage)) {
            $this->storage->storeStatusUpdateItem($threadId, new SystemMessage(
                time: $combined_ms,
                agent_id: $event->getAgentId(),
                agent_name: $event->getAgent()->getAiAgentEntity()->label(),
                agent_runner_id: $event->getAgentRunnerId(),
                loop_count: $event->getLoopCount(),
                calling_agent_id: $event->getCallerId(),
                system_prompt: $event->getSystemPrompt(),
            ));
        }

        if ($this->shouldLogType($event, AiAgentStatusItemTypes::Request)) {
            $this->storage->storeStatusUpdateItem($threadId, new AiProviderRequest(
                time: $combined_ms,
                agent_id: $event->getAgentId(),
                agent_name: $event->getAgent()->getAiAgentEntity()->label(),
                agent_runner_id: $event->getAgentRunnerId(),
                loop_count: $event->getLoopCount(),
                request_data: $event->getChatInput()->toArray(),
                provider_name: $event->getAgent()->getAiProvider()->getPluginId(),
                model_name: $event->getAgent()->getModelName(),
                config: $event->getAgent()->getAiConfiguration(),
                calling_agent_id: $event->getCallerId(),
            ));
        }
    }

    /**
     * Agent response event (provider response, text generated, tool selected).
     */
    public function onAgentRespondedExecution(AgentResponseEvent $event): void
    {
        if (!$this->shouldLog($event)) {
            return;
        }

        $threadId = $event->getThreadId();
        $response = $event->getResponse();
        $combined_ms = $this->time->getCurrentMicroTime();

        if ($this->shouldLogType($event, AiAgentStatusItemTypes::Response)) {
            $this->storage->storeStatusUpdateItem($threadId, new AiProviderResponse(
                time: $combined_ms,
                agent_id: $event->getAgentId(),
                agent_name: $event->getAgent()->getAiAgentEntity()->label(),
                agent_runner_id: $event->getAgentRunnerId(),
                loop_count: $event->getLoopCount(),
                response_data: $response->toArray(),
                calling_agent_id: $event->getCallerId(),
            ));
        }

        if ($response->getNormalized()->getText() !== NULL && $this->shouldLogType($event, AiAgentStatusItemTypes::TextGenerated)) {
            $this->storage->storeStatusUpdateItem($threadId, new TextGenerated(
                time: $combined_ms,
                agent_id: $event->getAgentId(),
                agent_name: $event->getAgent()->getAiAgentEntity()->label(),
                agent_runner_id: $event->getAgentRunnerId(),
                loop_count: $event->getLoopCount(),
                text_response: $response->getNormalized()->getText(),
                calling_agent_id: $event->getCallerId(),
            ));
        }

        if (!empty($response->getNormalized()->getTools()) && $this->shouldLogType($event, AiAgentStatusItemTypes::ToolSelected)) {
            foreach ($response->getNormalized()->getTools() as $tool) {
                if ($tool === NULL) {
                    continue;
                }
                $tool_as_array = $tool->getOutputRenderArray();
                $definition = $this->functionCallPluginManager->getFunctionCallFromFunctionName($tool_as_array['function']['name']);
                $plugin = $definition->getPluginDefinition();
                $settings = $event->getAgent()->getAiAgentEntity()->get('tool_settings')[$plugin['id']]['progress_message'] ?? '';
                $this->storage->storeStatusUpdateItem($threadId, new ToolSelected(
                    time: $combined_ms,
                    agent_id: $event->getAgentId(),
                    agent_name: $event->getAgent()->getAiAgentEntity()->label(),
                    agent_runner_id: $event->getAgentRunnerId(),
                    tool_name: $tool_as_array['function']['name'] ?? '',
                    tool_input: $tool_as_array['function']['arguments'] ?? '',
                    calling_agent_id: $event->getCallerId(),
                    tool_id: $tool->getToolId() ?? '',
                    tool_feedback_message: $settings,
                ));
            }
        }
    }

    /**
     * Tool finished event.
     */
    public function onAgentToolFinishedExecution(AgentToolFinishedExecutionEvent $event): void
    {
        if (!$this->shouldLog($event) || !$this->shouldLogType($event, AiAgentStatusItemTypes::ToolFinished)) {
            return;
        }

        $tool = $event->getTool();
        $tool_input = [];
        $history = $event->getAgent()->getChatHistory();
        $message = end($history);
        if ($message->getTools()) {
            foreach ($message->getTools() as $tool_object) {
                if ($tool_object->getToolId() == $tool->getToolsId()) {
                    $arguments = $tool_object->getArguments() ?? [];
                    foreach ($arguments as $argument) {
                        $tool_input[$argument->getName()] = Json::encode($argument->getValue());
                    }
                }
            }
        }

        $this->storage->storeStatusUpdateItem($event->getThreadId(), new ToolFinishedExecution(
            time: $this->time->getCurrentMicroTime(),
            agent_id: $event->getAgentId(),
            agent_name: $event->getAgent()->getAiAgentEntity()->label(),
            agent_runner_id: $event->getAgentRunnerId(),
            tool_name: $tool->getFunctionName(),
            tool_input: Json::encode($tool_input),
            tool_results: $tool->getReadableOutput() ?? '',
            calling_agent_id: $event->getCallerId(),
            tool_id: $tool->getToolsId() ?? '',
            tool_feedback_message: $event->getProgressMessage(),
        ));
    }

    /**
     * Tool pre-execute event.
     */
    public function onAgentPreToolExecuteEvent(AgentToolPreExecuteEvent $event): void
    {
        if (!$this->shouldLog($event) || !$this->shouldLogType($event, AiAgentStatusItemTypes::ToolStarted)) {
            return;
        }

        $tool = $event->getTool();
        $tool_input = [];
        $history = $event->getAgent()->getChatHistory();
        $message = end($history);
        if ($message->getTools()) {
            foreach ($message->getTools() as $tool_object) {
                if ($tool_object->getToolId() == $tool->getToolsId()) {
                    $arguments = $tool_object->getArguments() ?? [];
                    foreach ($arguments as $argument) {
                        $tool_input[$argument->getName()] = Json::encode($argument->getValue());
                    }
                }
            }
        }

        $this->storage->storeStatusUpdateItem($event->getThreadId(), new ToolStartedExecution(
            time: $this->time->getCurrentMicroTime(),
            agent_id: $event->getAgentId(),
            agent_name: $event->getAgent()->getAiAgentEntity()->label(),
            agent_runner_id: $event->getAgentRunnerId(),
            tool_name: $tool->getFunctionName(),
            tool_input: Json::encode($tool_input),
            calling_agent_id: $event->getCallerId(),
            tool_id: $tool->getToolsId() ?? '',
            tool_feedback_message: $event->getProgressMessage(),
        ));
    }

    // -----------------------------------------------------------------------
    // Helpers.
    // -----------------------------------------------------------------------

    /**
     * Whether this event should be logged.
     *
     * Only logs when the event has a thread ID AND that thread ID is
     * registered in the file storage (i.e. we are in a queue execution).
     */
    protected function shouldLog(AgentStatusBaseInterface $event): bool
    {
        $threadId = $event->getThreadId();
        return $threadId !== NULL && $this->storage->hasPath($threadId);
    }

    /**
     * Whether a specific event type should be logged.
     */
    protected function shouldLogType(AgentStatusBaseInterface $event, AiAgentStatusItemTypes $type): bool
    {
        $detailed_tracking = $event->getAgent()->getDetailedProgressTracking();
        if (empty($detailed_tracking)) {
            return TRUE;
        }
        return in_array($type, $detailed_tracking, TRUE);
    }

}
