<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\DTO\EditarConfiguracaoCasoInput;
use App\Cobranca\Entity\Carteira;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\EventoHistorico;
use App\Cobranca\Entity\ObjetoCobranca;
use App\Cobranca\Entity\Obrigacao;
use App\Cobranca\Enum\BaseEncargo;
use App\Cobranca\Enum\FormaHonorarios;
use App\Cobranca\Exception\CasoNaoEncontradoException;
use App\Cobranca\Repository\CasoCobrancaRepository;
use App\Cobranca\Repository\EventoHistoricoRepository;
use App\Cobranca\Repository\ObrigacaoRepository;
use App\Cobranca\Service\CalculadoraEncargos;
use App\Cobranca\Service\RegistrarEventoHistorico;
use App\Cobranca\Service\ResolvedorConfigEncargos;
use App\Cobranca\UseCase\EditarConfiguracaoCasoUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(EditarConfiguracaoCasoUseCase::class)]
final class EditarConfiguracaoCasoUseCaseTest extends TestCase
{
    private CasoCobrancaRepository&MockObject $casoRepository;
    private ObrigacaoRepository&MockObject $obrigacaoRepository;
    private EventoHistoricoRepository&MockObject $eventoRepository;
    private EditarConfiguracaoCasoUseCase $sut;
    private Tenant $tenant;
    private User $usuario;

    protected function setUp(): void
    {
        $this->casoRepository = $this->createMock(CasoCobrancaRepository::class);
        $this->obrigacaoRepository = $this->createMock(ObrigacaoRepository::class);
        // RegistrarEventoHistorico é final (não-mockável): usa-se o REAL com o repositório de eventos
        // mockado, validando o flush via a chamada salvar(EventoHistorico, flush).
        $this->eventoRepository = $this->createMock(EventoHistoricoRepository::class);
        // CalculadoraEncargos e ResolvedorConfigEncargos são `final` mas PUROS (sem I/O): usa-se o REAL
        // — mockar um motor de dinheiro esconderia justamente o cálculo que estamos verificando.
        $this->sut = new EditarConfiguracaoCasoUseCase(
            $this->casoRepository,
            $this->obrigacaoRepository,
            new ResolvedorConfigEncargos(),
            new CalculadoraEncargos(),
            new RegistrarEventoHistorico($this->eventoRepository),
        );
        // Tenant/User não são abstrações do domínio: instâncias reais, não mocks. O User é o autor do
        // evento de auditoria (mudança de config financeira precisa de ator — achado da revisão).
        $this->tenant = new Tenant();
        $this->usuario = new User();
    }

    #[Test]
    #[TestDox('Altera os 4 campos de honorário do caso e persiste com flush único (evento sem flush)')]
    public function alteraOsQuatroCamposDeHonorarioEPersiste(): void
    {
        // Caso com tenant (exigido pelo RegistrarEventoHistorico) e sem obrigações a recalcular.
        $caso = (new CasoCobranca())->setTenant($this->tenant);

        $this->casoRepository
            ->expects($this->once())
            ->method('findOneByIdDoTenant')
            ->with(30, $this->tenant)
            ->willReturn($caso);

        $this->obrigacaoRepository
            ->expects($this->once())
            ->method('paraRecalculoDeEncargosDoCaso')
            ->with($caso)
            ->willReturn([]);

        // Flush único no caso; o evento entra SEM flush (a transação fecha no salvar do caso).
        $this->casoRepository->expects($this->once())->method('salvar')->with($caso, true);
        $this->eventoRepository->expects($this->once())->method('salvar')->with(self::isInstanceOf(EventoHistorico::class), false);

        $input = $this->input('15.50');
        $input->baseHonorarios = BaseEncargo::Principal;
        $input->carenciaHonorariosDias = 45;

        $resultado = $this->sut->executar($input, $this->tenant, $this->usuario);

        self::assertSame($caso, $resultado);
        self::assertSame(FormaHonorarios::AcrescidoDivida, $resultado->getFormaHonorarios());
        self::assertSame('15.50', $resultado->getPercentualHonorarios());
        self::assertSame(BaseEncargo::Principal, $resultado->getBaseHonorarios());
        self::assertSame(45, $resultado->getCarenciaHonorariosDias());
    }

