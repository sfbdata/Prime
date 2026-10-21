<?php

declare(strict_types=1);

namespace App\Tests\Tarefa\Functional;

use App\Entity\Tarefa\Tarefa;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\PrioridadePasta;
use App\Tarefa\Command\HerdarPrioridadeDaPastaCommand;
use App\Tarefa\Enum\PrioridadeDaMeta;
use App\Tarefa\Repository\TarefaRepository;
use App\Tarefa\UseCase\HerdarPrioridadeDaPastaUseCase;
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
 * Backfill `app:tarefa:herdar-prioridade-da-pasta`: as metas antigas (todas `normal` depois da migration)
 * herdam a prioridade da pasta, por escritório. Roda sem sessão: o escritório é sempre explícito.
 *
 * O banco de teste pode ter metas de outros escritórios: as conferências olham só os escritórios criados
 * em cada teste.
 */
#[CoversClass(HerdarPrioridadeDaPastaCommand::class)]
#[CoversClass(HerdarPrioridadeDaPastaUseCase::class)]
#[CoversClass(TarefaRepository::class)]
final class HerdarPrioridadeDaPastaCommandTest extends KernelTestCase
{
    use Factories;

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    /** @param array<string, mixed> $opcoes */
    private function rodar(array $opcoes = []): CommandTester
    {
        $tester = new CommandTester((new Application(self::$kernel))->find('app:tarefa:herdar-prioridade-da-pasta'));
        $tester->execute($opcoes);

        return $tester;
    }

    /** Meta antiga: gravada direto (sem o UseCase, que já herdaria), com a prioridade escolhida. */
    private function metaAntiga(Tenant $tenant, string $titulo, PrioridadePasta $daPasta, PrioridadeDaMeta $daMeta = PrioridadeDaMeta::Normal): void
    {
        $meta = new Tarefa();
        $meta->setTitulo($titulo);
        $meta->setDescricao('...');
        $meta->setPasta(PastaFactory::createOne(['tenant' => $tenant, 'prioridade' => $daPasta])->_real());
        $meta->setTenant($tenant);
        $meta->setPrioridade($daMeta);
        $this->em()->persist($meta);
        $this->em()->flush();
    }

    /** @return array<string, string> título => prioridade, direto do banco */
    private function prioridadesDe(Tenant $tenant): array
    {
        $linhas = $this->em()->getConnection()->fetchAllAssociative(
            'SELECT titulo, prioridade FROM tarefa WHERE tenant_id = ? ORDER BY id',
            [$tenant->getId()],
        );

        return array_column($linhas, 'prioridade', 'titulo');
    }

    #[TestDox('--dry-run lista quantas metas o escritório teria atualizadas e não grava nada')]
    public function testDryRunNaoGrava(): void
    {
        self::bootKernel();
        $tenant = TenantFactory::createOne()->_real();
        $this->metaAntiga($tenant, 'Da urgente', PrioridadePasta::Urgente);
        $this->metaAntiga($tenant, 'Da prioridade', PrioridadePasta::Prioridade);
        $this->metaAntiga($tenant, 'Da normal', PrioridadePasta::Normal);

        $tester = $this->rodar(['--dry-run' => true, '--escritorio' => (string) $tenant->getId()]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertMatchesRegularExpression('/\b' . $tenant->getId() . '\s+2\b/', $tester->getDisplay());
        self::assertStringContainsString('nada foi gravado', $tester->getDisplay());
        self::assertSame(['Da urgente' => 'normal', 'Da prioridade' => 'normal', 'Da normal' => 'normal'], $this->prioridadesDe($tenant));
    }

    #[TestDox('Herda da pasta (urgente vira urgente, prioridade vira alta) e não mexe em meta que já não é normal')]
    public function testHerdaDaPastaSemTocarNaJaEscolhida(): void
    {
        self::bootKernel();
        $tenant = TenantFactory::createOne()->_real();
        $this->metaAntiga($tenant, 'Da urgente', PrioridadePasta::Urgente);
        $this->metaAntiga($tenant, 'Da prioridade', PrioridadePasta::Prioridade);
        $this->metaAntiga($tenant, 'Da normal', PrioridadePasta::Normal);
        $this->metaAntiga($tenant, 'Alta em pasta urgente', PrioridadePasta::Urgente, PrioridadeDaMeta::Alta);

        $tester = $this->rodar(['--escritorio' => (string) $tenant->getId()]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertSame(
            ['Da urgente' => 'urgente', 'Da prioridade' => 'alta', 'Da normal' => 'normal', 'Alta em pasta urgente' => 'alta'],
            $this->prioridadesDe($tenant),
        );
    }

    #[TestDox('Rodar duas vezes é idempotente: a segunda rodada não muda nada')]
    public function testIdempotente(): void
    {
        self::bootKernel();
        $tenant = TenantFactory::createOne()->_real();
        $this->metaAntiga($tenant, 'Da urgente', PrioridadePasta::Urgente);

        $this->rodar(['--escritorio' => (string) $tenant->getId()]);
        $segunda = $this->rodar(['--escritorio' => (string) $tenant->getId()]);

        self::assertSame(Command::SUCCESS, $segunda->getStatusCode());
        self::assertStringContainsString('Nada a fazer', $segunda->getDisplay());
        self::assertSame(['Da urgente' => 'urgente'], $this->prioridadesDe($tenant));
    }

    #[TestDox('ISOLAMENTO: --escritorio=A não toca nas metas do escritório B; sem a opção, cada um herda a sua')]
    public function testIsolamentoEntreEscritorios(): void
    {
        self::bootKernel();
        $a = TenantFactory::createOne()->_real();
        $b = TenantFactory::createOne()->_real();
        $this->metaAntiga($a, 'De A', PrioridadePasta::Urgente);
        $this->metaAntiga($b, 'De B', PrioridadePasta::Prioridade);

        $this->rodar(['--escritorio' => (string) $a->getId()]);
        self::assertSame(['De A' => 'urgente'], $this->prioridadesDe($a));
        self::assertSame(['De B' => 'normal'], $this->prioridadesDe($b), 'o escritório B ficou como estava');

        $this->rodar();
        self::assertSame(['De B' => 'alta'], $this->prioridadesDe($b));
    }

    #[TestDox('ISOLAMENTO: meta cuja pasta é de outro escritório (dado inconsistente) não herda pela pasta alheia')]
    public function testPastaDeOutroEscritorioNaoContamina(): void
    {
        self::bootKernel();
        $a = TenantFactory::createOne()->_real();
        $b = TenantFactory::createOne()->_real();
        $this->metaAntiga($a, 'Meta de A', PrioridadePasta::Normal);
        $pastaDeB = PastaFactory::createOne(['tenant' => $b, 'prioridade' => PrioridadePasta::Urgente])->_real();
        $this->em()->getConnection()->executeStatement('UPDATE tarefa SET pasta_id = ? WHERE tenant_id = ?', [$pastaDeB->getId(), $a->getId()]);

        $this->rodar(['--escritorio' => (string) $a->getId()]);

        self::assertSame(['Meta de A' => 'normal'], $this->prioridadesDe($a));
    }

    #[TestDox('--escritorio que não é id numérico é recusado sem gravar')]
    public function testEscritorioInvalido(): void
    {
        self::bootKernel();

        $tester = $this->rodar(['--escritorio' => 'abc']);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
    }
}
