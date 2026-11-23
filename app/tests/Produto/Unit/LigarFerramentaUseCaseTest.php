<?php

declare(strict_types=1);

namespace App\Tests\Produto\Unit;

use App\Backup\Service\DonoDoEscritorio;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\Entity\ConfiguracaoDeInteligencia;
use App\Inteligencia\Enum\ModoDeCobrancaDaIa;
use App\Inteligencia\Repository\ConfiguracaoDeInteligenciaRepository;
use App\Master\Repository\ConfiguracaoGlobalRepository;
use App\Produto\Enum\OrigemDaContratacao;
use App\Produto\Enum\Produto;
use App\Produto\Exception\FerramentaRecusadaException;
use App\Produto\Service\ContratacoesDoEscritorio;
use App\Produto\UseCase\LigarFerramentaUseCase;
use App\Service\TenantBootstrapService;
use App\Voto\Entity\LiberacaoDoVoto;
use App\Voto\Repository\LiberacaoDoVotoRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * O dono liga uma ferramenta no Meu Perfil (spec docs/specs/multiproduto-contratacao-e-voto.md, §9, regras 1, 2, 3 e 10;
 * D14 e D16). Mock só das abstrações nossas (serviço de contratações e repositórios); a trava roda a ação na hora.
 */
#[CoversClass(LigarFerramentaUseCase::class)]
#[CoversClass(FerramentaRecusadaException::class)]
#[CoversClass(ConfiguracaoDeInteligencia::class)]
final class LigarFerramentaUseCaseTest extends TestCase
{
    private const AGORA = '2026-11-23 10:00:00';

