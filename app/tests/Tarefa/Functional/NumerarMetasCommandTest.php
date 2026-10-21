<?php

declare(strict_types=1);

namespace App\Tests\Tarefa\Functional;

use App\Entity\Tarefa\Tarefa;
use App\Entity\Tenant\Tenant;
use App\Tarefa\Command\NumerarMetasCommand;
use App\Tarefa\Service\NumeracaoDeMetas;
use App\Tarefa\UseCase\NumerarMetasAntigasUseCase;
use App\Tests\Factory\Pasta\PastaFactory;
use App\Tests\Factory\Tenant\TenantFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Zenstruck\Foundry\Test\Factories;

/**
 * Backfill `app:tarefa:numerar-metas`: as metas antigas (número nulo) ganham número por escritório, em
 * ordem de criação, a partir do próximo livre. Roda sem sessão: o escritório é sempre explícito, e nada
 * aqui loga ninguém nem liga o TenantFilter.
 *
 * O banco de teste pode ter metas de outros escritórios: as conferências olham só os escritórios criados
 * em cada teste, e a maioria roda com `--escritorio`.
 */
#[CoversClass(NumerarMetasCommand::class)]
#[CoversClass(NumerarMetasAntigasUseCase::class)]
#[CoversClass(NumeracaoDeMetas::class)]
final class NumerarMetasCommandTest extends KernelTestCase
{
    use Factories;

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    /** @param array<string, mixed> $opcoes */
    private function rodar(array $opcoes = []): CommandTester
    {
        $application = new Application(self::$kernel);
        $tester = new CommandTester($application->find('app:tarefa:numerar-metas'));
        $tester->execute($opcoes);

        return $tester;
    }

    /** Meta antiga: gravada direto, sem passar pela numeração, com a data de criação escolhida. */
    private function metaAntiga(Tenant $tenant, string $titulo, string $criadaEm, ?int $numero = null): int
    {
        $pasta = PastaFactory::createOne(['tenant' => $tenant])->_real();

        $meta = new Tarefa();
        $meta->setTitulo($titulo);
        $meta->setDescricao('...');
        $meta->setPasta($pasta);
        $meta->setTenant($tenant);
        if ($numero !== null) {
            $meta->numerar($numero);
        }
        $this->em()->persist($meta);
        $this->em()->flush();

        $this->em()->getConnection()->executeStatement(
            'UPDATE tarefa SET data_criacao = ? WHERE id = ?',
            [$criadaEm, $meta->getId()],
        );

        return (int) $meta->getId();
    }

    /** @return array<string, int|null> título => número, direto do banco */
    private function numerosDe(Tenant $tenant): array
    {
        $linhas = $this->em()->getConnection()->fetchAllAssociative(
            'SELECT titulo, numero FROM tarefa WHERE tenant_id = ? ORDER BY id',
            [$tenant->getId()],
        );

        $numeros = [];
        foreach ($linhas as $linha) {
            $numeros[(string) $linha['titulo']] = $linha['numero'] === null ? null : (int) $linha['numero'];
        }

        return $numeros;
    }

