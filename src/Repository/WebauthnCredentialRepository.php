<?php

namespace App\Repository;

use App\Entity\WebauthnCredential;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Webauthn\Bundle\Repository\CanSaveCredentialRecord;
use Webauthn\Bundle\Repository\CredentialRecordRepositoryInterface;
use Webauthn\CredentialRecord;
use Webauthn\PublicKeyCredentialUserEntity;

/**
 * @extends ServiceEntityRepository<WebauthnCredential>
 */
class WebauthnCredentialRepository extends ServiceEntityRepository implements CredentialRecordRepositoryInterface, CanSaveCredentialRecord
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WebauthnCredential::class);
    }

    public function saveCredentialRecord(CredentialRecord $credentialRecord): void
    {
        // If the record is already our entity, persist directly.
        // Otherwise, wrap it in our Doctrine entity.
        if (!$credentialRecord instanceof WebauthnCredential) {
            $credentialRecord = new WebauthnCredential(
                $credentialRecord->publicKeyCredentialId,
                $credentialRecord->type,
                $credentialRecord->transports,
                $credentialRecord->attestationType,
                $credentialRecord->trustPath,
                $credentialRecord->aaguid,
                $credentialRecord->credentialPublicKey,
                $credentialRecord->userHandle,
                $credentialRecord->counter,
                $credentialRecord->otherUI,
                $credentialRecord->backupEligible,
                $credentialRecord->backupStatus,
                $credentialRecord->uvInitialized,
            );
        }

        $this->getEntityManager()->persist($credentialRecord);
        $this->getEntityManager()->flush();
    }

    /**
     * @return array<CredentialRecord>
     */
    public function findAllForUserEntity(PublicKeyCredentialUserEntity $publicKeyCredentialUserEntity): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.userHandle = :userHandle')
            ->setParameter('userHandle', $publicKeyCredentialUserEntity->id)
            ->getQuery()
            ->execute();
    }

    public function findOneByCredentialId(string $publicKeyCredentialId): ?CredentialRecord
    {
        return $this->createQueryBuilder('c')
            ->where('c.publicKeyCredentialId = :publicKeyCredentialId')
            ->setParameter('publicKeyCredentialId', $publicKeyCredentialId)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
