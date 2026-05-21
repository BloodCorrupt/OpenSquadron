<?php

namespace App\Tests\Controller;

use App\Entity\Admin;
use App\Entity\Message;
use App\Entity\Subscriber;
use App\Entity\WhatsAppConnection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class LiveChatControllerTest extends WebTestCase
{
    private function createAndLoginAdmin($client): Admin
    {
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $this->cleanTestData($em);

        $existingAdmin = $em->getRepository(Admin::class)->findOneBy(['email' => 'chat_test@admin.local']);
        if ($existingAdmin) {
            $em->remove($existingAdmin);
            $em->flush();
        }

        $admin = new Admin();
        $admin->setEmail('chat_test@admin.local');
        $admin->setRoles(['ROLE_ADMIN']);
        $admin->setPassword($hasher->hashPassword($admin, 'testpass'));
        $admin->setName('Chat Test Admin');
        
        $em->persist($admin);
        $em->flush();

        $client->loginUser($admin);

        return $admin;
    }

    private function cleanTestData(EntityManagerInterface $em): void
    {
        // Delete messages
        $messages = $em->getRepository(Message::class)->findAll();
        foreach ($messages as $msg) {
            $em->remove($msg);
        }

        // Delete subscribers
        $subscribers = $em->getRepository(Subscriber::class)->findAll();
        foreach ($subscribers as $sub) {
            $em->remove($sub);
        }

        // Delete connections
        $connections = $em->getRepository(WhatsAppConnection::class)->findAll();
        foreach ($connections as $conn) {
            $em->remove($conn);
        }

        $em->flush();
    }

    public function testChatWindowValidationAndEnforcement(): void
    {
        $client = static::createClient();
        $admin = $this->createAndLoginAdmin($client);

        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);

        $this->cleanTestData($em);

        // 1. Create a WhatsApp Connection
        $connection = new WhatsAppConnection();
        $connection->setBusinessAccountId('123456789');
        $connection->setPhoneNumberId('987654321');
        $connection->setEncryptedAccessToken('dummy_encrypted_token');
        $connection->setVerifyToken('dummy_verify_token');
        $connection->setStatus('active');
        $connection->setOwner($admin);
        $em->persist($connection);

        // 2. Create a Subscriber
        $subscriber = new Subscriber();
        $subscriber->setPhoneNumber('15550001111');
        $subscriber->setName('Test Subscriber');
        $subscriber->setWhatsAppConnection($connection);
        $subscriber->setOwner($admin);
        $subscriber->setCreatedAt(new \DateTime());
        $subscriber->setUpdatedAt(new \DateTime());
        $em->persist($subscriber);
        $em->flush();

        $subId = $subscriber->getId();

        // Clear Entity Manager tracking to simulate request boundaries
        $em->clear();

        // 3. Test scenario A: No inbound messages at all -> chat window is closed
        $client->request('GET', "/admin/inbox/api/subscriber/{$subId}/details");
        $this->assertResponseIsSuccessful();
        $responseContent = json_decode($client->getResponse()->getContent(), true);
        
        $this->assertFalse($responseContent['chatWindow']['isOpen'], 'Chat window should be closed when there are no inbound messages.');

        // Verify POST api/send is rejected with 403
        $client->request('POST', '/admin/inbox/api/send', [
            'subscriber_id' => $subId,
            'content' => 'Hello standard message'
        ]);
        $this->assertEquals(403, $client->getResponse()->getStatusCode());
        $sendResponse = json_decode($client->getResponse()->getContent(), true);
        $this->assertFalse($sendResponse['success']);
        $this->assertStringContainsString('The 24-hour customer service window has expired', $sendResponse['error']);

        // 4. Test scenario B: Inbound message from 25 hours ago -> chat window is closed
        $em = $container->get(EntityManagerInterface::class); // get fresh EM
        $subscriber = $em->getRepository(Subscriber::class)->find($subId);

        $oldMessage = new Message();
        $oldMessage->setSubscriber($subscriber);
        $oldMessage->setDirection('inbound');
        $oldMessage->setContent('Hello from yesterday');
        $oldMessage->setType('text');
        $oldMessage->setStatus('received');
        $oldMessage->setTimestamp((new \DateTime())->modify('-25 hours'));
        $em->persist($oldMessage);
        $em->flush();

        $em->clear();

        $client->request('GET', "/admin/inbox/api/subscriber/{$subId}/details");
        $this->assertResponseIsSuccessful();
        $responseContent = json_decode($client->getResponse()->getContent(), true);
        $this->assertFalse($responseContent['chatWindow']['isOpen'], 'Chat window should be closed when the last inbound message is older than 24 hours.');

        // Verify POST api/send is still rejected with 403
        $client->request('POST', '/admin/inbox/api/send', [
            'subscriber_id' => $subId,
            'content' => 'Hello standard message'
        ]);
        $this->assertEquals(403, $client->getResponse()->getStatusCode());

        // 5. Test scenario C: Inbound message from 2 hours ago -> chat window is open
        $em = $container->get(EntityManagerInterface::class); // get fresh EM
        $subscriber = $em->getRepository(Subscriber::class)->find($subId);

        $recentMessage = new Message();
        $recentMessage->setSubscriber($subscriber);
        $recentMessage->setDirection('inbound');
        $recentMessage->setContent('Hello from recently');
        $recentMessage->setType('text');
        $recentMessage->setStatus('received');
        $recentMessage->setTimestamp((new \DateTime())->modify('-2 hours'));
        $em->persist($recentMessage);
        $em->flush();

        $em->clear();

        $client->request('GET', "/admin/inbox/api/subscriber/{$subId}/details");
        $this->assertResponseIsSuccessful();
        $responseContent = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($responseContent['chatWindow']['isOpen'], 'Chat window should be open when there is a recent inbound message.');
        $this->assertGreaterThan(0, $responseContent['chatWindow']['remainingSeconds']);

        // Clean up
        $em = $container->get(EntityManagerInterface::class);
        $this->cleanTestData($em);
        $admin = $em->getRepository(Admin::class)->find($admin->getId());
        if ($admin) {
            $em->remove($admin);
            $em->flush();
        }
    }

    public function testInboxPageRendersSuccessfully(): void
    {
        $client = static::createClient();
        $admin = $this->createAndLoginAdmin($client);

        $client->request('GET', '/admin/inbox');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('.inbox-container');

        // Clean up
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $this->cleanTestData($em);
        $admin = $em->getRepository(Admin::class)->find($admin->getId());
        if ($admin) {
            $em->remove($admin);
            $em->flush();
        }
    }

    public function testInboxChatPageRendersSuccessfully(): void
    {
        $client = static::createClient();
        $admin = $this->createAndLoginAdmin($client);

        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);

        // 1. Create a WhatsApp Connection
        $connection = new WhatsAppConnection();
        $connection->setBusinessAccountId('123456789');
        $connection->setPhoneNumberId('987654321');
        $connection->setEncryptedAccessToken('dummy_encrypted_token');
        $connection->setVerifyToken('dummy_verify_token');
        $connection->setStatus('active');
        $connection->setOwner($admin);
        $em->persist($connection);

        // 2. Create a Subscriber
        $subscriber = new Subscriber();
        $subscriber->setPhoneNumber('15550001111');
        $subscriber->setName('Test Subscriber');
        $subscriber->setWhatsAppConnection($connection);
        $subscriber->setOwner($admin);
        $subscriber->setCreatedAt(new \DateTime());
        $subscriber->setUpdatedAt(new \DateTime());
        $em->persist($subscriber);
        $em->flush();

        $subId = $subscriber->getId();

        $client->request('GET', "/admin/inbox/{$subId}");
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('.inbox-container');

        // Clean up
        $em = $container->get(EntityManagerInterface::class);
        $this->cleanTestData($em);
        $admin = $em->getRepository(Admin::class)->find($admin->getId());
        if ($admin) {
            $em->remove($admin);
            $em->flush();
        }
    }
}
