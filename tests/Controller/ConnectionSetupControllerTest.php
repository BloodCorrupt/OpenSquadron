<?php

namespace App\Tests\Controller;

use App\Entity\Admin;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class ConnectionSetupControllerTest extends WebTestCase
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

    public function testShowActionReturns200AndForm(): void
    {
        $client = static::createClient();
        $this->createAndLoginAdmin($client);

        $crawler = $client->request('GET', '/whatsapp-business/connect');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('form[action="/whatsapp-business/connect"]');
        $this->assertSelectorExists('input[name="businessAccountId"]');
        $this->assertSelectorExists('input[name="accessToken"]');
    }

    public function testPostWithEmptyFieldsReturnsErrors(): void
    {
        $client = static::createClient();
        $this->createAndLoginAdmin($client);

        $client->request('POST', '/whatsapp-business/connect', [
            'businessAccountId' => '',
            'accessToken' => '',
            'phoneNumberId' => ''
        ]);

        $this->assertResponseRedirects('/whatsapp-business/connect');
        $client->followRedirect();
        // the class alert-error from base.html.twig will contain the text
        $this->assertSelectorTextContains('.alert-error', 'Business Account ID, Access Token, and Phone Number ID are required.');
    }

    public function testMetaApiErrorDisplaysMessage(): void
    {
        $client = static::createClient();
        $this->createAndLoginAdmin($client);
        
        $client->request('POST', '/whatsapp-business/connect', [
            'businessAccountId' => 'invalid_business_id',
            'accessToken' => 'invalid_token',
            'phoneNumberId' => '987654321'
        ]);

        $this->assertResponseRedirects('/whatsapp-business/connect');
        $client->followRedirect();
        
        // Since it's an invalid token, Meta API will reject it
        $content = $client->getResponse()->getContent();
        $this->assertStringContainsString('Failed to validate with Meta API', $content);
    }
}
