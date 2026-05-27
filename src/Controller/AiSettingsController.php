<?php

namespace App\Controller;

use App\Entity\AiContext;
use App\Entity\AiSetting;
use App\Entity\Admin;
use App\Entity\HttpApi;
use App\Service\AiAgentService;
use App\Service\HttpApiExecutorService;
use App\Service\SubscriptionUsageService;
use App\Security\Voter\TeamPermissionVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(TeamPermissionVoter::PERM_AI_MANAGE)]
class AiSettingsController extends AbstractController
{
    public function __construct(private SubscriptionUsageService $usageService) {}
    #[Route('/ai-settings', name: 'app_ai_settings')]
    public function aiSettings(EntityManagerInterface $em, AiAgentService $aiAgentService): Response
    {
        /** @var Admin $user */
        $user = $this->getUser();
        if (!$this->usageService->hasModuleAccess($user, 'ai_copilot')) {
            $this->addFlash('error', 'Your subscription plan does not include access to AI Copilot settings.');
            return $this->redirectToRoute('app_dashboard');
        }

        $aiSetting = $em->getRepository(AiSetting::class)->findOneBy([]);
        $globalSetting = $aiAgentService->getGlobalSetting();

        $contexts = $em->getRepository(AiContext::class)->findBy([], ['id' => 'DESC']);
        $httpApis = $em->getRepository(HttpApi::class)->findBy(['status' => 'active'], ['name' => 'ASC']);

        // Since AiSetting isn't easily accessible from AiAgentService directly without the superAdmin check above
        // we'll just check if the current setting has an API key or is ollama
        $isUsingCustom = false;
        if ($aiSetting && ($aiSetting->getApiKey() || in_array($aiSetting->getProvider(), ['ollama', 'lmstudio']))) {
            $isUsingCustom = true;
        }

        return $this->render('ai_settings/index.html.twig', [
            'aiSetting' => $aiSetting ?? new AiSetting(),
            'contexts' => $contexts,
            'httpApis' => $httpApis,
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
    public function generateFaqs(
        Request $request,
        AiAgentService $aiAgentService,
        EntityManagerInterface $em,
        HttpApiExecutorService $apiExecutor,
        HttpClientInterface $httpClient
    ): JsonResponse {
        $source = $request->request->get('source', 'text');

        $aiSetting = $aiAgentService->getEffectiveSetting($em->getRepository(AiSetting::class)->findOneBy([]));
        if (!$aiSetting) {
            return new JsonResponse(['success' => false, 'error' => 'Global AI Configuration is not set up.'], 400);
        }
        $dbProvider = strtolower($aiSetting->getProvider() ?? 'openai');
        if (empty($aiSetting->getApiKey()) && $dbProvider !== 'ollama' && $dbProvider !== 'lmstudio') {
            return new JsonResponse(['success' => false, 'error' => 'Global AI Configuration is not set up. Please save your API Key first.'], 400);
        }

        // --- Gather raw text based on source ---
        $rawText = '';

        if ($source === 'text') {
            $rawText = trim($request->request->get('contextData', ''));
            if (empty($rawText)) {
                return new JsonResponse(['success' => false, 'error' => 'No text provided.'], 400);
            }
        } elseif ($source === 'api') {
            $apiId = $request->request->get('apiId');
            if (!$apiId) {
                return new JsonResponse(['success' => false, 'error' => 'Please select a saved HTTP API.'], 400);
            }
            $httpApi = $em->getRepository(HttpApi::class)->find($apiId);
            if (!$httpApi) {
                return new JsonResponse(['success' => false, 'error' => 'HTTP API not found.'], 404);
            }
            try {
                $result = $apiExecutor->execute($httpApi, null);
                if (!$result['success']) {
                    return new JsonResponse(['success' => false, 'error' => 'API execution failed: ' . ($result['error'] ?? 'Unknown error')], 400);
                }
                $data = json_decode($result['responseBody'], true);
                $rawText = json_last_error() === JSON_ERROR_NONE
                    ? json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
                    : $result['responseBody'];
            } catch (\Exception $e) {
                return new JsonResponse(['success' => false, 'error' => 'Error fetching API: ' . $e->getMessage()], 500);
            }
        } elseif ($source === 'web') {
            $url = trim($request->request->get('url', ''));
            $selector = trim($request->request->get('selector', 'body')) ?: 'body';
            if (empty($url)) {
                return new JsonResponse(['success' => false, 'error' => 'Website URL is required.'], 400);
            }
            try {
                $response = $httpClient->request('GET', $url);
                if ($response->getStatusCode() !== 200) {
                    return new JsonResponse(['success' => false, 'error' => 'Website returned status ' . $response->getStatusCode()], 400);
                }
                $html = $response->getContent();
                libxml_use_internal_errors(true);
                $dom = new \DOMDocument();
                $dom->loadHTML($html, LIBXML_NOBLANKS | LIBXML_NOERROR);
                libxml_clear_errors();
                $xpath = new \DOMXPath($dom);
                $xpathQuery = '//' . $selector;
                if (str_starts_with($selector, '#')) {
                    $xpathQuery = "//*[@id='" . substr($selector, 1) . "']";
                } elseif (str_starts_with($selector, '.')) {
                    $class = substr($selector, 1);
                    $xpathQuery = "//*[contains(concat(' ', normalize-space(@class), ' '), ' $class ')]";
                }
                $elements = $xpath->query($xpathQuery);
                if ($elements && $elements->length > 0) {
                    $texts = [];
                    foreach ($elements as $el) {
                        $texts[] = trim(preg_replace('/\s+/', ' ', $el->textContent));
                    }
                    $rawText = implode("\n\n", $texts);
                } else {
                    return new JsonResponse(['success' => false, 'error' => 'No elements found with that selector.'], 404);
                }
            } catch (\Exception $e) {
                return new JsonResponse(['success' => false, 'error' => 'Error scraping website: ' . $e->getMessage()], 500);
            }
        } elseif ($source === 'ecommerce') {
            $products = $em->getRepository(\App\Entity\EcomProduct::class)->findBy(['status' => 'active'], ['name' => 'ASC']);
            if (empty($products)) {
                return new JsonResponse(['success' => false, 'error' => 'No active products found in the eCommerce catalog.'], 404);
            }
            $lines = ["PRODUCT CATALOG (Strictly ignore stock availability; do not generate FAQs about stock levels because it is handled dynamically by the system at runtime):"];
            foreach ($products as $cp) {
                $lines[] = "Product: {$cp->getName()} | Price: {$cp->getCurrency()} {$cp->getPrice()}";
                if ($cp->getDescription()) {
                    $lines[] = "Description: {$cp->getDescription()}";
                }
            }
            $rawText = implode("\n", $lines);
        } else {
            return new JsonResponse(['success' => false, 'error' => 'Invalid source.'], 400);
        }

        // --- Generate FAQs via AI ---
        try {
            $faqText = $aiAgentService->generateFaqs($rawText, $aiSetting);
            if (!$faqText) {
                return new JsonResponse(['success' => false, 'error' => 'AI generation failed or returned an empty response.']);
            }

            // Parse the text into structured {q, a} items
            $items = [];
            // Match lines like "Q: ..." followed by "A: ..."
            $lines = preg_split('/\r?\n/', $faqText);
            $currentQ = null;
            $currentA = [];
            foreach ($lines as $line) {
                $line = trim($line);
                if (empty($line)) {
                    if ($currentQ !== null && !empty($currentA)) {
                        $items[] = ['q' => $currentQ, 'a' => implode(' ', $currentA)];
                        $currentQ = null;
                        $currentA = [];
                    }
                    continue;
                }
                if (preg_match('/^Q[:.)]\s*(.+)/i', $line, $m)) {
                    if ($currentQ !== null && !empty($currentA)) {
                        $items[] = ['q' => $currentQ, 'a' => implode(' ', $currentA)];
                    }
                    $currentQ = trim($m[1]);
                    $currentA = [];
                } elseif (preg_match('/^A[:.)]\s*(.+)/i', $line, $m)) {
                    $currentA[] = trim($m[1]);
                } elseif ($currentQ !== null) {
                    $currentA[] = $line;
                }
            }
            if ($currentQ !== null && !empty($currentA)) {
                $items[] = ['q' => $currentQ, 'a' => implode(' ', $currentA)];
            }

            // Fallback: if no structured items parsed, return raw text as a single item
            if (empty($items)) {
                $items = [['q' => 'Generated Content', 'a' => $faqText]];
            }

            return new JsonResponse(['success' => true, 'items' => $items]);
        } catch (\Exception $e) {
            return new JsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    #[Route('/ai-settings/context/save', name: 'app_ai_context_save', methods: ['POST'])]
    public function saveAiContext(Request $request, EntityManagerInterface $em, HttpApiExecutorService $apiExecutor, HttpClientInterface $httpClient): JsonResponse
    {
        $id = $request->request->get('id');
        $name = trim($request->request->get('name', ''));
        $agentRole = trim($request->request->get('agentRole', ''));
        $systemInstruction = trim($request->request->get('systemInstruction', ''));
        $modulesDataJson = trim($request->request->get('modulesData', '[]'));

        if (empty($name)) {
            return new JsonResponse(['success' => false, 'error' => 'Context Name is required.'], 400);
        }

        $modulesData = json_decode($modulesDataJson, true);
        if (!is_array($modulesData)) {
            $modulesData = [];
        }

        // Build the compiled contextData string
        $compiledContext = [];
        foreach ($modulesData as $module) {
            $type = $module['type'] ?? '';
            
            if ($type === 'text') {
                $compiledContext[] = trim($module['content'] ?? '');
            } 
            elseif ($type === 'api') {
                $apiId = $module['apiId'] ?? null;
                if ($apiId) {
                    $httpApi = $em->getRepository(HttpApi::class)->find($apiId);
                    if ($httpApi) {
                        try {
                            $result = $apiExecutor->execute($httpApi, null);
                            if ($result['success']) {
                                $compiledContext[] = "--- HTTP API Data: {$httpApi->getName()} ---\n" . $result['responseBody'];
                            }
                        } catch (\Exception $e) {
                            // Skip on error
                        }
                    }
                }
            } 
            elseif ($type === 'web') {
                $url = $module['url'] ?? '';
                $selector = $module['selector'] ?? 'body';
                if ($url) {
                    try {
                        $response = $httpClient->request('GET', $url);
                        if ($response->getStatusCode() === 200) {
                            $html = $response->getContent();
                            libxml_use_internal_errors(true);
                            $dom = new \DOMDocument();
                            $dom->loadHTML($html, LIBXML_NOBLANKS | LIBXML_NOERROR);
                            libxml_clear_errors();
                            $xpath = new \DOMXPath($dom);
                            $xpathQuery = '//' . $selector; 
                            if (str_starts_with($selector, '#')) {
                                $xpathQuery = "//*[@id='" . substr($selector, 1) . "']";
                            } elseif (str_starts_with($selector, '.')) {
                                $class = substr($selector, 1);
                                $xpathQuery = "//*[contains(concat(' ', normalize-space(@class), ' '), ' $class ')]";
                            }
                            $elements = $xpath->query($xpathQuery);
                            if ($elements && $elements->length > 0) {
                                $extractedText = [];
                                foreach ($elements as $el) {
                                    $extractedText[] = trim(preg_replace('/\s+/', ' ', $el->textContent));
                                }
                                $compiledContext[] = "--- Scraped Web Data: {$url} ---\n" . implode("\n", $extractedText);
                            }
                        }
                    } catch (\Exception $e) {
                        // Skip on error
                    }
                }
            }
            elseif ($type === 'ecommerce') {
                try {
                    $catalogProducts = $em->getRepository(\App\Entity\EcomProduct::class)->findBy(['status' => 'active'], ['name' => 'ASC']);
                    if (!empty($catalogProducts)) {
                        $catalogLines = ["--- ECOMMERCE PRODUCT CATALOG ---"];
                        foreach ($catalogProducts as $cp) {
                            $stockInfo = $cp->getStock() > 0 ? "In Stock ({$cp->getStock()} available)" : 'Available';
                            $extLinkInfo = $cp->getExternalUrl() ? " — External Link: {$cp->getExternalUrl()}" : "";
                            $catalogLines[] = "• {$cp->getName()} — {$cp->getCurrency()} {$cp->getPrice()} — {$stockInfo}{$extLinkInfo}";
                            if ($cp->getDescription()) {
                                $catalogLines[] = "  {$cp->getDescription()}";
                            }
                        }
                        $compiledContext[] = implode("\n", $catalogLines);
                    }
                } catch (\Exception $e) {
                    // Skip
                }
            }
            elseif ($type === 'faq') {
                $faqItems = $module['items'] ?? [];
                if (!empty($faqItems)) {
                    $faqLines = ["--- FAQ ---"];
                    foreach ($faqItems as $item) {
                        $q = trim($item['q'] ?? '');
                        $a = trim($item['a'] ?? '');
                        if ($q && $a) {
                            $faqLines[] = "Q: {$q}";
                            $faqLines[] = "A: {$a}";
                            $faqLines[] = '';
                        }
                    }
                    $compiledContext[] = implode("\n", $faqLines);
                }
            }
        }
        
        $contextData = implode("\n\n", array_filter($compiledContext));

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
        $context->setModulesData($modulesData);
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
            'modulesData' => $context->getModulesData(),
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

    #[Route('/ai-settings/context/import-api', name: 'app_ai_context_import_api', methods: ['POST'])]
    public function importApiContext(Request $request, HttpApiExecutorService $apiExecutor, EntityManagerInterface $em): JsonResponse
    {
        $apiId = $request->request->get('apiId');
        if (empty($apiId)) {
            return new JsonResponse(['success' => false, 'error' => 'Please select a saved HTTP API.'], 400);
        }

        $httpApi = $em->getRepository(HttpApi::class)->find($apiId);
        if (!$httpApi) {
            return new JsonResponse(['success' => false, 'error' => 'HTTP API not found.'], 404);
        }

        try {
            $result = $apiExecutor->execute($httpApi, null);
            
            if (!$result['success']) {
                return new JsonResponse(['success' => false, 'error' => 'API Execution Failed: ' . ($result['error'] ?? 'Unknown Error')], 400);
            }

            $content = $result['responseBody'];
            
            // Try to format JSON if it is JSON
            $data = json_decode($content, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $content = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }

            return new JsonResponse(['success' => true, 'text' => $content]);
        } catch (\Exception $e) {
            return new JsonResponse(['success' => false, 'error' => 'Error fetching API: ' . $e->getMessage()], 500);
        }
    }

    #[Route('/ai-settings/context/import-web', name: 'app_ai_context_import_web', methods: ['POST'])]
    public function importWebContext(Request $request, HttpClientInterface $httpClient): JsonResponse
    {
        $url = trim($request->request->get('url', ''));
        $selector = trim($request->request->get('selector', ''));
        
        if (empty($url)) {
            return new JsonResponse(['success' => false, 'error' => 'Website URL is required.'], 400);
        }

        try {
            $response = $httpClient->request('GET', $url);
            $statusCode = $response->getStatusCode();
            if ($statusCode !== 200) {
                return new JsonResponse(['success' => false, 'error' => "Website returned status code $statusCode."], 400);
            }

            $html = $response->getContent();
            
            if (empty($selector)) {
                // If no selector, try to extract body text
                $selector = 'body';
            }

            libxml_use_internal_errors(true);
            $dom = new \DOMDocument();
            $dom->loadHTML($html, LIBXML_NOBLANKS | LIBXML_NOERROR);
            libxml_clear_errors();

            $xpath = new \DOMXPath($dom);
            
            // Very simple CSS to XPath converter for basic selectors
            $xpathQuery = '//' . $selector; // Default assume it's a tag like 'body' or 'article'
            if (str_starts_with($selector, '#')) {
                $id = substr($selector, 1);
                $xpathQuery = "//*[@id='$id']";
            } elseif (str_starts_with($selector, '.')) {
                $class = substr($selector, 1);
                $xpathQuery = "//*[contains(concat(' ', normalize-space(@class), ' '), ' $class ')]";
            }

            $elements = $xpath->query($xpathQuery);
            if ($elements && $elements->length > 0) {
                $extractedText = [];
                foreach ($elements as $el) {
                    $text = $el->textContent;
                    // basic cleanup
                    $text = preg_replace('/\s+/', ' ', $text);
                    $extractedText[] = trim($text);
                }
                return new JsonResponse(['success' => true, 'text' => implode("\n\n", $extractedText)]);
            }

            return new JsonResponse(['success' => false, 'error' => 'Could not find any elements matching that selector.'], 404);
            
        } catch (\Exception $e) {
            return new JsonResponse(['success' => false, 'error' => 'Error scraping website: ' . $e->getMessage()], 500);
        }
    }

    #[Route('/ai-settings/context/import-ecommerce', name: 'app_ai_context_import_ecommerce', methods: ['POST'])]
    public function importEcommerceContext(EntityManagerInterface $em): JsonResponse
    {
        try {
            $catalogProducts = $em->getRepository(\App\Entity\EcomProduct::class)->findBy(['status' => 'active'], ['name' => 'ASC']);
            
            if (empty($catalogProducts)) {
                return new JsonResponse(['success' => false, 'error' => 'No active products found in the eCommerce catalog.'], 404);
            }

            $catalogLines = ["=== PRODUCT CATALOG ==="];
            foreach ($catalogProducts as $cp) {
                $stockInfo = $cp->getStock() > 0 ? "In Stock ({$cp->getStock()} available)" : 'Available';
                $extLinkInfo = $cp->getExternalUrl() ? " - External Link: {$cp->getExternalUrl()}" : "";
                $imgInfo = $cp->getImageUrl() ? " - Image URL: {$cp->getImageUrl()}" : "";
                
                $catalogLines[] = "  {$cp->getName()} - {$cp->getCurrency()} {$cp->getPrice()} - {$stockInfo}{$extLinkInfo}{$imgInfo}";
                if ($cp->getDescription()) {
                    $catalogLines[] = "  {$cp->getDescription()}";
                }
            }
            $catalogLines[] = "=== END PRODUCT CATALOG ===";
            $catalogLines[] = "If the user asks for a product photo or details, you MUST include the exact tag [ATTACH_IMAGE: <Image URL>] anywhere in your response to send the photo.";

            return new JsonResponse(['success' => true, 'text' => implode("\n", $catalogLines)]);
            
        } catch (\Exception $e) {
            return new JsonResponse(['success' => false, 'error' => 'Error fetching eCommerce catalog: ' . $e->getMessage()], 500);
        }
    }
}
