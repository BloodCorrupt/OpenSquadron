<?php

namespace App\Repository;

use App\Entity\SmtpSettings;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SmtpSettings>
 *
 * @method SmtpSettings|null find($id, $lockMode = null, $lockVersion = null)
 * @method SmtpSettings|null findOneBy(array $criteria, array $orderBy = null)
 * @method SmtpSettings[]    findAll()
 * @method SmtpSettings[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class SmtpSettingsRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SmtpSettings::class);
    }

    public function save(SmtpSettings $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(SmtpSettings $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
