<?php

declare(strict_types=1);

namespace App\Tests\Processo\Functional;

use App\Entity\Tenant\Tenant;
use App\Processo\Entity\Processo;
use App\Processo\Repository\ProcessoRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Cobre os novos métodos paginados/filtrados da tela de índice de Processos.
 * O TenantFilter global fica DESLIGADO aqui de propósito: os testes provam que o
 * `andWhere('p.tenant = :tenant')` explícito (defesa em profundidade) isola sozinho.
 */
#[CoversClass(ProcessoRepository::class)]
final class ProcessoRepositoryFiltrosTest extends KernelTestCase
{
    private \Doctrine\ORM\EntityManagerInterface $em;
    private ProcessoRepository $repo;
    private int $seq = 0;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em   = static::getContainer()->get(\Doctrine\ORM\EntityManagerInterface::class);
        $this->repo = $this->em->getRepository(Processo::class);
    }

    #[TestDox('findByFiltrosPaginado escopa por tenant mesmo com o TenantFilter desligado')]
    public function testEscopaPorTenantExplicito(): void
    {
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $this->criarProcesso($tenantA, 'TRT2', 'Execução Fiscal');
        $this->criarProcesso($tenantA, 'TJSP', 'Mandado de Segurança');
        $this->criarProcesso($tenantB, 'TJRJ', 'Ação Civil');

        $doA = $this->repo->findByFiltrosPaginado($tenantA, [], 1, 25, '', 'desc');
        self::assertCount(2, $doA);
        foreach ($doA as $processo) {
            self::assertSame($tenantA->getId(), $processo->getTenant()->getId());
        }

        self::assertCount(1, $this->repo->findByFiltrosPaginado($tenantB, [], 1, 25, '', 'desc'));
        self::assertSame(2, $this->repo->countByFiltros($tenantA, []));
        self::assertSame(1, $this->repo->countByFiltros($tenantB, []));
    }

    #[TestDox('Busca livre filtra por número, classe, assunto ou tribunal — sempre dentro do tenant')]
    public function testBuscaLivre(): void
    {
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $this->criarProcesso($tenantA, 'TRT2', 'Execução Fiscal');
        $this->criarProcesso($tenantA, 'TJSP', 'Reclamação Trabalhista');
        // Mesmo termo em outro tenant não pode aparecer.
        $this->criarProcesso($tenantB, 'TRT2', 'Execução Fiscal');

        $porClasse = $this->repo->findByFiltrosPaginado($tenantA, ['busca' => 'execução'], 1, 25, '', 'desc');
        self::assertCount(1, $porClasse);
        self::assertStringContainsStringIgnoringCase('execução', (string) $porClasse[0]->getClasseProcessual());

        $porTribunal = $this->repo->findByFiltrosPaginado($tenantA, ['busca' => 'TJSP'], 1, 25, '', 'desc');
        self::assertCount(1, $porTribunal);

        self::assertSame(1, $this->repo->countByFiltros($tenantA, ['busca' => 'execução']));
    }

    #[TestDox('Faceta de tribunal e situação estreitam o conjunto')]
    public function testFacetasTribunalESituacao(): void
    {
        $tenant = $this->criarTenant();
        $this->criarProcesso($tenant, 'TRT2', 'A', 'EM_ANDAMENTO');
        $this->criarProcesso($tenant, 'TRT2', 'B', 'ARQUIVADO');
        $this->criarProcesso($tenant, 'TJSP', 'C', 'EM_ANDAMENTO');

        self::assertCount(2, $this->repo->findByFiltrosPaginado($tenant, ['tribunal' => 'TRT2'], 1, 25, '', 'desc'));
        self::assertCount(2, $this->repo->findByFiltrosPaginado($tenant, ['situacao' => 'EM_ANDAMENTO'], 1, 25, '', 'desc'));
        self::assertCount(1, $this->repo->findByFiltrosPaginado($tenant, ['tribunal' => 'TRT2', 'situacao' => 'ARQUIVADO'], 1, 25, '', 'desc'));
    }

    #[TestDox('Paginação devolve o pedaço certo do conjunto')]
    public function testPaginacao(): void
    {
        $tenant = $this->criarTenant();
        for ($i = 0; $i < 5; $i++) {
            $this->criarProcesso($tenant, 'TRT' . $i, 'Classe ' . $i);
        }

        self::assertCount(2, $this->repo->findByFiltrosPaginado($tenant, [], 1, 2, '', 'desc'));
        self::assertCount(2, $this->repo->findByFiltrosPaginado($tenant, [], 2, 2, '', 'desc'));
        self::assertCount(1, $this->repo->findByFiltrosPaginado($tenant, [], 3, 2, '', 'desc'));
        self::assertSame(5, $this->repo->countByFiltros($tenant, []));
    }

    // ----------------------------------------------------------------- helpers

    private function criarTenant(): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName('Tenant PROC FILTRO ' . uniqid());
        $this->em->persist($tenant);
        $this->em->flush();

        return $tenant;
    }

    private function criarProcesso(Tenant $tenant, string $sigla, string $classe, string $situacao = 'EM_ANDAMENTO'): Processo
    {
        $processo = new Processo();
        $processo->setNumeroProcesso('NUM-' . (++$this->seq) . '-' . uniqid());
        $processo->setSiglaTribunal($sigla);
        $processo->setClasseProcessual($classe);
        $processo->setAssuntoProcessual('Assunto ' . $classe);
        $processo->setSituacaoProcesso($situacao);
        $processo->setTenant($tenant);
        $this->em->persist($processo);
        $this->em->flush();

        return $processo;
    }
}
