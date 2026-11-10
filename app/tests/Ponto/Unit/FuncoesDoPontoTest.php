<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Master\Entity\RecursoEstado;
use App\Master\Repository\ContasDoSistemaRepository;
use App\Master\Repository\RecursoEstadoRepository;
use App\Master\Service\RecursosDoSistema;
use App\Master\Service\RegraDosMasters;
use App\Ponto\Entity\ConfiguracaoDoPonto;
use App\Ponto\Repository\ConfiguracaoDoPontoRepository;
use App\Ponto\Service\FuncoesDoPonto;
use App\Service\PermissionChecker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * As duas camadas das funções do Ponto (spec docs/specs/ponto-desenho-0811.md, E7, P95 e P96): vale o Global (Master)
 * E o Escritório; o Global desligado trava; chave sem registro em nenhuma camada nasce ligada.
 */
#[CoversClass(FuncoesDoPonto::class)]
#[Group('ponto')]
final class FuncoesDoPontoTest extends TestCase
{
    #[TestDox('Chave nova, sem registro em nenhuma camada, nasce ligada (P96)')]
    public function testChaveNovaNasceLigada(): void
    {
        $funcoes = $this->funcoes([], null);

        self::assertTrue($funcoes->liberadaPeloMaster(FuncoesDoPonto::GEO));
        self::assertTrue($funcoes->ligadaNoEscritorio(FuncoesDoPonto::GEO, new Tenant()));
        self::assertTrue($funcoes->vale(FuncoesDoPonto::GEO, new Tenant()));
    }

    #[TestDox('Global desligado: a função não vale, mesmo ligada no escritório')]
    public function testGlobalDesligadoVence(): void
    {
        $tenant = new Tenant();
        $configuracao = new ConfiguracaoDoPonto($tenant);
        $configuracao->definir(FuncoesDoPonto::GEO, true, new User(), new \DateTimeImmutable());

        $funcoes = $this->funcoes([FuncoesDoPonto::GEO => $this->desligada(FuncoesDoPonto::GEO)], $configuracao);

        self::assertFalse($funcoes->liberadaPeloMaster(FuncoesDoPonto::GEO));
        self::assertTrue($funcoes->ligadaNoEscritorio(FuncoesDoPonto::GEO, $tenant), 'o valor do escritório fica guardado');
        self::assertFalse($funcoes->vale(FuncoesDoPonto::GEO, $tenant));
    }

    #[TestDox('Global ligado e escritório desligado: a função não vale naquele escritório')]
    public function testEscritorioDesligado(): void
    {
        $tenant = new Tenant();
        $configuracao = new ConfiguracaoDoPonto($tenant);
        $configuracao->definir(FuncoesDoPonto::GEO, false, new User(), new \DateTimeImmutable());

        $funcoes = $this->funcoes([], $configuracao);

        self::assertTrue($funcoes->liberadaPeloMaster(FuncoesDoPonto::GEO));
        self::assertFalse($funcoes->vale(FuncoesDoPonto::GEO, $tenant));
    }

    #[TestDox('Linha do escritório sem a chave no mapa: ligada')]
    public function testLinhaSemAChave(): void
    {
        $tenant = new Tenant();
        $configuracao = new ConfiguracaoDoPonto($tenant);
        $configuracao->definir('outra', false, new User(), new \DateTimeImmutable());

        self::assertTrue($this->funcoes([], $configuracao)->vale(FuncoesDoPonto::GEO, $tenant));
    }

    #[TestDox('O comprovante (E15) e a geolocalização (E7) têm a camada Escritório, nessa ordem; quem configura é admin.ponto.manage')]
    public function testCamadaEPermissao(): void
    {
        self::assertTrue($this->funcoes([], null)->temCamadaEscritorio('ponto-geo'));
        self::assertTrue($this->funcoes([], null)->temCamadaEscritorio('ponto-comprovante'));
        self::assertFalse($this->funcoes([], null)->temCamadaEscritorio('ponto'));
        self::assertFalse($this->funcoes([], null)->temCamadaEscritorio('ponto-facial'));
        self::assertSame(['ponto-comprovante', 'ponto-geo'], array_keys(FuncoesDoPonto::COM_CAMADA_ESCRITORIO), 'a ordem do FEATS do desenho (L414 a L423)');

        $quem = new User();
        $tenant = new Tenant();
        $permissoes = $this->createMock(PermissionChecker::class);
        $permissoes->expects(self::once())->method('canAdminister')->with($quem, $tenant, 'admin.ponto.manage')->willReturn(true);

        self::assertTrue($this->funcoes([], null, $permissoes)->podeConfigurar($quem, $tenant));
    }

    /** @param array<string, RecursoEstado> $gravados */
    private function funcoes(array $gravados, ?ConfiguracaoDoPonto $configuracao, ?PermissionChecker $permissoes = null): FuncoesDoPonto
    {
        $estados = $this->createStub(RecursoEstadoRepository::class);
        $estados->method('todosPorChave')->willReturn($gravados);
        $recursos = new RecursosDoSistema(
            $estados,
            new MockClock('2026-11-09 10:00:00'),
            $this->createStub(Security::class),
            new RegraDosMasters($this->createStub(ContasDoSistemaRepository::class)),
            new RequestStack(),
            new NullLogger(),
        );
        $configuracoes = $this->createStub(ConfiguracaoDoPontoRepository::class);
        $configuracoes->method('doTenant')->willReturn($configuracao);

        return new FuncoesDoPonto($recursos, $configuracoes, $permissoes ?? $this->createStub(PermissionChecker::class));
    }

    private function desligada(string $chave): RecursoEstado
    {
        $estado = new RecursoEstado($chave);
        $estado->desativar(null, null, null, new \DateTimeImmutable('2026-11-01'));

        return $estado;
    }
}
