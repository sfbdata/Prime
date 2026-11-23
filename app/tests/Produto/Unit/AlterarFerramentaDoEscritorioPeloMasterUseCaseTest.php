<?php

declare(strict_types=1);

namespace App\Tests\Produto\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\Entity\ConfiguracaoDeInteligencia;
use App\Inteligencia\Enum\ModoDeCobrancaDaIa;
use App\Inteligencia\Repository\ConfiguracaoDeInteligenciaRepository;
use App\Inteligencia\UseCase\AlterarIaDoEscritorioUseCase;
use App\Produto\Enum\OrigemDaContratacao;
use App\Produto\Enum\Produto;
use App\Produto\Exception\EscritorioNaoEncontradoException;
use App\Produto\Service\ContratacoesDoEscritorio;
use App\Produto\UseCase\AlterarFerramentaDoEscritorioPeloMasterUseCase;
use App\Repository\TenantRepository;
use App\Shared\Audit\EscopoDeAuditoriaDePlataforma;
use App\Voto\Entity\LiberacaoDoVoto;
use App\Voto\Repository\LiberacaoDoVotoRepository;
use App\Voto\UseCase\AlterarLiberacaoDoVotoUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * O Master liga e desliga uma ferramenta de um escritório (spec docs/specs/multiproduto-contratacao-e-voto.md, §10, D10,
 * D16). Mock só das abstrações nossas; a trava roda a ação na hora. O Voto e a I.A. passam pelos UseCases reais das abas
 * de hoje (são `final`), com os repositórios simulados.
 */
#[CoversClass(AlterarFerramentaDoEscritorioPeloMasterUseCase::class)]
#[CoversClass(EscritorioNaoEncontradoException::class)]
final class AlterarFerramentaDoEscritorioPeloMasterUseCaseTest extends TestCase
{
    private const AGORA = '2026-11-23 10:00:00';

    private ContratacoesDoEscritorio&MockObject $contratacoes;
    private TenantRepository&MockObject $tenants;
    private LiberacaoDoVotoRepository&MockObject $liberacoes;
    private ConfiguracaoDeInteligenciaRepository&MockObject $configuracoes;
    private Tenant $tenant;
    private User $master;

    protected function setUp(): void
    {
        $this->master = (new User())->setRoles(['ROLE_SUPER_ADMIN']);
        $this->tenant = new Tenant();
        (new \ReflectionProperty(Tenant::class, 'id'))->setValue($this->tenant, 7);
        $this->tenants = $this->createMock(TenantRepository::class);
        $this->tenants->method('find')->willReturnCallback(fn (int $id): ?Tenant => 7 === $id ? $this->tenant : null);
        $this->contratacoes = $this->createMock(ContratacoesDoEscritorio::class);
        $this->contratacoes->method('sobTrava')->willReturnCallback(static fn (Tenant $t, callable $acao): mixed => $acao());
        $this->liberacoes = $this->createMock(LiberacaoDoVotoRepository::class);
        $this->configuracoes = $this->createMock(ConfiguracaoDeInteligenciaRepository::class);
    }

    private function sut(): AlterarFerramentaDoEscritorioPeloMasterUseCase
    {
        $escopo = new EscopoDeAuditoriaDePlataforma();
        $relogio = new MockClock(new \DateTimeImmutable(self::AGORA));

        return new AlterarFerramentaDoEscritorioPeloMasterUseCase(
            $this->tenants,
            $this->contratacoes,
            $escopo,
            new AlterarLiberacaoDoVotoUseCase($this->tenants, $this->liberacoes, $escopo, $this->contratacoes, $relogio),
            new AlterarIaDoEscritorioUseCase($this->tenants, $this->configuracoes, $escopo, $this->contratacoes, $relogio),
            $relogio,
        );
    }

