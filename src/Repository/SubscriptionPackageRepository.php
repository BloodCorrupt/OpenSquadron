<?php

namespace App\Repository;

use App\Entity\SubscriptionPackage;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SubscriptionPackage>
 *
 * @method SubscriptionPackage|null find($id, $lockMode = null, $lockVersion = null)
 * @method SubscriptionPackage|null findOneBy(array $criteria, array $orderBy = null)
 * @method SubscriptionPackage[]    findAll()
 * @method SubscriptionPackage[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class SubscriptionPackageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SubscriptionPackage::class);
    }

    public function save(SubscriptionPackage $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(SubscriptionPackage $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