    #[TestDox('--dry-run lista quantas metas o escritório teria numeradas e não grava nada')]
    public function testDryRunNaoGrava(): void
    {
        self::bootKernel();
        $tenant = TenantFactory::createOne()->_real();
        $this->metaAntiga($tenant, 'A', '2026-01-01 10:00:00');
        $this->metaAntiga($tenant, 'B', '2026-01-02 10:00:00');

        $tester = $this->rodar(['--dry-run' => true, '--escritorio' => (string) $tenant->getId()]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertMatchesRegularExpression('/\b' . $tenant->getId() . '\s+2\b/', $tester->getDisplay());
        self::assertStringContainsString('nada foi gravado', $tester->getDisplay());
        self::assertSame(['A' => null, 'B' => null], $this->numerosDe($tenant));
        self::assertFalse(
            $this->em()->getConnection()->fetchOne('SELECT 1 FROM tarefa_numeracao WHERE tenant_id = ?', [$tenant->getId()]),
            'a simulação não cria o contador',
        );
    }

    #[TestDox('Numera em ordem de criação (data, depois id), a partir do próximo número livre do escritório')]
    public function testNumeraEmOrdemDeCriacaoAPartirDoProximoLivre(): void
    {
        self::bootKernel();
        $tenant = TenantFactory::createOne()->_real();
        // Gravadas fora de ordem: quem manda é a data de criação. C e D empatam na data: o id desempata.
        $this->metaAntiga($tenant, 'Terceira', '2026-03-01 10:00:00');
        $this->metaAntiga($tenant, 'Primeira', '2026-01-01 10:00:00');
        $this->metaAntiga($tenant, 'Nova', '2026-10-21 10:00:00', 1);
        $this->metaAntiga($tenant, 'Segunda C', '2026-02-01 10:00:00');
        $this->metaAntiga($tenant, 'Segunda D', '2026-02-01 10:00:00');

        $tester = $this->rodar(['--escritorio' => (string) $tenant->getId()]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        // A meta que já tinha o 1 (criada depois da publicação) fica com ele; as antigas seguem do 2.
        self::assertSame(
            ['Terceira' => 5, 'Primeira' => 2, 'Nova' => 1, 'Segunda C' => 3, 'Segunda D' => 4],
            $this->numerosDe($tenant),
        );
        self::assertSame(
            5,
            (int) $this->em()->getConnection()->fetchOne('SELECT ultimo_numero FROM tarefa_numeracao WHERE tenant_id = ?', [$tenant->getId()]),
            'o contador anda junto: a próxima meta criada pega o 6',
        );
    }

    #[TestDox('Rodar duas vezes é idempotente: a segunda rodada não muda nada')]
    public function testRodarDuasVezesEhIdempotente(): void
    {
        self::bootKernel();
        $tenant = TenantFactory::createOne()->_real();
        $this->metaAntiga($tenant, 'A', '2026-01-01 10:00:00');
        $this->metaAntiga($tenant, 'B', '2026-01-02 10:00:00');

        $this->rodar(['--escritorio' => (string) $tenant->getId()]);
        $depoisDaPrimeira = $this->numerosDe($tenant);

        $tester = $this->rodar(['--escritorio' => (string) $tenant->getId()]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Nada a fazer', $tester->getDisplay());
        self::assertSame(['A' => 1, 'B' => 2], $depoisDaPrimeira);
        self::assertSame($depoisDaPrimeira, $this->numerosDe($tenant));
    }

    #[TestDox('--escritorio numera só aquele escritório; o outro continua sem número')]
    public function testEscritorioRespeitaOEscritorio(): void
    {
        self::bootKernel();
        $meu   = TenantFactory::createOne()->_real();
        $outro = TenantFactory::createOne()->_real();
        $this->metaAntiga($meu, 'Minha', '2026-01-01 10:00:00');
        $this->metaAntiga($outro, 'Do outro', '2026-01-01 10:00:00');

        $this->rodar(['--escritorio' => (string) $meu->getId()]);

        self::assertSame(['Minha' => 1], $this->numerosDe($meu));
        self::assertSame(['Do outro' => null], $this->numerosDe($outro));
    }

    #[TestDox('Sem --escritorio numera cada escritório na própria sequência, cada um começando no 1')]
    public function testSemEscritorioNumeraCadaUmNaSuaSequencia(): void
    {
        self::bootKernel();
        $a = TenantFactory::createOne()->_real();
        $b = TenantFactory::createOne()->_real();
        $this->metaAntiga($a, 'A1', '2026-01-01 10:00:00');
        $this->metaAntiga($b, 'B1', '2026-01-01 11:00:00');
        $this->metaAntiga($a, 'A2', '2026-01-02 10:00:00');

        $tester = $this->rodar();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertSame(['A1' => 1, 'A2' => 2], $this->numerosDe($a));
        self::assertSame(['B1' => 1], $this->numerosDe($b));
    }

    #[TestDox('--escritorio que não é um id numérico é recusado sem gravar')]
    public function testEscritorioInvalidoEhRecusado(): void
    {
        self::bootKernel();

        $tester = $this->rodar(['--escritorio' => 'abc']);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
    }
}
