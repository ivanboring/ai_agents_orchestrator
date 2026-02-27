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
use Drupal\ai_agents\PluginManager\AiAgentManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Plugin implementation of the List Agents tool.
 *
 * Lists all available AI agent definitions.
 */
#[Tool(
    id: 'orchestrator_list_agents',
    label: new TranslatableMarkup('List AI Agents'),
    description: new TranslatableMarkup('Lists all available AI agent definitions with their IDs and labels.'),
    operation: ToolOperation::Read,
    input_definitions: [],
    output_definitions: [
        'agents_list' => new ContextDefinition(
            data_type: 'string',
            label: new TranslatableMarkup('Agents List'),
            description: new TranslatableMarkup('A JSON-encoded list of agent definitions with id and label.')
        ),
    ],
)]
class ListAgents extends ToolBase
{

    /**
     * The AI agents plugin manager.
     *
     * @var \Drupal\ai_agents\PluginManager\AiAgentManager
     */
    protected AiAgentManager $agentsManager;

    /**
     * {@inheritdoc}
     */
    public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition)
    {
        $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
        $instance->agentsManager = $container->get('plugin.manager.ai_agents');
        return $instance;
    }

    /**
     * {@inheritdoc}
     */
    protected function doExecute(array $values): ExecutableResult
    {
        try {
            $definitions = $this->agentsManager->getDefinitions();
            $agents = [];

            foreach ($definitions as $id => $definition) {
                if (!isset($definition['custom_type']) || $definition['custom_type'] !== 'config') {
                    continue;
                }
                // No planning agent :)
                if (in_array($definition['id'], ['planning_agent', 'orchestrator', 'execution_agent'])) {
                    continue;
                }
                $agents[] = [
                    'id' => $id,
                    'label' => (string) ($definition['label'] ?? $id),
                    'description' => (string) ($definition['description'] ?? ''),
                ];
            }

            usort($agents, fn($a, $b) => strcmp($a['label'], $b['label']));

            $json = json_encode($agents, JSON_PRETTY_PRINT);

            return ExecutableResult::success(
                $this->t('Found @count agents.', ['@count' => count($agents)]),
                ['agents_list' => $json]
            );
        } catch (\Exception $e) {
            return ExecutableResult::failure(
                $this->t('Error listing agents: @message', ['@message' => $e->getMessage()])
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
