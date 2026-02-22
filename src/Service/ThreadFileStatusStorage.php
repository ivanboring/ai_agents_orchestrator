<?php

declare(strict_types=1);

namespace Drupal\ai_agents_orchestrator\Service;

use Drupal\Core\File\FileSystemInterface;
use Drupal\ai_agents\Service\AgentStatus\AiAgentStatusUpdate;
use Drupal\ai_agents\Service\AgentStatus\Interfaces\AiAgentStatusStorageInterface;
use Drupal\ai_agents\Service\AgentStatus\Interfaces\AiAgentStatusUpdateInterface;
use Drupal\ai_agents\Service\AgentStatus\Interfaces\UpdateItems\StatusBaseInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * File-based status storage for plan execution threads.
 *
 * Stores agent status updates as YAML files inside the thread directory
 * (one file per runner/step). This storage does not require a session and
 * works in queue/CLI contexts.
 *
 * Usage:
 *   1. Call registerPath($runnerId, $filePath) to map a runner ID to a file.
 *   2. Call startStatusUpdate($runnerId) to initialise the file.
 *   3. The event subscriber stores items via storeStatusUpdateItem().
 *   4. After execution, loadStatusUpdate($runnerId) returns the data.
 *   5. Call unregisterPath($runnerId) to clean up the mapping.
 */
class ThreadFileStatusStorage implements AiAgentStatusStorageInterface
{

    /**
     * Map of runner IDs to file paths.
     *
     * @var array<string, string>
     */
    protected array $pathMap = [];

    /**
     * Constructor.
     *
     * @param \Drupal\Core\File\FileSystemInterface $fileSystem
     *   The file system service.
     */
    public function __construct(
        protected readonly FileSystemInterface $fileSystem,
    ) {
    }

    /**
     * Registers a file path for a given runner ID.
     *
     * @param string $id
     *   The runner ID (progress thread ID set on the agent).
     * @param string $filePath
     *   The full URI where the status YAML should be written
     *   (e.g. public://ai_planning/1/20260220_abc/steps/1_step_def.yml).
     */
    public function registerPath(string $id, string $filePath): void
    {
        $this->pathMap[$id] = $filePath;
    }

    /**
     * Unregisters a runner ID.
     *
     * @param string $id
     *   The runner ID.
     */
    public function unregisterPath(string $id): void
    {
        unset($this->pathMap[$id]);
    }

    /**
     * Checks whether a runner ID has a registered path.
     *
     * @param string $id
     *   The runner ID.
     *
     * @return bool
     *   TRUE if registered.
     */
    public function hasPath(string $id): bool
    {
        return isset($this->pathMap[$id]);
    }

    /**
     * {@inheritdoc}
     */
    public function startStatusUpdate(string $id): bool
    {
        $path = $this->pathMap[$id] ?? NULL;
        if ($path === NULL) {
            return FALSE;
        }

        // Ensure the parent directory exists.
        $dir = dirname($path);
        $this->fileSystem->prepareDirectory($dir, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);

        // Write an empty status update as YAML.
        $status = new AiAgentStatusUpdate();
        $this->fileSystem->saveData(
            Yaml::dump($status->toArray(), 10, 2),
            $path,
            FileSystemInterface::EXISTS_REPLACE,
        );

        return TRUE;
    }

    /**
     * {@inheritdoc}
     */
    public function storeStatusUpdateItem(string $id, StatusBaseInterface $thread): void
    {
        $path = $this->pathMap[$id] ?? NULL;
        if ($path === NULL) {
            return;
        }

        $realPath = $this->fileSystem->realpath($path);
        if (!$realPath || !file_exists($realPath)) {
            // Not started yet — silently skip.
            return;
        }

        $content = file_get_contents($realPath);
        if ($content === FALSE) {
            return;
        }

        $data = Yaml::parse($content) ?: [];
        $status = AiAgentStatusUpdate::fromArray($data);
        $status->addItem($thread);
        $this->fileSystem->saveData(
            Yaml::dump($status->toArray(), 10, 2),
            $path,
            FileSystemInterface::EXISTS_REPLACE,
        );
    }

    /**
     * {@inheritdoc}
     */
    public function loadStatusUpdate(string $id): ?AiAgentStatusUpdateInterface
    {
        $path = $this->pathMap[$id] ?? NULL;
        if ($path === NULL) {
            return NULL;
        }

        $realPath = $this->fileSystem->realpath($path);
        if (!$realPath || !file_exists($realPath)) {
            return NULL;
        }

        $content = file_get_contents($realPath);
        if ($content === FALSE) {
            return NULL;
        }

        $data = Yaml::parse($content) ?: [];
        return AiAgentStatusUpdate::fromArray($data);
    }

    /**
     * {@inheritdoc}
     */
    public function deleteStatusUpdate(string $id): void
    {
        $path = $this->pathMap[$id] ?? NULL;
        if ($path === NULL) {
            return;
        }

        $realPath = $this->fileSystem->realpath($path);
        if ($realPath && file_exists($realPath)) {
            unlink($realPath);
        }

        unset($this->pathMap[$id]);
    }

}