    private ContratacoesDoEscritorio&MockObject $contratacoes;
    private LiberacaoDoVotoRepository&MockObject $liberacoes;
    private ConfiguracaoDeInteligenciaRepository&MockObject $configuracoes;
    private ConfiguracaoGlobalRepository&MockObject $configuracaoGlobal;
    private TenantBootstrapService&MockObject $bootstrap;
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
        $this->liberacoes = $this->createMock(LiberacaoDoVotoRepository::class);
        $this->configuracoes = $this->createMock(ConfiguracaoDeInteligenciaRepository::class);
        $this->configuracaoGlobal = $this->createMock(ConfiguracaoGlobalRepository::class);
        $this->bootstrap = $this->createMock(TenantBootstrapService::class);
    }

    private static function usuario(int $id): User
    {
        $usuario = new User();
        (new \ReflectionProperty(User::class, 'id'))->setValue($usuario, $id);

        return $usuario;
    }

    private function sut(): LigarFerramentaUseCase
    {
        return new LigarFerramentaUseCase(
            new DonoDoEscritorio(),
            $this->contratacoes,
            $this->liberacoes,
            $this->configuracoes,
            $this->configuracaoGlobal,
            $this->bootstrap,
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

    #[TestDox('quem não é o dono leva 403 antes de qualquer outra checagem, até com o `completo`, e nada é gravado')]
    public function testSoODono(): void
    {
        $this->contratacoes->expects($this->never())->method('sobTrava');
        $this->contratacoes->expects($this->never())->method('travadoPeloMaster');

        self::assertSame(403, $this->recusa(self::usuario(12), Produto::Voto)->status);
        self::assertSame(403, $this->recusa(self::usuario(12), Produto::Completo)->status, 'o 403 vem antes do 404');
    }

    #[TestDox('escritório sem dono (criador nulo): ninguém liga')]
    public function testEscritorioSemDono(): void
    {
        $this->tenant->setCriadoPor(null);

        self::assertSame(403, $this->recusa($this->dono, Produto::Kanban)->status);
    }

    #[TestDox('o `completo` não é ferramenta: 404, sem trava nem gravação')]
    public function testCompletoNaoLiga(): void
    {
        $this->contratacoes->expects($this->never())->method('sobTrava');

        self::assertSame(404, $this->recusa($this->dono, Produto::Completo)->status);
    }

    #[TestDox('travada pelo Master (D16): 422 com a mensagem do suporte, e nada é ligado')]
    public function testTravadaPeloMaster(): void
    {
        $this->contratacoes->method('travadoPeloMaster')->with($this->tenant, Produto::Ia)->willReturn(true);
        $this->contratacoes->expects($this->never())->method('incluir');
        $this->configuracoes->expects($this->never())->method('salvar');

        $e = $this->recusa($this->dono, Produto::Ia);

        self::assertSame(422, $e->status);
        self::assertSame('Esta ferramenta foi desligada pelo BlueJus. Fale com o suporte.', $e->getMessage());
    }

    #[TestDox('ligar o Voto: inclui com origem dono e cria a liberação já liberada')]
    public function testLigaOVoto(): void
    {
        $this->contratacoes->expects($this->once())->method('incluir')
            ->with($this->tenant, Produto::Voto, OrigemDaContratacao::Dono, $this->dono, new \DateTimeImmutable(self::AGORA));
        $this->liberacoes->method('findDoTenant')->willReturn(null);
        $salva = null;
        $this->liberacoes->expects($this->once())->method('salvar')->willReturnCallback(static function (LiberacaoDoVoto $l, bool $flush) use (&$salva): void {
            self::assertTrue($flush);
            $salva = $l;
        });

        $this->sut()->executar($this->dono, $this->tenant, Produto::Voto);

        self::assertInstanceOf(LiberacaoDoVoto::class, $salva);
        self::assertTrue($salva->isLiberado());
        self::assertSame($this->tenant, $salva->getTenant());
    }

    #[TestDox('ligar a I.A. com orçamento nulo: liberada, `Incluso` com o teto da configuração global, sem aceite')]
    public function testLigaAIaComOTetoGlobal(): void
    {
        $this->configuracoes->method('findDoTenant')->willReturn(null);
        $this->configuracaoGlobal->method('tetoPadraoDaIaCentavos')->willReturn(1234);
        $salva = $this->capturarConfiguracao();

        $this->sut()->executar($this->dono, $this->tenant, Produto::Ia);

        self::assertTrue($salva->valor?->isLiberadaPeloMaster());
        self::assertSame(ModoDeCobrancaDaIa::Incluso, $salva->valor?->getModoDeCobranca());
        self::assertSame(1234, $salva->valor?->getOrcamentoMensalCentavos());
        self::assertFalse($salva->valor?->isHabilitada(), 'ligar não grava aceite: segue desligada no escritório');
        self::assertNull($salva->valor?->getConsentimentoEnvioExternoEm());
    }

    #[TestDox('ligar a I.A. sem teto na configuração global: reserva de 500')]
    public function testLigaAIaComAReserva(): void
    {
        $this->configuracoes->method('findDoTenant')->willReturn(null);
        $this->configuracaoGlobal->method('tetoPadraoDaIaCentavos')->willReturn(null);
        $salva = $this->capturarConfiguracao();

        $this->sut()->executar($this->dono, $this->tenant, Produto::Ia);

        self::assertSame(LigarFerramentaUseCase::TETO_PADRAO_DE_RESERVA, $salva->valor?->getOrcamentoMensalCentavos());
        self::assertSame(500, LigarFerramentaUseCase::TETO_PADRAO_DE_RESERVA);
    }

    #[TestDox('ligar a I.A. com orçamento já gravado (D14): o modo e o teto ficam como estão')]
    public function testLigaAIaMantemOOrcamento(): void
    {
        $existente = new ConfiguracaoDeInteligencia($this->tenant);
        $existente->definirCobranca(ModoDeCobrancaDaIa::Adicional, 900);
        $this->configuracoes->method('findDoTenant')->willReturn($existente);
        $this->configuracaoGlobal->expects($this->never())->method('tetoPadraoDaIaCentavos');
        $this->configuracoes->expects($this->once())->method('salvar')->with($existente, true);

        $this->sut()->executar($this->dono, $this->tenant, Produto::Ia);

        self::assertTrue($existente->isLiberadaPeloMaster());
        self::assertSame(ModoDeCobrancaDaIa::Adicional, $existente->getModoDeCobranca());
        self::assertSame(900, $existente->getOrcamentoMensalCentavos());
    }

    #[TestDox('ligar o Expediente semeia os marcadores (se faltarem) e não liga a I.A.')]
    public function testLigaOExpediente(): void
    {
        $this->bootstrap->expects($this->once())->method('semearMarcadoresSeFaltarem')->with($this->tenant, $this->dono);
        $this->configuracoes->expects($this->never())->method('salvar');
        $this->contratacoes->expects($this->once())->method('incluir')->with($this->tenant, Produto::Expediente);

        $this->sut()->executar($this->dono, $this->tenant, Produto::Expediente);
    }

    #[TestDox('ligar outra ferramenta (Kanban) só inclui: sem liberação, sem I.A., sem marcadores')]
    public function testLigaOutra(): void
    {
        $this->contratacoes->expects($this->once())->method('incluir')->with($this->tenant, Produto::Kanban);
        $this->liberacoes->expects($this->never())->method('salvar');
        $this->configuracoes->expects($this->never())->method('salvar');
        $this->bootstrap->expects($this->never())->method('semearMarcadoresSeFaltarem');

        $this->sut()->executar($this->dono, $this->tenant, Produto::Kanban);
    }

    /** @return \stdClass com `valor`: a configuração que chegou ao `salvar` */
    private function capturarConfiguracao(): \stdClass
    {
        $salva = new \stdClass();
        $salva->valor = null;
        $this->configuracoes->expects($this->once())->method('salvar')->willReturnCallback(static function (ConfiguracaoDeInteligencia $c, bool $flush) use ($salva): void {
            self::assertTrue($flush);
            $salva->valor = $c;
        });

        return $salva;
    }
}