    #[Test]
    #[TestDox('T1: o input do caso NÃO move mais o honorário — recálculo lê a taxa VIVA da carteira/objeto')]
    public function recalculoIgnoraOInputDoCasoEUsaATaxaVivaDaCarteira(): void
    {
        // T1 (cascata ao vivo sem snapshot): `ResolvedorConfigEncargos::resolverDoCaso` passou a
        // delegar ao OBJETO/CARTEIRA e não lê mais `formaHonorarios`/`percentualHonorarios` do caso.
        // Este UseCase (fora do escopo de T1 — não foi aposentado) continua gravando essas colunas,
        // mas elas viraram sombra/mortas: o "20.00" pedido aqui não chega a influenciar o cálculo. A
        // taxa efetiva (10%, fixa na carteira de `casoComHonorarios`) é a mesma antes e depois.
        $caso = $this->casoComHonorarios('10.00');
        $obrigacao = $this->obrigacaoAutomatica($caso, 100000, '2020-01-01');

        $honAntes = $this->materializarParaHoje($obrigacao);
        self::assertGreaterThan(0, $honAntes, 'com 240+ dias de atraso a 10% já há honorário');

        $this->casoRepository->method('findOneByIdDoTenant')->willReturn($caso);
        $this->obrigacaoRepository->method('paraRecalculoDeEncargosDoCaso')->with($caso)->willReturn([$obrigacao]);
        $this->eventoRepository->method('salvar');

        $this->sut->executar($this->input('20.00'), $this->tenant, $this->usuario);

        $honDepois = $obrigacao->getHonorarios();
        self::assertSame($honAntes, $honDepois, 'T1: a taxa efetiva vem da carteira (10%), intocada pelo input do caso (20%)');
        // Recálculo automático NÃO congela (INV-E4): a obrigação segue sob o cron.
        self::assertFalse($obrigacao->encargosCongelados());
    }

    #[Test]
    #[TestDox('INV-E4: obrigação congelada não é tocada pelo laço de recálculo (prova por mutação)')]
    public function naoTocaObrigacaoCongelada(): void
    {
        $caso = $this->casoComHonorarios('10.00');

        // Congelada com valores "da contabilidade", DIFERENTES do que o recálculo produziria. O
        // predicado do repositório já exclui congeladas; aqui provamos o guard do laço: mesmo que uma
        // escapasse para a lista, ela NÃO é tocada.
        $congelada = $this->obrigacaoAutomatica($caso, 100000, '2020-01-01');
        $congelada->definirEncargos(111, 222, 333, 444, new \DateTimeImmutable('2026-02-01'));
        $congelada->congelarEncargos(new \DateTimeImmutable('2026-02-01'));

        $this->casoRepository->method('findOneByIdDoTenant')->willReturn($caso);
        $this->obrigacaoRepository->method('paraRecalculoDeEncargosDoCaso')->willReturn([$congelada]);
        $this->eventoRepository->method('salvar');

        $this->sut->executar($this->input('20.00'), $this->tenant, $this->usuario);

        self::assertSame(111, $congelada->getJuros());
        self::assertSame(222, $congelada->getMulta());
        self::assertSame(333, $congelada->getCorrecao());
        self::assertSame(444, $congelada->getHonorarios());
        self::assertSame('2026-02-01', $congelada->getEncargosAtualizadosEm()?->format('Y-m-d'), 'nem a data de referência muda');
    }

