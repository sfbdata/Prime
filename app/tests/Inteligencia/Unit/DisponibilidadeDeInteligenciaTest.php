<?php

declare(strict_types=1);

namespace App\Tests\Inteligencia\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\Entity\ConfiguracaoDeInteligencia;
use App\Inteligencia\Enum\Disponibilidade;
use App\Inteligencia\Enum\ModeloDeIa;
use App\Inteligencia\Enum\ModoDeCobrancaDaIa;
use App\Inteligencia\Repository\AnaliseDeInteligenciaRepository;
use App\Inteligencia\Repository\ConfiguracaoDeInteligenciaRepository;
use App\Inteligencia\Service\CustoDaIa;
use App\Inteligencia\Service\DisponibilidadeDeInteligencia;
use App\Inteligencia\Service\ProvedorDeLinguagem;
use App\Inteligencia\Service\ProvedorNaoConfigurado;
use App\Service\PermissionChecker;
use App\Tests\Inteligencia\Support\PlataformaDeIaNoTesteTrait;
use App\Tests\Inteligencia\Support\ProvedorFalso;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * A ordem dos motivos é o contrato: plataforma (trava do ambiente, provedor e chave geral do Master) → liberação do
 * Master → escritório → permissão → cota → orçamento em US$. A UI mostra o
 * primeiro que falhar, então trocar a ordem muda o que o usuário lê.
 */
#[CoversClass(DisponibilidadeDeInteligencia::class)]
final class DisponibilidadeDeInteligenciaTest extends TestCase
{
    use PlataformaDeIaNoTesteTrait;

    private ConfiguracaoDeInteligenciaRepository&MockObject $configuracoes;
    private AnaliseDeInteligenciaRepository&MockObject $analises;
    private PermissionChecker&MockObject $permissoes;
    private Tenant $tenant;
    private User $user;

    protected function setUp(): void
    {
        $this->configuracoes = $this->createMock(ConfiguracaoDeInteligenciaRepository::class);
        $this->analises = $this->createMock(AnaliseDeInteligenciaRepository::class);
        $this->permissoes = $this->createMock(PermissionChecker::class);
        $this->tenant = new Tenant();
        $this->user = new User();
    }

    private function sut(ProvedorDeLinguagem $provedor, bool $flag = true, bool $chaveGeral = true): DisponibilidadeDeInteligencia
    {
        return new DisponibilidadeDeInteligencia($provedor, $this->configuracoes, $this->analises, $this->permissoes, $flag, $this->plataformaDeIa($chaveGeral), new CustoDaIa('global'));
    }

    private function configuracao(bool $habilitada, int $diario = 50, int $mensal = 500, bool $liberada = true): ConfiguracaoDeInteligencia
    {
        $c = new ConfiguracaoDeInteligencia($this->tenant);
        if ($liberada) {
            $c->liberarPeloMaster();
        }
        $c->atualizar($habilitada, $diario, $mensal, true, $this->user);

        return $c;
    }

    #[TestDox('flag da plataforma desligada → não configurada na plataforma, mesmo com tudo o mais em ordem')]
    public function testFlagDesligada(): void
    {
        $this->configuracoes->method('findDoTenant')->willReturn($this->configuracao(true));
        $this->permissoes->method('canAccessModule')->willReturn(true);
        $this->analises->method('contarDesde')->willReturn(0);

        self::assertSame(Disponibilidade::NaoConfiguradaNaPlataforma, $this->sut(new ProvedorFalso(), flag: false)->para($this->user, $this->tenant));
    }

    #[TestDox('provedor não configurado → não configurada na plataforma (antes de olhar o escritório)')]
    public function testProvedorNaoConfigurado(): void
    {
        $this->configuracoes->expects($this->never())->method('findDoTenant');

        self::assertSame(Disponibilidade::NaoConfiguradaNaPlataforma, $this->sut(new ProvedorNaoConfigurado())->para($this->user, $this->tenant));
    }

