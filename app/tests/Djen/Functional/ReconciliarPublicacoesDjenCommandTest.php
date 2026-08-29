<?php

declare(strict_types=1);

namespace App\Tests\Djen\Functional;

use App\Djen\Entity\PublicacaoDjen;
use App\Entity\Tenant\Tenant;
use App\Processo\Entity\Processo;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `app:djen:reconciliar` — conserta o passivo das publicações que ficaram avulsas porque o processo
 * entrou no cadastro depois da captura (8 em produção).
 */
#[Group('djen')]
final class ReconciliarPublicacoesDjenCommandTest extends KernelTestCase
{
    #[Test]
    #[TestDox('Liga a publicação avulsa ao processo já cadastrado')]
    public function religaAAvulsa(): void
    {
        self::bootKernel();
        $em     = static::getContainer()->get(EntityManagerInterface::class);
        $tenant = $this->criarTenant($em);
        $numero = '07011345720258070007';
        $this->criarProcesso($em, $tenant, $numero);
        $pubId = $this->criarPublicacaoAvulsa($em, $tenant, '880001', $numero);

        $tester = $this->rodar(['--tenant' => (string) $tenant->getId()]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $em->clear();
        self::assertNotNull($em->find(PublicacaoDjen::class, $pubId)?->getProcesso());
    }

    #[Test]
    #[TestDox('--dry-run conta e não grava')]
    public function dryRunNaoGrava(): void
    {
        self::bootKernel();
        $em     = static::getContainer()->get(EntityManagerInterface::class);
        $tenant = $this->criarTenant($em);
        $numero = '07011345720258070008';
        $this->criarProcesso($em, $tenant, $numero);
        $pubId = $this->criarPublicacaoAvulsa($em, $tenant, '880002', $numero);

        $tester = $this->rodar(['--tenant' => (string) $tenant->getId(), '--dry-run' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('seriam religadas', $tester->getDisplay());
        $em->clear();
        self::assertNull($em->find(PublicacaoDjen::class, $pubId)?->getProcesso(), 'simulação não pode gravar');
    }

    #[Test]
    #[TestDox('Não atravessa escritório: processo de A não adota publicação de B com o mesmo número')]
    public function naoAtravessaEscritorio(): void
    {
        self::bootKernel();
        $em      = static::getContainer()->get(EntityManagerInterface::class);
        $tenantA = $this->criarTenant($em);
        $tenantB = $this->criarTenant($em);
        $numero  = '07011345720258070009';
        $this->criarProcesso($em, $tenantA, $numero);
        $pubB = $this->criarPublicacaoAvulsa($em, $tenantB, '880003', $numero);

        $tester = $this->rodar(['--tenant' => (string) $tenantA->getId()]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $em->clear();
        self::assertNull($em->find(PublicacaoDjen::class, $pubB)?->getProcesso());
    }

    #[Test]
    #[TestDox('Publicação do escritório A NÃO adota processo de B que tenha o mesmo número')]
    public function naoAdotaProcessoDeOutroEscritorio(): void
    {
        // A direção que importa. O teste anterior (a publicação de B não é tocada) fica verde mesmo
        // sem o filtro de tenant na busca de processos — medido por reintrodução —, porque a lista
        // de avulsas já é escopada. O vazamento possível é este: a FK da publicação de A apontando
        // para o processo de B. Dois escritórios no mesmo processo é situação comum.
        self::bootKernel();
        $em      = static::getContainer()->get(EntityManagerInterface::class);
        $tenantA = $this->criarTenant($em);
        $tenantB = $this->criarTenant($em);
        $numero  = '07011345720258070011';
        $this->criarProcesso($em, $tenantB, $numero);          // só B tem o processo cadastrado
        $pubA = $this->criarPublicacaoAvulsa($em, $tenantA, '880005', $numero);

        $tester = $this->rodar(['--tenant' => (string) $tenantA->getId()]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $em->clear();
        self::assertNull(
            $em->find(PublicacaoDjen::class, $pubA)?->getProcesso(),
            'a publicação de A não pode ficar apontando para o processo de B',
        );
    }

    #[Test]
    #[TestDox('Rodar de novo não muda nada — é idempotente')]
    public function ehIdempotente(): void
    {
        self::bootKernel();
        $em     = static::getContainer()->get(EntityManagerInterface::class);
        $tenant = $this->criarTenant($em);
        $numero = '07011345720258070010';
        $this->criarProcesso($em, $tenant, $numero);
        $this->criarPublicacaoAvulsa($em, $tenant, '880004', $numero);

        $this->rodar(['--tenant' => (string) $tenant->getId()]);
        $segunda = $this->rodar(['--tenant' => (string) $tenant->getId()]);

        self::assertSame(Command::SUCCESS, $segunda->getStatusCode());
        self::assertStringContainsString('0 publicação(ões) religadas ao processo', $segunda->getDisplay());
    }

    #[Test]
    #[TestDox('Escritório inexistente falha em vez de rodar em todos')]
    public function escritorioInexistenteFalha(): void
    {
        self::bootKernel();

        $tester = $this->rodar(['--tenant' => '99999999']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
    }

    /** @param array<string, string|bool> $args */
    private function rodar(array $args): CommandTester
    {
        $tester = new CommandTester((new Application(self::$kernel))->find('app:djen:reconciliar'));
        $tester->execute($args);

        return $tester;
    }

    private function criarTenant(EntityManagerInterface $em): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName('Escritório Reconciliar ' . uniqid());
        $em->persist($tenant);
        $em->flush();

        return $tenant;
    }

    private function criarProcesso(EntityManagerInterface $em, Tenant $tenant, string $numero): Processo
    {
        $processo = new Processo();
        $processo->setTenant($tenant);
        $processo->setNumeroProcesso($numero);
        $processo->setClasseProcessual('Procedimento Comum');
        $em->persist($processo);
        $em->flush();

        return $processo;
    }

    private function criarPublicacaoAvulsa(EntityManagerInterface $em, Tenant $tenant, string $djenId, string $numero): int
    {
        $pub = new PublicacaoDjen();
        $pub->setTenant($tenant);
        $pub->setDjenId($djenId);
        $pub->setNumeroProcesso($numero);
        $pub->setSiglaTribunal('TJDFT');
        $pub->setDataDisponibilizacao(new \DateTimeImmutable('2026-08-20'));
        $em->persist($pub);
        $em->flush();

        return (int) $pub->getId();
    }
}