    #[Test]
    #[TestDox('T1: pedir REDUÇÃO no input do caso também não move o honorário — a taxa vem da carteira')]
    public function inputDeReducaoNoCasoTambemNaoMoveOHonorario(): void
    {
        $caso = $this->casoComHonorarios('20.00');
        $obrigacao = $this->obrigacaoAutomatica($caso, 100000, '2020-01-01');

        $honAntes = $this->materializarParaHoje($obrigacao); // a 20% (carteira)
        self::assertGreaterThan(0, $honAntes);

        $this->casoRepository->method('findOneByIdDoTenant')->willReturn($caso);
        $this->obrigacaoRepository->method('paraRecalculoDeEncargosDoCaso')->willReturn([$obrigacao]);
        $this->eventoRepository->method('salvar');

        $this->sut->executar($this->input('10.00'), $this->tenant, $this->usuario);

        $honDepois = $obrigacao->getHonorarios();
        self::assertSame($honAntes, $honDepois, 'T1: input do caso (10%) morto — a taxa efetiva segue a da carteira (20%)');
    }

    #[Test]
    #[TestDox('Preserva o exigível: editar honorários NÃO recompõe juros/multa/correção (bomba F2 fechada)')]
    public function preservaOExigivelAoEditarHonorarios(): void
    {
        // Cenário da F2: a taxa de juros/multa da carteira foi BAIXADA A ZERO, mas a obrigação já tem o
        // exigível MATERIALIZADO (juros 500 · multa 100) de uma taxa antiga. Editar os honorários NÃO pode
        // recompor juros/multa para a taxa nova (0) — isso apagaria dinheiro reconhecido, a bomba que a
        // auditoria pegou. O fix recalcula SÓ o honorário; o exigível (INV-E1) fica intacto.
        $caso = $this->casoComHonorarios('10.00');
        $caso->getObjeto()->getCarteira()->setTaxaJurosMensalBp(0)->setTaxaMultaBp(0);
        $obrigacao = $this->obrigacaoAutomatica($caso, 100000, '2020-01-01');
        $obrigacao->definirEncargos(50000, 10000, 0, 15000, new \DateTimeImmutable('2026-01-01'));

        $this->casoRepository->method('findOneByIdDoTenant')->willReturn($caso);
        $this->obrigacaoRepository->method('paraRecalculoDeEncargosDoCaso')->willReturn([$obrigacao]);
        $this->eventoRepository->method('salvar');

        $this->sut->executar($this->input('20.00'), $this->tenant, $this->usuario);

        // Exigível INTACTO — não despencou para a taxa zerada.
        self::assertSame(50000, $obrigacao->getJuros(), 'juros (exigível) preservado');
        self::assertSame(10000, $obrigacao->getMulta(), 'multa (exigível) preservada');
        self::assertSame(0, $obrigacao->getCorrecao());
        // Só o honorário mudou — recalculado pela taxa VIVA da carteira (10%, fixa; T1: o "20.00" do
        // input do caso não é mais lido): 10% da base composta (100000+50000+10000+0 = 160000) = 16000.
        self::assertSame(16000, $obrigacao->getHonorarios(), 'só o honorário foi recalculado, pela taxa da carteira (10%)');
        self::assertFalse($obrigacao->encargosCongelados(), 'segue automática (INV-E4)');
    }

    #[Test]
    #[TestDox('Guarda multi-tenant: caso de outro escritório → exceção, sem salvar nem recalcular')]
    public function rejeitaCasoDeOutroTenant(): void
    {
        // Caso inexistente OU de outro escritório: findOneByIdDoTenant devolve null.
        $this->casoRepository->method('findOneByIdDoTenant')->willReturn(null);
        $this->casoRepository->expects($this->never())->method('salvar');
        $this->obrigacaoRepository->expects($this->never())->method('paraRecalculoDeEncargosDoCaso');
        $this->eventoRepository->expects($this->never())->method('salvar');

        $this->expectException(CasoNaoEncontradoException::class);

        $this->sut->executar($this->input('20.00'), $this->tenant, $this->usuario);
    }

