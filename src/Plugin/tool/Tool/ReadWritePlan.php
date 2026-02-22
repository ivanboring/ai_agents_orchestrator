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
use Drupal\ai_agents_orchestrator\Service\PlanDocumentService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Plugin implementation of the Plan tool.
 *
 * Manages plan steps stored as JSONL. Supports add, remove, update,
 * read_item, and read_all operations.
 */
#[Tool(
    id: 'orchestrator_plan',
    label: new TranslatableMarkup('Orchestrator Plan'),
    description: new TranslatableMarkup('Manages orchestrator plan steps (JSONL). Operations: add (append or insert), remove (by id), update (partial, by id), read_item (by id), read_all (full plan).'),
    operation: ToolOperation::Write,
    destructive: FALSE,
    input_definitions: [
        'operation' => new InputDefinition(
            data_type: 'string',
            label: new TranslatableMarkup('Operation'),
            description: new TranslatableMarkup('The operation: "add", "remove", "update", "read_item", or "read_all".'),
            required: TRUE,
            constraints: [
                'Choice' => ['choices' => ['add', 'remove', 'update', 'read_item', 'read_all']],
            ],
        ),
        'id' => new InputDefinition(
            data_type: 'string',
            label: new TranslatableMarkup('Step ID'),
            description: new TranslatableMarkup('The step ID. Required for remove, update, and read_item.'),
            required: FALSE,
        ),
        'after' => new InputDefinition(
            data_type: 'string',
            label: new TranslatableMarkup('After'),
            description: new TranslatableMarkup('For add: insert after this step ID, or "first" to insert at the beginning. Omit to append at end.'),
            required: FALSE,
        ),
        'name' => new InputDefinition(
            data_type: 'string',
            label: new TranslatableMarkup('Step Name'),
            description: new TranslatableMarkup('Human-readable name of the step.'),
            required: FALSE,
        ),
        'agent' => new InputDefinition(
            data_type: 'string',
            label: new TranslatableMarkup('Agent'),
            description: new TranslatableMarkup('The agent to run for this step.'),
            required: FALSE,
        ),
        'prompt' => new InputDefinition(
            data_type: 'string',
            label: new TranslatableMarkup('Prompt'),
            description: new TranslatableMarkup('The prompt to send to the agent.'),
            required: FALSE,
        ),
        'reason' => new InputDefinition(
            data_type: 'string',
            label: new TranslatableMarkup('Reason'),
            description: new TranslatableMarkup('Why this step is planned.'),
            required: FALSE,
        ),
        'result' => new InputDefinition(
            data_type: 'string',
            label: new TranslatableMarkup('Result'),
            description: new TranslatableMarkup('The result of running the step.'),
            required: FALSE,
        ),
        'plan_name' => new InputDefinition(
            data_type: 'string',
            label: new TranslatableMarkup('Plan Name'),
            description: new TranslatableMarkup('A short name (3-7 words) describing the user goal. Required on the first add operation and ignored afterwards.'),
            required: FALSE,
        ),
    ],
    output_definitions: [
        'plan_data' => new ContextDefinition(
            data_type: 'string',
            label: new TranslatableMarkup('Plan Data'),
            description: new TranslatableMarkup('JSON-encoded result of the operation.')
        ),
    ],
)]
class ReadWritePlan extends ToolBase
{

    /**
     * The plan document service.
     *
     * @var \Drupal\ai_agents_orchestrator\Service\PlanDocumentService
     */
    protected PlanDocumentService $planDocumentService;

