<?php

namespace App\Filter;

use App\Entity\TenantAwareInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\Filter\SQLFilter;

class TenantFilter extends SQLFilter
{
    public function addFilterConstraint(ClassMetadata $targetEntity, $targetTableAlias): string
    {
        // Check if the target entity class implements TenantAwareInterface
        if (!$targetEntity->reflClass->implementsInterface(TenantAwareInterface::class)) {
            return '';
        }

        try {
            $ownerId = $this->getParameter('owner_id');
        } catch (\InvalidArgumentException $e) {
            // Parameter has not been defined yet in the context
            return '';
        }

        if (empty($ownerId)) {
            return '';
        }

        // Clean query to filter strictly by owner_id
        return sprintf('%s.owner_id = %s', $targetTableAlias, $ownerId);
    }
}