    #[Test]
    #[TestDox('Registra UM único evento no histórico, mesmo com várias obrigações recalculadas')]
    public function registraUmUnicoEventoMesmoComVariasObrigacoes(): void
    {
        $caso = $this->casoComHonorarios('10.00');
        $o1 = $this->obrigacaoAutomatica($caso, 100000, '2020-01-01');
        $o2 = $this->obrigacaoAutomatica($caso, 50000, '2019-06-01');

        $this->casoRepository->method('findOneByIdDoTenant')->willReturn($caso);
        $this->obrigacaoRepository->method('paraRecalculoDeEncargosDoCaso')->willReturn([$o1, $o2]);

        // UM único evento (não um por obrigação — seria ruído no histórico).
        $this->eventoRepository->expects($this->once())->method('salvar');

        $this->sut->executar($this->input('20.00'), $this->tenant, $this->usuario);

        self::assertGreaterThan(0, $o1->getHonorarios());
        self::assertGreaterThan(0, $o2->getHonorarios());
    }

    /** Materializa os encargos da obrigação para HOJE pela cascata atual (estado "antes" coerente com o cron). */
    private function materializarParaHoje(Obrigacao $obrigacao): int
    {
        $hoje = new \DateTimeImmutable('today');
        $config = (new ResolvedorConfigEncargos())->resolver($obrigacao);
        $novos = (new CalculadoraEncargos())->calcular(
            $obrigacao->getValorOriginal(),
            $obrigacao->getVencimentoOriginal(),
            $config,
            $hoje,
        );
        $obrigacao->definirEncargos($novos['juros'], $novos['multa'], $novos['correcao'], $novos['honorarios'], $hoje);

        return $obrigacao->getHonorarios();
    }

    /**
     * Caso com carteira "TOPLIFE" (juros 1% a.m., multa 2%, honorários sobre base composta com carência
     * de 30 dias e alíquota `AcrescidoDivida` no percentual dado). Grafo em memória — o resolver lê só
     * os getters de config, sem persistência.
     *
     * T1 (cascata ao vivo sem snapshot): a alíquota de honorários mora na CARTEIRA (herdada pelo
     * objeto/caso sem override, T1 §3.1) — o snapshot do CASO (`formaHonorarios`/`percentualHonorarios`,
     * que `EditarConfiguracaoCasoUseCase` continua escrevendo) virou coluna-sombra morta: não é mais
     * lido por `ResolvedorConfigEncargos::resolverDoCaso`.
     */
    private function casoComHonorarios(string $percentual): CasoCobranca
    {
        $carteira = (new Carteira())
            ->setTaxaJurosMensalBp(100)
            ->setTaxaMultaBp(200)
            ->setBaseHonorarios(BaseEncargo::Composta)
            ->setCarenciaHonorariosDias(30)
            ->setFormaHonorarios(FormaHonorarios::AcrescidoDivida)
            ->setPercentualHonorarios($percentual);

        $objeto = (new ObjetoCobranca())->setCarteira($carteira);

        return (new CasoCobranca())
            ->setTenant($this->tenant)
            ->setObjeto($objeto);
    }

    private function obrigacaoAutomatica(CasoCobranca $caso, int $valorOriginal, string $vencimento): Obrigacao
    {
        return (new Obrigacao())
            ->setTenant($this->tenant)
            ->setCaso($caso)
            ->setDescricao('Boleto de teste')
            ->setValorOriginal($valorOriginal)
            ->setVencimentoOriginal(new \DateTimeImmutable($vencimento));
    }

    private function input(string $percentual): EditarConfiguracaoCasoInput
    {
        $input = new EditarConfiguracaoCasoInput();
        $input->casoId = 30;
        $input->formaHonorarios = FormaHonorarios::AcrescidoDivida;
        $input->percentualHonorarios = $percentual;

        return $input;
    }
}