    #[TestDox('paraEscritorio: só plataforma e escritório, sem olhar permissão nem cota')]
    public function testParaEscritorio(): void
    {
        $this->permissoes->expects($this->never())->method('canAccessModule');
        $this->analises->expects($this->never())->method('contarDesde');

        self::assertSame(Disponibilidade::NaoConfiguradaNaPlataforma, $this->sut(new ProvedorNaoConfigurado())->paraEscritorio($this->tenant));
        $this->configuracoes->method('findDoTenant')->willReturnOnConsecutiveCalls(null, $this->configuracao(false), $this->configuracao(true, 0, 0));
        self::assertSame(Disponibilidade::NaoLiberadaPeloMaster, $this->sut(new ProvedorFalso())->paraEscritorio($this->tenant));
        self::assertSame(Disponibilidade::DesligadaNoEscritorio, $this->sut(new ProvedorFalso())->paraEscritorio($this->tenant));
        self::assertSame(Disponibilidade::Disponivel, $this->sut(new ProvedorFalso())->paraEscritorio($this->tenant), 'cota zerada não conta');
    }

    #[TestDox('escritório sem configuração → não liberada pelo Master (antes de olhar a permissão)')]
    public function testSemConfiguracaoDoTenant(): void
    {
        $this->configuracoes->method('findDoTenant')->willReturn(null);
        $this->permissoes->expects($this->never())->method('canAccessModule');

        self::assertSame(Disponibilidade::NaoLiberadaPeloMaster, $this->sut(new ProvedorFalso())->para($this->user, $this->tenant));
    }

    #[TestDox('chave geral do Master desligada → não configurada na plataforma, mesmo com o escritório liberado e habilitado')]
    public function testChaveGeralDesligada(): void
    {
        $this->configuracoes->expects($this->never())->method('findDoTenant');

        self::assertSame(Disponibilidade::NaoConfiguradaNaPlataforma, $this->sut(new ProvedorFalso(), chaveGeral: false)->para($this->user, $this->tenant));
        self::assertFalse($this->sut(new ProvedorFalso(), chaveGeral: false)->plataformaConfigurada());
        self::assertTrue($this->sut(new ProvedorFalso())->plataformaConfigurada());
    }

    #[TestDox('trava do ambiente desligada: nem pergunta ao banco pela chave geral')]
    public function testTravaDoAmbienteAntesDoBanco(): void
    {
        $plataforma = $this->createMock(\App\Inteligencia\Repository\ConfiguracaoDaPlataformaDeIaRepository::class);
        $plataforma->expects($this->never())->method('obter');
        $sut = new DisponibilidadeDeInteligencia(new ProvedorFalso(), $this->configuracoes, $this->analises, $this->permissoes, false, $plataforma, new CustoDaIa('global'));

        self::assertFalse($sut->plataformaConfigurada());
    }

    #[TestDox('leitura da chave geral que falha no banco (tabela ausente) → desligada, sem lançar')]
    public function testChaveGeralIlegivelEhDesligada(): void
    {
        $plataforma = $this->createStub(\App\Inteligencia\Repository\ConfiguracaoDaPlataformaDeIaRepository::class);
        $plataforma->method('obter')->willThrowException(new \Doctrine\DBAL\Exception\TableNotFoundException(
            new class ('relation "inteligencia_plataforma" does not exist') extends \Doctrine\DBAL\Driver\AbstractException {},
            null,
        ));
        $sut = new DisponibilidadeDeInteligencia(new ProvedorFalso(), $this->configuracoes, $this->analises, $this->permissoes, true, $plataforma, new CustoDaIa('global'));

        self::assertSame(Disponibilidade::NaoConfiguradaNaPlataforma, $sut->paraEscritorio($this->tenant));
    }

    #[TestDox('escritório habilitado pelo admin mas NÃO liberado pelo Master → não liberada (o aceite não basta)')]
    public function testHabilitadoSemLiberacao(): void
    {
        $this->configuracoes->method('findDoTenant')->willReturn($this->configuracao(true, liberada: false));
        $this->permissoes->expects($this->never())->method('canAccessModule');

        $resultado = $this->sut(new ProvedorFalso())->para($this->user, $this->tenant);

        self::assertSame(Disponibilidade::NaoLiberadaPeloMaster, $resultado);
        self::assertSame('A BlueJus IA ainda não foi liberada para este escritório', $resultado->mensagem());
    }

