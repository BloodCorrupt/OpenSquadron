<?php

namespace App\Entity;

interface TenantAwareInterface
{
    public function getOwner(): ?Admin;

    public function setOwner(?Admin $owner): static;
}
