<?php

declare(strict_types=1);

namespace Drupal\ai_agents_orchestrator\Service;

use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Session\AccountProxyInterface;

/**
 * Service for managing JSONL-based plan steps, scoped to per-user threads.
 *
 * Storage layout: public://ai_planning/{uid}/{YYYYMMDD}_{thread_id}/
 *   - plan.jsonl       (plan steps)
 *   - plan.json        (metadata: name, execution_ready, is_executing, uid)
 *   - chat_history.json
 */
class PlanDocumentService
{

    /**
     * Base URI for all planning data.
     */
    protected const BASE_URI = 'public://ai_planning';

    /**
     * The filename for the plan document.
     */
    protected const FILENAME = 'plan.jsonl';

    /**
     * The metadata filename.
     */
    protected const METADATA_FILENAME = 'plan.json';

    /**
     * The active thread ID (set per-request).
     */
    protected ?string $activeThreadId = NULL;

    /**
     * Constructor.
     *
     * @param \Drupal\Core\File\FileSystemInterface $fileSystem
     *   The file system service.
     * @param \Drupal\Core\Session\AccountProxyInterface $currentUser
     *   The current user.
     */
    public function __construct(
        protected readonly FileSystemInterface $fileSystem,
        protected readonly AccountProxyInterface $currentUser,
    ) {
    }

    // -----------------------------------------------------------------------
    // Thread management.
    // -----------------------------------------------------------------------

    /**
     * Sets the active thread for the current request.
     *
     * @param string $threadId
     *   The thread identifier (e.g. "20260220_a1b2c3d4").
     */
    public function setActiveThread(string $threadId): void
    {
        $this->activeThreadId = $threadId;
    }

    /**
     * Gets the active thread ID.
     *
     * @return string|null
     *   The active thread ID, or NULL if not set.
     */
    public function getActiveThread(): ?string
    {
        return $this->activeThreadId;
    }

    /**
     * Creates a new thread directory with default metadata.
     *
     * @param string $type
     *   The thread type: 'planning' or 'direct'.
     * @param string $agentId
     *   The agent ID for direct threads (empty for planning).
     *
     * @return string
     *   The new thread ID (format: "YYYYMMDD_hexhex").
     */
    public function createThread(string $type = 'planning', string $agentId = ''): string
    {
        $date = date('Ymd');
        $hex = bin2hex(random_bytes(4));
        $threadId = $date . '_' . $hex;

        $dir = $this->getThreadDir($threadId);
        $this->fileSystem->prepareDirectory($dir, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);

        // Write default metadata.
        $this->activeThreadId = $threadId;
        $metadata = [
            'name' => '',
            'thread_id' => $threadId,
            'type' => $type,
            'execution_ready' => FALSE,
            'is_executing' => FALSE,
            'uid' => (int) $this->currentUser->id(),
        ];
        if ($type === 'direct' && $agentId !== '') {
            $metadata['agent_id'] = $agentId;
        }
        $this->writeMetadata($metadata);

        return $threadId;
    }

