<?php

declare(strict_types=1);

namespace Drupal\ai_agents_orchestrator\Plugin\tool\Tool;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\tool\Attribute\Tool;
use Drupal\tool\ExecutableResult;
use Drupal\tool\Tool\ToolBase;
use Drupal\tool\Tool\ToolOperation;
use Drupal\tool\TypedData\InputDefinition;
use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai_agents\PluginInterfaces\AiAgentInterface;
use Drupal\ai_agents\PluginManager\AiAgentManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Plugin implementation of the Execute Agent tool.
 *
 * Creates and executes an AI agent with a given message.
 */
#[Tool(
    id: 'orchestrator_execute_agent',
    label: new TranslatableMarkup('Execute AI Agent'),
    description: new TranslatableMarkup('Executes a specific AI agent with a user message. Configures the agent with the default provider and runs it.'),
    operation: ToolOperation::Trigger,
    destructive: FALSE,
    input_definitions: [
        'agent_id' => new InputDefinition(
            data_type: 'string',
            label: new TranslatableMarkup('Agent ID'),
            description: new TranslatableMarkup('The machine name of the agent to execute.'),
            required: TRUE,
        ),
        'message' => new InputDefinition(
            data_type: 'string',
            label: new TranslatableMarkup('Message'),
            description: new TranslatableMarkup('The user message to send to the agent.'),
            required: TRUE,
        ),
        'thread_id' => new InputDefinition(
            data_type: 'string',
            label: new TranslatableMarkup('Thread ID'),
            description: new TranslatableMarkup('An optional thread ID for the agent run. If not provided, a UUID will be generated.'),
            required: FALSE,
        ),
    ],
    output_definitions: [
        'result' => new ContextDefinition(
            data_type: 'string',
            label: new TranslatableMarkup('Result'),
            description: new TranslatableMarkup('The agent execution result or response.')
        ),
    ],
)]
class ExecuteAgent extends ToolBase
{

    /**
     * The AI agents plugin manager.
     *
     * @var \Drupal\ai_agents\PluginManager\AiAgentManager
     */
    protected AiAgentManager $agentsManager;

    /**
     * The AI provider plugin manager.
     *
     * @var \Drupal\ai\AiProviderPluginManager
     */
    protected AiProviderPluginManager $providerManager;

    /**
     * {@inheritdoc}
     */
    public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition)
    {
        $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
        $instance->agentsManager = $container->get('plugin.manager.ai_agents');
        $instance->providerManager = $container->get('ai.provider');
        return $instance;
    }

    /**
     * {@inheritdoc}
     */
    protected function doExecute(array $values): ExecutableResult
    {
        ['agent_id' => $agent_id, 'message' => $message] = $values;
        $thread_id = $values['thread_id'] ?? \Drupal::service('uuid')->generate();

        try {
            // Create agent instance.
            $agent = $this->agentsManager->createInstance($agent_id);

            // Get default provider for chat with tools.
            $default = $this->providerManager->getDefaultProviderForOperationType('chat_with_tools');
            if (!is_array($default) || empty($default['provider_id']) || empty($default['model_id'])) {
                return ExecutableResult::failure(
                    $this->t('No default provider configured for chat_with_tools.')
                );
            }

            $provider_option = $default['provider_id'] . '__' . $default['model_id'];
            $provider = $this->providerManager->loadProviderFromSimpleOption($provider_option);
            if ($provider === NULL) {
                return ExecutableResult::failure(
                    $this->t('Could not load the default provider.')
                );
            }

            $model_name = $this->providerManager->getModelNameFromSimpleOption($provider_option);

            // Build chat message.
            $messages = [new ChatMessage('user', $message)];

            // Configure the agent.
            $agent->setRunnerId($thread_id);
            $agent->setChatHistory($messages);
            $agent->setAiProvider($provider);
            $agent->setModelName($model_name);
            $agent->setAiConfiguration([]);
            $agent->setCreateDirectly(TRUE);
            $agent->setProgressThreadId($thread_id);

            // Determine solvability and run.
            $response = '';
            $can_solve = $agent->determineSolvability();

            switch ($can_solve) {
                case AiAgentInterface::JOB_SOLVABLE:
                    $response = $agent->solve();
                    break;

                case AiAgentInterface::JOB_SHOULD_ANSWER_QUESTION:
                    $response = $agent->answerQuestion();
                    break;

                case AiAgentInterface::JOB_NEEDS_ANSWERS:
                    $response = implode("\n", $agent->askQuestion());
                    break;

                case AiAgentInterface::JOB_INFORMS:
                    $response = $agent->inform();
                    break;

                case AiAgentInterface::JOB_NOT_SOLVABLE:
                    return ExecutableResult::failure(
                        $this->t('The agent determined the task is not solvable.')
                    );
            }

            return ExecutableResult::success(
                $this->t('Agent @id executed successfully.', ['@id' => $agent_id]),
                ['result' => (string) $response]
            );
        } catch (\Exception $e) {
            return ExecutableResult::failure(
                $this->t('Error executing agent @id: @message', [
                    '@id' => $agent_id,
                    '@message' => $e->getMessage(),
                ])
            );
        }
    }

    /**
     * {@inheritdoc}
     */
    protected function checkAccess(array $values, AccountInterface $account, bool $return_as_object = FALSE): bool|AccessResultInterface
    {
        $access_result = AccessResult::allowedIfHasPermission($account, 'orchestrate ai agents');
        return $return_as_object ? $access_result : $access_result->isAllowed();
    }

}
