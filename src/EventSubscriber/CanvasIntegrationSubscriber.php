<?php

declare(strict_types=1);

namespace Drupal\ai_agents_orchestrator\EventSubscriber;

use Drupal\Core\Render\HtmlResponse;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Injects the orchestrator widget into Canvas UI pages.
 *
 * Canvas bypasses standard Drupal page rendering (hook_page_attachments) and
 * its HTML template only defines header JS/CSS placeholders. Our library JS
 * ends up in footer scripts which Canvas has no placeholder for. We fix this
 * by injecting a scripts_bottom placeholder into the response body and
 * registering it in the attachment placeholders.
 */
final class CanvasIntegrationSubscriber implements EventSubscriberInterface
{

    /**
     * A unique placeholder token for footer scripts.
     */
    private const SCRIPTS_BOTTOM_PLACEHOLDER = '<js-bottom-placeholder token="ORCHESTRATOR-JS-BOTTOM">';

    public function __construct(
        private readonly AccountInterface $currentUser,
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public static function getSubscribedEvents(): array
    {
        // Run before HtmlResponseSubscriber (priority 0) which calls
        // HtmlResponseAttachmentsProcessor to replace placeholders.
        return [
            KernelEvents::RESPONSE => ['onResponse', 10],
        ];
    }

    /**
     * Adds orchestrator attachments to Canvas HTML responses.
     */
    public function onResponse(ResponseEvent $event): void
    {
        $response = $event->getResponse();
        if (!$response instanceof HtmlResponse) {
            return;
        }

        $request = $event->getRequest();
        $route_name = $request->attributes->get('_route', '');
        if (!str_starts_with($route_name, 'canvas.boot.')) {
            return;
        }

        if (!$this->currentUser->hasPermission('orchestrate ai agents')) {
            return;
        }

        // Inject a scripts_bottom placeholder before </body> so Drupal's
        // HtmlResponseAttachmentsProcessor can render footer JS there.
        $content = $response->getContent();
        $content = str_replace('</body>', self::SCRIPTS_BOTTOM_PLACEHOLDER . "\n</body>", $content);
        $response->setContent($content);

        // Add our library and settings to the response attachments.
        $attachments = $response->getAttachments();
        $attachments['library'][] = 'ai_agents_orchestrator/orchestrator';
        $attachments['drupalSettings']['aiAgentsOrchestrator'] = [
            'chatUrl' => Url::fromRoute('ai_agents_orchestrator.chat')->toString(),
            'planStatusUrl' => Url::fromRoute('ai_agents_orchestrator.plan_status')->toString(),
            'pollUrl' => Url::fromRoute('ai_agents_orchestrator.poll', ['thread_id' => '__THREAD_ID__'])->toString(),
            'savePlanUrl' => Url::fromRoute('ai_agents_orchestrator.save_plan')->toString(),
            'chatHistoryUrl' => Url::fromRoute('ai_agents_orchestrator.load_chat_history')->toString(),
            'threadsUrl' => Url::fromRoute('ai_agents_orchestrator.list_threads')->toString(),
            'threadMetadataUrl' => Url::fromRoute('ai_agents_orchestrator.update_thread_metadata')->toString(),
            'deleteThreadUrl' => Url::fromRoute('ai_agents_orchestrator.delete_thread')->toString(),
            'executeUrl' => Url::fromRoute('ai_agents_orchestrator.execute_plan')->toString(),
            'agentsUrl' => Url::fromRoute('ai_agents_orchestrator.list_agents')->toString(),
        ];

        // Register the scripts_bottom placeholder so the attachment processor
        // knows where to render footer JS.
        $attachments['html_response_attachment_placeholders']['scripts_bottom'] = self::SCRIPTS_BOTTOM_PLACEHOLDER;
        $response->setAttachments($attachments);
    }

}