    /**
     * Deletes a thread directory and all its contents.
     *
     * @param string $threadId
     *   The thread ID to delete.
     *
     * @return bool
     *   TRUE if deleted, FALSE if the directory did not exist.
     */
    public function deleteThread(string $threadId): bool
    {
        $dir = $this->getThreadDir($threadId);
        $realDir = $this->fileSystem->realpath($dir);

        if (!$realDir || !is_dir($realDir)) {
            return FALSE;
        }

        // Recursively delete all files in the thread directory.
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($realDir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($files as $file) {
            if ($file->isDir()) {
                rmdir($file->getRealPath());
            } else {
                unlink($file->getRealPath());
            }
        }

        rmdir($realDir);

        // Reset active thread if it was the deleted one.
        if ($this->activeThreadId === $threadId) {
            $this->activeThreadId = NULL;
        }

        return TRUE;
    }

    /**
     * Lists all threads for the current user, newest first.
     *
     * @return array
     *   Array of thread info: [{id, name, date, directory}].
     */
    public function listThreads(): array
    {
        $uid = (int) $this->currentUser->id();
        $userDir = self::BASE_URI . '/' . $uid;

        $realUserDir = $this->fileSystem->realpath($userDir);
        if (!$realUserDir || !is_dir($realUserDir)) {
            return [];
        }

        $threads = [];
        $entries = scandir($realUserDir);
        if ($entries === FALSE) {
            return [];
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $fullPath = $realUserDir . '/' . $entry;
            if (!is_dir($fullPath)) {
                continue;
            }

            // Expect format: YYYYMMDD_hexhex
            if (!preg_match('/^(\d{8})_([a-f0-9]+)$/', $entry)) {
                continue;
            }

            // Read metadata if available.
            $metaPath = $fullPath . '/' . self::METADATA_FILENAME;
            $name = '';
            $isExecuting = FALSE;
            $executionReady = FALSE;
            $lastUpdated = filemtime($fullPath);
            if (file_exists($metaPath)) {
                $content = file_get_contents($metaPath);
                $lastUpdated = filemtime($metaPath) ?: $lastUpdated;
                if ($content !== FALSE) {
                    $meta = json_decode($content, TRUE);
                    if (is_array($meta)) {
                        $name = $meta['name'] ?? '';
                        $isExecuting = $meta['is_executing'] ?? FALSE;
                        $executionReady = $meta['execution_ready'] ?? FALSE;
                    }
                }
            }

            $threads[] = [
                'id' => $entry,
                'name' => $name,
                'date' => substr($entry, 0, 8),
                'type' => $meta['type'] ?? 'planning',
                'agent_id' => $meta['agent_id'] ?? '',
                'is_executing' => $isExecuting,
                'execution_ready' => $executionReady,
                'last_updated' => $lastUpdated ?: 0,
            ];
        }

        // Sort by last updated, newest first.
        usort($threads, function ($a, $b) {
            return $b['last_updated'] <=> $a['last_updated'];
        });

        return $threads;
    }

    // -----------------------------------------------------------------------
    // Directory resolution.
    // -----------------------------------------------------------------------

    /**
     * Returns the thread directory URI for a given thread ID.
     *
     * @param string|null $threadId
     *   The thread ID. Falls back to the active thread.
     *
     * @return string
     *   The directory URI (e.g. public://ai_planning/1/20260220_a1b2c3d4).
     *
     * @throws \RuntimeException
     *   If no thread ID is set or provided.
     */
    protected function getThreadDir(?string $threadId = NULL): string
    {
        $id = $threadId ?? $this->activeThreadId;
        if ($id === NULL) {
            throw new \RuntimeException('No active thread set. Call setActiveThread() first.');
        }
        $uid = (int) $this->currentUser->id();
        return self::BASE_URI . '/' . $uid . '/' . $id;
    }

    /**
     * Ensures the thread directory exists.
     */
    protected function ensureDir(): void
    {
        $dir = $this->getThreadDir();
        $this->fileSystem->prepareDirectory($dir, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
    }

    // -----------------------------------------------------------------------
    // Plan steps (JSONL).
    // -----------------------------------------------------------------------

    /**
     * Reads all steps from the plan file.
     *
     * @return array
     *   An array of step arrays.
     */
    public function readAll(): array
    {
        $path = $this->getFilePath();
        $real_path = $this->fileSystem->realpath($path);

        if (!$real_path || !file_exists($real_path)) {
            return [];
        }

        $content = file_get_contents($real_path);
        if ($content === FALSE || trim($content) === '') {
            return [];
        }

        $steps = [];
        foreach (explode("\n", trim($content)) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $decoded = json_decode($line, TRUE);
            if (is_array($decoded)) {
                $steps[] = $decoded;
            }
        }

        return $steps;
    }

    /**
     * Writes all steps to the plan file.
     *
     * @param array $steps
     *   The array of step arrays to write.
     */
    public function writeAll(array $steps): void
    {
        $this->ensureDir();

        $lines = [];
        foreach ($steps as $step) {
            $lines[] = json_encode($step, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        $content = implode("\n", $lines);
        if ($content !== '') {
            $content .= "\n";
        }

        $path = $this->getFilePath();
        $this->fileSystem->saveData($content, $path, FileSystemInterface::EXISTS_REPLACE);
    }

    /**
     * Adds a step to the plan.
     *
     * @param array $step
     *   The step data (name, agent, prompt, reason). ID is auto-generated.
     * @param string|null $after
     *   Where to insert: NULL = end, "first" = beginning, or a step ID.
     *
     * @return array
     *   The step with the generated ID attached.
     *
     * @throws \InvalidArgumentException
     *   If the 'after' ID is not found.
     */
    public function addStep(array $step, ?string $after = NULL): array
    {
        $steps = $this->readAll();

        $step['id'] = $this->generateId();
        $step += ['result' => NULL];

        if ($after === NULL) {
            $steps[] = $step;
        } elseif ($after === 'first') {
            array_unshift($steps, $step);
        } else {
            $index = $this->findIndex($steps, $after);
            if ($index === NULL) {
                throw new \InvalidArgumentException("Step with ID '$after' not found.");
            }
            array_splice($steps, $index + 1, 0, [$step]);
        }

        $this->writeAll($steps);
        return $step;
    }

    /**
     * Removes a step by ID.
     *
     * @param string $id
     *   The step ID to remove.
     *
     * @return bool
     *   TRUE if found and removed, FALSE if not found.
     */
    public function removeStep(string $id): bool
    {
        $steps = $this->readAll();
        $index = $this->findIndex($steps, $id);

        if ($index === NULL) {
            return FALSE;
        }

        array_splice($steps, $index, 1);
        $this->writeAll($steps);
        return TRUE;
    }

    /**
     * Updates a step by ID with partial data.
     *
     * @param string $id
     *   The step ID to update.
     * @param array $fields
     *   Fields to update (any of: name, agent, prompt, reason, result).
     *
     * @return array|null
     *   The updated step, or NULL if not found.
     */
    public function updateStep(string $id, array $fields): ?array
    {
        $steps = $this->readAll();
        $index = $this->findIndex($steps, $id);

        if ($index === NULL) {
            return NULL;
        }

        $allowed = ['name', 'agent', 'prompt', 'reason', 'result'];
        foreach ($fields as $key => $value) {
            if (in_array($key, $allowed, TRUE)) {
                $steps[$index][$key] = $value;
            }
        }

        $this->writeAll($steps);
        return $steps[$index];
    }

    /**
     * Reads a single step by ID.
     *
     * @param string $id
     *   The step ID.
     *
     * @return array|null
     *   The step, or NULL if not found.
     */
    public function readStep(string $id): ?array
    {
        $steps = $this->readAll();
        $index = $this->findIndex($steps, $id);
        return $index !== NULL ? $steps[$index] : NULL;
    }

    /**
     * Generates a unique step ID.
     *
     * @return string
     *   A string like "step_a1b2c3d4".
     */
    public function generateId(): string
    {
        return 'step_' . bin2hex(random_bytes(4));
    }

    /**
     * Returns the full URI for the plan file.
     *
     * @return string
     *   The file URI.
     */
    public function getFilePath(): string
    {
        return $this->getThreadDir() . '/' . self::FILENAME;
    }

    /**
     * Finds the index of a step by ID.
     *
     * @param array $steps
     *   The steps array.
     * @param string $id
     *   The step ID to find.
     *
     * @return int|null
     *   The index, or NULL if not found.
     */
    protected function findIndex(array $steps, string $id): ?int
    {
        foreach ($steps as $i => $step) {
            if (($step['id'] ?? NULL) === $id) {
                return $i;
            }
        }
        return NULL;
    }

    // -----------------------------------------------------------------------
    // Plan metadata (plan.json).
    // -----------------------------------------------------------------------

    /**
     * Reads the plan metadata.
     *
     * @return array
     *   The metadata.
     */
    public function readMetadata(): array
    {
        $defaults = ['name' => '', 'execution_ready' => FALSE, 'is_executing' => FALSE];

        $path = $this->getThreadDir() . '/' . self::METADATA_FILENAME;
        $real_path = $this->fileSystem->realpath($path);

        if (!$real_path || !file_exists($real_path)) {
            return $defaults;
        }

        $content = file_get_contents($real_path);
        if ($content === FALSE) {
            return $defaults;
        }

        $data = json_decode($content, TRUE);
        return is_array($data) ? $data + $defaults : $defaults;
    }

    /**
     * Writes the plan metadata.
     *
     * @param array $metadata
     *   The metadata to write.
     */
    public function writeMetadata(array $metadata): void
    {
        $this->ensureDir();

        // Always ensure thread_id and uid are present.
        if (!isset($metadata['thread_id']) && $this->activeThreadId) {
            $metadata['thread_id'] = $this->activeThreadId;
        }
        if (!isset($metadata['uid'])) {
            $metadata['uid'] = (int) $this->currentUser->id();
        }

        $path = $this->getThreadDir() . '/' . self::METADATA_FILENAME;
        $content = json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->fileSystem->saveData($content, $path, FileSystemInterface::EXISTS_REPLACE);
    }

    /**
     * Ensures the metadata file exists, creating it with defaults if not.
     *
     * @param string $name
     *   The plan name.
     */
    public function ensureMetadata(string $name = ''): void
    {
        $path = $this->getThreadDir() . '/' . self::METADATA_FILENAME;
        $real_path = $this->fileSystem->realpath($path);

        if ($real_path && file_exists($real_path)) {
            return;
        }

        $this->writeMetadata([
            'name' => $name,
            'thread_id' => $this->activeThreadId ?? '',
            'execution_ready' => FALSE,
            'is_executing' => FALSE,
            'uid' => (int) $this->currentUser->id(),
        ]);
    }

    // -----------------------------------------------------------------------
    // Chat history.
    // -----------------------------------------------------------------------

    /**
     * Reads the chat history.
     *
     * @return array
     *   Array of chat message objects with 'role' and 'content'.
     */
    public function readChatHistory(): array
    {
        $path = $this->getThreadDir() . '/chat_history.json';
        $real_path = $this->fileSystem->realpath($path);

        if (!$real_path || !file_exists($real_path)) {
            return [];
        }

        $content = file_get_contents($real_path);
        if ($content === FALSE) {
            return [];
        }

        $data = json_decode($content, TRUE);
        return is_array($data) ? $data : [];
    }

    /**
     * Writes the chat history.
     *
     * @param array $messages
     *   Array of chat message objects with 'role' and 'content'.
     */
    public function writeChatHistory(array $messages): void
    {
        $this->ensureDir();

        $path = $this->getThreadDir() . '/chat_history.json';
        $content = json_encode($messages, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->fileSystem->saveData($content, $path, FileSystemInterface::EXISTS_REPLACE);
    }

    // -----------------------------------------------------------------------
    // Step debug data.
    // -----------------------------------------------------------------------

    /**
     * Writes debug data for a specific plan step.
     *
     * Stores agent status items (debugger format) in a per-step JSON file
     * inside the thread's steps/ subdirectory.
     *
     * @param string $stepId
     *   The plan step ID (e.g. "step_a1b2c3d4").
     * @param array $data
     *   The debug data array in debugger format: ['items' => [...]].
     */
    public function writeStepDebug(string $stepId, array $data): void
    {
        $dir = $this->getThreadDir() . '/steps';
        $this->fileSystem->prepareDirectory($dir, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);

        $path = $dir . '/' . $stepId . '.json';
        $content = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->fileSystem->saveData($content, $path, FileSystemInterface::EXISTS_REPLACE);
    }

    /**
     * Reads debug data for a specific plan step.
     *
     * @param string $stepId
     *   The plan step ID.
     *
     * @return array
     *   The debug data array, or empty items if not found.
     */
    public function readStepDebug(string $stepId): array
    {
        $path = $this->getThreadDir() . '/steps/' . $stepId . '.json';
        $real_path = $this->fileSystem->realpath($path);

        if (!$real_path || !file_exists($real_path)) {
            return ['items' => []];
        }

        $content = file_get_contents($real_path);
        if ($content === FALSE) {
            return ['items' => []];
        }

        $data = json_decode($content, TRUE);
        return is_array($data) ? $data : ['items' => []];
    }

    /**
     * Lists execution step files with summarised progress data.
     *
     * Scans the thread's steps/ directory for .yml files, parses each one, and
     * returns a compact summary suitable for the frontend execution timeline.
     *
     * @return array
     *   Array of step summaries, ordered by step number.
     */
    public function listStepFiles(): array
    {
        $dir = $this->getThreadDir() . '/steps';
        $realDir = $this->fileSystem->realpath($dir);
        if (!$realDir || !is_dir($realDir)) {
            return [];
        }

        $files = glob($realDir . '/*.yml');
        if (empty($files)) {
            return [];
        }

        // Event types we want to send to the frontend.
        $keepTypes = [
            'agent_started',
            'agent_iteration',
            'tool_started',
            'tool_finished',
            'tool_selected',
            'text_generated',
        ];

        $steps = [];
        foreach ($files as $file) {
            $basename = basename($file, '.yml');
            // Filename format: {N}_{stepId}  e.g. 1_step_6c2e99bf
            $parts = explode('_', $basename, 2);
            $stepNumber = (int) $parts[0];
            $stepId = $parts[1] ?? $basename;

            $content = file_get_contents($file);
            if ($content === FALSE) {
                continue;
            }

            $data = \Symfony\Component\Yaml\Yaml::parse($content) ?: [];
            $items = $data['items'] ?? [];

            // Extract the agent name from the first event.
            $agentName = '';
            foreach ($items as $item) {
                if (!empty($item['agent_name'])) {
                    $agentName = $item['agent_name'];
                    break;
                }
            }

            // Determine status: if the last event is text_generated or
            // agent_finished, the step is complete.
            $lastItem = end($items) ?: [];
            $lastType = $lastItem['type'] ?? '';
            $isComplete = in_array($lastType, ['text_generated', 'agent_finished'], TRUE);

            // Filter to only the event types the frontend cares about.
            $filteredEvents = [];
            foreach ($items as $item) {
                $type = $item['type'] ?? '';
                if (!in_array($type, $keepTypes, TRUE)) {
                    continue;
                }

                $event = [
                    'type' => $type,
                    'time' => $item['time'] ?? 0,
                ];

                if (!empty($item['agent_name'])) {
                    $event['agent_name'] = $item['agent_name'];
                }
                if (!empty($item['tool_name'])) {
                    $event['tool_name'] = $item['tool_name'];
                }
                if (isset($item['tool_results'])) {
                    // Truncate verbose results.
                    $result = (string) $item['tool_results'];
                    $event['tool_results'] = mb_strlen($result) > 300
                        ? mb_substr($result, 0, 300) . '…'
                        : $result;
                }
                if (isset($item['text_response'])) {
                    $text = (string) $item['text_response'];
                    $event['text_response'] = mb_strlen($text) > 300
                        ? mb_substr($text, 0, 300) . '…'
                        : $text;
                }

                $filteredEvents[] = $event;
            }

            $steps[] = [
                'step_number' => $stepNumber,
                'step_id' => $stepId,
                'agent_name' => $agentName,
                'status' => $isComplete ? 'complete' : 'running',
                'events' => $filteredEvents,
            ];
        }

        // Sort by step number.
        usort($steps, fn($a, $b) => $a['step_number'] <=> $b['step_number']);

        return $steps;
    }

}
