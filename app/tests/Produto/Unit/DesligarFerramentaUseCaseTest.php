<?php

declare(strict_types=1);

namespace App\Tests\Produto\Unit;

use App\Backup\Service\DonoDoEscritorio;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\Entity\ConfiguracaoDeInteligencia;
use App\Inteligencia\Repository\ConfiguracaoDeInteligenciaRepository;
use App\Produto\Enum\OrigemDaContratacao;
use App\Produto\Enum\Produto;
use App\Produto\Exception\FerramentaRecusadaException;
use App\Produto\Service\ContratacoesDoEscritorio;
use App\Produto\UseCase\DesligarFerramentaUseCase;
use App\Voto\Entity\LiberacaoDoVoto;
use App\Voto\Repository\AssembleiaRepository;
use App\Voto\Repository\LiberacaoDoVotoRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * O dono desliga uma ferramenta no Meu Perfil (spec docs/specs/multiproduto-contratacao-e-voto.md, §9, regras 1, 2, 6 e
 * 7; D15). Mock só das abstrações nossas; a trava roda a ação na hora.
 */
#[CoversClass(DesligarFerramentaUseCase::class)]
#[CoversClass(FerramentaRecusadaException::class)]
final class DesligarFerramentaUseCaseTest extends TestCase
{
    private const AGORA = '2026-11-23 10:00:00';

    private ContratacoesDoEscritorio&MockObject $contratacoes;
    private AssembleiaRepository&MockObject $assembleias;
    private LiberacaoDoVotoRepository&MockObject $liberacoes;
    private ConfiguracaoDeInteligenciaRepository&MockObject $configuracoes;
    private Tenant $tenant;
    private User $dono;

    protected function setUp(): void
    {
        $this->dono = self::usuario(11);
        $this->tenant = new Tenant();
        (new \ReflectionProperty(Tenant::class, 'id'))->setValue($this->tenant, 7);
        $this->tenant->setCriadoPor($this->dono);
        $this->contratacoes = $this->createMock(ContratacoesDoEscritorio::class);
        $this->contratacoes->method('sobTrava')->willReturnCallback(static fn (Tenant $t, callable $acao): mixed => $acao());
        $this->assembleias = $this->createMock(AssembleiaRepository::class);
        $this->liberacoes = $this->createMock(LiberacaoDoVotoRepository::class);
        $this->configuracoes = $this->createMock(ConfiguracaoDeInteligenciaRepository::class);
    }

    private static function usuario(int $id): User
    {
        $usuario = new User();
        (new \ReflectionProperty(User::class, 'id'))->setValue($usuario, $id);

        return $usuario;
    }

    private function sut(): DesligarFerramentaUseCase
    {
        return new DesligarFerramentaUseCase(
            new DonoDoEscritorio(),
            $this->contratacoes,
            $this->assembleias,
            $this->liberacoes,
            $this->configuracoes,
            new MockClock(self::AGORA),
        );
    }

    private function recusa(User $usuario, Produto $produto): FerramentaRecusadaException
    {
        try {
            $this->sut()->executar($usuario, $this->tenant, $produto);
        } catch (FerramentaRecusadaException $e) {
            return $e;
        }
        self::fail('deveria recusar');
    }

    #[TestDox('quem não é o dono leva 403 antes de qualquer outra checagem (nem olha as assembleias)')]
    public function testSoODono(): void
    {
        $this->contratacoes->expects($this->never())->method('sobTrava');
        $this->assembleias->expects($this->never())->method('existeAbertaNoEscritorio');

        self::assertSame(403, $this->recusa(self::usuario(12), Produto::Voto)->status);
        self::assertSame(403, $this->recusa(self::usuario(12), Produto::Completo)->status);
    }

    #[TestDox('o `completo` não se desliga: 404')]
    public function testCompletoNaoDesliga(): void
    {
        $this->contratacoes->expects($this->never())->method('sobTrava');

        self::assertSame(404, $this->recusa($this->dono, Produto::Completo)->status);
    }

    #[TestDox('Voto com assembleia em andamento: 422, sem encerrar a contratação nem mexer na liberação')]
    public function testVotoComAssembleiaAberta(): void
    {
        $this->assembleias->method('existeAbertaNoEscritorio')->with($this->tenant)->willReturn(true);
        $this->contratacoes->expects($this->never())->method('excluir');
        $this->liberacoes->expects($this->never())->method('salvar');

        self::assertSame(422, $this->recusa($this->dono, Produto::Voto)->status);
    }

    #[TestDox('desligar o Voto: encerra a contratação (origem dono) e bloqueia a liberação existente')]
    public function testDesligaOVoto(): void
    {
        $liberacao = new LiberacaoDoVoto($this->tenant);
        $liberacao->liberar();
        $this->assembleias->method('existeAbertaNoEscritorio')->willReturn(false);
        $this->liberacoes->method('findDoTenant')->with($this->tenant)->willReturn($liberacao);
        $this->contratacoes->expects($this->once())->method('excluir')
            ->with($this->tenant, Produto::Voto, OrigemDaContratacao::Dono, $this->dono, new \DateTimeImmutable(self::AGORA));
        $this->liberacoes->expects($this->once())->method('salvar')->with($liberacao, true);

        $this->sut()->executar($this->dono, $this->tenant, Produto::Voto);

        self::assertFalse($liberacao->isLiberado());
    }

    #[TestDox('desligar o Voto sem liberação gravada: só encerra a contratação')]
    public function testDesligaOVotoSemLiberacao(): void
    {
        $this->assembleias->method('existeAbertaNoEscritorio')->willReturn(false);
        $this->liberacoes->method('findDoTenant')->willReturn(null);
        $this->contratacoes->expects($this->once())->method('excluir');
        $this->liberacoes->expects($this->never())->method('salvar');

        $this->sut()->executar($this->dono, $this->tenant, Produto::Voto);
    }

    #[TestDox('desligar a I.A. (D15): encerra a contratação e bloqueia a liberação, com o aceite preservado')]
    public function testDesligaAIa(): void
    {
        $configuracao = new ConfiguracaoDeInteligencia($this->tenant);
        $configuracao->liberarPeloDono();
        $configuracao->atualizar(true, 50, 500, true, $this->dono);
        $this->configuracoes->method('findDoTenant')->willReturn($configuracao);
        $this->contratacoes->expects($this->once())->method('excluir')->with($this->tenant, Produto::Ia, OrigemDaContratacao::Dono);
        $this->configuracoes->expects($this->once())->method('salvar')->with($configuracao, true);

        $this->sut()->executar($this->dono, $this->tenant, Produto::Ia);

        self::assertFalse($configuracao->isLiberadaPeloMaster());
        self::assertTrue($configuracao->isHabilitada(), 'o aceite fica guardado');
        self::assertNotNull($configuracao->getConsentimentoEnvioExternoEm());
    }

    #[TestDox('desligar outra ferramenta (Ponto) só encerra a contratação: nem assembleia, nem liberação, nem I.A.')]
    public function testDesligaOutra(): void
    {
        $this->assembleias->expects($this->never())->method('existeAbertaNoEscritorio');
        $this->contratacoes->expects($this->once())->method('excluir')->with($this->tenant, Produto::Ponto);
        $this->liberacoes->expects($this->never())->method('salvar');
        $this->configuracoes->expects($this->never())->method('salvar');

        $this->sut()->executar($this->dono, $this->tenant, Produto::Ponto);
    }
}
