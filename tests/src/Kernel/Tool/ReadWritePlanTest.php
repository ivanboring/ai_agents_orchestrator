<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_agents_orchestrator\Kernel\Tool;

use Drupal\KernelTests\KernelTestBase;
use Drupal\tool\Tool\ToolPluginManager;

/**
 * Tests the Orchestrator Plan tool.
 *
 * @group ai_agents_orchestrator
 */
class ReadWritePlanTest extends KernelTestBase
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
        'file',
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
        $tool = $this->toolPluginManager->createInstance('orchestrator_plan');
        $this->assertNotNull($tool);
    }

    /**
     * Tests add and read_all.
     */
    public function testAddAndReadAll(): void
    {
        $tool = $this->toolPluginManager->createInstance('orchestrator_plan');

        // Add a step.
        $result = $tool->execute([
            'operation' => 'add',
            'name' => 'Analyze content',
            'agent' => 'content_analyzer',
            'prompt' => 'Analyze the homepage',
            'reason' => 'Need content audit',
        ]);
        $this->assertTrue($result->isSuccess(), (string) $result->getMessage());

        $step = json_decode($result->getContextValue('plan_data'), TRUE);
        $this->assertStringStartsWith('step_', $step['id']);
        $this->assertEquals('Analyze content', $step['name']);
        $this->assertNull($step['result']);

        // Read all.
        $read_tool = $this->toolPluginManager->createInstance('orchestrator_plan');
        $read_result = $read_tool->execute(['operation' => 'read_all']);
        $this->assertTrue($read_result->isSuccess());
        $steps = json_decode($read_result->getContextValue('plan_data'), TRUE);
        $this->assertCount(1, $steps);
        $this->assertEquals('content_analyzer', $steps[0]['agent']);
    }

    /**
     * Tests add with "first" ordering.
     */
    public function testAddFirst(): void
    {
        $tool = $this->toolPluginManager->createInstance('orchestrator_plan');

        // Add step A.
        $tool->execute([
            'operation' => 'add',
            'name' => 'Step A',
            'agent' => 'agent_a',
            'prompt' => 'prompt_a',
            'reason' => 'reason_a',
        ]);

        // Add step B as first.
        $tool2 = $this->toolPluginManager->createInstance('orchestrator_plan');
        $tool2->execute([
            'operation' => 'add',
            'name' => 'Step B',
            'agent' => 'agent_b',
            'prompt' => 'prompt_b',
            'reason' => 'reason_b',
            'after' => 'first',
        ]);

        // Read all — B should be first.
        $read_tool = $this->toolPluginManager->createInstance('orchestrator_plan');
        $result = $read_tool->execute(['operation' => 'read_all']);
        $steps = json_decode($result->getContextValue('plan_data'), TRUE);
        $this->assertCount(2, $steps);
        $this->assertEquals('Step B', $steps[0]['name']);
        $this->assertEquals('Step A', $steps[1]['name']);
    }

    /**
     * Tests add with after a specific ID.
     */
    public function testAddAfterSpecificId(): void
    {
        $tool = $this->toolPluginManager->createInstance('orchestrator_plan');

        // Add step A.
        $result_a = $tool->execute([
            'operation' => 'add',
            'name' => 'Step A',
            'agent' => 'agent_a',
            'prompt' => 'prompt_a',
            'reason' => 'reason_a',
        ]);
        $step_a = json_decode($result_a->getContextValue('plan_data'), TRUE);

        // Add step C.
        $tool2 = $this->toolPluginManager->createInstance('orchestrator_plan');
        $tool2->execute([
            'operation' => 'add',
            'name' => 'Step C',
            'agent' => 'agent_c',
            'prompt' => 'prompt_c',
            'reason' => 'reason_c',
        ]);

        // Add step B after A.
        $tool3 = $this->toolPluginManager->createInstance('orchestrator_plan');
        $tool3->execute([
            'operation' => 'add',
            'name' => 'Step B',
            'agent' => 'agent_b',
            'prompt' => 'prompt_b',
            'reason' => 'reason_b',
            'after' => $step_a['id'],
        ]);

        // Order should be A, B, C.
        $read_tool = $this->toolPluginManager->createInstance('orchestrator_plan');
        $result = $read_tool->execute(['operation' => 'read_all']);
        $steps = json_decode($result->getContextValue('plan_data'), TRUE);
        $this->assertCount(3, $steps);
        $this->assertEquals('Step A', $steps[0]['name']);
        $this->assertEquals('Step B', $steps[1]['name']);
        $this->assertEquals('Step C', $steps[2]['name']);
    }

    /**
     * Tests remove.
     */
    public function testRemove(): void
    {
        $tool = $this->toolPluginManager->createInstance('orchestrator_plan');

        $result = $tool->execute([
            'operation' => 'add',
            'name' => 'To remove',
            'agent' => 'agent_x',
            'prompt' => 'prompt_x',
            'reason' => 'reason_x',
        ]);
        $step = json_decode($result->getContextValue('plan_data'), TRUE);

        $tool2 = $this->toolPluginManager->createInstance('orchestrator_plan');
        $remove_result = $tool2->execute(['operation' => 'remove', 'id' => $step['id']]);
        $this->assertTrue($remove_result->isSuccess());

        $tool3 = $this->toolPluginManager->createInstance('orchestrator_plan');
        $read_result = $tool3->execute(['operation' => 'read_all']);
        $steps = json_decode($read_result->getContextValue('plan_data'), TRUE);
        $this->assertCount(0, $steps);
    }

    /**
     * Tests partial update.
     */
    public function testUpdate(): void
    {
        $tool = $this->toolPluginManager->createInstance('orchestrator_plan');

        $result = $tool->execute([
            'operation' => 'add',
            'name' => 'Original Name',
            'agent' => 'agent_x',
            'prompt' => 'original prompt',
            'reason' => 'original reason',
        ]);
        $step = json_decode($result->getContextValue('plan_data'), TRUE);

        // Partial update — only change name and result.
        $tool2 = $this->toolPluginManager->createInstance('orchestrator_plan');
        $update_result = $tool2->execute([
            'operation' => 'update',
            'id' => $step['id'],
            'name' => 'Updated Name',
            'result' => 'Completed successfully',
        ]);
        $this->assertTrue($update_result->isSuccess());

        $updated = json_decode($update_result->getContextValue('plan_data'), TRUE);
        $this->assertEquals('Updated Name', $updated['name']);
        $this->assertEquals('Completed successfully', $updated['result']);
        // Untouched fields should remain the same.
        $this->assertEquals('agent_x', $updated['agent']);
        $this->assertEquals('original prompt', $updated['prompt']);
    }

    /**
     * Tests read_item.
     */
    public function testReadItem(): void
    {
        $tool = $this->toolPluginManager->createInstance('orchestrator_plan');

        $result = $tool->execute([
            'operation' => 'add',
            'name' => 'Find me',
            'agent' => 'finder',
            'prompt' => 'find prompt',
            'reason' => 'find reason',
        ]);
        $step = json_decode($result->getContextValue('plan_data'), TRUE);

        $tool2 = $this->toolPluginManager->createInstance('orchestrator_plan');
        $read_result = $tool2->execute(['operation' => 'read_item', 'id' => $step['id']]);
        $this->assertTrue($read_result->isSuccess());
        $found = json_decode($read_result->getContextValue('plan_data'), TRUE);
        $this->assertEquals('Find me', $found['name']);
    }

    /**
     * Tests operations on non-existent IDs fail gracefully.
     */
    public function testNonExistentIdFails(): void
    {
        $tool = $this->toolPluginManager->createInstance('orchestrator_plan');
        $result = $tool->execute(['operation' => 'remove', 'id' => 'step_nonexistent']);
        $this->assertFalse($result->isSuccess());

        $tool2 = $this->toolPluginManager->createInstance('orchestrator_plan');
        $result2 = $tool2->execute(['operation' => 'read_item', 'id' => 'step_nonexistent']);
        $this->assertFalse($result2->isSuccess());

        $tool3 = $this->toolPluginManager->createInstance('orchestrator_plan');
        $result3 = $tool3->execute(['operation' => 'update', 'id' => 'step_nonexistent', 'name' => 'x']);
        $this->assertFalse($result3->isSuccess());
    }

    /**
     * Tests add without required fields fails.
     */
    public function testAddMissingFieldsFails(): void
    {
        $tool = $this->toolPluginManager->createInstance('orchestrator_plan');
        $result = $tool->execute([
            'operation' => 'add',
            'name' => 'Missing fields',
        ]);
        $this->assertFalse($result->isSuccess());
    }

}
