<?php

namespace App\Service;

use App\Entity\Admin;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

class TenantDatabaseService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        #[Autowire('%kernel.project_dir%')]
        private string $projectDir
    ) {
    }

    /**
     * Switch current MariaDB connection session database (NOOP).
     */
    public function switchToTenantDatabase(string $dbName): void
    {
        // NOOP in single database row isolation
    }

    /**
     * Create isolated database (NOOP).
     */
    public function createTenantDatabase(Admin $user): string
    {
        return $this->getMainDatabaseName();
    }

    /**
     * Drop a tenant's isolated database (NOOP).
     */
    public function dropTenantDatabase(string $dbName): void
    {
        // NOOP
    }

    /**
     * Parse primary database name from DATABASE_URL context.
     */
    public function getMainDatabaseName(): string
    {
        $databaseUrl = $_ENV['DATABASE_URL'] ?? getenv('DATABASE_URL') ?: '';
        $path = parse_url($databaseUrl, PHP_URL_PATH);
        return $path ? ltrim($path, '/') : 'opensquadron';
    }
}
