<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_agents_orchestrator\Kernel\Tool;

use Drupal\KernelTests\KernelTestBase;
use Drupal\tool\Tool\ToolPluginManager;

/**
 * Tests the Execute Agent tool.
 *
 * @group ai_agents_orchestrator
 */
class ExecuteAgentTest extends KernelTestBase
{

    /**
     * {@inheritdoc}
     */
    protected static $modules = [
        'tool',
        'ai_agents_orchestrator',
        'ai_agents',
        'ai',
        'user',
        'system',
    ];

    /**
     * The tool plugin manager.
     *
     * @var \Drupal\tool\Tool\ToolPluginManager
     */
    protected $toolPluginManager;

    /**
     * {@inheritdoc}
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->installEntitySchema('user');
        $this->installConfig(['tool']);
        $this->toolPluginManager = $this->container->get('plugin.manager.tool');
    }

    /**
     * Tests the tool can be instantiated.
     */
    public function testToolExists(): void
    {
        $tool = $this->toolPluginManager->createInstance('orchestrator_execute_agent');
        $this->assertNotNull($tool);
    }

    /**
     * Tests execution with a non-existent agent fails gracefully.
     */
    public function testExecuteNonExistentAgentFails(): void
    {
        $tool = $this->toolPluginManager->createInstance('orchestrator_execute_agent');
        $result = $tool->execute([
            'agent_id' => 'non_existent_agent_12345',
            'message' => 'Hello',
        ]);
        $this->assertFalse($result->isSuccess());
    }

}
