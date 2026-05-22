<?php

namespace App\Service;

use App\Entity\FacebookBotFlow;
use App\Entity\Message;
use App\Entity\Subscriber;
use App\Service\HttpApiExecutorService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Walks a saved FacebookBotFlow and dispatches its actions through the Facebook API.
 *
 * Supports graph format:      { format:'graph', nodes:[...], edges:[...] }
 */
class FacebookBotFlowExecutor
{
    private const MAX_STEPS = 25;
    private const MAX_DELAY_SECONDS = 5;

    public function __construct(
        private FacebookService $facebook,
        private EntityManagerInterface $em,
        private HttpApiExecutorService $apiExecutor,
        private ?LoggerInterface $logger = null
    ) {
        $this->logger ??= new NullLogger();
    }

    public function execute(FacebookBotFlow $flow, Subscriber $subscriber, ?string $startNodeId = null): void
    {
        $data = $flow->getFlowData();
        $connection = $flow->getFacebookConnection();

        if (!isset($data['format']) || $data['format'] !== 'graph') {
            return;
        }

        $this->runGraph($flow, $data, $subscriber, $connection, $startNodeId);
    }

    /**
     * @param array{format:string, nodes?:array, edges?:array} $graph
     */
    private function runGraph(FacebookBotFlow $flow, array $graph, Subscriber $subscriber, ?\App\Entity\FacebookConnection $connection = null, ?string $startNodeId = null): void
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

        // Find the start node. Prefer startNodeId if supplied, otherwise find 'start' type or root.
        $startId = $startNodeId;
        if ($startId === null) {
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
        }
        if ($startId === null) {
            return;
        }

        $current = $startId;
        $visited = [];
        $steps = 0;

        while ($current !== null && $steps < self::MAX_STEPS) {
            if (isset($visited[$current])) {
                break;
            }
            $visited[$current] = true;
            $steps++;

            $node = $nodes[$current] ?? null;
            if (!$node) {
                break;
            }

            // Handle user_input node differently to pause execution
            if (($node['type'] ?? '') === 'user_input') {
                $varName = trim((string)($node['data']['variable'] ?? ''));
                $question = trim((string)($node['data']['question'] ?? $node['data']['text'] ?? ''));
                $psid = (string)$subscriber->getPsid();
                if ($varName !== '' && $question !== '' && !empty($psid)) {
                    $response = $this->facebook->sendMessage($psid, $question, $connection);
                    $this->logOutbound($subscriber, 'text', $question, null, $response);

                    // Save waiting state
                    $subscriber->setCustomAttributes(array_merge($subscriber->getCustomAttributes(), [
                        '_waiting_for_input' => $varName,
                        '_waiting_node_id' => $current,
                        '_waiting_flow_id' => $flow->getId(),
                        '_waiting_flow_type' => 'facebook'
                    ]));
                    $this->em->flush();
                }
                // Pause and halt execution
                break;
            }

            $this->runAction([
                'type' => $node['type'] ?? '',
                ...($node['data'] ?? []),
            ], $subscriber, $connection);

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
    private function runAction(array $action, Subscriber $subscriber, ?\App\Entity\FacebookConnection $connection = null): void
    {
        $type = (string)($action['type'] ?? '');
        $psid = (string)$subscriber->getPsid();

        if (empty($psid)) {
            $this->logger?->warning('FacebookBotFlowExecutor: Subscriber has no PSID');
            return;
        }

        try {
            switch ($type) {
                case 'start':
                    return;

                case 'send_text':
                    $text = trim((string)($action['text'] ?? ''));
                    if ($text === '') {
                        return;
                    }
                    $response = $this->facebook->sendMessage($psid, $text, $connection);
                    $this->logOutbound($subscriber, 'text', $text, null, $response);
                    return;

                case 'send_image':
                    $url = trim((string)($action['url'] ?? ''));
                    if ($url === '') {
                        return;
                    }
                    $response = $this->facebook->sendMediaMessage($psid, 'image', $url, $connection);
                    $caption = trim((string)($action['caption'] ?? ''));
                    if ($caption !== '') {
                        // Messenger API sends caption and image separately if we don't use generic templates, 
                        // so we might just send the text caption right after.
                        $this->facebook->sendMessage($psid, $caption, $connection);
                    }
                    $this->logOutbound($subscriber, 'image', $caption, $url, $response);
                    return;

                case 'delay':
                    $seconds = (int)($action['seconds'] ?? 0);
                    if ($seconds > 0) {
                        sleep(min($seconds, self::MAX_DELAY_SECONDS));
                    }
                    return;

                case 'call_http_api':
                case 'http_api':
                    $apiId = (int)($action['apiId'] ?? ($action['api_id'] ?? 0));
                    if ($apiId <= 0) {
                        return;
                    }
                    $httpApi = $this->em->getRepository(\App\Entity\HttpApi::class)->find($apiId);
                    if ($httpApi && $httpApi->getStatus() === 'active') {
                        $result = $this->apiExecutor->execute($httpApi, $subscriber);
                        $responseVar = trim((string)($action['responseVar'] ?? ''));
                        if ($responseVar !== '' && !empty($result['success']) && isset($result['responseBody'])) {
                            $subscriber->setCustomAttributes(array_merge($subscriber->getCustomAttributes(), [
                                $responseVar => $result['responseBody']
                            ]));
                            $this->em->flush();
                        }
                    }
                    return;

                default:
                    // Unknown action or unsupported (like send_template on FB for now)
                    return;
            }
        } catch (\Throwable $e) {
            $this->logger?->warning('FacebookBotFlowExecutor action failed', [
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
        $msg->setMetaMessageId($apiResponse['message_id'] ?? null);
        $msg->setStatus('sent');
        $this->em->persist($msg);
    }
}
