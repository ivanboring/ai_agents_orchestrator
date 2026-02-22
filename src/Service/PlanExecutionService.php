<?php

declare(strict_types=1);

namespace Drupal\ai_agents_orchestrator\Service;

use Drupal\Core\Queue\QueueFactory;

/**
 * Service for starting and managing queue-based plan execution.
 */
class PlanExecutionService
{

    /**
     * The queue name for the plan executor.
     */
    public const QUEUE_NAME = 'ai_agents_orchestrator_plan_executor';

    /**
     * Constructor.
     *
     * @param \Drupal\ai_agents_orchestrator\Service\PlanDocumentService $planDocumentService
     *   The plan document service.
     * @param \Drupal\Core\Queue\QueueFactory $queueFactory
     *   The queue factory.
     */
    public function __construct(
        protected readonly PlanDocumentService $planDocumentService,
        protected readonly QueueFactory $queueFactory,
    ) {
    }

    /**
     * Starts execution of a plan thread via the queue.
     *
     * Reads the thread creator uid from metadata and queues the first step
     * to run as that user.
     *
     * @param string $threadId
     *   The thread ID to execute.
     *
     * @throws \RuntimeException
     *   If the plan is not ready for execution, is already executing, or
     *   has no owner uid in metadata.
     */
    public function startExecution(string $threadId): void
    {
        $this->planDocumentService->setActiveThread($threadId);
        $metadata = $this->planDocumentService->readMetadata();

        if (!empty($metadata['is_executing'])) {
            throw new \RuntimeException('Plan is already executing.');
        }

        if (empty($metadata['execution_ready'])) {
            throw new \RuntimeException('Plan is not marked as ready for execution.');
        }

        $uid = (int) ($metadata['uid'] ?? 0);
        if (empty($uid)) {
            throw new \RuntimeException('Thread metadata has no owner uid.');
        }

        // Mark the plan as executing.
        $metadata['is_executing'] = TRUE;
        $this->planDocumentService->writeMetadata($metadata);

        // Queue the first step, running as the thread creator.
        $this->queueNext($threadId, $uid);
    }

    /**
     * Queues the next execution step.
     *
     * When called from the queue worker, the uid is already known. When
     * called externally, use startExecution() which reads uid from metadata.
     *
     * @param string $threadId
     *   The thread ID.
     * @param int $uid
     *   The user ID that owns the thread.
     */
    public function queueNext(string $threadId, int $uid): void
    {
        $queue = $this->queueFactory->get(self::QUEUE_NAME);
        $queue->createItem([
            'thread_id' => $threadId,
            'uid' => $uid,
        ]);
    }

}
