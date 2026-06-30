<?php

declare(strict_types=1);

namespace App\Tests\Processo\Functional;

use App\Command\AtualizarProcessoDatajudCommand;
use App\Entity\Tenant\Tenant;
use App\Processo\Entity\Processo;
use App\Processo\Repository\ProcessoRepository;
use App\Processo\Service\DatajudClient;
use App\Processo\Service\DatajudProcessoMapper;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * B1 — o fluxo Datajud roda no CLI com o TenantFilter DESLIGADO, e `numero_processo` é único só
 * POR tenant. Sem escopo, o `findOneBy(['numeroProcesso' => ...])` casa um processo homônimo de
 * OUTRO escritório e o sobrescreve (corrupção cross-tenant). Estes testes rodam em KernelTestCase
 * (filtro OFF, igual ao CLI) e provam que o escopo explícito por tenant protege o outro escritório.
 */
final class DatajudIsolamentoTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    #[TestDox('Atualizar via Datajud NÃO sobrescreve processo homônimo de outro tenant (cria no tenant do base)')]
    public function testNaoSobrescreveProcessoHomonimoDeOutroTenant(): void
    {
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();

        // Vítima: processo "0001" no tenant B (é o único "0001" no banco).
        $vitimaB = $this->criarProcesso($tenantB, '0001', 'CLS-ORIGINAL-B');
        // Base informado ao comando: pertence ao tenant A.
        $base = $this->criarProcesso($tenantA, 'BASE-A-' . uniqid(), 'CLS-BASE');

        $vitimaBId = (int) $vitimaB->getId();
        $baseId    = (int) $base->getId();

        // Datajud devolve um hit para "0001" com classe nova.
        $this->stubDatajud([
            'hits' => ['hits' => [
                ['_source' => ['numeroProcesso' => '0001', 'classe' => ['nome' => 'CLS-NOVA'], 'tribunal' => 'TJSP']],
            ]],
        ]);

        $tester = new CommandTester(static::getContainer()->get(AtualizarProcessoDatajudCommand::class));
        $tester->execute([
            'processoId'     => $baseId,
            'tribunalAlias'  => 'api_publica_tjsp',
            'numeroProcesso' => '0001',
        ]);
        $tester->assertCommandIsSuccessful();

        $this->em->clear();

        // Vítima de B: INTACTA (não sobrescrita).
        $vitimaReload = $this->em->find(Processo::class, $vitimaBId);
        self::assertSame('CLS-ORIGINAL-B', $vitimaReload->getClasseProcessual(), 'processo de B NÃO pode ser sobrescrito');

        // Tenant A ganhou um processo "0001" novo com a classe do Datajud.
        $novoA = $this->repo()->findOneBy(['numeroProcesso' => '0001', 'tenant' => $tenantA]);
        self::assertNotNull($novoA, 'o processo do Datajud deve ser criado no tenant do base');
        self::assertSame('CLS-NOVA', $novoA->getClasseProcessual());
        self::assertNotSame($vitimaBId, (int) $novoA->getId(), 'deve ser um processo distinto do de B');
    }

    #[TestDox('O processo-pai resolvido pelo mapper respeita o tenant (não linka pai de outro escritório)')]
    public function testProcessoPaiNaoVazaEntreTenants(): void
    {
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();

        // Pai homônimo nos dois tenants; o de B é criado PRIMEIRO (id menor) — é o que um lookup
        // sem escopo tenderia a casar.
        $paiB = $this->criarProcesso($tenantB, 'PAI-001', 'CLS-PAI-B');
        $paiA = $this->criarProcesso($tenantA, 'PAI-001', 'CLS-PAI-A');
        $paiAId = (int) $paiA->getId();

        $filho = $this->criarProcesso($tenantA, 'FILHO-001', 'CLS-FILHO');

        /** @var DatajudProcessoMapper $mapper */
        $mapper = static::getContainer()->get(DatajudProcessoMapper::class);
        $mapper->mapFromSource($filho, ['processoPai' => 'PAI-001']);

        $ref = $filho->getProcessoPaiRef();
        self::assertNotNull($ref, 'o pai do mesmo tenant deve ser linkado');
        self::assertSame($paiAId, (int) $ref->getId(), 'o pai linkado deve ser o do tenant A, não o de B');
        self::assertSame((int) $tenantA->getId(), (int) $ref->getTenant()->getId(), 'o pai não pode ser de outro tenant');
        self::assertNotSame((int) $paiB->getId(), (int) $ref->getId());
    }

    // ----------------------------------------------------------------- helpers

    private function repo(): ProcessoRepository
    {
        return static::getContainer()->get(ProcessoRepository::class);
    }

    /**
     * @param array<string,mixed> $response
     */
    private function stubDatajud(array $response): void
    {
        $client = $this->createMock(DatajudClient::class);
        $client->method('searchByNumeroProcesso')->willReturn($response);
        static::getContainer()->set(DatajudClient::class, $client);
    }

    private function criarTenant(): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName('Tenant DATAJUD ' . uniqid());
        $this->em->persist($tenant);
        $this->em->flush();

        return $tenant;
    }

    private function criarProcesso(Tenant $tenant, string $numero, string $classe): Processo
    {
        $processo = new Processo();
        $processo->setNumeroProcesso($numero);
        $processo->setClasseProcessual($classe);
        $processo->setTenant($tenant);
        $this->em->persist($processo);
        $this->em->flush();

        return $processo;
    }
}
