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
        $connection->setEncryptedPageAccessToken('encrypted_token_here');
        $connection->setAppId('1234567890');
        $connection->setEncryptedAppSecret('encrypted_secret_here');
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
            'type' => 'welcome-screen',
            'data' => json_encode([
                'greetingText' => 'Testing interactive hello screen greeting!',
                'getStartedPayload' => 'HELLO_GREETING_PAYLOAD',
                'showGreeting' => true
            ])
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertJson($client->getResponse()->getContent());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame('Settings saved successfully.', $data['message']);

        // Verify JSON file is stored and contains saved values
        $dir = __DIR__ . '/../../var/facebook_bot_settings';
        $file = $dir . "/conn_{$connection->getId()}.json";
        $this->assertFileExists($file);
        
        $savedContent = json_decode(file_get_contents($file), true);
        $this->assertSame('Testing interactive hello screen greeting!', $savedContent['welcome-screen']['greetingText']);
        $this->assertSame('HELLO_GREETING_PAYLOAD', $savedContent['welcome-screen']['getStartedPayload']);
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
}
