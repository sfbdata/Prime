<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\Entity\Acordo;
use App\Cobranca\Entity\AlocacaoPagamento;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\EventoHistorico;
use App\Cobranca\Entity\Obrigacao;
use App\Cobranca\Entity\Pagamento;
use App\Cobranca\Enum\StatusCaso;
use App\Cobranca\Enum\TipoEventoHistorico;
use App\Cobranca\Exception\CasoEncerradoException;
use App\Cobranca\Exception\PagamentoNaoEncontradoException;
use App\Cobranca\Repository\AlocacaoPagamentoRepository;
use App\Cobranca\Repository\EventoHistoricoRepository;
use App\Cobranca\Repository\PagamentoRepository;
use App\Cobranca\Service\RegistrarEventoHistorico;
use App\Cobranca\UseCase\ExcluirPagamentoUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Exclusão de recebimento (spec `cobranca-excluir-recebimento.md`).
 *
 * A decisão Liquidada/Viva desta operação NÃO passa pelo `ReconciliadorLiquidacao` e não usa data
 * nenhuma (§3.3.1): para obrigação liquidada ela compara o alocado final contra o SNAPSHOT congelado,
 * e obrigação viva nunca é tocada — apagar pagamento só tira dinheiro. Por isso o motor de encargos não
 * entra aqui, e os números dos cenários são os que se lê.
 */
#[CoversClass(ExcluirPagamentoUseCase::class)]
final class ExcluirPagamentoUseCaseTest extends TestCase
{
    private PagamentoRepository&MockObject $pagamentoRepository;
    private AlocacaoPagamentoRepository&MockObject $alocacaoRepository;
    private EventoHistoricoRepository&MockObject $eventoRepository;
    private ExcluirPagamentoUseCase $sut;
    private Tenant $tenant;
    private User $usuario;

    protected function setUp(): void
    {
        $this->pagamentoRepository = $this->createMock(PagamentoRepository::class);
        $this->alocacaoRepository = $this->createMock(AlocacaoPagamentoRepository::class);
        // RegistrarEventoHistorico é final: usa-se o REAL com o repositório de eventos mockado.
        $this->eventoRepository = $this->createMock(EventoHistoricoRepository::class);
        $this->sut = new ExcluirPagamentoUseCase(
            $this->pagamentoRepository,
            $this->alocacaoRepository,
            new RegistrarEventoHistorico($this->eventoRepository),
        );
        $this->tenant = new Tenant();
        $this->usuario = new User();
    }

    #[Test]
    #[TestDox('Obrigação liquidada por ESTE pagamento volta a VIVA e descongela')]
    public function reabreObrigacaoQueEstePagamentoHaviaLiquidado(): void
    {
        $caso = $this->caso();
        $obrigacao = $this->obrigacao(5000);
        $obrigacao->liquidar(0, 0, 0, 0, new \DateTimeImmutable('2026-07-10'));
        self::assertTrue($obrigacao->estaLiquidada(), 'pré-condição do cenário');

        // Σ persistido = 5000, TODO deste pagamento → alocado final 0 < exigível 5000 → reabre.
        $pagamento = $this->pagamento($caso, 5000);
        $this->comAlocacoes($pagamento, [[$obrigacao, 5000]], somaPersistida: [$this->idDe($obrigacao) => 5000]);

        $this->pagamentoRepository->expects($this->once())->method('remover')->with($pagamento, true);

        $this->sut->executar(77, null, $this->tenant, $this->usuario);

        self::assertFalse($obrigacao->estaLiquidada(), 'sem reconciliar, a dívida ficaria quitada com o pagamento apagado');
        self::assertNull($obrigacao->getLiquidadaEm());
        self::assertFalse($obrigacao->encargosCongelados(), 'descongelada: os juros voltam a correr');
    }

