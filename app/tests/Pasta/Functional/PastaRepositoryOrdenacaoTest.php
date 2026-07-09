<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Pasta\Entity\Pasta;
use App\Pasta\Repository\PastaRepository;
use App\Tests\Factory\Pasta\PastaFactory;
use App\Tests\Factory\Tenant\TenantFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

#[CoversClass(PastaRepository::class)]
#[Group('pasta')]
final class PastaRepositoryOrdenacaoTest extends KernelTestCase
{
    use Factories;

    private PastaRepository $repo;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->repo = static::getContainer()->get(PastaRepository::class);
    }

    #[TestDox('findByFilters ordena NUP com sufixo de letra de forma natural (10A/10B junto do 10, não no fim)')]
    public function testOrdenacaoNaturalComSufixoDeLetra(): void
    {
        $tenant = TenantFactory::createOne()->_real();
        foreach (['9', '10', '10A', '10B', '11'] as $nup) {
            PastaFactory::createOne(['tenant' => $tenant, 'nup' => $nup, 'nomeCliente' => 'CLIENTE ' . $nup]);
        }

        $nups = array_map(
            static fn (Pasta $p): string => $p->getNup(),
            $this->repo->findByFilters([], $tenant),
        );

        // Direção atual (DESC): número maior primeiro; dentro do mesmo número, a letra
        // fica adjacente (10B > 10A > 10), nunca jogada para o fim da lista.
        self::assertSame(['11', '10B', '10A', '10', '9'], $nups);
    }
}
