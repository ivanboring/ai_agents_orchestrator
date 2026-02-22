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
use Drupal\ai_agents\PluginManager\AiAgentManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Plugin implementation of the Search Agents tool.
 *
 * Searches AI agent definitions by keyword against id, label, and description.
 */
#[Tool(
    id: 'orchestrator_search_agents',
    label: new TranslatableMarkup('Search AI Agents'),
    description: new TranslatableMarkup('Searches available AI agents by keyword, matching against agent ID, label, and description.'),
    operation: ToolOperation::Read,
    input_definitions: [
        'keyword' => new InputDefinition(
            data_type: 'string',
            label: new TranslatableMarkup('Keyword'),
            description: new TranslatableMarkup('The search keyword to match against agent id, label, and description.'),
            required: TRUE,
        ),
    ],
    output_definitions: [
        'matching_agents' => new ContextDefinition(
            data_type: 'string',
            label: new TranslatableMarkup('Matching Agents'),
            description: new TranslatableMarkup('A JSON-encoded list of agents matching the keyword.')
        ),
    ],
)]
class SearchAgents extends ToolBase
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
        ['keyword' => $keyword] = $values;

        try {
            $definitions = $this->agentsManager->getDefinitions();
            $matching = [];
            $keyword_lower = mb_strtolower($keyword);

            foreach ($definitions as $id => $definition) {
                $label = (string) ($definition['label'] ?? '');
                $description = (string) ($definition['description'] ?? '');

                // Match keyword against id, label, or description.
                if (
                    str_contains(mb_strtolower($id), $keyword_lower) ||
                    str_contains(mb_strtolower($label), $keyword_lower) ||
                    str_contains(mb_strtolower($description), $keyword_lower)
                ) {
                    $matching[] = [
                        'id' => $id,
                        'label' => $label,
                        'description' => $description,
                    ];
                }
            }

            usort($matching, fn($a, $b) => strcmp($a['label'], $b['label']));

            $json = json_encode($matching, JSON_PRETTY_PRINT);

            return ExecutableResult::success(
                $this->t('Found @count agents matching "@keyword".', [
                    '@count' => count($matching),
                    '@keyword' => $keyword,
                ]),
                ['matching_agents' => $json]
            );
        } catch (\Exception $e) {
            return ExecutableResult::failure(
                $this->t('Error searching agents: @message', ['@message' => $e->getMessage()])
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
