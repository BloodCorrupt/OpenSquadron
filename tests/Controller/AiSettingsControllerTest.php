<?php

namespace App\Tests\Controller;

use App\Entity\Admin;
use App\Entity\AiSetting;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class AiSettingsControllerTest extends WebTestCase
{
    private function createAndLoginAdmin($client): void
    {
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get(UserPasswordHasherInterface::class);

        // Delete any existing test admin if tests run multiple times
        $existingAdmin = $em->getRepository(Admin::class)->findOneBy(['email' => 'test@admin.local']);
        if ($existingAdmin) {
            $em->remove($existingAdmin);
            $em->flush();
        }

        $admin = new Admin();
        $admin->setEmail('test@admin.local');
        $admin->setRoles(['ROLE_ADMIN']);
        $admin->setPassword($hasher->hashPassword($admin, 'testpass'));
        
        $em->persist($admin);
        $em->flush();

        $client->loginUser($admin);
    }

    public function testAiSettingsPageRenders(): void
    {
        $client = static::createClient();
        $this->createAndLoginAdmin($client);

        $client->request('GET', '/ai-settings');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('form#ai-api-config-form');
        $this->assertSelectorExists('select#ai-provider');
    }

    public function testSaveCloudProviderWithEmptyEndpointSucceeds(): void
    {
        $client = static::createClient();
        $this->createAndLoginAdmin($client);

        $client->request('POST', '/ai-settings/save', [
            'configType' => 'api',
            'provider' => 'openai',
            'apiKey' => 'sk-testkey',
            'apiEndpoint' => '',
            'model' => 'gpt-4o-mini',
            'isActive' => '1'
        ]);

        $this->assertResponseIsSuccessful();
        $response = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($response['success']);
    }

    public function testSaveCustomProviderWithEmptyEndpointFails(): void
    {
        $client = static::createClient();
        $this->createAndLoginAdmin($client);

        $client->request('POST', '/ai-settings/save', [
            'configType' => 'api',
            'provider' => 'custom',
            'apiKey' => 'sk-testkey',
            'apiEndpoint' => '',
            'model' => 'custom-model',
            'isActive' => '1'
        ]);

        $this->assertResponseStatusCodeSame(400);
        $response = json_decode($client->getResponse()->getContent(), true);
        $this->assertFalse($response['success']);
        $this->assertStringContainsString('Custom API Endpoint is required', $response['error']);
    }

    public function testSaveCustomProviderWithValidEndpointSucceeds(): void
    {
        $client = static::createClient();
        $this->createAndLoginAdmin($client);

        $client->request('POST', '/ai-settings/save', [
            'configType' => 'api',
            'provider' => 'custom',
            'apiKey' => 'sk-testkey',
            'apiEndpoint' => 'http://localhost:11434/v1',
            'model' => 'custom-model',
            'isActive' => '1'
        ]);

        $this->assertResponseIsSuccessful();
        $response = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($response['success']);
    }

    public function testSaveOllamaLocalWithEmptyApiKeySucceeds(): void
    {
        $client = static::createClient();
        $this->createAndLoginAdmin($client);

        $client->request('POST', '/ai-settings/save', [
            'configType' => 'api',
            'provider' => 'ollama',
            'apiKey' => '',
            'apiEndpoint' => 'http://localhost:11434/v1',
            'model' => 'llama3',
            'isActive' => '1'
        ]);

        $this->assertResponseIsSuccessful();
        $response = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($response['success']);
    }

    public function testSaveLmStudioWithEmptyApiKeySucceeds(): void
    {
        $client = static::createClient();
        $this->createAndLoginAdmin($client);

        $client->request('POST', '/ai-settings/save', [
            'configType' => 'api',
            'provider' => 'lmstudio',
            'apiKey' => '',
            'apiEndpoint' => 'http://localhost:1234/v1',
            'model' => 'meta-llama-3-8b-instruct',
            'isActive' => '1'
        ]);

        $this->assertResponseIsSuccessful();
        $response = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($response['success']);
    }

    public function testSaveOllamaLocalWithEmptyEndpointFails(): void
    {
        $client = static::createClient();
        $this->createAndLoginAdmin($client);

        $client->request('POST', '/ai-settings/save', [
            'configType' => 'api',
            'provider' => 'ollama',
            'apiKey' => '',
            'apiEndpoint' => '',
            'model' => 'llama3',
            'isActive' => '1'
        ]);

        $this->assertResponseStatusCodeSame(400);
        $response = json_decode($client->getResponse()->getContent(), true);
        $this->assertFalse($response['success']);
        $this->assertStringContainsString('Custom API Endpoint is required for Ollama (Local)', $response['error']);
    }

    public function testSaveOllamaCloudWithEmptyEndpointSucceeds(): void
    {
        $client = static::createClient();
        $this->createAndLoginAdmin($client);

        $client->request('POST', '/ai-settings/save', [
            'configType' => 'api',
            'provider' => 'ollamacloud',
            'apiKey' => 'sk-ollamacloud',
            'apiEndpoint' => '',
            'model' => 'llama3',
            'isActive' => '1'
        ]);

        $this->assertResponseIsSuccessful();
        $response = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($response['success']);
    }

    public function testSaveOllamaCloudWithValidEndpointSucceeds(): void
    {
        $client = static::createClient();
        $this->createAndLoginAdmin($client);

        $client->request('POST', '/ai-settings/save', [
            'configType' => 'api',
            'provider' => 'ollamacloud',
            'apiKey' => 'sk-ollamacloud',
            'apiEndpoint' => 'https://api.ollamacloud.com/v1',
            'model' => 'llama3',
            'isActive' => '1'
        ]);

        $this->assertResponseIsSuccessful();
        $response = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($response['success']);
    }
}

