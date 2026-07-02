<?php

declare(strict_types=1);

namespace App\Tests\Processo\Functional;

use App\Entity\Tenant\Tenant;
use App\Processo\Entity\DocumentoProcesso;
use App\Processo\Entity\MovimentacaoProcesso;
use App\Processo\Entity\ParteProcesso;
use App\Processo\Entity\Processo;
use App\Processo\Repository\ProcessoRepository;
use App\Shared\Doctrine\Filter\TenantFilter;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Valida que o TenantFilter isola o domínio Processo após Processo + as 3 filhas virarem
 * TenantAware. Cobre a listagem, os 4 dropdowns de metadados (o maior risco — vazam números
 * de processo/tribunais de todos os escritórios), a carga por id (Processo e cada filha) e a
 * nova unicidade de numeroProcesso por escritório.
 */
#[CoversClass(TenantFilter::class)]
#[CoversClass(ProcessoRepository::class)]
final class ProcessoRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private int $seq = 0;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    #[TestDox('findByFilters só retorna processos do tenant ativo')]
    public function testFiltroIsolaListagem(): void
    {
        [$tenantA, $tenantB] = [$this->criarTenant(), $this->criarTenant()];
        $this->criarProcesso($tenantA, 'TRT2');
        $this->criarProcesso($tenantA, 'TJSP');
        $this->criarProcesso($tenantB, 'TJRJ');

        /** @var ProcessoRepository $repo */
        $repo = $this->em->getRepository(Processo::class);

        self::assertCount(3, $repo->findByFilters([]));

        $this->ligarFiltro((int) $tenantA->getId());
        $doA = $repo->findByFilters([]);
        self::assertCount(2, $doA);
        foreach ($doA as $processo) {
            self::assertSame($tenantA->getId(), $processo->getTenant()->getId());
        }

        $this->ligarFiltro((int) $tenantB->getId());
        self::assertCount(1, $repo->findByFilters([]));
    }

    #[TestDox('Dropdowns (tribunais/números) só expõem metadados do tenant ativo')]
    public function testFiltroIsolaDropdownsDeMetadados(): void
    {
        [$tenantA, $tenantB] = [$this->criarTenant(), $this->criarTenant()];
        $procA = $this->criarProcesso($tenantA, 'TRT2-AAA');
        $procB = $this->criarProcesso($tenantB, 'TJRJ-BBB');

        /** @var ProcessoRepository $repo */
        $repo = $this->em->getRepository(Processo::class);

        $this->ligarFiltro((int) $tenantA->getId());

        $tribunais = $repo->findAllTribunais($tenantA);
        self::assertContains('TRT2-AAA', $tribunais);
        self::assertNotContains('TJRJ-BBB', $tribunais, 'dropdown de tribunais vazou metadado de outro tenant');

        $classes = $repo->findAllClasses();
        self::assertContains('CLS-TRT2-AAA', $classes);
        self::assertNotContains('CLS-TJRJ-BBB', $classes, 'dropdown de classes vazou metadado de outro tenant');

        $assuntos = $repo->findAllAssuntos();
        self::assertContains('ASS-TRT2-AAA', $assuntos);
        self::assertNotContains('ASS-TJRJ-BBB', $assuntos, 'dropdown de assuntos vazou metadado de outro tenant');

        $numeros = $repo->findAllNumerosProcesso();
        self::assertContains($procA->getNumeroProcesso(), $numeros);
        self::assertNotContains($procB->getNumeroProcesso(), $numeros, 'dropdown de números vazou processo de outro tenant');
    }

    #[TestDox('find() por id de Processo de outro tenant retorna null (fecha IDOR da raiz)')]
    public function testFindPorIdFechaIdorDoProcesso(): void
    {
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $procB = $this->criarProcesso($tenantB, 'TJRJ');
        $idB = (int) $procB->getId();

        $this->ligarFiltro((int) $tenantA->getId());
        $this->em->clear();

        self::assertNull($this->em->find(Processo::class, $idB), 'IDOR aberto na raiz Processo');
    }

    #[TestDox('find() por id de cada filha (documento/movimentação/parte) de outro tenant retorna null')]
    public function testFindPorIdFechaIdorDasFilhas(): void
    {
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $procB = $this->criarProcesso($tenantB, 'TJRJ');
        $docB  = $this->criarDocumento($procB);
        $movB  = $this->criarMovimentacao($procB);
        $parteB = $this->criarParte($procB);
        $ids = [
            DocumentoProcesso::class    => (int) $docB->getId(),
            MovimentacaoProcesso::class => (int) $movB->getId(),
            ParteProcesso::class        => (int) $parteB->getId(),
        ];

        $this->ligarFiltro((int) $tenantA->getId());
        $this->em->clear();

        foreach ($ids as $classe => $id) {
            self::assertNull($this->em->find($classe, $id), "IDOR aberto em $classe");
        }
    }

    #[TestDox('findByNumeroProcesso é escopado por tenant e o mesmo número convive em escritórios distintos')]
    public function testNumeroProcessoUnicoPorTenant(): void
    {
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $numero = '0001234-56.2024.5.02.0099';

        // O mesmo número em dois escritórios (partes adversárias) — só possível com unique composto.
        $this->criarProcessoComNumero($tenantA, $numero);
        $this->criarProcessoComNumero($tenantB, $numero);

        /** @var ProcessoRepository $repo */
        $repo = $this->em->getRepository(Processo::class);

        $this->ligarFiltro((int) $tenantA->getId());
        $achadoA = $repo->findByNumeroProcesso($numero);
        self::assertNotNull($achadoA);
        self::assertSame($tenantA->getId(), $achadoA->getTenant()->getId(), 'findByNumeroProcesso devolveu processo de outro tenant');
    }

    // ----------------------------------------------------------------- helpers

    private function ligarFiltro(int $tenantId): void
    {
        $this->em->getFilters()->enable('tenant')->setParameter('tenant', $tenantId, Types::INTEGER);
    }

    private function criarTenant(): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName('Tenant PROC ' . uniqid());
        $this->em->persist($tenant);
        $this->em->flush();

        return $tenant;
    }

    private function criarProcesso(Tenant $tenant, string $sigla): Processo
    {
        return $this->criarProcessoComNumero($tenant, 'NUM-' . (++$this->seq) . '-' . uniqid(), $sigla);
    }

    private function criarProcessoComNumero(Tenant $tenant, string $numero, string $sigla = 'TRT2'): Processo
    {
        $processo = new Processo();
        $processo->setNumeroProcesso($numero);
        $processo->setSiglaTribunal($sigla);
        $processo->setClasseProcessual('CLS-' . $sigla);
        $processo->setAssuntoProcessual('ASS-' . $sigla);
        $processo->setTenant($tenant);
        $this->em->persist($processo);
        $this->em->flush();

        return $processo;
    }

    private function criarDocumento(Processo $processo): DocumentoProcesso
    {
        $doc = new DocumentoProcesso();
        $doc->setProcesso($processo);
        $doc->setTenant($processo->getTenant());
        $doc->setTipo(DocumentoProcesso::TIPO_PECA);
        $doc->setNomeOriginal('peca.pdf');
        $doc->setCaminhoArquivo('fixtures/peca_' . uniqid() . '.pdf');
        $this->em->persist($doc);
        $this->em->flush();

        return $doc;
    }

    private function criarMovimentacao(Processo $processo): MovimentacaoProcesso
    {
        $mov = new MovimentacaoProcesso();
        $mov->setProcesso($processo);
        $mov->setTenant($processo->getTenant());
        $mov->setDescricao('Distribuição');
        $this->em->persist($mov);
        $this->em->flush();

        return $mov;
    }

    private function criarParte(Processo $processo): ParteProcesso
    {
        $parte = new ParteProcesso();
        $parte->setProcesso($processo);
        $parte->setTenant($processo->getTenant());
        $parte->setTipo('AUTOR');
        $parte->setNome('Fulano de Tal');
        $this->em->persist($parte);
        $this->em->flush();

        return $parte;
    }
}
