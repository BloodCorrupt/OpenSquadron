<?php

namespace App\Tests\Controller;

use App\Entity\Admin;
use App\Entity\FacebookSetting;
use App\Entity\FacebookConnection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class FacebookConnectionControllerTest extends WebTestCase
{
    private function createAndLoginAdmin($client): void
    {
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get(UserPasswordHasherInterface::class);

        // Delete any existing test admin and Facebook settings/connections
        $existingAdmin = $em->getRepository(Admin::class)->findOneBy(['email' => 'test_fb@admin.local']);
        if ($existingAdmin) {
            $em->remove($existingAdmin);
        }

        // Clean up settings and connections
        $settings = $em->getRepository(FacebookSetting::class)->findAll();
        foreach ($settings as $s) {
            $em->remove($s);
        }
        $connections = $em->getRepository(FacebookConnection::class)->findAll();
        foreach ($connections as $c) {
            $em->remove($c);
        }
        
        $em->flush();

        $admin = new Admin();
        $admin->setEmail('test_fb@admin.local');
        $admin->setRoles(['ROLE_ADMIN']);
        $admin->setPassword($hasher->hashPassword($admin, 'testpass'));
        
        $em->persist($admin);
        $em->flush();

        $client->loginUser($admin);
    }

    public function testFacebookSettingsPageLoadsAndSaves(): void
    {
        $client = static::createClient();
        $this->createAndLoginAdmin($client);

        // 1. Load settings page
        $crawler = $client->request('GET', '/settings/facebook');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('form#fb-api-config-form');
        $this->assertSelectorExists('input[name="appId"]');
        $this->assertSelectorExists('input[name="appSecret"]');

        // 2. Submit credentials via AJAX
        $client->request('POST', '/settings/facebook/save', [
            'appId' => '1234567890',
            'appSecret' => 'super_secret_app_key'
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertJson($client->getResponse()->getContent());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame('Facebook Settings saved successfully.', $data['message']);
        $this->assertNotEmpty($data['verifyToken']);

        // Verify entity was saved
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $setting = $em->getRepository(FacebookSetting::class)->findOneBy(['appId' => '1234567890']);
        $this->assertNotNull($setting);
        $this->assertNotEmpty($setting->getVerifyToken());
        $this->assertNotEmpty($setting->getEncryptedAppSecret());
    }

    public function testConnectionPageDisplayWithAndWithoutSettings(): void
    {
        $client = static::createClient();
        $this->createAndLoginAdmin($client);

        // 1. Without settings configured
        $client->request('GET', '/facebook/connect');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('.glass-card', 'Configuration Required');
        $this->assertSelectorExists('a[href="/settings/facebook"]');

        // 2. Configure settings
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $setting = new FacebookSetting();
        $setting->setAppId('1234567890');
        $setting->setEncryptedAppSecret('encrypted_secret');
        $setting->setVerifyToken('my_verify_token');
        $em->persist($setting);
        $em->flush();

        // 3. Check connection page again
        $client->request('GET', '/facebook/connect');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('button[type="submit"]', 'Connect Facebook Account');
    }

    public function testWebhookVerificationViaFacebookSettingVerifyToken(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);

        // Clear settings
        $settings = $em->getRepository(FacebookSetting::class)->findAll();
        foreach ($settings as $s) {
            $em->remove($s);
        }
        $em->flush();

        // Add a test setting with a specific verify token
        $setting = new FacebookSetting();
        $setting->setAppId('987654321');
        $setting->setEncryptedAppSecret('encrypted_secret');
        $setting->setVerifyToken('custom_test_verify_token');
        $em->persist($setting);
        $em->flush();

        // Request webhook verification
        $client->request('GET', '/webhook/facebook', [
            'hub_mode' => 'subscribe',
            'hub_verify_token' => 'custom_test_verify_token',
            'hub_challenge' => 'hello_challenge'
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertSame('hello_challenge', $client->getResponse()->getContent());

        // Test with incorrect verify token
        $client->request('GET', '/webhook/facebook', [
            'hub_mode' => 'subscribe',
            'hub_verify_token' => 'wrong_token',
            'hub_challenge' => 'hello_challenge'
        ]);

        $this->assertSame(403, $client->getResponse()->getStatusCode());
    }

    public function testFacebookDataDeletionWebhookAndStatusPage(): void
    {
        $client = static::createClient();
        
        // 1. Create a FacebookSetting entry so we have a decrypted App Secret
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        
        $settings = $em->getRepository(FacebookSetting::class)->findAll();
        foreach ($settings as $s) {
            $em->remove($s);
        }
        $em->flush();

        $facebookService = $container->get(\App\Service\FacebookService::class);
        
        $setting = new FacebookSetting();
        $setting->setAppId('11223344');
        $setting->setEncryptedAppSecret($facebookService->encryptToken('my_test_app_secret'));
        $setting->setVerifyToken('test_verify_token');
        $em->persist($setting);
        $em->flush();

        // 2. Build a valid signed_request payload
        $payloadData = [
            'algorithm' => 'HMAC-SHA256',
            'user_id' => '123456789',
        ];
        
        $jsonPayload = json_encode($payloadData);
        $base64UrlPayload = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($jsonPayload));
        
        $expectedSig = hash_hmac('sha256', $base64UrlPayload, 'my_test_app_secret', true);
        $base64UrlSig = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($expectedSig));
        
        $validSignedRequest = $base64UrlSig . '.' . $base64UrlPayload;

        // 3. Test POST /facebook/data-deletion with valid signed request
        $client->request('POST', '/facebook/data-deletion', [
            'signed_request' => $validSignedRequest,
        ]);
        
        $this->assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('url', $data);
        $this->assertArrayHasKey('confirmation_code', $data);
        $this->assertStringContainsString('https://', $data['url']);
        $this->assertStringContainsString('/facebook/deletion-status', $data['url']);
        
        $confirmationCode = $data['confirmation_code'];

        // 4. Test GET /facebook/deletion-status (public route)
        $client->request('GET', '/facebook/deletion-status', ['code' => $confirmationCode]);
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('#confirmation-code', $confirmationCode);

        // 5. Test POST with invalid signature (tampered signature)
        $invalidSignedRequest = 'badsignature.' . $base64UrlPayload;
        $client->request('POST', '/facebook/data-deletion', [
            'signed_request' => $invalidSignedRequest,
        ]);
        $this->assertSame(400, $client->getResponse()->getStatusCode());

        // 6. Test POST with missing parameter
        $client->request('POST', '/facebook/data-deletion');
        $this->assertSame(400, $client->getResponse()->getStatusCode());
    }

    public function testFacebookWebhookSubscription(): void
    {
        $client = static::createClient();
        $this->createAndLoginAdmin($client);

        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $facebookService = $container->get(\App\Service\FacebookService::class);

        // 1. Create a dummy connection
        $connection = new FacebookConnection();
        $connection->setPageId('12345678901');
        $connection->setPageName('Test Page');
        $connection->setEncryptedPageAccessToken($facebookService->encryptToken('dummy_token'));
        $connection->setAppId('1234567890');
        $connection->setEncryptedAppSecret($facebookService->encryptToken('dummy_secret'));
        $connection->setVerifyToken('dummy_verify');
        $connection->setStatus('active');
        $em->persist($connection);
        $em->flush();

        $connId = $connection->getId();

        // 2. Request the subscribe route
        $client->request('POST', "/facebook/connect/{$connId}/subscribe");
        
        // Should redirect back to the connect show page
        $this->assertResponseRedirects('/facebook/connect');
        
        $client->followRedirect();
        
        // Since we used dummy credentials, it should show an error or warning message
        $this->assertSelectorExists('.alert');
    }
}

