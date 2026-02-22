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
 * Plugin implementation of the Flag Human Review tool.
 *
 * Flags a plan step as needing human input before execution can continue.
 * Stores the flag in plan metadata and updates the step result.
 */
#[Tool(
    id: 'orchestrator_flag_human_review',
    label: new TranslatableMarkup('Flag Human Review'),
    description: new TranslatableMarkup('Flags a plan step as needing human review or input before execution can continue. Use this when the step requires API keys, credentials, ambiguous decisions, destructive confirmations, or any information only a human can provide.'),
    operation: ToolOperation::Write,
    destructive: FALSE,
    input_definitions: [
        'step_id' => new InputDefinition(
            data_type: 'string',
            label: new TranslatableMarkup('Step ID'),
            description: new TranslatableMarkup('The ID of the plan step that needs human review.'),
            required: TRUE,
        ),
        'reason' => new InputDefinition(
            data_type: 'string',
            label: new TranslatableMarkup('Reason'),
            description: new TranslatableMarkup('A clear explanation of what human input or decision is needed and why the agent cannot proceed without it.'),
            required: TRUE,
        ),
    ],
    output_definitions: [
        'result' => new ContextDefinition(
            data_type: 'string',
            label: new TranslatableMarkup('Result'),
            description: new TranslatableMarkup('Confirmation of the flag.')
        ),
    ],
)]
class FlagHumanReview extends ToolBase
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
        $step_id = $values['step_id'] ?? '';
        $reason = $values['reason'] ?? '';

        if (empty($step_id)) {
            return ExecutableResult::failure($this->t('Field "step_id" is required.'));
        }

        if (empty($reason)) {
            return ExecutableResult::failure($this->t('Field "reason" is required.'));
        }

        try {
            // Verify the step exists.
            $step = $this->planDocumentService->readStep($step_id);
            if ($step === NULL) {
                return ExecutableResult::failure(
                    $this->t('Step "@id" not found.', ['@id' => $step_id])
                );
            }

            // Update the step result to indicate human review is needed.
            $this->planDocumentService->updateStep($step_id, [
                'result' => '[HUMAN_REVIEW_NEEDED] ' . $reason,
            ]);

            // Set the flag in plan metadata.
            $metadata = $this->planDocumentService->readMetadata();
            $metadata['human_review_needed'] = TRUE;
            $metadata['human_review_step'] = $step_id;
            $metadata['human_review_reason'] = $reason;
            $this->planDocumentService->writeMetadata($metadata);

            return ExecutableResult::success(
                $this->t('Step "@id" flagged for human review: @reason', [
                    '@id' => $step_id,
                    '@reason' => $reason,
                ]),
                ['result' => json_encode([
                    'step_id' => $step_id,
                    'step_name' => $step['name'] ?? '',
                    'human_review_needed' => TRUE,
                    'reason' => $reason,
                ])]
            );
        }
        catch (\Exception $e) {
            return ExecutableResult::failure(
                $this->t('Error flagging step for human review: @message', [
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