    #[Test]
    #[TestDox('Obrigação que OUTRO pagamento ainda cobre CONTINUA liquidada (o alocado não é zerado)')]
    public function mantemLiquidadaQuandoOutroPagamentoAindaCobreADivida(): void
    {
        // Cenário real: o mesmo recebimento foi lançado DUAS vezes na mesma obrigação de 50,00. Apagar a
        // duplicata não pode ressuscitar a dívida — o outro pagamento continua cobrindo os 50,00.
        //
        // 🔑 É o teste que guarda o defeito do handoff: ele mandava reconciliar com o alocado ZERADO nas
        // obrigações tocadas. Zerado, o final seria 0 < 5000, a obrigação reabriria e o devedor voltaria a
        // ser cobrado por dinheiro que já pagou. Com `Σ (10000) − este pagamento (5000) = 5000 >= 5000`,
        // ela permanece liquidada.
        $caso = $this->caso();
        $obrigacao = $this->obrigacao(5000);
        $liquidadaEm = new \DateTimeImmutable('2026-07-10');
        $obrigacao->liquidar(0, 0, 0, 0, $liquidadaEm);

        $pagamento = $this->pagamento($caso, 5000);
        $this->comAlocacoes($pagamento, [[$obrigacao, 5000]], somaPersistida: [$this->idDe($obrigacao) => 10000]);

        $this->pagamentoRepository->expects($this->once())->method('remover')->with($pagamento, true);

        $this->sut->executar(77, null, $this->tenant, $this->usuario);

        self::assertTrue($obrigacao->estaLiquidada(), 'o outro pagamento ainda cobre a dívida');
        self::assertEquals($liquidadaEm, $obrigacao->getLiquidadaEm(), 'snapshot da liquidação preservado');
        self::assertTrue($obrigacao->encargosCongelados());
    }

    #[Test]
    #[TestDox('O que se subtrai é a ALOCAÇÃO na obrigação, não o valor total do pagamento')]
    public function subtraiAAlocacaoNaoOTotalDoPagamento(): void
    {
        // Os dois números são DIFERENTES de propósito: o pagamento recebeu 50,00 do devedor (30,00 de
        // dívida + 20,00 de honorários do escritório), mas só 30,00 abateram esta obrigação — honorário
        // não vira alocação. A obrigação de 30,00 tem Σ 60,00 (outro pagamento também a cobriu).
        //
        // Subtraindo a ALOCAÇÃO (30,00): final 30,00 >= exigível 30,00 → segue liquidada, correto.
        // Subtraindo o TOTAL do pagamento (50,00): final 10,00 < 30,00 → reabriria uma dívida paga.
        $caso = $this->caso();
        $obrigacao = $this->obrigacao(3000);
        $liquidadaEm = new \DateTimeImmutable('2026-07-10');
        $obrigacao->liquidar(0, 0, 0, 0, $liquidadaEm);

        $pagamento = $this->pagamento($caso, 3000, honorarios: 2000);
        self::assertSame(5000, $pagamento->valorTotalRecebido(), 'pré-condição: total != alocado');
        $this->comAlocacoes($pagamento, [[$obrigacao, 3000]], somaPersistida: [$this->idDe($obrigacao) => 6000]);

        $this->sut->executar(77, null, $this->tenant, $this->usuario);

        self::assertTrue($obrigacao->estaLiquidada(), 'o outro pagamento cobre os 30,00 da obrigação');
        self::assertEquals($liquidadaEm, $obrigacao->getLiquidadaEm());
    }

    #[Test]
    #[TestDox('Liquidada é decidida pelo SNAPSHOT congelado, não pelo exigível recalculado')]
    public function decideLiquidadaPeloSnapshotNaoPeloRecalculo(): void
    {
        // A obrigação vale 50,00 e foi liquidada por 60,00 (o snapshot inclui 10,00 de encargos que
        // corriam então). Sobram 55,00 alocados depois da exclusão.
        //
        // Pelo SNAPSHOT (correto): 55,00 < 60,00 → reabre, porque faltam 5,00 do que era devido.
        // Pelo recálculo AO VIVO neste cenário (config neutra → exigível = 50,00): 55,00 >= 50,00 → ela
        // ficaria liquidada e CONGELADA com valor a descoberto, e o que falta nunca mais cresceria.
        $caso = $this->caso();
        $obrigacao = $this->obrigacao(5000);
        $obrigacao->liquidar(1000, 0, 0, 0, new \DateTimeImmutable('2026-07-10'));
        self::assertSame(6000, $obrigacao->valorExigivel(), 'pré-condição: snapshot acima do valor original');

        $pagamento = $this->pagamento($caso, 2000);
        $this->comAlocacoes($pagamento, [[$obrigacao, 2000]], somaPersistida: [$this->idDe($obrigacao) => 7500]);

        $this->sut->executar(77, null, $this->tenant, $this->usuario);

        self::assertFalse($obrigacao->estaLiquidada(), 'faltam 5,00 do que era devido — quitada seria subcobrança');
        self::assertNull($obrigacao->getLiquidadaEm());
        self::assertFalse($obrigacao->encargosCongelados(), 'o relógio volta a correr sobre o que falta');
    }

