<?php

namespace App\Repository;

use App\Entity\EcomOrder;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EcomOrder>
 */
class EcomOrderRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EcomOrder::class);
    }

    /**
     * Generate the next order number in the format ORD-YYYYMMDD-NNNN
     */
    public function generateOrderNumber(): string
    {
        $today = (new \DateTime('now', new \DateTimeZone('UTC')))->format('Ymd');
        $prefix = 'ORD-' . $today . '-';

        $conn = $this->getEntityManager()->getConnection();
        $result = $conn->fetchOne(
            "SELECT order_number FROM ecom_order WHERE order_number LIKE :prefix ORDER BY id DESC LIMIT 1",
            ['prefix' => $prefix . '%']
        );

        if ($result) {
            $lastSeq = (int) substr($result, strlen($prefix));
            $nextSeq = $lastSeq + 1;
        } else {
            $nextSeq = 1;
        }

        return $prefix . str_pad((string) $nextSeq, 4, '0', STR_PAD_LEFT);
    }
}
