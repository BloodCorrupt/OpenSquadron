<?php

namespace App\Controller;

use App\Entity\BotFlow;
use App\Entity\MessageTemplate;
use App\Service\WhatsAppConnectionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class BotManagerController extends AbstractController
{
    #[Route('/admin/bot-manager', name: 'app_bot_manager')]
    public function index(EntityManagerInterface $em): Response
    {
        $flows = $em->getRepository(BotFlow::class)->findBy([], ['id' => 'DESC']);
        $templates = $em->getRepository(MessageTemplate::class)->findAll();

        return $this->render('bot_manager/index.html.twig', [
            'flows' => $flows,
            'templates' => $templates,
        ]);
    }

    #[Route('/admin/bot-manager/flows/{id}/clone', name: 'app_bot_flows_clone', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function cloneFlow(int $id, EntityManagerInterface $em): JsonResponse
    {
        $flow = $em->getRepository(BotFlow::class)->find($id);
        if (!$flow) {
            return new JsonResponse(['success' => false, 'error' => 'Flow not found.'], 404);
        }

        $clone = new BotFlow();
        $clone->setName($flow->getName() . ' (Copy)');
        $clone->setTriggerKeyword($flow->getTriggerKeyword());
        $clone->setMatchMode($flow->getMatchMode());
        $clone->setActive($flow->isActive());
        $clone->setFlowData($flow->getFlowData());

        $em->persist($clone);
        $em->flush();

        return new JsonResponse(['success' => true]);
    }

    #[Route('/admin/bot-manager/flows/{id}/export', name: 'app_bot_flows_export', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function exportFlow(int $id, EntityManagerInterface $em): Response
    {
        $flow = $em->getRepository(BotFlow::class)->find($id);
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

    // ───────────────────────── Templates ─────────────────────────

    #[Route('/admin/bot-manager/templates', name: 'app_bot_templates', methods: ['GET'])]
    public function templates(): Response
    {
        return $this->redirectToRoute('app_bot_manager', ['tab' => 'templates']);
    }

    #[Route('/admin/bot-manager/templates/sync', name: 'app_bot_templates_sync', methods: ['POST'])]
    public function syncTemplates(WhatsAppConnectionService $whatsappService): JsonResponse
    {
        try {
            $result = $whatsappService->syncTemplates();
            return new JsonResponse([
                'success' => true,
                'message' => "Successfully synced {$result['count']} approved templates.",
            ]);
        } catch (\Exception $e) {
            return new JsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    #[Route('/admin/bot-manager/templates/create', name: 'app_bot_templates_create', methods: ['POST'])]
    public function createTemplate(Request $request, WhatsAppConnectionService $whatsappService): JsonResponse
    {
        $name     = trim($request->request->get('name', ''));
        $language = $request->request->get('language', 'en_US');
        $category = $request->request->get('category', 'UTILITY');
        $body     = trim($request->request->get('body', ''));
        $header   = trim($request->request->get('header', '')) ?: null;
        $footer   = trim($request->request->get('footer', '')) ?: null;

        if (!$name || !$body) {
            return new JsonResponse(['success' => false, 'error' => 'Name and Body are required.'], 400);
        }

        try {
            $result = $whatsappService->createTemplate($name, $language, $category, $body, $header, $footer);
            try {
                $whatsappService->syncTemplates();
            } catch (\Exception $syncEx) {
                // Sync failure is non-critical — Meta still has the template.
            }

            return new JsonResponse([
                'success' => true,
                'message' => 'Template submitted to Meta! It will appear as APPROVED once Meta reviews it.',
                'id' => $result['id'] ?? null,
            ]);
        } catch (\Exception $e) {
            return new JsonResponse(['success' => false, 'error' => $e->getMessage()], 422);
        }
    }

    // ───────────────────────── Flows ─────────────────────────

    #[Route('/admin/bot-manager/flows', name: 'app_bot_flows', methods: ['GET'])]
    public function flows(EntityManagerInterface $em): Response
    {
        $flows = $em->getRepository(BotFlow::class)->findBy([], ['id' => 'DESC']);
        $templates = $em->getRepository(MessageTemplate::class)->findBy(['status' => 'APPROVED']);
        $connection = $em->getRepository(\App\Entity\WhatsAppConnection::class)->findOneBy([]);

        $payload = array_map(static fn (BotFlow $f) => self::flowToArray($f), $flows);

        return $this->render('bot_manager/flows.html.twig', [
            'flows'       => $flows,
            'flowsJson'   => $payload,
            'templates'   => $templates,
            'connection'  => $connection,
        ]);
    }

    #[Route('/admin/bot-manager/flows/save', name: 'app_bot_flows_save', methods: ['POST'])]
    public function saveFlow(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        if (!\is_array($payload)) {
            return new JsonResponse(['success' => false, 'error' => 'Invalid payload'], 400);
        }

        $id        = isset($payload['id']) ? (int)$payload['id'] : null;
        $name      = trim((string)($payload['name'] ?? ''));
        $keywords  = $this->normaliseKeywords($payload['keywords'] ?? '');
        $matchMode = (string)($payload['matchMode'] ?? BotFlow::MATCH_EXACT);
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

        $flow = $id ? $em->getRepository(BotFlow::class)->find($id) : null;
        if (!$flow) {
            $flow = new BotFlow();
        }

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

    #[Route('/admin/bot-manager/flows/{id}/delete', name: 'app_bot_flows_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function deleteFlow(int $id, EntityManagerInterface $em): JsonResponse
    {
        $flow = $em->getRepository(BotFlow::class)->find($id);
        if (!$flow) {
            return new JsonResponse(['success' => false, 'error' => 'Flow not found.'], 404);
        }
        $em->remove($flow);
        $em->flush();

        return new JsonResponse(['success' => true]);
    }

    #[Route('/admin/bot-manager/flows/{id}/toggle', name: 'app_bot_flows_toggle', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function toggleFlow(int $id, Request $request, EntityManagerInterface $em): JsonResponse
    {
        $flow = $em->getRepository(BotFlow::class)->find($id);
        if (!$flow) {
            return new JsonResponse(['success' => false, 'error' => 'Flow not found.'], 404);
        }

        $payload = json_decode($request->getContent(), true);
        $isActive = isset($payload['isActive']) ? (bool)$payload['isActive'] : !$flow->isActive();

        $flow->setActive($isActive);
        $em->flush();

        return new JsonResponse(['success' => true, 'isActive' => $flow->isActive()]);
    }

    // ───────────────────────── helpers ─────────────────────────

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
    private static function flowToArray(BotFlow $flow): array
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
