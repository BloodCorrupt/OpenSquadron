<?php

namespace App\Tests\Controller;

use App\Entity\Admin;
use App\Entity\WhatsAppConnection;
use App\Entity\WhatsappBotFlow;
use App\Entity\MessageTemplate;
use App\Service\WhatsAppConnectionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class WhatsappBotManagerControllerTest extends WebTestCase
{
    private function createAndLoginAdmin($client): WhatsAppConnection
    {
        $container = self::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get(UserPasswordHasherInterface::class);

        // Delete any existing test admin and WhatsApp connections/flows/templates
        $existingAdmin = $em->getRepository(Admin::class)->findOneBy(['email' => 'test_wa_bot_mgr@admin.local']);
        if ($existingAdmin) {
            $em->remove($existingAdmin);
        }

        $flows = $em->getRepository(WhatsappBotFlow::class)->findAll();
        foreach ($flows as $f) {
            $em->remove($f);
        }

        $templates = $em->getRepository(MessageTemplate::class)->findAll();
        foreach ($templates as $t) {
            $em->remove($t);
        }

        $connections = $em->getRepository(WhatsAppConnection::class)->findAll();
        foreach ($connections as $c) {
            $em->remove($c);
        }
        
        $em->flush();

        $admin = new Admin();
        $admin->setEmail('test_wa_bot_mgr@admin.local');
        $admin->setRoles(['ROLE_ADMIN']);
        $admin->setPassword($hasher->hashPassword($admin, 'testpass'));
        
        $em->persist($admin);

        // Create a mock WhatsAppConnection explicitly owned by this Admin for Tenant context matching
        $connection = new WhatsAppConnection();
        $connection->setOwner($admin);
        $connection->setBusinessAccountId('123456789012345');
        $connection->setPhoneNumberId('123456789012345');
        $connection->setLabel('Test Management WA');
        $connection->setPhoneNumber('+15550199');
        $connection->setEncryptedAccessToken('encrypted_token_here');
        $connection->setVerifyToken('test_verify_token');
        $connection->setStatus('active');
        $connection->setAiActive(false);

        $em->persist($connection);
        $em->flush();

        $client->loginUser($admin);

        return $connection;
    }

    public function testWhatsappBotManagerDashboardLoads(): void
    {
        $client = static::createClient();
        $connection = $this->createAndLoginAdmin($client);

        // 1. Get dashboard page
        $client->request('GET', '/whatsapp-bot-manager');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('.manager-shell');
        $this->assertSelectorTextContains('.content-title span', 'WhatsApp Bot Console');
    }

    public function testSaveSettingsEndpoint(): void
    {
        $client = static::createClient();
        $connection = $this->createAndLoginAdmin($client);

        // 2. Submit saved settings via POST
        $client->request('POST', '/whatsapp-bot-manager/save-settings', [
            'connectionId' => $connection->getId(),
            'type' => 'ecommerce-automations',
            'data' => json_encode([
                'abandonedCartActive' => true,
                'abandonedCartDelay' => '15 minutes',
                'abandonedCartTemplate' => 'custom_cart_reminder',
                'orderConfirmationActive' => true,
                'orderConfirmationTemplate' => 'custom_order_conf'
            ])
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertJson($client->getResponse()->getContent());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame('Settings saved successfully.', $data['message']);

        // Verify JSON file is stored and contains saved values
        $dir = __DIR__ . '/../../var/whatsapp_bot_settings';
        $file = $dir . "/conn_{$connection->getId()}.json";
        $this->assertFileExists($file);
        
        $savedContent = json_decode(file_get_contents($file), true);
        $this->assertTrue($savedContent['ecommerce-automations']['abandonedCartActive']);
        $this->assertSame('15 minutes', $savedContent['ecommerce-automations']['abandonedCartDelay']);
        $this->assertSame('custom_cart_reminder', $savedContent['ecommerce-automations']['abandonedCartTemplate']);
    }

    public function testFlowsEndpoints(): void
    {
        $client = static::createClient();
        $connection = $this->createAndLoginAdmin($client);

        // 1. View flows page
        $client->request('GET', '/whatsapp-bot-manager/flows', ['connectionId' => $connection->getId()]);
        $this->assertResponseIsSuccessful();

        // 2. Save a new flow
        $client->request('POST', '/whatsapp-bot-manager/flows/save', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'connectionId' => $connection->getId(),
            'name' => 'WA Support Flow Test',
            'keywords' => 'help, support, ticket',
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
        $savedFlow = $em->getRepository(WhatsappBotFlow::class)->find($flowId);
        $this->assertNotNull($savedFlow);
        $this->assertSame('WA Support Flow Test', $savedFlow->getName());
        $this->assertSame('help,support,ticket', $savedFlow->getTriggerKeyword());

        // 3. Toggle flow
        $client->request('POST', "/whatsapp-bot-manager/flows/{$flowId}/toggle", [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'isActive' => false
        ]));
        $this->assertResponseIsSuccessful();
        $toggleResponse = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($toggleResponse['success']);
        $this->assertFalse($toggleResponse['isActive']);

        // 4. Clone flow
        $client->request('POST', "/whatsapp-bot-manager/flows/{$flowId}/clone");
        $this->assertResponseIsSuccessful();
        $cloneResponse = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($cloneResponse['success']);

        // Verify cloned flow exists in database by using fresh EntityManager
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $allFlows = $em->getRepository(WhatsappBotFlow::class)->findAll();
        $this->assertCount(2, $allFlows);

        // 5. Export flow
        $client->request('GET', "/whatsapp-bot-manager/flows/{$flowId}/export");
        $this->assertResponseIsSuccessful();
        $this->assertSame('application/json', $client->getResponse()->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment; filename=', $client->getResponse()->headers->get('Content-Disposition'));

        // 6. Delete flow
        $client->request('POST', "/whatsapp-bot-manager/flows/{$flowId}/delete");
        $this->assertResponseIsSuccessful();
        $deleteResponse = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($deleteResponse['success']);
        
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $deletedFlow = $em->getRepository(WhatsappBotFlow::class)->find($flowId);
        $this->assertNull($deletedFlow);
    }

    public function testSaveAiAgentSettings(): void
    {
        $client = static::createClient();
        $connection = $this->createAndLoginAdmin($client);

        // Submit AI Agent settings
        $client->request('POST', '/whatsapp-bot-manager/ai-settings/agent', [
            'connectionId' => $connection->getId(),
            'aiActive' => '1',
            'agentName' => 'OpenSquadron WA AI Copilot',
            'agentRole' => 'Helpdesk Specialist',
            'contextData' => 'This is a premium context background for WA test.'
        ]);

        $this->assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame('WhatsApp AI Agent Settings saved successfully.', $data['message']);

        // Verify database using fresh EntityManager and findOneBy
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        
        $updatedConnection = $em->getRepository(WhatsAppConnection::class)->findOneBy([]);
        $this->assertNotNull($updatedConnection);
        $this->assertTrue($updatedConnection->isAiActive());
        $this->assertSame('OpenSquadron WA AI Copilot', $updatedConnection->getAgentName());
        $this->assertSame('Helpdesk Specialist', $updatedConnection->getAgentRole());
        $this->assertSame('This is a premium context background for WA test.', $updatedConnection->getContextData());
    }

    public function testTemplatesEndpoints(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $connection = $this->createAndLoginAdmin($client);

        $container = self::getContainer();

        // Mock WhatsAppConnectionService
        $whatsappServiceMock = $this->createMock(WhatsAppConnectionService::class);
        $whatsappServiceMock->expects($this->exactly(2))
            ->method('syncTemplates')
            ->willReturn(['success' => true, 'count' => 3]);

        $whatsappServiceMock->expects($this->once())
            ->method('createTemplate')
            ->willReturn(['success' => true, 'id' => 'template_12345']);

        $container->set(WhatsAppConnectionService::class, $whatsappServiceMock);

        // 1. Templates redirect
        $client->request('GET', '/whatsapp-bot-manager/templates');
        $this->assertResponseRedirects('/whatsapp-bot-manager?tab=templates');

        // 2. Templates Sync
        $client->request('POST', '/whatsapp-bot-manager/templates/sync', [
            'connectionId' => $connection->getId()
        ]);
        $this->assertResponseIsSuccessful();
        $dataSync = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($dataSync['success']);
        $this->assertSame('Successfully synced 3 approved templates.', $dataSync['message']);

        // 3. Template Creation Submit
        $client->request('POST', '/whatsapp-bot-manager/templates/create', [
            'connectionId' => $connection->getId(),
            'name' => 'WA Test Template Submission',
            'language' => 'en_US',
            'category' => 'UTILITY',
            'body' => 'Welcome to OpenSquadron WA! Your registration code is {{1}}.',
            'header' => 'Registration Successful',
            'footer' => 'Do not share this code.'
        ]);
        $this->assertResponseIsSuccessful();
        $dataCreate = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($dataCreate['success']);
        $this->assertSame('Template submitted to Meta! It will appear as APPROVED once Meta reviews it.', $dataCreate['message']);
        $this->assertSame('template_12345', $dataCreate['id']);
    }

    public function testSequenceBuilderEndpoints(): void
    {
        $client = static::createClient();
        $connection = $this->createAndLoginAdmin($client);

        // 1. Load sequence builder page (GET)
        $client->request('GET', '/whatsapp-bot-manager/sequence-builder', [
            'connectionId' => $connection->getId()
        ]);
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('.fb-shell');

        // 2. Save new sequence (POST) — id is null for brand-new sequences
        $client->request('POST', '/whatsapp-bot-manager/sequence-builder/save', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'id' => null,
            'connectionId' => $connection->getId(),
            'name' => 'Test WhatsApp Sequence',
            'trigger' => 'NEW_SUBSCRIBER',
            'preferredTime' => 'daytime',
            'timezone' => 'UTC',
            'allowReentry' => false,
            'isActive' => true,
            'graph' => [
                'nodes' => [
                    ['id' => 'start_node', 'type' => 'start', 'x' => 100, 'y' => 100],
                    ['id' => 'campaign_node', 'type' => 'sequence_campaign', 'x' => 300, 'y' => 100],
                    ['id' => 'msg_node', 'type' => 'send_message_after', 'x' => 500, 'y' => 100, 'data' => ['delayNumber' => 24, 'delayUnit' => 'hours']]
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
        $this->assertSame('Test WhatsApp Sequence', $res['sequence']['name']);
        $this->assertSame(1, $res['sequence']['stepsCount']);
        $this->assertIsInt($res['sequence']['id']);

        // 3. Update the same sequence by passing the returned DB ID
        $savedId = $res['sequence']['id'];
        $client->request('POST', '/whatsapp-bot-manager/sequence-builder/save', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'id' => $savedId,
            'connectionId' => $connection->getId(),
            'name' => 'Updated WhatsApp Sequence',
            'trigger' => 'CUSTOM_TRIGGER',
            'preferredTime' => 'business_hours',
            'timezone' => 'UTC',
            'allowReentry' => true,
            'isActive' => true,
            'graph' => [
                'nodes' => [
                    ['id' => 'start_node', 'type' => 'start', 'x' => 100, 'y' => 100],
                    ['id' => 'campaign_node', 'type' => 'sequence_campaign', 'x' => 300, 'y' => 100],
                    ['id' => 'msg_node', 'type' => 'send_message_after', 'x' => 500, 'y' => 100, 'data' => ['delayNumber' => 24, 'delayUnit' => 'hours']]
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
        $this->assertSame('Updated WhatsApp Sequence', $resUpdate['sequence']['name']);

        // 4. Delete the sequence
        $client->request('POST', '/whatsapp-bot-manager/sequence-builder/delete', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'sequenceId' => $savedId
        ]));
        $this->assertResponseIsSuccessful();
        $resDel = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($resDel['success']);
    }
}

