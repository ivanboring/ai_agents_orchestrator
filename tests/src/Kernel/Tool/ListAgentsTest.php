<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_agents_orchestrator\Kernel\Tool;

use Drupal\KernelTests\KernelTestBase;
use Drupal\tool\Tool\ToolPluginManager;

/**
 * Tests the List Agents tool.
 *
 * @group ai_agents_orchestrator
 */
class ListAgentsTest extends KernelTestBase
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
        $tool = $this->toolPluginManager->createInstance('orchestrator_list_agents');
        $this->assertNotNull($tool);
    }

    /**
     * Tests listing agents returns valid JSON.
     */
    public function testListAgentsReturnsJson(): void
    {
        $tool = $this->toolPluginManager->createInstance('orchestrator_list_agents');
        $result = $tool->execute([]);
        $this->assertTrue($result->isSuccess(), (string) $result->getMessage());

        $agents_json = $result->getContextValue('agents_list');
        $agents = json_decode($agents_json, TRUE);
        $this->assertIsArray($agents);
    }

}