    #[TestDox('liberado pelo Master mas sem o aceite do admin → desligada no escritório')]
    public function testLiberadoSemAceite(): void
    {
        $this->configuracoes->method('findDoTenant')->willReturn($this->configuracao(false));

        self::assertSame(Disponibilidade::DesligadaNoEscritorio, $this->sut(new ProvedorFalso())->para($this->user, $this->tenant));
    }

    #[TestDox('escritório com a IA desligada → desligada no escritório')]
    public function testConfiguracaoDesligada(): void
    {
        $this->configuracoes->method('findDoTenant')->willReturn($this->configuracao(false));

        self::assertSame(Disponibilidade::DesligadaNoEscritorio, $this->sut(new ProvedorFalso())->para($this->user, $this->tenant));
    }

    #[TestDox('usuário sem modules.inteligencia.view → sem permissão (antes de contar a cota)')]
    public function testSemPermissao(): void
    {
        $this->configuracoes->method('findDoTenant')->willReturn($this->configuracao(true));
        $this->permissoes->method('canAccessModule')->with($this->user, $this->tenant, 'inteligencia')->willReturn(false);
        $this->analises->expects($this->never())->method('contarDesde');

        self::assertSame(Disponibilidade::SemPermissao, $this->sut(new ProvedorFalso())->para($this->user, $this->tenant));
    }

    #[TestDox('cota diária atingida → limite atingido')]
    public function testLimiteDiario(): void
    {
        $this->configuracoes->method('findDoTenant')->willReturn($this->configuracao(true, diario: 3));
        $this->permissoes->method('canAccessModule')->willReturn(true);
        $this->analises->method('contarDesde')->willReturn(3);

        $resultado = $this->sut(new ProvedorFalso())->para($this->user, $this->tenant);

        self::assertSame(Disponibilidade::LimiteAtingido, $resultado);
        self::assertSame('Limite diário de análises atingido', $resultado->mensagem());
    }

    #[TestDox('cota mensal atingida → limite MENSAL atingido (mesmo com folga no dia), e a mensagem diz mensal')]
    public function testLimiteMensal(): void
    {
        $this->configuracoes->method('findDoTenant')->willReturn($this->configuracao(true, diario: 50, mensal: 100));
        $this->permissoes->method('canAccessModule')->willReturn(true);
        // 1ª chamada = hoje, 2ª = mês.
        $this->analises->method('contarDesde')->willReturnOnConsecutiveCalls(2, 100);

        $resultado = $this->sut(new ProvedorFalso())->para($this->user, $this->tenant);

        self::assertSame(Disponibilidade::LimiteMensalAtingido, $resultado);
        self::assertSame('Limite mensal de análises atingido', $resultado->mensagem());
        self::assertTrue($resultado->ehLimiteDeCota());
        self::assertSame('limite_atingido', $resultado->codigoDaRecusa());
    }

    #[TestDox('tudo em ordem → disponível')]
    public function testDisponivel(): void
    {
        $this->configuracoes->method('findDoTenant')->willReturn($this->configuracao(true));
        $this->permissoes->method('canAccessModule')->willReturn(true);
        $this->analises->method('contarDesde')->willReturn(1);

        $resultado = $this->sut(new ProvedorFalso())->para($this->user, $this->tenant);

        self::assertSame(Disponibilidade::Disponivel, $resultado);
        self::assertTrue($resultado->estaDisponivel());
    }

    #[TestDox('cada motivo tem a mensagem da spec para a tela')]
    public function testMensagens(): void
    {
        self::assertSame('IA não configurada nesta instalação', Disponibilidade::NaoConfiguradaNaPlataforma->mensagem());
        self::assertSame('A BlueJus IA ainda não foi liberada para este escritório', Disponibilidade::NaoLiberadaPeloMaster->mensagem());
        self::assertSame('BlueJus IA desligada neste escritório. Peça ao administrador', Disponibilidade::DesligadaNoEscritorio->mensagem());
        self::assertSame('Sem permissão para usar a BlueJus IA', Disponibilidade::SemPermissao->mensagem());
        self::assertSame('Limite diário de análises atingido', Disponibilidade::LimiteAtingido->mensagem());
        self::assertSame('Limite mensal de análises atingido', Disponibilidade::LimiteMensalAtingido->mensagem());
        self::assertSame('Orçamento mensal da BlueJus IA atingido; volta no dia 1º', Disponibilidade::OrcamentoAtingido->mensagem());
    }

