<?php

namespace App\Command;

use App\Entity\Admin;
use App\Entity\WhatsAppConnection;
use App\Entity\Subscriber;
use App\Service\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'app:test-tenant',
    description: 'Runs integration tests for the Single-Database Row-Level Multi-Tenant system.',
)]
class TestTenantCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private TenantContext $tenantContext,
        private UserPasswordHasherInterface $passwordHasher
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Running Single-Database Multi-Tenant Integration Tests');

        // 1. CLEANUP PREVIOUS TEST RUNS
        $io->section('1. Cleaning up previous test runs');
        
        $this->tenantContext->disableTenantFilter();

        $emailsToDelete = [
            'test_team_member@opensquadron.local',
            'test_owner@opensquadron.local',
            'test_other_owner@opensquadron.local'
        ];

        foreach ($emailsToDelete as $email) {
            $oldAcc = $this->entityManager->getRepository(Admin::class)->findOneBy(['email' => $email]);
            if ($oldAcc) {
                $io->text(sprintf('Found old account %s, deleting...', $email));
                $this->entityManager->remove($oldAcc);
            }
        }
        $this->entityManager->flush();
        $io->text('Cleanup completed.');

        // 2. CREATE WORKSPACE OWNER (USER)
        $io->section('2. Creating test workspace owner');
        $owner = new Admin();
        $owner->setEmail('test_owner@opensquadron.local');
        $owner->setRoles(['ROLE_USER']);
        $owner->setAccountType('user');
        $owner->setTeamEnabled(true);
        $owner->setPassword($this->passwordHasher->hashPassword($owner, 'password123'));

        $this->entityManager->persist($owner);
        $this->entityManager->flush();
        $io->text(sprintf('Workspace owner created with ID: %d', $owner->getId()));

        // 3. VERIFY PRE-PERSIST LIFECYCLE LISTENER
        $io->section('3. Verifying prePersist lifecycle owner assignment');
        
        // Set context to the new owner
        $this->tenantContext->setCurrentOwner($owner);

        // Create a connection (implementing TenantAwareInterface)
        $conn = new WhatsAppConnection();
        $conn->setBusinessAccountId('1234567890');
        $conn->setEncryptedAccessToken('abcxyz');
        $conn->setVerifyToken('my_verify_token');
        
        $this->entityManager->persist($conn);
        $this->entityManager->flush();

        if ($conn->getOwner() !== null && $conn->getOwner()->getId() === $owner->getId()) {
            $io->success(sprintf('Connection successfully mapped to owner ID %d via prePersist!', $conn->getOwner()->getId()));
        } else {
            $io->error('Lifecycle listener failed: owner field is null or mapped incorrectly.');
            return Command::FAILURE;
        }

        // 4. VERIFY ROW-LEVEL ISOLATION
        $io->section('4. Verifying row-level query filtering and isolation');

        // Create another owner
        $this->tenantContext->disableTenantFilter();
        $otherOwner = new Admin();
        $otherOwner->setEmail('test_other_owner@opensquadron.local');
        $otherOwner->setRoles(['ROLE_USER']);
        $otherOwner->setAccountType('user');
        $otherOwner->setPassword($this->passwordHasher->hashPassword($otherOwner, 'password123'));
        $this->entityManager->persist($otherOwner);
        $this->entityManager->flush();

        // Switch to other owner and create a connection
        $this->tenantContext->setCurrentOwner($otherOwner);
        $otherConn = new WhatsAppConnection();
        $otherConn->setBusinessAccountId('0987654321');
        $otherConn->setEncryptedAccessToken('zyxcba');
        $otherConn->setVerifyToken('other_verify_token');
        $this->entityManager->persist($otherConn);
        $this->entityManager->flush();

        // Test filtering: when test_owner is active
        $this->tenantContext->setCurrentOwner($owner);
        $connections = $this->entityManager->getRepository(WhatsAppConnection::class)->findAll();
        $io->text(sprintf('Query connections for "test_owner" returned %d connection(s)', count($connections)));
        
        if (count($connections) === 1 && $connections[0]->getId() === $conn->getId()) {
            $io->success('Query filtering successfully isolated test_owner connection!');
        } else {
            $io->error('Isolation failed or leaked other owner rows.');
            return Command::FAILURE;
        }

        // Test filtering: when test_other_owner is active
        $this->tenantContext->setCurrentOwner($otherOwner);
        $otherConnections = $this->entityManager->getRepository(WhatsAppConnection::class)->findAll();
        $io->text(sprintf('Query connections for "test_other_owner" returned %d connection(s)', count($otherConnections)));

        if (count($otherConnections) === 1 && $otherConnections[0]->getId() === $otherConn->getId()) {
            $io->success('Query filtering successfully isolated test_other_owner connection!');
        } else {
            $io->error('Isolation failed or leaked other owner rows.');
            return Command::FAILURE;
        }

        // Test filtering disabled: we should see both connections
        $this->tenantContext->disableTenantFilter();
        $allConnections = $this->entityManager->getRepository(WhatsAppConnection::class)->findAll();
        $io->text(sprintf('Query connections with disabled tenant filter returned %d connection(s)', count($allConnections)));
        if (count($allConnections) >= 2) {
            $io->success('Disabled filter successfully retrieves cross-tenant records (critical for webhooks).');
        } else {
            $io->error('Filter disable failed.');
            return Command::FAILURE;
        }

        // 5. TEST DELETION CASCADE
        $io->section('5. Testing cascaded deletion of isolated tenant rows');
        try {
            $ownerId = $owner->getId();
            
            // Delete owner
            $ownerRef = $this->entityManager->getRepository(Admin::class)->find($ownerId);
            $this->entityManager->remove($ownerRef);
            $this->entityManager->flush();
            $io->text('Deleted test_owner successfully.');

            // Clear EntityManager to purge Identity Map and force DB select
            $this->entityManager->clear();

            // Verify connection was deleted automatically via cascade constraint
            $connCheck = $this->entityManager->getRepository(WhatsAppConnection::class)->find($conn->getId());
            if ($connCheck === null) {
                $io->success('Database foreign key cascade deleted the isolated WhatsApp connection successfully!');
            } else {
                $io->error('Cascade deletion failed: connection row still exists.');
                return Command::FAILURE;
            }
        } catch (\Exception $e) {
            $io->error('Cascaded deletion check encountered error: ' . $e->getMessage());
            return Command::FAILURE;
        }

        // Clean up other owner too
        $otherOwnerRef = $this->entityManager->getRepository(Admin::class)->find($otherOwner->getId());
        if ($otherOwnerRef) {
            $this->entityManager->remove($otherOwnerRef);
            $this->entityManager->flush();
        }

        $io->newLine();
        $io->success('All single-database tenant isolation integration tests completed with zero errors!');
        return Command::SUCCESS;
    }
}
