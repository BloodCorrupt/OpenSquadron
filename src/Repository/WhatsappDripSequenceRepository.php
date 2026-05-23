<?php

namespace App\Repository;

use App\Entity\WhatsappDripSequence;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WhatsappDripSequence>
 */
class WhatsappDripSequenceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WhatsappDripSequence::class);
    }
}