    #[TestDox('nenhuma mensagem da tela usa travessão (regra do README)')]
    public function testMensagensSemTravessao(): void
    {
        foreach (Disponibilidade::cases() as $motivo) {
            self::assertStringNotContainsString('—', $motivo->mensagem(), $motivo->name);
            self::assertStringNotContainsString('–', $motivo->mensagem(), $motivo->name);
        }
    }

    #[TestDox('só as cotas (dia, mês e orçamento) viram 429; o código da recusa delas é "limite_atingido" e o da falta de liberação é "desligada_no_escritorio" (contrato com o JS)')]
    public function testCodigoDaRecusa(): void
    {
        foreach (Disponibilidade::cases() as $motivo) {
            $ehCota = \in_array($motivo, [Disponibilidade::LimiteAtingido, Disponibilidade::LimiteMensalAtingido, Disponibilidade::OrcamentoAtingido], true);
            $esperado = match (true) {
                $ehCota => 'limite_atingido',
                $motivo === Disponibilidade::NaoLiberadaPeloMaster => 'desligada_no_escritorio',
                default => $motivo->value,
            };

            self::assertSame($ehCota, $motivo->ehLimiteDeCota(), $motivo->name);
            self::assertSame($esperado, $motivo->codigoDaRecusa(), $motivo->name);
        }
    }

    /**
     * Gasto do mês em centavos com o Haiku 5.5 na região global: 10 centavos por milhão de tokens de entrada, então
     * 10 milhões de tokens = US$ 1,00 (100 centavos) exatos.
     *
     * @return iterable<string, array{ModoDeCobrancaDaIa, ?int, int, Disponibilidade}>
     */
    public static function orcamentos(): iterable
    {
        foreach ([ModoDeCobrancaDaIa::Incluso, ModoDeCobrancaDaIa::Adicional] as $modo) {
            yield $modo->value . ', orçamento nulo' => [$modo, null, 500, Disponibilidade::Disponivel];
            yield $modo->value . ', abaixo' => [$modo, 101, 100, Disponibilidade::Disponivel];
            yield $modo->value . ', no limite' => [$modo, 100, 100, Disponibilidade::OrcamentoAtingido];
            yield $modo->value . ', acima' => [$modo, 99, 100, Disponibilidade::OrcamentoAtingido];
        }
        yield 'limite_gratis, orçamento nulo' => [ModoDeCobrancaDaIa::LimiteGratis, null, 500, Disponibilidade::Disponivel];
        yield 'limite_gratis, abaixo' => [ModoDeCobrancaDaIa::LimiteGratis, 101, 100, Disponibilidade::Disponivel];
        yield 'limite_gratis, no limite (a parte grátis não trava)' => [ModoDeCobrancaDaIa::LimiteGratis, 100, 100, Disponibilidade::Disponivel];
        yield 'limite_gratis, acima (a parte grátis não trava)' => [ModoDeCobrancaDaIa::LimiteGratis, 99, 100, Disponibilidade::Disponivel];
    }

