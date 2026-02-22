<?php

declare(strict_types=1);

namespace Drupal\ai_agents_orchestrator\Service;

use Drupal\Core\Session\SessionManagerInterface;
use Drupal\ai_agents\Service\AgentStatus\Interfaces\AiAgentStatusStorageInterface;
use Drupal\ai_agents\Service\AgentStatus\Interfaces\AiAgentStatusUpdateInterface;
use Drupal\ai_agents\Service\AgentStatus\Interfaces\UpdateItems\StatusBaseInterface;

/**
 * Decorator that makes PrivateTempStatusStorage safe for queue/CLI contexts.
 *
 * The contrib PrivateTempStatusStorage throws a RuntimeException when no
 * session exists (e.g. during queue worker execution). This decorator wraps
 * the original storage and silently skips all operations when there is no
 * active session, preventing errors in sessionless contexts.
 */
class SessionSafeStatusStorageDecorator implements AiAgentStatusStorageInterface
{

    /**
     * Constructor.
     *
     * @param \Drupal\ai_agents\Service\AgentStatus\Interfaces\AiAgentStatusStorageInterface $inner
     *   The original status storage being decorated.
     * @param \Drupal\Core\Session\SessionManagerInterface $sessionManager
     *   The session manager.
     */
    public function __construct(
        protected readonly AiAgentStatusStorageInterface $inner,
        protected readonly SessionManagerInterface $sessionManager,
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function startStatusUpdate(string $id): bool
    {
        if (!$this->sessionManager->isStarted()) {
            return FALSE;
        }
        return $this->inner->startStatusUpdate($id);
    }

    /**
     * {@inheritdoc}
     */
    public function storeStatusUpdateItem(string $id, StatusBaseInterface $thread): void
    {
        if (!$this->sessionManager->isStarted()) {
            return;
        }
        $this->inner->storeStatusUpdateItem($id, $thread);
    }

    /**
     * {@inheritdoc}
     */
    public function loadStatusUpdate(string $id): ?AiAgentStatusUpdateInterface
    {
        if (!$this->sessionManager->isStarted()) {
            return NULL;
        }
        return $this->inner->loadStatusUpdate($id);
    }

    /**
     * {@inheritdoc}
     */
    public function deleteStatusUpdate(string $id): void
    {
        if (!$this->sessionManager->isStarted()) {
            return;
        }
        $this->inner->deleteStatusUpdate($id);
    }

}
