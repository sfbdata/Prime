<?php

declare(strict_types=1);

namespace App\Tests\Voto\Functional;

use App\Tests\Factory\Tenant\TenantFactory;
use Doctrine\ORM\EntityManagerInterface;
use DoctrineMigrations\Version20261111132746;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Zenstruck\Foundry\Test\Factories;

#[CoversClass(Version20261111132746::class)]
final class MigracaoDoPortalDaAssembleiaTest extends KernelTestCase
{
    use Factories;

    public function testDownUpPreencheCodigoUnicoSemAlterarAssembleiasAntigas(): void
    {
        self::bootKernel();
        $tenant = TenantFactory::createOne()->_real();
        $banco = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $comando = new CommandTester((new Application(self::$kernel))->find('doctrine:migrations:execute'));
        self::assertSame(0, $comando->execute(['versions' => ['DoctrineMigrations\\Version20261111132746'], '--down' => true], ['interactive' => false]));
        foreach (['AGO antiga A', 'AGO antiga B'] as $titulo) {
            $banco->executeStatement("INSERT INTO voto.assembleia (tenant_id, titulo, peso, criada_por_nome, criada_em, estado) VALUES (?, ?, 'unidade', 'Gestora', NOW(), 'rascunho')", [$tenant->getId(), $titulo]);
        }
        self::ensureKernelShutdown();
        self::bootKernel();
        $comando = new CommandTester((new Application(self::$kernel))->find('doctrine:migrations:execute'));
        $banco = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
        self::assertSame(0, $comando->execute(['versions' => ['DoctrineMigrations\\Version20261111132746'], '--up' => true], ['interactive' => false]));
        $linhas = $banco->fetchAllAssociative('SELECT titulo, codigo_publico FROM voto.assembleia WHERE tenant_id = ? ORDER BY titulo', [$tenant->getId()]);
        self::assertSame(['AGO antiga A', 'AGO antiga B'], array_column($linhas, 'titulo'));
        foreach ($linhas as $linha) {
            self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $linha['codigo_publico']);
        }
        self::assertNotSame($linhas[0]['codigo_publico'], $linhas[1]['codigo_publico']);
        self::assertSame(6, (int) $banco->fetchOne("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = 'voto' AND table_name = 'assembleia_unidade' AND column_name LIKE 'codigo_%'"));
    }
}