    #[DataProvider('orcamentos')]
    #[TestDox('orçamento em US$ nos três modos: nulo, abaixo, no limite e acima (o nome do caso diz qual)')]
    public function testOrcamentoMensal(ModoDeCobrancaDaIa $modo, ?int $orcamento, int $gasto, Disponibilidade $esperado): void
    {
        $configuracao = $this->configuracao(true);
        $configuracao->definirCobranca($modo, $orcamento);
        $this->configuracoes->method('findDoTenant')->willReturn($configuracao);
        $this->permissoes->method('canAccessModule')->willReturn(true);
        $this->analises->method('contarDesde')->willReturn(0);
        $somaPedida = $orcamento !== null && $modo !== ModoDeCobrancaDaIa::LimiteGratis;
        $this->analises->expects($somaPedida ? $this->once() : $this->never())->method('somarTokensPorModeloDesde')
            ->with($this->tenant, new \DateTimeImmutable('first day of this month midnight'))
            ->willReturn([['modelo' => ModeloDeIa::Haiku55->value, 'tokensEntrada' => $gasto * 100_000, 'tokensSaida' => 0]]);

        $resultado = $this->sut(new ProvedorFalso())->para($this->user, $this->tenant);

        self::assertSame($esperado, $resultado);
    }

    #[TestDox('a cota mensal vem antes do orçamento: com os dois estourados, a tela diz o limite de análises')]
    public function testCotaMensalAntesDoOrcamento(): void
    {
        $configuracao = $this->configuracao(true, mensal: 10);
        $configuracao->definirCobranca(ModoDeCobrancaDaIa::Incluso, 0);
        $this->configuracoes->method('findDoTenant')->willReturn($configuracao);
        $this->permissoes->method('canAccessModule')->willReturn(true);
        $this->analises->method('contarDesde')->willReturnOnConsecutiveCalls(1, 10);
        $this->analises->expects($this->never())->method('somarTokensPorModeloDesde');

        self::assertSame(Disponibilidade::LimiteMensalAtingido, $this->sut(new ProvedorFalso())->para($this->user, $this->tenant));
    }

    #[TestDox('paraProcessamento (worker) não confere o orçamento, igual à cota')]
    public function testParaProcessamentoIgnoraOOrcamento(): void
    {
        $configuracao = $this->configuracao(true);
        $configuracao->definirCobranca(ModoDeCobrancaDaIa::Adicional, 0);
        $this->configuracoes->method('findDoTenant')->willReturn($configuracao);
        $this->permissoes->method('canAccessModule')->willReturn(true);
        $this->analises->expects($this->never())->method('somarTokensPorModeloDesde');

        self::assertSame(Disponibilidade::Disponivel, $this->sut(new ProvedorFalso())->paraProcessamento($this->user, $this->tenant));
    }

    #[TestDox('paraProcessamento (worker, IA-034L): mesmos três níveis, mas a cota não é consultada')]
    public function testParaProcessamentoIgnoraACota(): void
    {
        $this->configuracoes->method('findDoTenant')->willReturn($this->configuracao(true, diario: 0, mensal: 0));
        $this->permissoes->method('canAccessModule')->willReturn(true);
        $this->analises->expects($this->never())->method('contarDesde');

        self::assertSame(Disponibilidade::Disponivel, $this->sut(new ProvedorFalso())->paraProcessamento($this->user, $this->tenant));
    }

    #[TestDox('paraProcessamento: plataforma, escritório e permissão continuam valendo, na mesma ordem')]
    public function testParaProcessamentoRecusaOsTresNiveis(): void
    {
        $this->configuracoes->method('findDoTenant')->willReturnOnConsecutiveCalls($this->configuracao(true, liberada: false), $this->configuracao(false), $this->configuracao(true));
        $this->permissoes->method('canAccessModule')->willReturn(false);

        self::assertSame(Disponibilidade::NaoConfiguradaNaPlataforma, $this->sut(new ProvedorFalso(), flag: false)->paraProcessamento($this->user, $this->tenant));
        self::assertSame(Disponibilidade::NaoConfiguradaNaPlataforma, $this->sut(new ProvedorFalso(), chaveGeral: false)->paraProcessamento($this->user, $this->tenant));
        self::assertSame(Disponibilidade::NaoLiberadaPeloMaster, $this->sut(new ProvedorFalso())->paraProcessamento($this->user, $this->tenant));
        self::assertSame(Disponibilidade::DesligadaNoEscritorio, $this->sut(new ProvedorFalso())->paraProcessamento($this->user, $this->tenant));
        self::assertSame(Disponibilidade::SemPermissao, $this->sut(new ProvedorFalso())->paraProcessamento($this->user, $this->tenant));
    }
}
