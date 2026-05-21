<?php

namespace App\Controller;

use App\Entity\FacebookBotFlow;
use App\Entity\FacebookConnection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

use App\Entity\AiContext;

class FacebookBotManagerController extends AbstractController
{
    #[Route('/admin/facebook-bot-manager', name: 'app_facebook_bot_manager')]
    public function index(Request $request, EntityManagerInterface $em): Response
    {
        $connections = $em->getRepository(FacebookConnection::class)->findBy([], ['id' => 'DESC']);
        
        $selectedConnectionId = $request->query->get('connectionId');
        $selectedConnection = null;
        if ($selectedConnectionId) {
            $selectedConnection = $em->getRepository(FacebookConnection::class)->find($selectedConnectionId);
        }
        if (!$selectedConnection && !empty($connections)) {
            $selectedConnection = $connections[0];
        }

        $flows = [];
        if ($selectedConnection) {
            $flows = $em->getRepository(FacebookBotFlow::class)->findBy(['facebookConnection' => $selectedConnection], ['id' => 'DESC']);
        }

        $contexts = $em->getRepository(AiContext::class)->findBy([], ['id' => 'DESC']);

        return $this->render('facebook_bot_manager/index.html.twig', [
            'flows'       => $flows,
            'connection'  => $selectedConnection,
            'connections' => $connections,
            'contexts'    => $contexts,
        ]);
    }

    #[Route('/admin/facebook-bot-manager/flows', name: 'app_facebook_bot_flows', methods: ['GET'])]
    public function flows(Request $request, EntityManagerInterface $em): Response
    {
        $connections = $em->getRepository(FacebookConnection::class)->findBy([], ['id' => 'DESC']);
        
        $selectedConnectionId = $request->query->get('connectionId');
        $selectedConnection = null;
        if ($selectedConnectionId) {
            $selectedConnection = $em->getRepository(FacebookConnection::class)->find($selectedConnectionId);
        }
        if (!$selectedConnection && !empty($connections)) {
            $selectedConnection = $connections[0];
        }

        $flows = [];
        if ($selectedConnection) {
            $flows = $em->getRepository(FacebookBotFlow::class)->findBy(['facebookConnection' => $selectedConnection], ['id' => 'DESC']);
        }

        $payload = array_map(static fn (FacebookBotFlow $f) => self::flowToArray($f), $flows);

        return $this->render('facebook_bot_manager/flows.html.twig', [
            'flows'       => $flows,
            'flowsJson'   => $payload,
            'connection'  => $selectedConnection,
            'connections' => $connections,
        ]);
    }

    #[Route('/admin/facebook-bot-manager/flows/save', name: 'app_facebook_bot_flows_save', methods: ['POST'])]
    public function saveFlow(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        if (!\is_array($payload)) {
            return new JsonResponse(['success' => false, 'error' => 'Invalid payload'], 400);
        }

        $id        = isset($payload['id']) ? (int)$payload['id'] : null;
        $name      = trim((string)($payload['name'] ?? ''));
        $keywords  = $this->normaliseKeywords($payload['keywords'] ?? '');
        $matchMode = (string)($payload['matchMode'] ?? FacebookBotFlow::MATCH_EXACT);
        $isActive  = (bool)($payload['isActive'] ?? true);
        $graph     = $payload['graph'] ?? null;

        if ($name === '') {
            return new JsonResponse(['success' => false, 'error' => 'Flow name is required.'], 400);
        }
        if ($keywords === '') {
            return new JsonResponse(['success' => false, 'error' => 'At least one trigger keyword is required.'], 400);
        }
        if (!\is_array($graph)) {
            return new JsonResponse(['success' => false, 'error' => 'Flow graph payload missing.'], 400);
        }

        $flow = $id ? $em->getRepository(FacebookBotFlow::class)->find($id) : null;
        if (!$flow) {
            $flow = new FacebookBotFlow();
        }

        // Get selected connection
        $connectionId = isset($payload['connectionId']) ? (int)$payload['connectionId'] : (int)$request->query->get('connectionId');
        $connection = null;
        if ($connectionId) {
            $connection = $em->getRepository(FacebookConnection::class)->find($connectionId);
        }
        if (!$connection) {
            // Default to the first connection
            $connections = $em->getRepository(FacebookConnection::class)->findBy([], ['id' => 'DESC']);
            $connection = $connections[0] ?? null;
        }

        $flow->setFacebookConnection($connection);
        $flow->setName($name);
        $flow->setTriggerKeyword($keywords);
        try {
            $flow->setMatchMode($matchMode);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['success' => false, 'error' => $e->getMessage()], 400);
        }
        $flow->setActive($isActive);
        $flow->setFlowData([
            'format'   => 'graph',
            'nodes'    => $graph['nodes'] ?? [],
            'edges'    => $graph['edges'] ?? [],
            'viewport' => $graph['viewport'] ?? null,
        ]);

        $em->persist($flow);
        $em->flush();

