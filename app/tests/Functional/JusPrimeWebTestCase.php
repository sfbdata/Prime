<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Auth\Tenant;
use App\Entity\Auth\User;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

abstract class JusPrimeWebTestCase extends WebTestCase
{
    protected function logarComTenant(
        KernelBrowser $client,
        User $user,
        Tenant $tenant,
    ): void {
        $client->loginUser($user);
        $session = $client->getSession();
        $session->set('current_tenant_id', $tenant->getId());
        $session->save();
    }
}