    #[Test]
    #[TestDox('Substituída por acordo VIGENTE não é tocada nem quando fica a descoberto')]
    public function naoTocaObrigacaoSubstituidaPorAcordoVigente(): void
    {
        // Quem manda numa obrigação substituída é o acordo — mesma guarda do Reconciliador.
        $caso = $this->caso();
        $obrigacao = $this->obrigacao(5000);
        $obrigacao->liquidar(0, 0, 0, 0, new \DateTimeImmutable('2026-07-10'));
        $obrigacao->setAcordoSubstituto(new Acordo()); // status Ativo = vigente (default da entidade)

        $pagamento = $this->pagamento($caso, 5000);
        $this->comAlocacoes($pagamento, [[$obrigacao, 5000]], somaPersistida: [$this->idDe($obrigacao) => 5000]);

        $this->sut->executar(77, null, $this->tenant, $this->usuario);

        // Alocado final = 0, bem abaixo do snapshot: sem a guarda, ela reabriria.
        self::assertTrue($obrigacao->estaLiquidada(), 'a obrigação substituída é gerida pelo acordo, não pela exclusão');
        self::assertTrue($obrigacao->encargosCongelados());
    }

    #[Test]
    #[TestDox('As alocações vêm por QUERY, não da coleção inversa do pagamento')]
    public function leAsAlocacoesPorQuery(): void
    {
        $caso = $this->caso();
        $obrigacao = $this->obrigacao(5000);
        $obrigacao->liquidar(0, 0, 0, 0, new \DateTimeImmutable('2026-07-10'));

        // A coleção inversa aponta para OUTRA obrigação — é o que discrimina de verdade: se o UseCase
        // lesse a coleção, ele subtrairia da obrigação errada, deixando a certa liquidada e reabrindo uma
        // que este pagamento nunca tocou. Um cenário com a coleção só vazia não separa esse erro do erro
        // de não subtrair nada.
        $intrusa = $this->obrigacao(5000);
        $intrusa->liquidar(0, 0, 0, 0, new \DateTimeImmutable('2026-07-10'));
        $pagamento = $this->pagamento($caso, 5000);
        $pagamento->adicionarAlocacao(
            (new AlocacaoPagamento())->setTenant($this->tenant)->setObrigacao($intrusa)->setValor(5000),
        );

        $this->comAlocacoes($pagamento, [[$obrigacao, 5000]], somaPersistida: [
            $this->idDe($obrigacao) => 5000,
            $this->idDe($intrusa) => 5000,
        ]);

        $this->sut->executar(77, null, $this->tenant, $this->usuario);

        self::assertFalse($obrigacao->estaLiquidada(), 'a obrigação que a QUERY aponta é a que reabre');
        self::assertTrue($intrusa->estaLiquidada(), 'a da coleção inversa não podia ser tocada');
    }

    #[Test]
    #[TestDox('O evento é autocontido: valor, data, composição e onde estava alocado')]
    public function registraEventoAutocontido(): void
    {
        $caso = $this->caso();
        $obrigacao = $this->obrigacao(5000)->setDescricao('Taxa 05/2026');

        $pagamento = $this->pagamento($caso, 4000, honorarios: 1000, data: new \DateTimeImmutable('2026-06-15'));
        $this->comAlocacoes($pagamento, [[$obrigacao, 4000]], somaPersistida: [$this->idDe($obrigacao) => 4000]);

        $capturado = null;
        $this->eventoRepository
            ->expects($this->once())
            ->method('salvar')
            ->with(
                self::callback(static function (EventoHistorico $e) use (&$capturado): bool {
                    $capturado = $e;

                    return true;
                }),
                false,
            );

        $this->sut->executar(77, '  Lançamento em duplicidade  ', $this->tenant, $this->usuario);

        self::assertInstanceOf(EventoHistorico::class, $capturado);
        self::assertSame(TipoEventoHistorico::PagamentoExcluido, $capturado->getTipo());
        self::assertSame(
            'Recebimento excluído: R$ 50,00 de 15/06/2026 — Lançamento em duplicidade',
            $capturado->getDescricao(),
            'a timeline não exibe o payload JSON: a descrição tem de bastar',
        );

        $dados = $capturado->getDados();
        self::assertSame(77, $dados['pagamentoId']);
        self::assertSame('2026-06-15', $dados['data']);
        self::assertSame(4000, $dados['valorDivida']);
        self::assertSame(0, $dados['valorEncargos']);
        self::assertSame(1000, $dados['valorHonorarios']);
        self::assertSame(5000, $dados['valorTotal']);
        self::assertSame('Lançamento em duplicidade', $dados['motivo'], 'motivo aparado');
        self::assertSame(
            [['obrigacaoId' => $this->idDe($obrigacao), 'descricao' => 'Taxa 05/2026', 'valor' => 4000]],
            $dados['alocacoes'],
            'sem isto não há como saber o que o pagamento apagado abatia',
        );
    }

