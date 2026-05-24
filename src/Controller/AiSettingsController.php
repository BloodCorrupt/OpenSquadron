<?php

namespace App\Controller;

use App\Entity\AiContext;
use App\Entity\AiSetting;
use App\Service\AiAgentService;
use App\Security\Voter\TeamPermissionVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(TeamPermissionVoter::PERM_AI_MANAGE)]
class AiSettingsController extends AbstractController
{
    #[Route('/ai-settings', name: 'app_ai_settings')]
    public function aiSettings(EntityManagerInterface $em, AiAgentService $aiAgentService): Response
    {
        $aiSetting = $em->getRepository(AiSetting::class)->findOneBy([]);
        $globalSetting = $aiAgentService->getGlobalSetting();

        $contexts = $em->getRepository(AiContext::class)->findBy([], ['id' => 'DESC']);

        // Since AiSetting isn't easily accessible from AiAgentService directly without the superAdmin check above
        // we'll just check if the current setting has an API key or is ollama
        $isUsingCustom = false;
        if ($aiSetting && ($aiSetting->getApiKey() || in_array($aiSetting->getProvider(), ['ollama', 'lmstudio']))) {
            $isUsingCustom = true;
        }

        return $this->render('ai_settings/index.html.twig', [
            'aiSetting' => $aiSetting ?? new AiSetting(),
            'contexts' => $contexts,
            'hasGlobalSetting' => $globalSetting && ($globalSetting->getApiKey() || in_array($globalSetting->getProvider(), ['ollama', 'lmstudio'])),
            'isUsingCustom' => $isUsingCustom,
        ]);
    }