        return new JsonResponse(['success' => true, 'flow' => self::flowToArray($flow)]);
    }

    #[Route('/admin/facebook-bot-manager/flows/{id}/delete', name: 'app_facebook_bot_flows_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function deleteFlow(int $id, EntityManagerInterface $em): JsonResponse
    {
        $flow = $em->getRepository(FacebookBotFlow::class)->find($id);
        if (!$flow) {
            return new JsonResponse(['success' => false, 'error' => 'Flow not found.'], 404);
        }
        $em->remove($flow);
        $em->flush();

        return new JsonResponse(['success' => true]);
    }

    #[Route('/admin/facebook-bot-manager/flows/{id}/toggle', name: 'app_facebook_bot_flows_toggle', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function toggleFlow(int $id, Request $request, EntityManagerInterface $em): JsonResponse
    {
        $flow = $em->getRepository(FacebookBotFlow::class)->find($id);
        if (!$flow) {
            return new JsonResponse(['success' => false, 'error' => 'Flow not found.'], 404);
        }

        $payload = json_decode($request->getContent(), true);
        $isActive = isset($payload['isActive']) ? (bool)$payload['isActive'] : !$flow->isActive();

        $flow->setActive($isActive);
        $em->flush();

        return new JsonResponse(['success' => true, 'isActive' => $flow->isActive()]);
    }

    #[Route('/admin/facebook-bot-manager/flows/{id}/clone', name: 'app_facebook_bot_flows_clone', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function cloneFlow(int $id, EntityManagerInterface $em): JsonResponse
    {
        $flow = $em->getRepository(FacebookBotFlow::class)->find($id);
        if (!$flow) {
            return new JsonResponse(['success' => false, 'error' => 'Flow not found.'], 404);
        }

        $clone = new FacebookBotFlow();
        $clone->setName($flow->getName() . ' (Copy)');
        $clone->setTriggerKeyword($flow->getTriggerKeyword());
        $clone->setMatchMode($flow->getMatchMode());
        $clone->setActive($flow->isActive());
        $clone->setFlowData($flow->getFlowData());
        $clone->setFacebookConnection($flow->getFacebookConnection());

        $em->persist($clone);
        $em->flush();

        return new JsonResponse(['success' => true]);
    }

    #[Route('/admin/facebook-bot-manager/flows/{id}/export', name: 'app_facebook_bot_flows_export', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function exportFlow(int $id, EntityManagerInterface $em): Response
    {
        $flow = $em->getRepository(FacebookBotFlow::class)->find($id);
        if (!$flow) {
            throw $this->createNotFoundException('Flow not found.');
        }

        $data = [
            'name' => $flow->getName(),
            'keywords' => $flow->getTriggerKeyword(),
            'matchMode' => $flow->getMatchMode(),
            'isActive' => $flow->isActive(),
            'flowData' => $flow->getFlowData(),
        ];

        $response = new Response(json_encode($data, JSON_PRETTY_PRINT));
        $response->headers->set('Content-Type', 'application/json');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $flow->getName()) . '_flow.json"');

        return $response;
    }

    #[Route('/admin/facebook-bot-manager/ai-settings/agent', name: 'app_facebook_bot_ai_agent_save', methods: ['POST'])]
    public function saveAiAgentSettings(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $connectionId = $request->request->get('connectionId');
        $connection = null;
        if ($connectionId) {
            $connection = $em->getRepository(FacebookConnection::class)->find($connectionId);
        }
        if (!$connection) {
            $connection = $em->getRepository(FacebookConnection::class)->findOneBy([]);
        }

        if (!$connection) {
            return new JsonResponse(['success' => false, 'error' => 'No Facebook Connection found.']);
        }

        $aiActive = (bool)$request->request->get('aiActive', false);
        $activeContextId = $request->request->get('activeContextId');

        $connection->setAiActive($aiActive);

        if ($request->request->has('agentName')) {
            $connection->setAgentName(trim($request->request->get('agentName', '')) ?: null);
        }
        if ($request->request->has('agentRole')) {
            $connection->setAgentRole(trim($request->request->get('agentRole', '')) ?: null);
        }
        if ($request->request->has('contextData')) {
            $connection->setContextData(trim($request->request->get('contextData', '')) ?: null);
        }

        if ($activeContextId !== null && $activeContextId !== '') {
            $context = $em->getRepository(AiContext::class)->find($activeContextId);
            $connection->setActiveContext($context);
        } else {
            $connection->setActiveContext(null);
        }

        $em->persist($connection);
        $em->flush();

        return new JsonResponse([
            'success' => true,
            'message' => 'Facebook AI Agent Settings saved successfully.',
        ]);
    }

    /**
     * @param mixed $raw  Either a string or an array of keywords from the client.
     */
    private function normaliseKeywords(mixed $raw): string
    {
        if (\is_array($raw)) {
            $parts = $raw;
        } else {
            $parts = explode(',', (string)$raw);
        }
        $parts = array_map(
            static fn ($k): string => strtolower(trim((string)$k)),
            $parts
        );
        $parts = array_values(array_unique(array_filter($parts, static fn (string $k): bool => $k !== '')));

        return implode(',', $parts);
    }

    /**
     * @return array<string, mixed>
     */
    private static function flowToArray(FacebookBotFlow $flow): array
    {
        $data = $flow->getFlowData();
        $isGraph = isset($data['format']) && $data['format'] === 'graph';

        return [
            'id'        => $flow->getId(),
            'name'      => $flow->getName() ?? ('Flow #' . $flow->getId()),
            'keywords'  => $flow->getKeywordList(),
            'matchMode' => $flow->getMatchMode(),
            'isActive'  => (bool)$flow->isActive(),
            'graph'     => $isGraph
                ? [
                    'nodes'    => $data['nodes'] ?? [],
                    'edges'    => $data['edges'] ?? [],
                    'viewport' => $data['viewport'] ?? null,
                ]
                : null,
            'legacyActions' => $isGraph ? null : $data,
        ];
    }
}
