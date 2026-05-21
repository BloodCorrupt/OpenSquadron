<?php

namespace App\Command;

use App\Entity\Admin;
use App\Entity\WhatsAppConnection;
use App\Entity\Subscriber;
use App\Entity\Message;
use App\Service\TenantContext;
use App\Service\WhatsAppConnectionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    name: 'app:restore-connection',
    description: 'Restores the original WhatsApp connection and old chat history for the main admin account.',
)]
class RestoreConnectionCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private TenantContext $tenantContext,
        private WhatsAppConnectionService $whatsappService,
        #[Autowire('%env(WHATSAPP_ACCESS_TOKEN)%')]
        private string $envAccessToken
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('token', InputArgument::OPTIONAL, 'The plain Meta Access Token')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Restoring WhatsApp Connection & Chat History to Admin Account');

        // 1. Disable Tenant Filter to look up globally and clear legacy data
        $this->tenantContext->disableTenantFilter();

        // 2. Fetch primary admin (ID = 1)
        $admin = $this->entityManager->getRepository(Admin::class)->find(1);
        if (!$admin) {
            $io->error('Super Admin account with ID 1 was not found in the database!');
            return Command::FAILURE;
        }
        $io->text(sprintf('Found Admin account: %s (ID: %d)', $admin->getEmail(), $admin->getId()));

        // Set the active tenant context to admin
        $this->tenantContext->setCurrentOwner($admin);

        // 3. Clean up previous matching data to ensure idempotency
        $io->section('1. Performing clean up of existing records');
        $oldConnections = $this->entityManager->getRepository(WhatsAppConnection::class)->findBy([
            'businessAccountId' => '1297297349135038'
        ]);
        foreach ($oldConnections as $oldConn) {
            $io->text(sprintf('Deleting old WhatsApp connection (ID: %d, Business Account ID: %s)', $oldConn->getId(), $oldConn->getBusinessAccountId()));
            $this->entityManager->remove($oldConn);
        }
        
        $oldSubscribers = $this->entityManager->getRepository(Subscriber::class)->findBy([
            'phoneNumber' => '8801303179567'
        ]);
        foreach ($oldSubscribers as $oldSub) {
            $io->text(sprintf('Deleting old Subscriber (ID: %d, Phone: %s)', $oldSub->getId(), $oldSub->getPhoneNumber()));
            $this->entityManager->remove($oldSub);
        }

        $this->entityManager->flush();
        $io->success('Cleanup completed.');

        // 4. Create the WhatsApp Connection
        $io->section('2. Restoring WhatsApp Connection');
        
        $connection = new WhatsAppConnection();
        $connection->setOwner($admin);
        $connection->setBusinessAccountId('1297297349135038');
        $connection->setPhoneNumberId('1141198275740552');
        $connection->setPhoneNumber('8801581439088');
        $connection->setLabel('Admin WhatsApp Connection');
        $connection->setVerifyToken('my_custom_verify_token_123');
        $connection->setWebhookUrl($this->whatsappService->buildWebhookUrl());
        $connection->setStatus('active');
        
        // Encrypt the current access token from command argument or fallback to environment
        $token = $input->getArgument('token') ?: $this->envAccessToken;
        $encryptedToken = $this->whatsappService->encryptToken($token);
        $connection->setEncryptedAccessToken($encryptedToken);

        $this->entityManager->persist($connection);
        $this->entityManager->flush();
        
        $io->success(sprintf('WhatsApp connection successfully created with ID: %d', $connection->getId()));

        // 5. Create Subscriber "Fahim"
        $io->section('3. Restoring Subscriber "Fahim"');
        
        $subscriber = new Subscriber();
        $subscriber->setOwner($admin);
        $subscriber->setPhoneNumber('8801303179567');
        $subscriber->setName('Fahim');
        $subscriber->setWhatsAppConnection($connection);
        $subscriber->setStatus('active');
        // Set creation/update time in sync with initial messages
        $subscriber->setCreatedAt(new \DateTime('@1779162436'));
        $subscriber->setUpdatedAt(new \DateTime('@1779271809'));

        $this->entityManager->persist($subscriber);
        $this->entityManager->flush();
        
        $io->success(sprintf('Subscriber "Fahim" successfully created with ID: %d', $subscriber->getId()));

        // 6. Create Message Threads in perfect chronological order
        $io->section('4. Restoring Chronological Chat Messages');

        $messagesData = [
            // 1
            [
                'direction' => 'inbound',
                'type' => 'text',
                'content' => 'Hi',
                'status' => 'received',
                'metaId' => 'wamid.HBgNODgwMTMwMzE3OTU2NxUCABIYFDNBOTBERUMyNjdFNUU1REI1NDA0AA==',
                'timestamp' => 1779162436
            ],
            // 2
            [
                'direction' => 'inbound',
                'type' => 'text',
                'content' => 'Hoi',
                'status' => 'received',
                'metaId' => 'wamid.HBgNODgwMTMwMzE3OTU2NxUCABIYFDNBRjU3NTE4RTc0QjUwRDFCOTNBAA==',
                'timestamp' => 1779162981
            ],
            // 3
            [
                'direction' => 'inbound',
                'type' => 'text',
                'content' => 'Holai',
                'status' => 'received',
                'metaId' => 'wamid.HBgNODgwMTMwMzE3OTU2NxUCABIYFDNBODc4NDE5OTQyQzZEMTE1NjIzAA==',
                'timestamp' => 1779200024
            ],
            // 4
            [
                'direction' => 'inbound',
                'type' => 'text',
                'content' => 'Djsosgd',
                'status' => 'received',
                'metaId' => 'wamid.HBgNODgwMTMwMzE3OTU2NxUCABIYFDNBQzZFNTg4MjI4OTRFNTUxMjk2AA==',
                'timestamp' => 1779200798
            ],
            // 5
            [
                'direction' => 'outbound',
                'type' => 'text',
                'content' => 'Hello! How can I assist you today?',
                'status' => 'read',
                'metaId' => 'wamid.HBgNODgwMTMwMzE3OTU2NxUCABEYEjRENDNENEJGODM4MTgzRkRCQgA=',
                'timestamp' => 1779205248
            ],
            // 6
            [
                'direction' => 'outbound',
                'type' => 'text',
                'content' => 'Is there anything specific you would like to know about our services?',
                'status' => 'read',
                'metaId' => 'wamid.HBgNODgwMTMwMzE3OTU2NxUCABEYEkEzQjMxRkM0REU3RDA3RUM3QwA=',
                'timestamp' => 1779205585
            ],
            // 7
            [
                'direction' => 'inbound',
                'type' => 'text',
                'content' => 'Yooo nigg',
                'status' => 'received',
                'metaId' => 'wamid.HBgNODgwMTMwMzE3OTU2NxUCABIYFDNBQjJCQjUyMkZBQ0U0RjYxNjBBAA==',
                'timestamp' => 1779205680
            ],
            // 8
            [
                'direction' => 'inbound',
                'type' => 'text',
                'content' => 'DEJ3E07LOR',
                'status' => 'received',
                'metaId' => 'wamid.HBgNODgwMTMwMzE3OTU2NxUCABIYFDNBRTVENUExODE4MEI1NDdCRjhDAA==',
                'timestamp' => 1779206593
            ],
            // 9
            [
                'direction' => 'outbound',
                'type' => 'text',
                'content' => "I'm sorry, I didn't quite get that. Could you please specify?",
                'status' => 'read',
                'metaId' => 'wamid.HBgNODgwMTMwMzE3OTU2NxUCABEYEjZENEZDM0EzNUREODVERjI5RAA=',
                'timestamp' => 1779208664
            ],
            // 10
            [
                'direction' => 'inbound',
                'type' => 'text',
                'content' => 'hoi',
                'status' => 'received',
                'metaId' => 'wamid.HBgNODgwMTMwMzE3OTU2NxUCABIYFDNBMEJGMkQxQUFFQkYxNjhFODkwAA==',
                'timestamp' => 1779208854
            ],
            // 11
            [
                'direction' => 'outbound',
                'type' => 'text',
                'content' => "Hello again! How's it going?",
                'status' => 'read',
                'metaId' => 'wamid.HBgNODgwMTMwMzE3OTU2NxUCABEYEjY0REYxMUM1NzYxOUQxQTYxOAA=',
                'timestamp' => 1779208859
            ],
            // 12
            [
                'direction' => 'outbound',
                'type' => 'text',
                'content' => 'Let me know if you have any questions.',
                'status' => 'read',
                'metaId' => 'wamid.HBgNODgwMTMwMzE3OTU2NxUCABEYEjAxQzg2NjEyRTMwQjMyMDYwMQA=',
                'timestamp' => 1779208995
            ],
            // 13
            [
                'direction' => 'outbound',
                'type' => 'template',
                'content' => '[Template: marketing_promo]',
                'status' => 'read',
                'metaId' => 'wamid.HBgNODgwMTMwMzE3OTU2NxUCABEYEkUzRUVGMTA4NDI3MDNCM0NCMwA=',
                'timestamp' => 1779209447
            ],
            // 14 (Image Attachment)
            [
                'direction' => 'inbound',
                'type' => 'image',
                'content' => '',
                'mediaUrl' => 'uploads/whatsapp_media/restored_image.jpg',
                'status' => 'received',
                'metaId' => 'wamid.HBgNODgwMTMwMzE3OTU2NxUCABIYFDNBNUNCOTQwRDdCMkE5MjgwODBFAA==',
                'timestamp' => 1779209483
            ],
            // 15
            [
                'direction' => 'outbound',
                'type' => 'text',
                'content' => "Thank you for the image! I'll review it.",
                'status' => 'read',
                'metaId' => 'wamid.HBgNODgwMTMwMzE3OTU2NxUCABEYEkYwNUUxNTU2QzQ2QjE1QzM5OQA=',
                'timestamp' => 1779251664
            ],
            // 16
            [
                'direction' => 'outbound',
                'type' => 'text',
                'content' => 'Hope you are having a great day!',
                'status' => 'read',
                'metaId' => 'wamid.HBgNODgwMTMwMzE3OTU2NxUCABEYEkRCOEEyNzE1MjcwMTEzNURDNgA=',
                'timestamp' => 1779268425
            ],
            // 17
            [
                'direction' => 'inbound',
                'type' => 'text',
                'content' => 'Hunu',
                'status' => 'received',
                'metaId' => 'wamid.HBgNODgwMTMwMzE3OTU2NxUCABIYFDNBN0NFNThEOEIyNkU5MzNCQjRGAA==',
                'timestamp' => 1779271809
            ]
        ];

        foreach ($messagesData as $index => $data) {
            $msg = new Message();
            $msg->setSubscriber($subscriber);
            $msg->setDirection($data['direction']);
            $msg->setType($data['type']);
            $msg->setContent($data['content']);
            $msg->setStatus($data['status']);
            $msg->setMetaMessageId($data['metaId']);
            $msg->setTimestamp(new \DateTime('@' . $data['timestamp']));
            
            if (isset($data['mediaUrl'])) {
                $msg->setMediaUrl($data['mediaUrl']);
            }

            $this->entityManager->persist($msg);
        }

        $this->entityManager->flush();
        $io->success(sprintf('All %d messages successfully created and restored!', count($messagesData)));

        $io->newLine();
        $io->success('Connection and chat history successfully restored in perfect order!');

        return Command::SUCCESS;
    }
}