    #[Route('/ai-settings/save', name: 'app_ai_settings_save', methods: ['POST'])]
    public function saveAiSettings(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $configType = $request->request->get('configType', 'all');

        if ($configType === 'all' || $configType === 'api') {
            $useCustom = filter_var($request->request->get('useCustom', 'false'), FILTER_VALIDATE_BOOLEAN);
            $aiSetting = $em->getRepository(AiSetting::class)->findOneBy([]);

            if (!$useCustom) {
                if ($aiSetting) {
                    $aiSetting->setProvider('openai');
                    $aiSetting->setApiKey(null);
                    $aiSetting->setApiEndpoint(null);
                    $em->flush();
                }
                return new JsonResponse([
                    'success' => true,
                    'message' => 'Now using Global Server AI Settings.',
                ]);
            }

            if (!$aiSetting) {
                $aiSetting = new AiSetting();
                $em->persist($aiSetting);
            }

            $provider = $request->request->get('provider', 'openai');
            $apiKey = trim($request->request->get('apiKey', ''));
            $apiEndpoint = trim($request->request->get('apiEndpoint', ''));
            $model = trim($request->request->get('model', ''));
            $isActive = (bool)$request->request->get('isActive', false);

            if (($provider === 'custom' || $provider === 'ollama') && empty($apiEndpoint)) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Custom API Endpoint is required for ' . ($provider === 'ollama' ? 'Ollama (Local)' : 'OpenAI Compatible (Custom)') . ' provider.'
                ], 400);
            }

            $aiSetting->setProvider($provider);
            $aiSetting->setApiKey($apiKey ?: null);
            $aiSetting->setApiEndpoint($apiEndpoint ?: null);
            $aiSetting->setModel($model ?: null);
            $aiSetting->setActive($isActive);
        }

        $em->flush();

        return new JsonResponse([
            'success' => true,
            'message' => 'AI Settings saved successfully.',
        ]);
    }

    #[Route('/ai-settings/fetch-models', name: 'app_ai_models_fetch', methods: ['POST'])]
    public function fetchModels(Request $request, AiAgentService $aiAgentService): JsonResponse
    {
        $provider = $request->request->get('provider', '');
        $apiKey = trim($request->request->get('apiKey', ''));
        $apiEndpoint = trim($request->request->get('apiEndpoint', ''));

        $provider = strtolower($provider);
        if (empty($provider) || (empty($apiKey) && $provider !== 'ollama' && $provider !== 'lmstudio')) {
            return new JsonResponse([
                'success' => false,
                'error' => 'AI Provider and API Key are required to fetch models.'
            ], 400);
        }

        try {
            $models = $aiAgentService->fetchAvailableModels($provider, $apiKey, $apiEndpoint ?: null);
            return new JsonResponse([
                'success' => true,
                'models' => $models
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    #[Route('/ai-settings/generate-faqs', name: 'app_ai_generate_faqs', methods: ['POST'])]
    public function generateFaqs(Request $request, AiAgentService $aiAgentService, EntityManagerInterface $em): JsonResponse
    {
        $contextData = trim($request->request->get('contextData', ''));
        if (empty($contextData)) {
            return new JsonResponse(['success' => false, 'error' => 'No context data provided.'], 400);
        }

        $aiSetting = $aiAgentService->getEffectiveSetting($em->getRepository(AiSetting::class)->findOneBy([]));
        if (!$aiSetting) {
            return new JsonResponse(['success' => false, 'error' => 'Global AI Configuration is not set up.'], 400);
        }
        $dbProvider = strtolower($aiSetting->getProvider() ?? 'openai');
        if (empty($aiSetting->getApiKey()) && $dbProvider !== 'ollama' && $dbProvider !== 'lmstudio') {
            return new JsonResponse(['success' => false, 'error' => 'Global AI Configuration is not set up. Please save your API Key first.'], 400);
        }

        try {
            $faqs = $aiAgentService->generateFaqs($contextData, $aiSetting);
            if (!$faqs) {
                return new JsonResponse(['success' => false, 'error' => 'AI generation failed or returned an empty response.']);
            }
            return new JsonResponse(['success' => true, 'faqs' => $faqs]);
        } catch (\Exception $e) {
            return new JsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    #[Route('/ai-settings/context/save', name: 'app_ai_context_save', methods: ['POST'])]
    public function saveAiContext(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $id = $request->request->get('id');
        $name = trim($request->request->get('name', ''));
        $agentRole = trim($request->request->get('agentRole', ''));
        $systemInstruction = trim($request->request->get('systemInstruction', ''));
        $contextData = trim($request->request->get('contextData', ''));

        if (empty($name)) {
            return new JsonResponse(['success' => false, 'error' => 'Context Name is required.'], 400);
        }

        if ($id) {
            $context = $em->getRepository(AiContext::class)->find($id);
            if (!$context) {
                return new JsonResponse(['success' => false, 'error' => 'Context not found.'], 404);
            }
        } else {
            $context = new AiContext();
        }

        $context->setName($name);
        $context->setAgentRole($agentRole ?: null);
        $context->setSystemInstruction($systemInstruction ?: null);
        $context->setContextData($contextData ?: null);

        if (!$id) {
            $em->persist($context);
        }

        $em->flush();

        return new JsonResponse([
            'success' => true,
            'id' => $context->getId(),
            'name' => $context->getName(),
            'agentRole' => $context->getAgentRole(),
            'systemInstruction' => $context->getSystemInstruction(),
            'contextData' => $context->getContextData(),
            'isActive' => $context->isActive()
        ]);
    }

    #[Route('/ai-settings/context/{id}/toggle', name: 'app_ai_context_toggle', methods: ['POST'])]
    public function toggleAiContext(int $id, Request $request, EntityManagerInterface $em): JsonResponse
    {
        $context = $em->getRepository(AiContext::class)->find($id);
        if (!$context) {
            return new JsonResponse(['success' => false, 'error' => 'Context not found.'], 404);
        }

        $payload = json_decode($request->getContent(), true);
        $isActive = isset($payload['isActive']) ? (bool)$payload['isActive'] : !$context->isActive();

        if ($isActive) {
            // Deactivate all other contexts
            $contexts = $em->getRepository(AiContext::class)->findAll();
            foreach ($contexts as $c) {
                if ($c->getId() !== $context->getId()) {
                    $c->setActive(false);
                }
            }
            $context->setActive(true);
        } else {
            $context->setActive(false);
        }

        $em->flush();

        return new JsonResponse([
            'success' => true,
            'isActive' => $context->isActive()
        ]);
    }

    #[Route('/ai-settings/context/{id}/delete', name: 'app_ai_context_delete', methods: ['POST'])]
    public function deleteAiContext(int $id, EntityManagerInterface $em): JsonResponse
    {
        $context = $em->getRepository(AiContext::class)->find($id);
        if (!$context) {
            return new JsonResponse(['success' => false, 'error' => 'Context not found.'], 404);
        }

        $em->remove($context);
        $em->flush();

        return new JsonResponse(['success' => true]);
    }
}