    private static function agora(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::AGORA);
    }

    #[TestDox('quem não é Master: AccessDenied e nada é gravado')]
    public function testNaoMaster(): void
    {
        $this->contratacoes->expects($this->never())->method('incluir');
        $this->contratacoes->expects($this->never())->method('excluir');

        $this->expectException(AccessDeniedException::class);
        $this->sut()->executar(7, Produto::Cobrancas, false, new User());
    }

    #[TestDox('escritório inexistente, suspenso ou excluído: EscritorioNaoEncontrado e nada é gravado')]
    public function testEscritorioNaoEncontrado(): void
    {
        $suspenso = (new Tenant())->setIsActive(false);
        (new \ReflectionProperty(Tenant::class, 'id'))->setValue($suspenso, 8);
        $excluido = (new Tenant())->setExcluidoEm(new \DateTimeImmutable('-1 day'));
        (new \ReflectionProperty(Tenant::class, 'id'))->setValue($excluido, 9);
        $this->tenants = $this->createMock(TenantRepository::class);
        $this->tenants->method('find')->willReturnCallback(static fn (int $id): ?Tenant => [8 => $suspenso, 9 => $excluido][$id] ?? null);
        $this->contratacoes->expects($this->never())->method('excluir');
        $sut = $this->sut();

        foreach ([0, 5, 8, 9] as $id) {
            try {
                $sut->executar($id, Produto::Cobrancas, false, $this->master);
                self::fail('O escritório ' . $id . ' devia ser recusado.');
            } catch (EscritorioNaoEncontradoException $e) {
                self::assertSame($id, $e->tenantId);
            }
        }
    }

    #[TestDox('uma ferramenta comum: ligar inclui e desligar exclui, sempre com origem master, sob a trava')]
    public function testFerramentaComum(): void
    {
        $this->contratacoes->expects($this->once())->method('incluir')->with($this->tenant, Produto::Cobrancas, OrigemDaContratacao::Master, $this->master, self::agora());
        $this->contratacoes->expects($this->once())->method('excluir')->with($this->tenant, Produto::Kanban, OrigemDaContratacao::Master, $this->master, self::agora());
        $sut = $this->sut();

        $sut->executar(7, Produto::Cobrancas, true, $this->master);
        $sut->executar(7, Produto::Kanban, false, $this->master);
    }

    #[TestDox('`completo` pode ser posto e tirado pelo mesmo caminho')]
    public function testCompleto(): void
    {
        $this->contratacoes->expects($this->once())->method('incluir')->with($this->tenant, Produto::Completo, OrigemDaContratacao::Master, $this->master, self::agora());
        $this->contratacoes->expects($this->once())->method('excluir')->with($this->tenant, Produto::Completo, OrigemDaContratacao::Master, $this->master, self::agora());
        $sut = $this->sut();

        $sut->executar(7, Produto::Completo, true, $this->master);
        $sut->executar(7, Produto::Completo, false, $this->master);
    }

    #[TestDox('a D7 não vale para o Master: desligar não pergunta se é a única ferramenta ligada')]
    public function testSemD7(): void
    {
        $this->contratacoes->expects($this->never())->method('eOUnicoLigado');
        $this->contratacoes->expects($this->once())->method('excluir');

        $this->sut()->executar(7, Produto::Expediente, false, $this->master);
    }

    #[TestDox('Voto: ligar libera a LiberacaoDoVoto (D10) e inclui a contratação com origem master; desligar bloqueia e exclui')]
    public function testVoto(): void
    {
        $liberacao = new LiberacaoDoVoto($this->tenant);
        $this->liberacoes->method('findDoTenantParaOMaster')->willReturn($liberacao);
        $this->liberacoes->expects($this->exactly(2))->method('salvar');
        $this->contratacoes->expects($this->once())->method('incluir')->with($this->tenant, Produto::Voto, OrigemDaContratacao::Master, $this->master, self::agora());
        $this->contratacoes->expects($this->once())->method('excluir')->with($this->tenant, Produto::Voto, OrigemDaContratacao::Master, $this->master, self::agora());
        $sut = $this->sut();

        $sut->executar(7, Produto::Voto, true, $this->master);
        self::assertTrue($liberacao->isLiberado());
        $sut->executar(7, Produto::Voto, false, $this->master);
        self::assertFalse($liberacao->isLiberado());
    }

    #[TestDox('I.A.: ligar libera pelo Master e inclui a contratação; desligar bloqueia e exclui; o teto do escritório não muda')]
    public function testIa(): void
    {
        $configuracao = new ConfiguracaoDeInteligencia($this->tenant);
        $configuracao->definirCobranca(ModoDeCobrancaDaIa::Incluso, 900);
        $this->configuracoes->method('findDoTenantParaOMaster')->willReturn($configuracao);
        $this->configuracoes->expects($this->exactly(2))->method('salvar');
        $this->contratacoes->expects($this->once())->method('incluir')->with($this->tenant, Produto::Ia, OrigemDaContratacao::Master, $this->master, self::agora());
        $this->contratacoes->expects($this->once())->method('excluir')->with($this->tenant, Produto::Ia, OrigemDaContratacao::Master, $this->master, self::agora());
        $sut = $this->sut();

        $sut->executar(7, Produto::Ia, true, $this->master);
        self::assertTrue($configuracao->isLiberadaPeloMaster());
        $sut->executar(7, Produto::Ia, false, $this->master);
        self::assertFalse($configuracao->isLiberadaPeloMaster());
        self::assertSame(900, $configuracao->getOrcamentoMensalCentavos());
    }
}
