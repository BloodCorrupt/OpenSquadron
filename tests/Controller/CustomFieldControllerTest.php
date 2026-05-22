<?php

namespace App\Tests\Controller;

use App\Entity\Admin;
use App\Entity\CustomField;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class CustomFieldControllerTest extends WebTestCase
{
    private function createAndLoginAdmin($client): void
    {
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get(UserPasswordHasherInterface::class);

        // Delete any existing test admin if tests run multiple times
        $existingAdmin = $em->getRepository(Admin::class)->findOneBy(['email' => 'test@admin.local']);
        if ($existingAdmin) {
            // Delete custom fields associated first
            $fields = $em->getRepository(CustomField::class)->findBy(['owner' => $existingAdmin]);
            foreach ($fields as $f) {
                $em->remove($f);
            }
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

    public function testCustomFieldPageRenders(): void
    {
        $client = static::createClient();
        $this->createAndLoginAdmin($client);

        $client->request('GET', '/settings/custom-fields');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('h1.content-title');
        $this->assertSelectorTextContains('h1.content-title', 'Global Custom Fields');
    }

    public function testListJsonEmpty(): void
    {
        $client = static::createClient();
        $this->createAndLoginAdmin($client);

        $client->request('GET', '/settings/custom-fields/json');

        $this->assertResponseIsSuccessful();
        $response = json_decode($client->getResponse()->getContent(), true);
        $this->assertIsArray($response);
    }

    public function testSaveAndEditCustomField(): void
    {
        $client = static::createClient();
        $this->createAndLoginAdmin($client);

        // 1. Create a new custom field
        $client->request('POST', '/settings/custom-fields/save', [
            'name' => 'test_cf_user_age',
            'type' => 'NUMBER',
            'description' => 'User age in years',
            'defaultValue' => '18'
        ]);

        $this->assertResponseIsSuccessful();
        $response = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($response['success']);
        $this->assertEquals('test_cf_user_age', $response['data']['name']);
        $this->assertEquals('NUMBER', $response['data']['type']);
        $id = $response['data']['id'];

        // 2. Edit the custom field
        $client->request('POST', '/settings/custom-fields/save', [
            'id' => $id,
            'name' => 'test_cf_user_age',
            'type' => 'TEXT',
            'description' => 'Updated description',
            'defaultValue' => '21'
        ]);

        $this->assertResponseIsSuccessful();
        $response = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($response['success']);
        $this->assertEquals('TEXT', $response['data']['type']);
        $this->assertEquals('Updated description', $response['data']['description']);
    }

    public function testSaveDuplicateNameFails(): void
    {
        $client = static::createClient();
        $this->createAndLoginAdmin($client);

        // 1. Create custom field
        $client->request('POST', '/settings/custom-fields/save', [
            'name' => 'test_duplicate_field',
            'type' => 'TEXT'
        ]);
        $this->assertResponseIsSuccessful();

        // 2. Create another custom field with the exact same name
        $client->request('POST', '/settings/custom-fields/save', [
            'name' => 'test_duplicate_field',
            'type' => 'NUMBER'
        ]);

        $this->assertResponseStatusCodeSame(400);
        $response = json_decode($client->getResponse()->getContent(), true);
        $this->assertFalse($response['success']);
        $this->assertStringContainsString('already exists', $response['error']);
    }

    public function testDeleteCustomField(): void
    {
        $client = static::createClient();
        $this->createAndLoginAdmin($client);

        // 1. Create custom field
        $client->request('POST', '/settings/custom-fields/save', [
            'name' => 'test_to_delete',
            'type' => 'BOOLEAN'
        ]);
        $this->assertResponseIsSuccessful();
        $response = json_decode($client->getResponse()->getContent(), true);
        $id = $response['data']['id'];

        // 2. Delete it
        $client->request('POST', '/settings/custom-fields/delete/' . $id);
        $this->assertResponseIsSuccessful();
        $response = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($response['success']);
    }
}
