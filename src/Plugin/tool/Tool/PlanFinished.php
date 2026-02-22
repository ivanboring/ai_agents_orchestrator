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
 * Plugin implementation of the Plan Finished tool.
 *
 * Sets the execution_ready flag on the plan metadata.
 */
#[Tool(
    id: 'orchestrator_plan_finished',
    label: new TranslatableMarkup('Plan Finished'),
    description: new TranslatableMarkup('Marks the plan as ready or not ready for execution. Set execution_ready to true when the plan is complete and approved, or false if the plan still needs work.'),
    operation: ToolOperation::Write,
    destructive: FALSE,
    input_definitions: [
        'execution_ready' => new InputDefinition(
            data_type: 'string',
            label: new TranslatableMarkup('Execution Ready'),
            description: new TranslatableMarkup('Set to "true" when the plan is complete and ready to execute, or "false" if it still needs changes.'),
            required: TRUE,
        ),
    ],
    output_definitions: [
        'result' => new ContextDefinition(
            data_type: 'string',
            label: new TranslatableMarkup('Result'),
            description: new TranslatableMarkup('Confirmation of the update.')
        ),
    ],
)]
class PlanFinished extends ToolBase
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
        $ready_value = strtolower(trim($values['execution_ready'] ?? ''));
        $execution_ready = in_array($ready_value, ['true', '1', 'yes'], TRUE);

        try {
            $metadata = $this->planDocumentService->readMetadata();
            $metadata['execution_ready'] = $execution_ready;
            $this->planDocumentService->writeMetadata($metadata);

            $status = $execution_ready ? 'ready for execution' : 'not ready for execution';
            return ExecutableResult::success(
                $this->t('Plan marked as @status.', ['@status' => $status]),
                ['result' => json_encode($metadata)]
            );
        } catch (\Exception $e) {
            return ExecutableResult::failure(
                $this->t('Error updating plan status: @message', ['@message' => $e->getMessage()])
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
