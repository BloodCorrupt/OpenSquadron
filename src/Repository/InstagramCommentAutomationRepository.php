<?php

namespace App\Repository;

use App\Entity\InstagramCommentAutomation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<InstagramCommentAutomation>
 *
 * @method InstagramCommentAutomation|null find($id, $lockMode = null, $lockVersion = null)
 * @method InstagramCommentAutomation|null findOneBy(array $criteria, array $orderBy = null)
 * @method InstagramCommentAutomation[]    findAll()
 * @method InstagramCommentAutomation[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class InstagramCommentAutomationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InstagramCommentAutomation::class);
    }

    public function save(InstagramCommentAutomation $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(InstagramCommentAutomation $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}

