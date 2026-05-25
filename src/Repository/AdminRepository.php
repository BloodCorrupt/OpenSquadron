<?php

namespace App\Repository;

use App\Entity\Admin;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;

/**
 * @extends ServiceEntityRepository<Admin>
 *
 * @implements PasswordUpgraderInterface<Admin>
 *
 * @method Admin|null find($id, $lockMode = null, $lockVersion = null)
 * @method Admin|null findOneBy(array $criteria, array $orderBy = null)
 * @method Admin[]    findAll()
 * @method Admin[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class AdminRepository extends ServiceEntityRepository implements PasswordUpgraderInterface, \Webauthn\Bundle\Repository\PublicKeyCredentialUserEntityRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Admin::class);
    }

    /**
     * Used to upgrade (rehash) the user's password automatically over time.
     */
    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof Admin) {
            throw new UnsupportedUserException(sprintf('Instances of "%s" are not supported.', $user::class));
        }

        $user->setPassword($newHashedPassword);
        $this->getEntityManager()->persist($user);
        $this->getEntityManager()->flush();
    }

    public function findOneByUsername(string $username): ?\Webauthn\PublicKeyCredentialUserEntity
    {
        $admin = $this->findOneBy(['email' => $username]);
        if (!$admin) {
            return null;
        }

        return $this->createWebAuthnUserEntity($admin);
    }

    public function findOneByUserHandle(string $userHandle): ?\Webauthn\PublicKeyCredentialUserEntity
    {
        $admin = $this->find((int) $userHandle);
        if (!$admin) {
            return null;
        }

        return $this->createWebAuthnUserEntity($admin);
    }

    private function createWebAuthnUserEntity(Admin $admin): \Webauthn\PublicKeyCredentialUserEntity
    {
        return new \Webauthn\PublicKeyCredentialUserEntity(
            $admin->getEmail(),           // name
            (string) $admin->getId(),     // id (user handle)
            $admin->getName() ?? $admin->getEmail() // display name
        );
    }
}
