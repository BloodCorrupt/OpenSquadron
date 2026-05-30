<?php

namespace App\Tests\Controller;

use App\Entity\Admin;
use App\Entity\FacebookConnection;
use App\Entity\FacebookBotFlow;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class FacebookBotManagerControllerTest extends WebTestCase
{
    private function createAndLoginAdmin($client): FacebookConnection
    {
        $container = self::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get(UserPasswordHasherInterface::class);
        $facebookService = $container->get(\App\Service\FacebookService::class);

        // Delete any existing test admin and Facebook connections/flows
        $existingAdmin = $em->getRepository(Admin::class)->findOneBy(['email' => 'test_bot_mgr@admin.local']);
        if ($existingAdmin) {
            $em->remove($existingAdmin);
        }

        $flows = $em->getRepository(FacebookBotFlow::class)->findAll();
        foreach ($flows as $f) {
            $em->remove($f);
        }

        $connections = $em->getRepository(FacebookConnection::class)->findAll();
        foreach ($connections as $c) {
            $em->remove($c);
        }
        
        $em->flush();

        $admin = new Admin();
        $admin->setEmail('test_bot_mgr@admin.local');
        $admin->setRoles(['ROLE_ADMIN']);
        $admin->setPassword($hasher->hashPassword($admin, 'testpass'));
        
        $em->persist($admin);

        // Create a mock FacebookConnection
        $connection = new FacebookConnection();
        $connection->setOwner($admin);
        $connection->setPageId('98765432101');
        $connection->setPageName('Test Management Page');
        $connection->setEncryptedPageAccessToken($facebookService->encryptToken('mock_page_token'));
        $connection->setAppId('1234567890');
        $connection->setEncryptedAppSecret($facebookService->encryptToken('mock_app_secret'));
        $connection->setVerifyToken('test_verify_token');
        $connection->setStatus('active');
        $connection->setAiActive(false);

        $em->persist($connection);
        $em->flush();

        $client->loginUser($admin);

        return $connection;
    }

    public function testBotManagerDashboardLoads(): void
    {
        $client = static::createClient();
        $connection = $this->createAndLoginAdmin($client);

        // 1. Get dashboard page
        $client->request('GET', '/facebook-bot-manager');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('.manager-shell');
        $this->assertSelectorTextContains('.content-title span', 'Bot Management Console');
    }

    public function testSaveSettingsEndpoint(): void
    {
        $client = static::createClient();
        $connection = $this->createAndLoginAdmin($client);

        // 2. Submit saved settings via POST
        $client->request('POST', '/facebook-bot-manager/save-settings', [
            'connectionId' => $connection->getId(),
            'type' => 'copilot-settings',
            'data' => json_encode([
                'enableIntentRouting' => true,
                'intentCampaign' => 'test_campaign',
                'routingProtocol' => 'omnipresent'
            ])
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertJson($client->getResponse()->getContent());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame('Settings saved successfully.', $data['message']);

        // Verify database is updated and contains saved values
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $updatedConnection = $em->getRepository(FacebookConnection::class)->find($connection->getId());
        $savedContent = $updatedConnection->getBotSettings();
        $this->assertTrue($savedContent['copilot-settings']['enableIntentRouting']);
        $this->assertSame('test_campaign', $savedContent['copilot-settings']['intentCampaign']);
    }

    public function testFlowsEndpoints(): void
    {
        $client = static::createClient();
        $connection = $this->createAndLoginAdmin($client);

        // 1. View flows page
        $client->request('GET', '/facebook-bot-manager/flows', ['connectionId' => $connection->getId()]);
        $this->assertResponseIsSuccessful();

        // 2. Save a new flow
        $client->request('POST', '/facebook-bot-manager/flows/save', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'connectionId' => $connection->getId(),
            'name' => 'Support Flow Unit Test',
            'keywords' => 'help, support, agent',
            'matchMode' => 'exact',
            'isActive' => true,
            'graph' => [
                'nodes' => [
                    ['id' => '1', 'type' => 'trigger', 'data' => ['label' => 'help']]
                ],
                'edges' => []
            ]
        ]));

        $this->assertResponseIsSuccessful();
        $responseContent = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($responseContent['success']);
        $flowId = $responseContent['flow']['id'];

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $savedFlow = $em->getRepository(FacebookBotFlow::class)->find($flowId);
        $this->assertNotNull($savedFlow);
        $this->assertSame('Support Flow Unit Test', $savedFlow->getName());
        $this->assertSame('help,support,agent', $savedFlow->getTriggerKeyword());

        // 3. Toggle flow
        $client->request('POST', "/facebook-bot-manager/flows/{$flowId}/toggle", [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'isActive' => false
        ]));
        $this->assertResponseIsSuccessful();
        $toggleResponse = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($toggleResponse['success']);
        $this->assertFalse($toggleResponse['isActive']);

        // 4. Clone flow
        $client->request('POST', "/facebook-bot-manager/flows/{$flowId}/clone");
        $this->assertResponseIsSuccessful();
        $cloneResponse = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($cloneResponse['success']);

        // Verify cloned flow exists in database by using fresh EntityManager
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $allFlows = $em->getRepository(FacebookBotFlow::class)->findAll();
        $this->assertCount(2, $allFlows);

        // 5. Export flow
        $client->request('GET', "/facebook-bot-manager/flows/{$flowId}/export");
        $this->assertResponseIsSuccessful();
        $this->assertSame('application/json', $client->getResponse()->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment; filename=', $client->getResponse()->headers->get('Content-Disposition'));

        // 6. Delete flow
        $client->request('POST', "/facebook-bot-manager/flows/{$flowId}/delete");
        $this->assertResponseIsSuccessful();
        $deleteResponse = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($deleteResponse['success']);
        
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $deletedFlow = $em->getRepository(FacebookBotFlow::class)->find($flowId);
        $this->assertNull($deletedFlow);
    }

    public function testSaveAiAgentSettings(): void
    {
        $client = static::createClient();
        $connection = $this->createAndLoginAdmin($client);

        // Submit AI Agent settings
        $client->request('POST', '/facebook-bot-manager/ai-settings/agent', [
            'connectionId' => $connection->getId(),
            'aiActive' => '1',
            'agentName' => 'OpenSquadron Bot Copilot',
            'agentRole' => 'Sales Assistant',
            'contextData' => 'This is a premium context background for test.'
        ]);

        $this->assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame('Facebook AI Agent Settings saved successfully.', $data['message']);

        // Verify database using fresh EntityManager and findOneBy
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        
        $updatedConnection = $em->getRepository(FacebookConnection::class)->findOneBy([]);
        $this->assertNotNull($updatedConnection);
        $this->assertTrue($updatedConnection->isAiActive());
        $this->assertSame('OpenSquadron Bot Copilot', $updatedConnection->getAgentName());
        $this->assertSame('Sales Assistant', $updatedConnection->getAgentRole());
        $this->assertSame('This is a premium context background for test.', $updatedConnection->getContextData());
    }

    public function testSavePersistentMenuSyncsToFacebook(): void
    {
        $client = static::createClient();

        // Mock HttpClient to intercept Graph API calls
        $mockResponse = $this->createMock(\Symfony\Contracts\HttpClient\ResponseInterface::class);
        $mockResponse->method('getStatusCode')->willReturn(200);
        $mockResponse->method('toArray')->willReturn(['success' => true]);

        $mockHttpClient = $this->createMock(\Symfony\Contracts\HttpClient\HttpClientInterface::class);
        $mockHttpClient->expects($this->once())
            ->method('request')
            ->with('POST', 'https://graph.facebook.com/v21.0/me/messenger_profile')
            ->willReturn($mockResponse);

        self::getContainer()->set(\Symfony\Contracts\HttpClient\HttpClientInterface::class, $mockHttpClient);

        $connection = $this->createAndLoginAdmin($client);

        $client->request('POST', '/facebook-bot-manager/save-settings', [
            'connectionId' => $connection->getId(),
            'type' => 'persistent-menu',
            'data' => json_encode([
                ['title' => '🏠 Home Portal', 'type' => 'postback', 'payload' => 'MAIN_MENU_TRIGGER'],
                ['title' => '🛍️ View Products', 'type' => 'web_url', 'url' => 'https://opensquadron.io/shop']
            ])
        ]);

        $this->assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($data['success']);

        // Verify database is updated and contains saved values
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $updatedConnection = $em->getRepository(FacebookConnection::class)->find($connection->getId());
        $savedContent = $updatedConnection->getBotSettings();
        $this->assertCount(2, $savedContent['persistent-menu']);
        $this->assertSame('🏠 Home Portal', $savedContent['persistent-menu'][0]['title']);
        $this->assertSame('web_url', $savedContent['persistent-menu'][1]['type']);
        $this->assertSame('https://opensquadron.io/shop', $savedContent['persistent-menu'][1]['url']);
    }

    public function testSyncPersistentMenuFromFacebook(): void
    {
        $client = static::createClient();

        // Mock HttpClient for GET request to messenger_profile
        $mockResponse = $this->createMock(\Symfony\Contracts\HttpClient\ResponseInterface::class);
        $mockResponse->method('getStatusCode')->willReturn(200);
        $mockResponse->method('toArray')->willReturn([
            'data' => [
                [
                    'persistent_menu' => [
                        [
                            'locale' => 'default',
                            'call_to_actions' => [
                                ['title' => '🏠 Synced Home', 'type' => 'postback', 'payload' => 'SYNCED_HOME_PAYLOAD'],
                                ['title' => '🌐 Website', 'type' => 'web_url', 'url' => 'https://synced.com']
                            ]
                        ]
                    ]
                ]
            ]
        ]);

        $mockHttpClient = $this->createMock(\Symfony\Contracts\HttpClient\HttpClientInterface::class);
        $mockHttpClient->expects($this->once())
            ->method('request')
            ->with('GET', 'https://graph.facebook.com/v21.0/me/messenger_profile')
            ->willReturn($mockResponse);

        self::getContainer()->set(\Symfony\Contracts\HttpClient\HttpClientInterface::class, $mockHttpClient);

        $connection = $this->createAndLoginAdmin($client);

        $client->request('POST', '/facebook-bot-manager/sync-persistent-menu', [
            'connectionId' => $connection->getId()
        ]);

        $this->assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertCount(2, $data['data']);
        $this->assertSame('🏠 Synced Home', $data['data'][0]['title']);
        $this->assertSame('SYNCED_HOME_PAYLOAD', $data['data'][0]['payload']);

        // Verify it was saved to the database
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $updatedConnection = $em->getRepository(FacebookConnection::class)->find($connection->getId());
        $savedContent = $updatedConnection->getBotSettings();
        $this->assertSame('🏠 Synced Home', $savedContent['persistent-menu'][0]['title']);
    }

    public function testSaveWelcomeScreenSyncsToFacebook(): void
    {
        $client = static::createClient();

        // Mock HttpClient to intercept Graph API calls
        $mockPostResponse = $this->createMock(\Symfony\Contracts\HttpClient\ResponseInterface::class);
        $mockPostResponse->method('getStatusCode')->willReturn(200);
        $mockPostResponse->method('toArray')->willReturn(['success' => true]);

        $mockHttpClient = $this->createMock(\Symfony\Contracts\HttpClient\HttpClientInterface::class);
        
        // It should call POST to messenger_profile to update greeting & get_started
        $mockHttpClient->expects($this->once())
            ->method('request')
            ->with('POST', 'https://graph.facebook.com/v21.0/me/messenger_profile')
            ->willReturn($mockPostResponse);

        self::getContainer()->set(\Symfony\Contracts\HttpClient\HttpClientInterface::class, $mockHttpClient);

        $connection = $this->createAndLoginAdmin($client);

        $client->request('POST', '/facebook-bot-manager/save-settings', [
            'connectionId' => $connection->getId(),
            'type' => 'welcome-screen',
            'data' => json_encode([
                'greetingText' => 'Testing interactive hello screen greeting!',
                'getStartedStatus' => 'enabled',
                'getStartedPayload' => 'HELLO_GREETING_PAYLOAD',
                'showGreeting' => true,
                'iceBreakersStatus' => 'enabled',
                'iceBreakers' => [
                    ['question' => 'How can I contact support?', 'payload' => 'SUPPORT_FLOW_PAYLOAD'],
                    ['question' => 'What are your products?', 'payload' => 'FLOW_ID_12']
                ]
            ])
        ]);

        $this->assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($data['success']);

        // Verify database is updated and contains saved values
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $updatedConnection = $em->getRepository(FacebookConnection::class)->find($connection->getId());
        $savedContent = $updatedConnection->getBotSettings();
        $this->assertSame('Testing interactive hello screen greeting!', $savedContent['welcome-screen']['greetingText']);
        $this->assertSame('HELLO_GREETING_PAYLOAD', $savedContent['welcome-screen']['getStartedPayload']);
        $this->assertSame('enabled', $savedContent['welcome-screen']['getStartedStatus']);
        $this->assertSame('enabled', $savedContent['welcome-screen']['iceBreakersStatus']);
        $this->assertCount(2, $savedContent['welcome-screen']['iceBreakers']);
        $this->assertSame('How can I contact support?', $savedContent['welcome-screen']['iceBreakers'][0]['question']);
    }

    public function testSyncWelcomeScreenFromFacebook(): void
    {
        $client = static::createClient();

        // Mock HttpClient for GET request to messenger_profile
        $mockResponse = $this->createMock(\Symfony\Contracts\HttpClient\ResponseInterface::class);
        $mockResponse->method('getStatusCode')->willReturn(200);
        $mockResponse->method('toArray')->willReturn([
            'data' => [
                [
                    'greeting' => [
                        [
                            'locale' => 'default',
                            'text' => 'Welcome Synced greeting text!'
                        ]
                    ],
                    'get_started' => [
                        'payload' => 'WELCOME_GET_STARTED_SYNCED'
                    ],
                    'ice_breakers' => [
                        [
                            'locale' => 'default',
                            'call_to_actions' => [
                                ['question' => 'Synced FAQ 1', 'payload' => 'SYNCED_FAQ_1_PAYLOAD'],
                                ['question' => 'Synced FAQ 2', 'payload' => 'FLOW_ID_100']
                            ]
                        ]
                    ]
                ]
            ]
        ]);

        $mockHttpClient = $this->createMock(\Symfony\Contracts\HttpClient\HttpClientInterface::class);
        $mockHttpClient->expects($this->once())
            ->method('request')
            ->with('GET', 'https://graph.facebook.com/v21.0/me/messenger_profile')
            ->willReturn($mockResponse);

        self::getContainer()->set(\Symfony\Contracts\HttpClient\HttpClientInterface::class, $mockHttpClient);

        $connection = $this->createAndLoginAdmin($client);

        $client->request('POST', '/facebook-bot-manager/sync-welcome-screen', [
            'connectionId' => $connection->getId()
        ]);

        $this->assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame('Welcome Synced greeting text!', $data['data']['greetingText']);
        $this->assertSame('WELCOME_GET_STARTED_SYNCED', $data['data']['getStartedPayload']);
        $this->assertTrue($data['data']['showGreeting']);
        $this->assertSame('enabled', $data['data']['iceBreakersStatus']);
        $this->assertCount(2, $data['data']['iceBreakers']);
        $this->assertSame('Synced FAQ 1', $data['data']['iceBreakers'][0]['question']);

        // Verify it was saved to the database
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $updatedConnection = $em->getRepository(FacebookConnection::class)->find($connection->getId());
        $savedContent = $updatedConnection->getBotSettings();
        $this->assertSame('Welcome Synced greeting text!', $savedContent['welcome-screen']['greetingText']);
    }

    public function testSyncWelcomeScreenFromFacebookPreservesLocalDraftSettings(): void
    {
        $client = static::createClient();

        // 1. Mock HttpClient for GET request returning no settings (e.g. empty data)
        $mockResponse = $this->createMock(\Symfony\Contracts\HttpClient\ResponseInterface::class);
        $mockResponse->method('getStatusCode')->willReturn(200);
        $mockResponse->method('toArray')->willReturn([
            'data' => []
        ]);

        $mockHttpClient = $this->createMock(\Symfony\Contracts\HttpClient\HttpClientInterface::class);
        $mockHttpClient->expects($this->once())
            ->method('request')
            ->with('GET', 'https://graph.facebook.com/v21.0/me/messenger_profile')
            ->willReturn($mockResponse);

        self::getContainer()->set(\Symfony\Contracts\HttpClient\HttpClientInterface::class, $mockHttpClient);

        $connection = $this->createAndLoginAdmin($client);

        // 2. Pre-populate database settings with some draft values
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $connectionEntity = $em->getRepository(FacebookConnection::class)->find($connection->getId());
        $localSettings = [
            'welcome-screen' => [
                'showGreeting' => true,
                'greetingText' => 'Custom Local Draft Text',
                'getStartedStatus' => 'disabled',
                'getStartedPayload' => 'FLOW_ID_999',
                'iceBreakersStatus' => 'enabled',
                'iceBreakers' => [
                    ['question' => 'My Draft FAQ', 'payload' => 'FAQ_PAYLOAD']
                ]
            ]
        ];
        $connectionEntity->setBotSettings($localSettings);
        $em->flush();

        // 3. Trigger sync
        $client->request('POST', '/facebook-bot-manager/sync-welcome-screen', [
            'connectionId' => $connection->getId()
        ]);

        $this->assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($data['success']);

        // 4. Assert local draft settings were preserved
        $this->assertFalse($data['data']['showGreeting']);
        $this->assertSame('Custom Local Draft Text', $data['data']['greetingText']);
        $this->assertSame('disabled', $data['data']['getStartedStatus']);
        $this->assertSame('FLOW_ID_999', $data['data']['getStartedPayload']);
        // Since Facebook returned no ice_breakers, status becomes disabled but draft questions remain preserved
        $this->assertSame('disabled', $data['data']['iceBreakersStatus']);
        $this->assertCount(1, $data['data']['iceBreakers']);
        $this->assertSame('My Draft FAQ', $data['data']['iceBreakers'][0]['question']);

        // Verify the database content
        $em->clear();
        $updatedConnection = $em->getRepository(FacebookConnection::class)->find($connection->getId());
        $savedContent = $updatedConnection->getBotSettings();
        $this->assertSame('Custom Local Draft Text', $savedContent['welcome-screen']['greetingText']);
        $this->assertSame('FLOW_ID_999', $savedContent['welcome-screen']['getStartedPayload']);
    }

    public function testSequenceBuilderEndpoints(): void
    {
        $client = static::createClient();
        $connection = $this->createAndLoginAdmin($client);

        // 1. Load sequence builder page (GET)
        $client->request('GET', '/facebook-bot-manager/sequence-builder', [
            'connectionId' => $connection->getId()
        ]);
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('.fb-shell');

        // 2. Save new sequence (POST) — id is null for brand-new sequences
        $client->request('POST', '/facebook-bot-manager/sequence-builder/save', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'id' => null,
            'connectionId' => $connection->getId(),
            'name' => 'Test Sequence Flow Name',
            'preferredTime' => 'business_hours',
            'timezone' => 'America/New_York',
            'messageTag' => 'ACCOUNT_UPDATE',
            'allowReentry' => true,
            'isActive' => true,
            'graph' => [
                'nodes' => [
                    ['id' => 'start_node', 'type' => 'start', 'x' => 100, 'y' => 100],
                    ['id' => 'campaign_node', 'type' => 'sequence_campaign', 'x' => 300, 'y' => 100],
                    ['id' => 'msg_node', 'type' => 'send_message_after', 'x' => 500, 'y' => 100, 'data' => ['delayNumber' => 12, 'delayUnit' => 'hours']]
                ],
                'edges' => [
                    ['id' => 'e1', 'source' => 'start_node', 'sourceHandle' => 'subscribe_to_sequence', 'target' => 'campaign_node', 'targetHandle' => 'in'],
                    ['id' => 'e2', 'source' => 'campaign_node', 'sourceHandle' => 'schedule_message', 'target' => 'msg_node', 'targetHandle' => 'in']
                ],
                'viewport' => ['x' => 0, 'y' => 0, 'scale' => 1]
            ]
        ]));

        $this->assertResponseIsSuccessful();
        $res = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($res['success']);
        $this->assertSame('Test Sequence Flow Name', $res['sequence']['name']);
        $this->assertSame(1, $res['sequence']['stepsCount']);
        $this->assertIsInt($res['sequence']['id']);

        // 3. Update the same sequence by passing the returned DB ID
        $savedId = $res['sequence']['id'];
        $client->request('POST', '/facebook-bot-manager/sequence-builder/save', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'id' => $savedId,
            'connectionId' => $connection->getId(),
            'name' => 'Updated Sequence Name',
            'preferredTime' => 'business_hours',
            'timezone' => 'America/New_York',
            'messageTag' => 'ACCOUNT_UPDATE',
            'allowReentry' => true,
            'isActive' => true,
            'graph' => [
                'nodes' => [
                    ['id' => 'start_node', 'type' => 'start', 'x' => 100, 'y' => 100],
                    ['id' => 'campaign_node', 'type' => 'sequence_campaign', 'x' => 300, 'y' => 100],
                    ['id' => 'msg_node', 'type' => 'send_message_after', 'x' => 500, 'y' => 100, 'data' => ['delayNumber' => 12, 'delayUnit' => 'hours']]
                ],
                'edges' => [
                    ['id' => 'e1', 'source' => 'start_node', 'sourceHandle' => 'subscribe_to_sequence', 'target' => 'campaign_node', 'targetHandle' => 'in'],
                    ['id' => 'e2', 'source' => 'campaign_node', 'sourceHandle' => 'schedule_message', 'target' => 'msg_node', 'targetHandle' => 'in']
                ],
                'viewport' => ['x' => 0, 'y' => 0, 'scale' => 1]
            ]
        ]));

        $this->assertResponseIsSuccessful();
        $resUpdate = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($resUpdate['success']);
        $this->assertSame($savedId, $resUpdate['sequence']['id']);
        $this->assertSame('Updated Sequence Name', $resUpdate['sequence']['name']);

        // 4. Delete the sequence
        $client->request('POST', '/facebook-bot-manager/sequence-builder/delete', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'sequenceId' => $savedId
        ]));
        $this->assertResponseIsSuccessful();
        $resDel = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($resDel['success']);
    }

    public function testSaveActionButtons(): void
    {
        $client = static::createClient();
        $connection = $this->createAndLoginAdmin($client);
        $em = self::getContainer()->get(EntityManagerInterface::class);

        // 1. Verify dashboard load seeds 7 action buttons automatically
        $client->request('GET', '/facebook-bot-manager');
        $this->assertResponseIsSuccessful();

        $em->clear();
        $seededButtons = $em->getRepository(\App\Entity\FacebookActionButton::class)->findBy(['facebookConnection' => $connection]);
        $this->assertCount(7, $seededButtons);

        // 2. Save settings for Action Buttons
        $client->request('POST', '/facebook-bot-manager/action-buttons/save', [
            'connectionId' => $connection->getId(),
            'data' => json_encode([
                [
                    'buttonKey' => 'get-started',
                    'buttonLabel' => 'Get-started Custom',
                    'isEnabled' => true,
                    'replyType' => 'text',
                    'replyText' => 'Welcome back!',
                    'flowId' => null
                ],
                [
                    'buttonKey' => 'no-match',
                    'buttonLabel' => 'No Match Custom',
                    'isEnabled' => false,
                    'replyType' => 'none',
                    'replyText' => null,
                    'flowId' => null
                ]
            ])
        ]);

        $this->assertResponseIsSuccessful();
        $res = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($res['success']);
        $this->assertSame('Action button templates saved successfully.', $res['message']);

        // 3. Verify they were updated in MySQL database
        $em->clear();
        $btn = $em->getRepository(\App\Entity\FacebookActionButton::class)->findOneBy([
            'facebookConnection' => $connection,
            'buttonKey' => 'get-started'
        ]);
        $this->assertNotNull($btn);
        $this->assertSame('Get-started Custom', $btn->getButtonLabel());
        $this->assertTrue($btn->isEnabled());
        $this->assertSame('text', $btn->getReplyType());
        $this->assertSame('Welcome back!', $btn->getReplyText());

        $btnNoMatch = $em->getRepository(\App\Entity\FacebookActionButton::class)->findOneBy([
            'facebookConnection' => $connection,
            'buttonKey' => 'no-match'
        ]);
        $this->assertNotNull($btnNoMatch);
        $this->assertSame('No Match Custom', $btnNoMatch->getButtonLabel());
        $this->assertFalse($btnNoMatch->isEnabled());
    }
}
