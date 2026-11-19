<?php

declare(strict_types=1);

namespace App\Tests\Processo\Integration;

use App\Entity\Tenant\Tenant;
use App\Processo\Entity\MovimentacaoProcesso;
use App\Processo\Entity\Processo;
use App\Processo\Repository\MovimentacaoProcessoRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

#[CoversClass(MovimentacaoProcessoRepository::class)]
final class MovimentacaoProcessoRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private MovimentacaoProcessoRepository $repo;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->repo = static::getContainer()->get(MovimentacaoProcessoRepository::class);
    }

    #[TestDox('paginaDoProcesso: a mais recente primeiro, sem data por último, pagina e traz uma linha a mais quando há próxima página')]
    public function testPaginaEOrdem(): void
    {
        $tenant = $this->tenant();
        $processo = $this->processo($tenant);
        $this->mov($processo, $tenant, 'antiga', '2026-01-01');
        $this->mov($processo, $tenant, 'meio', '2026-02-01');
        $this->mov($processo, $tenant, 'recente', '2026-03-01');
        $this->mov($processo, $tenant, 'sem data', null);

        $primeira = $this->repo->paginaDoProcesso($processo, $tenant, 1, 2);
        self::assertSame(['RECENTE', 'MEIO', 'ANTIGA'], array_map(static fn (MovimentacaoProcesso $m): string => $m->getDescricao(), $primeira), 'porPagina + 1 linhas: a 3ª só sinaliza que há mais');

        $segunda = $this->repo->paginaDoProcesso($processo, $tenant, 2, 2);
        self::assertSame(['ANTIGA', 'SEM DATA'], array_map(static fn (MovimentacaoProcesso $m): string => $m->getDescricao(), $segunda));
    }

    #[TestDox('paginaDoProcesso: nunca traz movimentação de outro escritório, mesmo pedindo o processo dele')]
    public function testIsolaPorEscritorio(): void
    {
        $dono = $this->tenant();
        $outro = $this->tenant();
        $processo = $this->processo($dono);
        $this->mov($processo, $dono, 'minha', '2026-01-01');

        self::assertCount(1, $this->repo->paginaDoProcesso($processo, $dono, 1, 10));
        self::assertSame([], $this->repo->paginaDoProcesso($processo, $outro, 1, 10));
    }

    private function tenant(): Tenant
    {
        $tenant = (new Tenant())->setName('Mov ' . uniqid())->setIsActive(true);
        $this->em->persist($tenant);
        $this->em->flush();

        return $tenant;
    }

    private function processo(Tenant $tenant): Processo
    {
        $processo = (new Processo())->setTenant($tenant)->setNumeroProcesso(sprintf('0701134572025%07d', random_int(0, 9999999)))->setSiglaTribunal('TJDFT');
        $this->em->persist($processo);
        $this->em->flush();

        return $processo;
    }

    private function mov(Processo $processo, Tenant $tenant, string $descricao, ?string $data): void
    {
        $this->em->persist((new MovimentacaoProcesso())->setDescricao($descricao)->setDataMovimentacao($data !== null ? new \DateTime($data) : null)->setProcesso($processo)->setTenant($tenant));
        $this->em->flush();
    }
}