    /**
     * {@inheritdoc}
     */
    public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition)
    {
        $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
        $instance->planDocumentService = $container->get('ai_agents_orchestrator.plan_document');
        return $instance;
    }

    /**
     * {@inheritdoc}
     */
    protected function doExecute(array $values): ExecutableResult
    {
        $operation = $values['operation'];

        try {
            return match ($operation) {
                'add' => $this->handleAdd($values),
                'remove' => $this->handleRemove($values),
                'update' => $this->handleUpdate($values),
                'read_item' => $this->handleReadItem($values),
                'read_all' => $this->handleReadAll(),
            };
        } catch (\InvalidArgumentException $e) {
            return ExecutableResult::failure($this->t('@message', ['@message' => $e->getMessage()]));
        } catch (\Exception $e) {
            return ExecutableResult::failure(
                $this->t('Error during @op: @message', [
                    '@op' => $operation,
                    '@message' => $e->getMessage(),
                ])
            );
        }
    }

    /**
     * Handles the add operation.
     */
    protected function handleAdd(array $values): ExecutableResult
    {
        $required = ['name', 'agent', 'prompt', 'reason'];
        foreach ($required as $field) {
            if (empty($values[$field])) {
                return ExecutableResult::failure(
                    $this->t('Field "@field" is required for add.', ['@field' => $field])
                );
            }
        }

        $step = [
            'name' => $values['name'],
            'agent' => $values['agent'],
            'prompt' => $values['prompt'],
            'reason' => $values['reason'],
        ];

        $after = !empty($values['after']) ? $values['after'] : NULL;
        $created = $this->planDocumentService->addStep($step, $after);

        // Auto-create the metadata file if it doesn't exist.
        $plan_name = !empty($values['plan_name']) ? $values['plan_name'] : '';
        $this->planDocumentService->ensureMetadata($plan_name);

        return ExecutableResult::success(
            $this->t('Step "@name" added.', ['@name' => $created['name']]),
            ['plan_data' => json_encode($created)]
        );
    }

    /**
     * Handles the remove operation.
     */
    protected function handleRemove(array $values): ExecutableResult
    {
        if (empty($values['id'])) {
            return ExecutableResult::failure($this->t('Field "id" is required for remove.'));
        }

        $removed = $this->planDocumentService->removeStep($values['id']);
        if (!$removed) {
            return ExecutableResult::failure(
                $this->t('Step "@id" not found.', ['@id' => $values['id']])
            );
        }

        return ExecutableResult::success(
            $this->t('Step "@id" removed.', ['@id' => $values['id']]),
            ['plan_data' => json_encode(['removed' => $values['id']])]
        );
    }

    /**
     * Handles the update operation.
     */
    protected function handleUpdate(array $values): ExecutableResult
    {
        if (empty($values['id'])) {
            return ExecutableResult::failure($this->t('Field "id" is required for update.'));
        }

        $fields = array_filter(
            array_intersect_key($values, array_flip(['name', 'agent', 'prompt', 'reason', 'result'])),
            fn($v) => $v !== NULL && $v !== '',
        );

        if (empty($fields)) {
            return ExecutableResult::failure($this->t('At least one field must be provided for update.'));
        }

        $updated = $this->planDocumentService->updateStep($values['id'], $fields);
        if ($updated === NULL) {
            return ExecutableResult::failure(
                $this->t('Step "@id" not found.', ['@id' => $values['id']])
            );
        }

        return ExecutableResult::success(
            $this->t('Step "@id" updated.', ['@id' => $values['id']]),
            ['plan_data' => json_encode($updated)]
        );
    }

    /**
     * Handles the read_item operation.
     */
    protected function handleReadItem(array $values): ExecutableResult
    {
        if (empty($values['id'])) {
            return ExecutableResult::failure($this->t('Field "id" is required for read_item.'));
        }

        $step = $this->planDocumentService->readStep($values['id']);
        if ($step === NULL) {
            return ExecutableResult::failure(
                $this->t('Step "@id" not found.', ['@id' => $values['id']])
            );
        }

        return ExecutableResult::success(
            $this->t('Step "@id" found.', ['@id' => $values['id']]),
            ['plan_data' => json_encode($step)]
        );
    }

    /**
     * Handles the read_all operation.
     */
    protected function handleReadAll(): ExecutableResult
    {
        $steps = $this->planDocumentService->readAll();

        return ExecutableResult::success(
            $this->t('Plan has @count steps.', ['@count' => count($steps)]),
            ['plan_data' => json_encode($steps)]
        );
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
