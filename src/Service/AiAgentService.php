<?php

namespace App\Service;

use App\Entity\AiSetting;
use App\Entity\Admin;
use App\Entity\WhatsAppConnection;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class AiAgentService
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        private \Doctrine\ORM\EntityManagerInterface $em,
        private ?\App\Service\TenantContext $tenantContext = null
    ) {}

    public function getSetting(): ?AiSetting
    {
        return $this->em->getRepository(AiSetting::class)->findOneBy([]);
    }

    public function getGlobalSetting(): ?AiSetting
    {
        $isTenantFilterEnabled = false;
        
        if ($this->tenantContext) {
            $filters = $this->em->getFilters();
            $isTenantFilterEnabled = $filters->has('tenant_filter') && $filters->isEnabled('tenant_filter');
            if ($isTenantFilterEnabled) {
                $this->tenantContext->disableTenantFilter();
            }
        } else {
            // Fallback if TenantContext wasn't injected
            $filters = $this->em->getFilters();
            $isTenantFilterEnabled = $filters->has('tenant_filter') && $filters->isEnabled('tenant_filter');
            if ($isTenantFilterEnabled) {
                $filters->disable('tenant_filter');
            }
        }

        $superAdmin = $this->em->getRepository(Admin::class)->findOneBy(['accountType' => 'super_admin']);
        $globalSetting = null;
        if ($superAdmin) {
            $globalSetting = $this->em->getRepository(AiSetting::class)->findOneBy(['owner' => $superAdmin]);
        }

        if ($isTenantFilterEnabled) {
            if ($this->tenantContext && $this->tenantContext->getCurrentOwner()) {
                $this->tenantContext->enableTenantFilter($this->tenantContext->getCurrentOwner()->getId());
            } else {
                $filters->enable('tenant_filter');
            }
        }

        return $globalSetting;
    }

    public function getEffectiveSetting(?AiSetting $setting = null): ?AiSetting
    {
        if (!$setting) {
            $setting = $this->getSetting();
        }

        if ($setting && $setting->getApiKey()) {
            return $setting;
        }

        // Ollama doesn't require API key, but requires endpoint
        if ($setting && ($setting->getProvider() === 'ollama' || $setting->getProvider() === 'lmstudio')) {
            return $setting;
        }

        return $this->getGlobalSetting();
    }

    public function fetchAvailableModels(string $provider, string $apiKey, ?string $apiEndpoint = null): array
    {
        $provider = strtolower($provider);
        if (empty($apiKey) && $provider === 'lmstudio') {
            $apiKey = 'lm-studio';
        }
        if (empty($apiKey) && $provider !== 'ollama') {
            throw new \InvalidArgumentException('API Key cannot be empty.');
        }

        if ($provider === 'gemini') {
            $url = "https://generativelanguage.googleapis.com/v1beta/models?key={$apiKey}";
            try {
                $response = $this->httpClient->request('GET', $url);
                if ($response->getStatusCode() !== 200) {
                    throw new \RuntimeException('Gemini API returned status code ' . $response->getStatusCode() . ': ' . $response->getContent(false));
                }
                $data = $response->toArray();
                $models = [];
                if (isset($data['models'])) {
                    foreach ($data['models'] as $m) {
                        $name = $m['name'] ?? '';
                        if (str_starts_with($name, 'models/')) {
                            $name = substr($name, 7);
                        }
                        $methods = $m['supportedGenerationMethods'] ?? [];
                        $canGenerate = false;
                        foreach ($methods as $method) {
                            if (str_contains($method, 'generateContent')) {
                                $canGenerate = true;
                                break;
                            }
                        }
                        if ($canGenerate && !empty($name)) {
                            $models[] = [
                                'id' => $name,
                                'name' => $m['displayName'] ?? $name
                            ];
                        }
                    }
                }
                return $models;
            } catch (\Exception $e) {
                throw new \RuntimeException('Gemini Fetch Error: ' . $e->getMessage());
            }
        }

        // For OpenAI-compatible providers
        $url = '';
        $headers = [
            'Content-Type' => 'application/json'
        ];
        if (!empty($apiKey)) {
            $headers['Authorization'] = 'Bearer ' . $apiKey;
        }

        if ($provider === 'openrouter') {
            $url = 'https://openrouter.ai/api/v1/models';
        } elseif ($provider === 'kimi') {
            $url = 'https://api.moonshot.cn/v1/models';
        } elseif ($provider === 'openai') {
            $url = 'https://api.openai.com/v1/models';
        } elseif ($provider === 'deepseek') {
            $url = 'https://api.deepseek.com/models';
        } elseif ($provider === 'ollama') {
            if (empty($apiEndpoint)) {
                throw new \InvalidArgumentException('Custom API Endpoint is required for Ollama Local.');
            }
            $url = rtrim($apiEndpoint, '/') . '/models';
        } elseif ($provider === 'lmstudio') {
            $endpoint = $apiEndpoint ?: 'http://localhost:1234/v1';
            $url = rtrim($endpoint, '/') . '/models';
        } elseif ($provider === 'ollamacloud') {
            $endpoint = $apiEndpoint ?: 'https://ollama.com/v1';
            $url = rtrim($endpoint, '/') . '/models';
        } elseif ($provider === 'custom') {
            if (empty($apiEndpoint)) {
                throw new \InvalidArgumentException('Custom API Endpoint is required for this provider.');
            }
            $url = $apiEndpoint;
            if (str_contains($url, '/chat/completions')) {
                $url = str_replace('/chat/completions', '/models', $url);
            } elseif (str_contains($url, '/completions')) {
                $url = str_replace('/completions', '/models', $url);
            } else {
                $url = rtrim($url, '/') . '/models';
            }
        }

        try {
            $response = $this->httpClient->request('GET', $url, [
                'headers' => $headers
            ]);
            if ($response->getStatusCode() !== 200) {
                throw new \RuntimeException("{$provider} API returned status code " . $response->getStatusCode() . ': ' . $response->getContent(false));
            }
            $data = $response->toArray();
            $models = [];
            if (isset($data['data'])) {
                foreach ($data['data'] as $m) {
                    $id = $m['id'] ?? '';
                    if (!empty($id)) {
                        $models[] = [
                            'id' => $id,
                            'name' => $id
                        ];
                    }
                }
            }
            return $models;
        } catch (\Exception $e) {
            throw new \RuntimeException("{$provider} Fetch Error: " . $e->getMessage());
        }
    }

    public function generateResponse(string $userMessage, AiSetting $setting, object|null $connection = null): ?string
    {
        $provider = strtolower($setting->getProvider() ?? 'openai');
        $apiKey = $setting->getApiKey();
        
        if (empty($apiKey) && $provider === 'lmstudio') {
            $apiKey = 'lm-studio';
        }
        
        if (empty($apiKey) && $provider !== 'ollama') {
            $this->logger->warning('AI Agent requested but API Key is empty.');
            return null;
        }

        // Build dynamic, grounded system prompt from active context
        $systemParts = [];
        $activeContext = null;
        if ($connection) {
            $activeContext = $connection->getActiveContext();
        }
        if (!$activeContext) {
            $activeContext = $this->em->getRepository(\App\Entity\AiContext::class)->findOneBy(['isActive' => true]);
        }

        if ($activeContext) {
            if (!empty($activeContext->getName())) {
                $systemParts[] = "Your name is " . $activeContext->getName() . ".";
            }
            if (!empty($activeContext->getAgentRole())) {
                $systemParts[] = "Your role is " . $activeContext->getAgentRole() . ".";
            }
            $baseInstruction = $activeContext->getSystemInstruction() ?: ($setting->getSystemInstruction() ?? 'You are a helpful customer support chatbot assistant on WhatsApp.');
            $systemParts[] = $baseInstruction;

            if (!empty($activeContext->getContextData())) {
                $systemParts[] = "Use the following context data/knowledge base to ground your answers. Answer based on this information when possible. Do not make up information outside this context data:\n\n=== CONTEXT DATA ===\n" . $activeContext->getContextData() . "\n=== END CONTEXT DATA ===";
            }
        } else {
            if ($connection) {
                if (!empty($connection->getAgentName())) {
                    $systemParts[] = "Your name is " . $connection->getAgentName() . ".";
                }
                if (!empty($connection->getAgentRole())) {
                    $systemParts[] = "Your role is " . $connection->getAgentRole() . ".";
                }
            }
            
            $baseInstruction = $setting->getSystemInstruction() ?? 'You are a helpful customer support chatbot assistant on WhatsApp.';
            $systemParts[] = $baseInstruction;

            if ($connection && !empty($connection->getContextData())) {
                $systemParts[] = "Use the following context data/knowledge base to ground your answers. Answer based on this information when possible. Do not make up information outside this context data:\n\n=== CONTEXT DATA ===\n" . $connection->getContextData() . "\n=== END CONTEXT DATA ===";
            }
        }

        $systemInstruction = implode("\n\n", $systemParts);
        $model = $setting->getModel();

        if ($provider === 'gemini') {
            return $this->callGemini($userMessage, $apiKey, $model, $systemInstruction);
        }

        // For OpenAI, Kimi, OpenRouter, and OpenAI-Compatible (Custom)
        return $this->callOpenAiCompatible($provider, $userMessage, $apiKey, $model, $systemInstruction, $setting->getApiEndpoint());
    }

    public function generateFaqs(string $rawText, AiSetting $setting): ?string
    {
        $provider = strtolower($setting->getProvider() ?? 'openai');
        $apiKey = $setting->getApiKey();
        
        if (empty($apiKey) && $provider === 'lmstudio') {
            $apiKey = 'lm-studio';
        }
        
        if (empty($apiKey) && $provider !== 'ollama') {
            $this->logger->warning('FAQ Generation requested but API Key is empty.');
            return null;
        }

        $systemInstruction = "You are a helpful AI assistant tasked with converting unstructured business text into a clean, structured Frequently Asked Questions (FAQ) list. Output strictly in Q&A format. Do not include introductory or concluding conversational text.";
        $userMessage = "Convert the following information into an FAQ format:\n\n" . $rawText;
        $model = $setting->getModel();

        if ($provider === 'gemini') {
            return $this->callGemini($userMessage, $apiKey, $model, $systemInstruction);
        }

        return $this->callOpenAiCompatible($provider, $userMessage, $apiKey, $model, $systemInstruction, $setting->getApiEndpoint());
    }

    private function callGemini(string $userMessage, string $apiKey, ?string $model, string $systemInstruction): ?string
    {
        $activeModel = $model ?: 'gemini-1.5-flash';
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$activeModel}:generateContent?key={$apiKey}";

        try {
            $payload = [
                'systemInstruction' => [
                    'parts' => [
                        ['text' => $systemInstruction]
                    ]
                ],
                'contents' => [
                    [
                        'parts' => [
                            ['text' => $userMessage]
                        ]
                    ]
                ],
                'generationConfig' => [
                    'maxOutputTokens' => 1024
                ]
            ];

            $response = $this->httpClient->request('POST', $url, [
                'json' => $payload,
                'headers' => [
                    'Content-Type' => 'application/json'
                ]
            ]);

            if ($response->getStatusCode() !== 200) {
                $this->logger->error('Gemini API returned status code ' . $response->getStatusCode() . ': ' . $response->getContent(false));
                return null;
            }

            $data = $response->toArray();
            return $data['candidates'][0]['content']['parts'][0]['text'] ?? null;

        } catch (\Exception $e) {
            $this->logger->error('Error calling Gemini API: ' . $e->getMessage());
            return null;
        }
    }

    private function callOpenAiCompatible(
        string $provider,
        string $userMessage,
        string $apiKey,
        ?string $model,
        string $systemInstruction,
        ?string $customEndpoint
    ): ?string {
        $endpoint = $customEndpoint;
        $defaultModel = 'gpt-4o-mini';

        if (empty($endpoint)) {
            switch ($provider) {
                case 'openrouter':
                    $endpoint = 'https://openrouter.ai/api/v1/chat/completions';
                    $defaultModel = 'meta-llama/llama-3-8b-instruct:free';
                    break;
                case 'kimi':
                    $endpoint = 'https://api.moonshot.cn/v1/chat/completions';
                    $defaultModel = 'moonshot-v1-8k';
                    break;
                case 'deepseek':
                    $endpoint = 'https://api.deepseek.com/chat/completions';
                    $defaultModel = 'deepseek-chat';
                    break;
                case 'ollama':
                    $endpoint = 'http://localhost:11434/v1/chat/completions';
                    $defaultModel = 'llama3';
                    break;
                case 'lmstudio':
                    $endpoint = 'http://localhost:1234/v1/chat/completions';
                    $defaultModel = 'meta-llama-3-8b-instruct';
                    break;
                case 'ollamacloud':
                    $endpoint = 'https://ollama.com/v1/chat/completions';
                    $defaultModel = 'llama3';
                    break;
                case 'openai':
                default:
                    $endpoint = 'https://api.openai.com/v1/chat/completions';
                    $defaultModel = 'gpt-4o-mini';
                    break;
            }
        }

        if (!empty($customEndpoint) && ($provider === 'ollamacloud' || $provider === 'custom')) {
            $endpoint = $customEndpoint;
        }

        if (!empty($endpoint)) {
            if (!str_contains($endpoint, '/chat/completions') && !str_contains($endpoint, '/completions')) {
                $endpoint = rtrim($endpoint, '/') . '/chat/completions';
            }
        }

        if (empty($apiKey) && $provider === 'lmstudio') {
            $apiKey = 'lm-studio';
        }

        $activeModel = $model ?: $defaultModel;

        try {
            $messages = [];
            if (!empty($systemInstruction)) {
                $messages[] = ['role' => 'system', 'content' => $systemInstruction];
            }
            $messages[] = ['role' => 'user', 'content' => $userMessage];

            $payload = [
                'model' => $activeModel,
                'messages' => $messages,
                'max_tokens' => 1024
            ];

            $headers = [
                'Content-Type' => 'application/json'
            ];
            if (!empty($apiKey)) {
                $headers['Authorization'] = 'Bearer ' . $apiKey;
            }

            $response = $this->httpClient->request('POST', $endpoint, [
                'json' => $payload,
                'headers' => $headers
            ]);

            if ($response->getStatusCode() !== 200) {
                $this->logger->error("{$provider} API returned status code " . $response->getStatusCode() . ': ' . $response->getContent(false));
                return null;
            }

            $data = $response->toArray();
            return $data['choices'][0]['message']['content'] ?? null;

        } catch (\Exception $e) {
            $this->logger->error("Error calling {$provider} API: " . $e->getMessage());
            return null;
        }
    }
}