    #[Test]
    #[TestDox('Motivo vazio ou só espaços vira null (o campo é opcional — decisão E2)')]
    public function motivoOpcionalViraNull(): void
    {
        $caso = $this->caso();
        $pagamento = $this->pagamento($caso, 4000);
        $this->comAlocacoes($pagamento, [], somaPersistida: []);

        $capturado = null;
        $this->eventoRepository->method('salvar')->willReturnCallback(
            static function (EventoHistorico $e) use (&$capturado): void {
                $capturado = $e;
            },
        );

        $this->sut->executar(77, '   ', $this->tenant, $this->usuario);

        self::assertInstanceOf(EventoHistorico::class, $capturado);
        self::assertNull($capturado->getDados()['motivo']);
        self::assertSame('Recebimento excluído: R$ 40,00 de 15/06/2026', $capturado->getDescricao());
    }

    #[Test]
    #[TestDox('Pagamento inexistente ou de outro escritório: não apaga nada')]
    public function rejeitaPagamentoNaoEncontrado(): void
    {
        // Inexistente OU de outro tenant: findOneByIdDoTenant devolve null (guarda multi-tenant).
        $this->pagamentoRepository->method('findOneByIdDoTenant')->willReturn(null);
        $this->pagamentoRepository->expects($this->never())->method('remover');
        $this->eventoRepository->expects($this->never())->method('salvar');

        $this->expectException(PagamentoNaoEncontradoException::class);

        $this->sut->executar(77, null, $this->tenant, $this->usuario);
    }

    #[Test]
    #[TestDox('Caso encerrado não aceita exclusão (mesma regra da correção)')]
    public function rejeitaCasoEncerrado(): void
    {
        $caso = (new CasoCobranca())->setTenant($this->tenant)->setStatus(StatusCaso::Encerrado);
        $pagamento = $this->pagamento($caso, 4000);
        $this->pagamentoRepository->method('findOneByIdDoTenant')->willReturn($pagamento);
        $this->pagamentoRepository->expects($this->never())->method('remover');
        $this->eventoRepository->expects($this->never())->method('salvar');

        $this->expectException(CasoEncerradoException::class);

        $this->sut->executar(77, null, $this->tenant, $this->usuario);
    }

    // ---------------------------------------------------------------- helpers

    private function caso(): CasoCobranca
    {
        // Sem objeto → ConfigEncargos::neutra() → exigível = valorOriginal (ver docblock da classe).
        $caso = (new CasoCobranca())->setTenant($this->tenant);
        (new \ReflectionProperty(CasoCobranca::class, 'id'))->setValue($caso, 900);

        return $caso;
    }

    private function obrigacao(int $valorOriginal): Obrigacao
    {
        static $seq = 0;
        $o = (new Obrigacao())
            ->setTenant($this->tenant)
            ->setDescricao('Obrigação')
            ->setValorOriginal($valorOriginal)
            ->setVencimentoOriginal(new \DateTimeImmutable('2026-06-01'));
        (new \ReflectionProperty(Obrigacao::class, 'id'))->setValue($o, ++$seq);

        return $o;
    }

    private function pagamento(
        CasoCobranca $caso,
        int $valorDivida,
        int $honorarios = 0,
        ?\DateTimeImmutable $data = null,
    ): Pagamento {
        $p = new Pagamento();
        $p->setTenant($this->tenant);
        $p->setCaso($caso);
        $p->setValorDivida($valorDivida);
        $p->setValorHonorarios($honorarios);
        $p->setData($data ?? new \DateTimeImmutable('2026-06-15'));

        return $p;
    }

    /**
     * Arma os dois retornos do repositório de alocações: as alocações DESTE pagamento (por query) e o
     * Σ persistido por obrigação (que ainda as inclui, pois o remove só some no flush).
     *
     * @param list<array{0: Obrigacao, 1: int}> $paresObrigacaoValor
     * @param array<int, int>                   $somaPersistida
     */
    private function comAlocacoes(Pagamento $pagamento, array $paresObrigacaoValor, array $somaPersistida): void
    {
        $alocacoes = [];
        foreach ($paresObrigacaoValor as [$obrigacao, $valor]) {
            $alocacoes[] = (new AlocacaoPagamento())
                ->setTenant($this->tenant)
                ->setObrigacao($obrigacao)
                ->setValor($valor);
        }

        $this->pagamentoRepository->method('findOneByIdDoTenant')->with(77, $this->tenant)->willReturn($pagamento);
        $this->alocacaoRepository->method('doPagamento')->with(77, $this->tenant)->willReturn($alocacoes);
        $this->alocacaoRepository->method('somasPorObrigacaoDosCasos')->with([900], $this->tenant)->willReturn($somaPersistida);
    }

    private function idDe(Obrigacao $o): int
    {
        return (int) $o->getId();
    }
}
