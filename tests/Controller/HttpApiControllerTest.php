<?php

namespace App\Tests\Controller;

use App\Entity\Admin;
use App\Entity\HttpApi;
use App\Entity\HttpApiCallLog;
use App\Entity\Subscriber;
use App\Entity\WhatsappBotFlow;
use App\Service\WhatsappBotFlowExecutor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class HttpApiControllerTest extends WebTestCase
{
    private function createAndLoginAdmin($client): Admin
    {
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get(UserPasswordHasherInterface::class);

        // Delete any existing test admin to prevent duplications
        $existingAdmin = $em->getRepository(Admin::class)->findOneBy(['email' => 'test@httpapi.local']);
        if ($existingAdmin) {
            $em->remove($existingAdmin);
            $em->flush();
        }

        $admin = new Admin();
        $admin->setEmail('test@httpapi.local');
        $admin->setRoles(['ROLE_ADMIN']);
        $admin->setPassword($hasher->hashPassword($admin, 'testpass'));
        
        $em->persist($admin);
        $em->flush();

        $client->loginUser($admin);

        return $admin;
    }

    public function testIndexRouteRequiresAuthentication(): void
    {
        $client = static::createClient();
        $client->request('GET', '/settings/http-api');

        // Redirects to login
        $this->assertResponseRedirects('/login');
    }

    public function testIndexRouteWithAuthentication(): void
    {
        $client = static::createClient();
        $this->createAndLoginAdmin($client);

        $client->request('GET', '/settings/http-api');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1.page-title', 'Global HTTP API Manager');
        $this->assertSelectorExists('table.glass-table');
    }

    public function testSaveHttpApi(): void
    {
        $client = static::createClient();
        $this->createAndLoginAdmin($client);

        $client->request('POST', '/settings/http-api/save', [
            'name' => 'Stripe Webhook Gateway',
            'endpointUrl' => 'https://api.stripe.com/v1/webhooks',
            'method' => 'POST',
            'channel' => 'global',
            'status' => 'active',
            'bodyType' => 'JSON',
            'bodyData' => '{"subscriber_name": "{{subscriber.name}}"}',
            'headers' => json_encode([['key' => 'Authorization', 'value' => 'Bearer secret']]),
            'options' => json_encode([['key' => 'live', 'value' => 'true']]),
            'cookies' => json_encode([['key' => 'sess', 'value' => 'abc']])
        ]);

        $this->assertResponseIsSuccessful();
        $response = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($response['success']);
        $this->assertNotNull($response['id']);

        // Check DB entry
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $httpApi = $em->getRepository(HttpApi::class)->find($response['id']);
        $this->assertNotNull($httpApi);
        $this->assertEquals('Stripe Webhook Gateway', $httpApi->getName());
        $this->assertEquals('JSON', $httpApi->getBodyType());
        $this->assertCount(1, $httpApi->getHeaders());
        $this->assertEquals('Authorization', $httpApi->getHeaders()[0]['key']);
    }

    public function testVerifyHttpApiConnectionWithPlaceholders(): void
    {
        $client = static::createClient();
        $admin = $this->createAndLoginAdmin($client);

        $em = static::getContainer()->get(EntityManagerInterface::class);

        // 1. Create a dummy subscriber
        $subscriber = new Subscriber();
        $subscriber->setName('Sarah Connor');
        $subscriber->setPhoneNumber('+15551234');
        $subscriber->setChannel('whatsapp');
        $subscriber->setOwner($admin);
        $em->persist($subscriber);
        $em->flush();

        // 2. Mock HttpClientInterface
        $mockResponse = $this->createMock(ResponseInterface::class);
        $mockResponse->method('getStatusCode')->willReturn(200);
        $mockResponse->method('getHeaders')->willReturn(['content-type' => ['application/json']]);
        $mockResponse->method('getContent')->willReturn('{"status": "captured", "name": "Sarah Connor"}');

        $mockHttpClient = $this->createMock(HttpClientInterface::class);
        $mockHttpClient->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                'https://api.service.test/webhook',
                $this->callback(function($options) {
                    return $options['headers']['Authorization'] === 'Bearer token-Sarah Connor' &&
                           $options['headers']['Cookie'] === 'sess=whatsapp' &&
                           $options['body'] === '{"name":"Sarah Connor"}';
                })
            )
            ->willReturn($mockResponse);

        // Swap real service with mock inside test container
        static::getContainer()->set(HttpClientInterface::class, $mockHttpClient);

        // 3. Trigger verification request
        $client->request('POST', '/settings/http-api/verify', [
            'endpointUrl' => 'https://api.service.test/webhook',
            'method' => 'POST',
            'testSubscriberId' => (string) $subscriber->getId(),
            'bodyType' => 'JSON',
            'bodyData' => '{"name":"{{subscriber.name}}"}',
            'headers' => json_encode([['key' => 'Authorization', 'value' => 'Bearer token-{{subscriber.name}}']]),
            'options' => json_encode([['key' => 'phone', 'value' => '{{subscriber.phone_number}}']]),
            'cookies' => json_encode([['key' => 'sess', 'value' => '{{subscriber.channel}}']])
        ]);

        $this->assertResponseIsSuccessful();
        $response = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($response['success']);
        $this->assertEquals(200, $response['statusCode']);
        $this->assertStringContainsString('captured', $response['responseBody']);

        // Assert audit log was recorded in DB
        $logs = $em->getRepository(HttpApiCallLog::class)->findBy(['subscriber' => $subscriber]);
        $this->assertCount(1, $logs);
        $this->assertEquals('POST', $logs[0]->getMethod());
        $this->assertEquals(200, $logs[0]->getResponseStatus());
        $this->assertStringContainsString('captured', $logs[0]->getResponseBody());
    }

    public function testLogsAndImportExportFlow(): void
    {
        $client = static::createClient();
        $this->createAndLoginAdmin($client);

        $em = static::getContainer()->get(EntityManagerInterface::class);

        // Create a connection to export
        $api = new HttpApi();
        $api->setName('Log Hub');
        $api->setEndpointUrl('https://logs.domain.com/v1');
        $api->setMethod('PUT');
        $api->setHeaders([['key' => 'X-API-Key', 'value' => 'xyz']]);
        $em->persist($api);
        $em->flush();

        // 1. Export Connection
        $client->request('GET', '/settings/http-api/export/' . $api->getId());
        $this->assertResponseIsSuccessful();
        $exportResponse = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($exportResponse['success']);
        $this->assertEquals('Log Hub', $exportResponse['config']['name']);

        // 2. Import Connection
        $client->request('POST', '/settings/http-api/import', [
            'configJson' => json_encode($exportResponse['config'])
        ]);
        $this->assertResponseIsSuccessful();
        $importResponse = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($importResponse['success']);
        $newId = $importResponse['id'];

        $newApi = $em->getRepository(HttpApi::class)->find($newId);
        $this->assertNotNull($newApi);
        $this->assertEquals('Log Hub', $newApi->getName());
        $this->assertEquals('PUT', $newApi->getMethod());
        $this->assertEquals('xyz', $newApi->getHeaders()[0]['value']);

        // 3. List logs
        $client->request('GET', '/settings/http-api/logs');
        $this->assertResponseIsSuccessful();
        $logsResponse = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($logsResponse['success']);
        $this->assertIsArray($logsResponse['logs']);
    }

    public function testDeleteHttpApi(): void
    {
        $client = static::createClient();
        $this->createAndLoginAdmin($client);

        $em = static::getContainer()->get(EntityManagerInterface::class);

        $api = new HttpApi();
        $api->setName('Old Webhook');
        $api->setEndpointUrl('https://old.endpoint.org');
        $api->setMethod('DELETE');
        $em->persist($api);
        $em->flush();

        $apiId = $api->getId();

        $client->request('POST', '/settings/http-api/delete/' . $apiId);
        $this->assertResponseIsSuccessful();
        $response = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($response['success']);

        // Verify deleted from DB
        $deletedApi = $em->getRepository(HttpApi::class)->find($apiId);
        $this->assertNull($deletedApi);
    }

    public function testWhatsappBotFlowExecutorTriggersHttpApi(): void
    {
        $client = static::createClient();
        $admin = $this->createAndLoginAdmin($client);

        $em = static::getContainer()->get(EntityManagerInterface::class);

        // 1. Create dummy subscriber
        $subscriber = new Subscriber();
        $subscriber->setName('Sarah Connor');
        $subscriber->setPhoneNumber('+15551234');
        $subscriber->setChannel('whatsapp');
        $subscriber->setOwner($admin);
        $em->persist($subscriber);

        // 2. Create WhatsApp connection
        $waConn = new \App\Entity\WhatsAppConnection();
        $waConn->setLabel('Test WhatsApp');
        $waConn->setBusinessAccountId('123456');
        $waConn->setPhoneNumberId('123456');
        $waConn->setEncryptedAccessToken('secret-token');
        $waConn->setVerifyToken('test_verify_token');
        $waConn->setOwner($admin);
        $em->persist($waConn);

        // 3. Create HttpApi connection
        $httpApi = new HttpApi();
        $httpApi->setName('Webhook Endpoint');
        $httpApi->setEndpointUrl('https://api.test/webhook');
        $httpApi->setMethod('POST');
        $httpApi->setBodyType('JSON');
        $httpApi->setBodyData('{"name": "{{subscriber.name}}"}');
        $httpApi->setOwner($admin);
        $em->persist($httpApi);
        $em->flush();

        $apiId = $httpApi->getId();

        // 4. Create WhatsappBotFlow containing call_http_api action
        $flow = new WhatsappBotFlow();
        $flow->setName('Test Flow');
        $flow->setTriggerKeyword('hello');
        $flow->setMatchMode('exact');
        $flow->setActive(true);
        $flow->setWhatsAppConnection($waConn);
        $flow->setOwner($admin);
        $flow->setFlowData([
            'format' => 'graph',
            'nodes' => [
                ['id' => 'start-node', 'type' => 'start'],
                ['id' => 'api-node', 'type' => 'call_http_api', 'data' => ['apiId' => $apiId]]
            ],
            'edges' => [
                ['source' => 'start-node', 'target' => 'api-node', 'sourceHandle' => 'out']
            ]
        ]);
        $em->persist($flow);
        $em->flush();

        // 5. Mock HttpClientInterface
        $mockResponse = $this->createMock(ResponseInterface::class);
        $mockResponse->method('getStatusCode')->willReturn(200);
        $mockResponse->method('getHeaders')->willReturn(['content-type' => ['application/json']]);
        $mockResponse->method('getContent')->willReturn('{"status": "ok"}');

        $mockHttpClient = $this->createMock(HttpClientInterface::class);
        $mockHttpClient->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                'https://api.test/webhook',
                $this->callback(function($options) {
                    return trim($options['body']) === '{"name": "Sarah Connor"}';
                })
            )
            ->willReturn($mockResponse);

        static::getContainer()->set(HttpClientInterface::class, $mockHttpClient);

        // 6. Run Flow Executor
        $executor = static::getContainer()->get(WhatsappBotFlowExecutor::class);
        $executor->execute($flow, $subscriber);

        // 7. Verify call log was created in DB
        $logs = $em->getRepository(HttpApiCallLog::class)->findBy(['subscriber' => $subscriber]);
        $this->assertCount(1, $logs);
        $this->assertEquals('POST', $logs[0]->getMethod());
        $this->assertEquals(200, $logs[0]->getResponseStatus());
        $this->assertEquals('https://api.test/webhook', $logs[0]->getUrl());
    }
}

