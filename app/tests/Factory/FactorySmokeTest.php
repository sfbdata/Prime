<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Tests\Factory\Auth\UserFactory;
use App\Tests\Factory\Auth\UserTenantFactory;
use App\Tests\Factory\Pasta\PastaFactory;
use App\Tests\Factory\Tarefa\TarefaFactory;
use App\Tests\Factory\Tenant\TenantFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class FactorySmokeTest extends KernelTestCase
{
    use ResetDatabase;
    use Factories;

    public function testCadaFactoryPersisteSemErro(): void
    {
        TenantFactory::createOne();
        UserFactory::createOne();
        UserTenantFactory::createOne();
        PastaFactory::createOne();
        TarefaFactory::createOne();

        self::assertTrue(true);
    }
}
