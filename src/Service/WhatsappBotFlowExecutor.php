<?php

namespace App\Service;

use App\Entity\WhatsappBotFlow;
use App\Entity\Message;
use App\Entity\Subscriber;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Walks a saved WhatsappBotFlow and dispatches its actions through the WhatsApp API.
 *
 * Supports two payload shapes inside WhatsappBotFlow::flowData:
 *
 *  1. Legacy flat list:  [ {type:'send_text', text:'...'}, ... ]
 *  2. Graph format:      { format:'graph', nodes:[...], edges:[...] }
 *
 * Graph nodes recognised by v1:
 *   - start         (no payload, single output)
 *   - send_text     ({ text })
 *   - send_image    ({ url, caption? })
 *   - send_template ({ name, language })
 *   - delay         ({ seconds }) — best effort, capped to 5s in the webhook path
 */
class WhatsappBotFlowExecutor
{
    /** Hard ceiling so a flow can never spam recipients or hold the webhook. */
    private const MAX_STEPS = 25;
    private const MAX_DELAY_SECONDS = 5;

    public function __construct(
        private WhatsAppConnectionService $whatsapp,
        private EntityManagerInterface $em,
        private ?LoggerInterface $logger = null
    ) {
        $this->logger ??= new NullLogger();
    }

    public function execute(WhatsappBotFlow $flow, Subscriber $subscriber): void
    {
        $data = $flow->getFlowData();
        $connection = $flow->getWhatsAppConnection();

        // Legacy: a plain action list.
        if (!isset($data['format']) || $data['format'] !== 'graph') {
            $this->runActionList(\is_array($data) ? $data : [], $subscriber, $connection);
            return;
        }

        $this->runGraph($data, $subscriber, $connection);
    }

    /**
     * @param array<int, array<string, mixed>> $actions
     */
    private function runActionList(array $actions, Subscriber $subscriber, ?\App\Entity\WhatsAppConnection $connection = null): void
    {
        $steps = 0;
        foreach ($actions as $action) {
            if (++$steps > self::MAX_STEPS) {
                break;
            }
            $this->runAction(\is_array($action) ? $action : [], $subscriber, $connection);
        }
    }

    /**
     * @param array{format:string, nodes?:array, edges?:array} $graph
     */
    private function runGraph(array $graph, Subscriber $subscriber, ?\App\Entity\WhatsAppConnection $connection = null): void
    {
        $nodes = [];
        foreach (($graph['nodes'] ?? []) as $node) {
            if (isset($node['id'])) {
                $nodes[$node['id']] = $node;
            }
        }
        if (!$nodes) {
            return;
        }

        // Index outgoing edges by source node id (and optionally source handle).
        $outgoing = [];
        foreach (($graph['edges'] ?? []) as $edge) {
            $src = $edge['source'] ?? null;
            $tgt = $edge['target'] ?? null;
            if (!$src || !$tgt) {
                continue;
            }
            $outgoing[$src][] = [
                'target' => $tgt,
                'sourceHandle' => $edge['sourceHandle'] ?? 'out',
            ];
        }

        // Find the start node. Prefer an explicit 'start' type, fall back to
        // the first node that has no incoming edge.
        $startId = null;
        foreach ($nodes as $id => $node) {
            if (($node['type'] ?? '') === 'start') {
                $startId = $id;
                break;
            }
        }
        if ($startId === null) {
            $incoming = [];
            foreach (($graph['edges'] ?? []) as $edge) {
                if (isset($edge['target'])) {
                    $incoming[$edge['target']] = true;
                }
            }
            foreach ($nodes as $id => $_node) {
                if (!isset($incoming[$id])) {
                    $startId = $id;
                    break;
                }
            }
        }
        if ($startId === null) {
            return;
        }

        $current = $startId;
        $visited = [];
        $steps = 0;

        while ($current !== null && $steps < self::MAX_STEPS) {
            if (isset($visited[$current])) {
                // Cycle guard.
                break;
            }
            $visited[$current] = true;
            $steps++;

            $node = $nodes[$current] ?? null;
            if (!$node) {
                break;
            }

            $this->runAction([
                'type' => $node['type'] ?? '',
                ...($node['data'] ?? []),
            ], $subscriber, $connection);

            // Follow the first outgoing edge from the default 'out' handle.
            $next = null;
            foreach (($outgoing[$current] ?? []) as $edge) {
                if (($edge['sourceHandle'] ?? 'out') === 'out') {
                    $next = $edge['target'];
                    break;
                }
            }
            $current = $next;
        }
    }

    /**
     * @param array<string, mixed> $action
     */
    private function runAction(array $action, Subscriber $subscriber, ?\App\Entity\WhatsAppConnection $connection = null): void
    {
        $type = (string)($action['type'] ?? '');
        $to   = (string)$subscriber->getPhoneNumber();

        try {
            switch ($type) {
                case 'start':
                    return;

                case 'send_text':
                    $text = trim((string)($action['text'] ?? ''));
                    if ($text === '') {
                        return;
                    }
                    $response = $this->whatsapp->sendMessage($to, $text, $connection);
                    $this->logOutbound($subscriber, 'text', $text, null, $response);
                    return;

                case 'send_image':
                    $url = trim((string)($action['url'] ?? ''));
                    if ($url === '') {
                        return;
                    }
                    $response = $this->whatsapp->sendMediaMessage($to, 'image', $url, $connection);
                    $caption = trim((string)($action['caption'] ?? ''));
                    $this->logOutbound($subscriber, 'image', $caption, $url, $response);
                    return;

                case 'send_template':
                    $name = trim((string)($action['name'] ?? ''));
                    $lang = trim((string)($action['language'] ?? 'en_US'));
                    if ($name === '') {
                        return;
                    }
                    $response = $this->whatsapp->sendTemplateMessage($to, $name, $lang, $connection);
                    $this->logOutbound($subscriber, 'template', "[template:{$name}]", null, $response);
                    return;

                case 'delay':
                    $seconds = (int)($action['seconds'] ?? 0);
                    if ($seconds > 0) {
                        sleep(min($seconds, self::MAX_DELAY_SECONDS));
                    }
                    return;

                default:
                    // Unknown action — skip silently so old/new payloads coexist.
                    return;
            }
        } catch (\Throwable $e) {
            $this->logger?->warning('WhatsappBotFlowExecutor action failed', [
                'type' => $type,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param array<string, mixed> $apiResponse
     */
    private function logOutbound(
        Subscriber $subscriber,
        string $type,
        string $content,
        ?string $mediaUrl,
        array $apiResponse
    ): void {
        $msg = new Message();
        $msg->setSubscriber($subscriber);
        $msg->setDirection('outbound');
        $msg->setType($type);
        $msg->setContent($content);
        $msg->setMediaUrl($mediaUrl);
        $msg->setMetaMessageId($apiResponse['messages'][0]['id'] ?? null);
        $msg->setStatus('sent');
        $this->em->persist($msg);
    }
}
