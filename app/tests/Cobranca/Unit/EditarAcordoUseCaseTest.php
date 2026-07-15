<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\DTO\EditarAcordoInput;
use App\Cobranca\DTO\ParcelaEdicaoInput;
use App\Cobranca\Entity\Acordo;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\EventoHistorico;
use App\Cobranca\Entity\Obrigacao;
use App\Cobranca\Enum\StatusCaso;
use App\Cobranca\Exception\AcordoNaoAtivoException;
use App\Cobranca\Exception\AcordoNaoEncontradoException;
use App\Cobranca\Exception\CasoEncerradoException;
use App\Cobranca\Exception\ObrigacaoComPagamentoException;
use App\Cobranca\Exception\ObrigacaoDeAcordoException;
use App\Cobranca\Exception\ObrigacaoNaoEncontradaException;
use App\Cobranca\Exception\ParcelamentoInvalidoException;
use App\Cobranca\Repository\AcordoRepository;
use App\Cobranca\Repository\AlocacaoPagamentoRepository;
use App\Cobranca\Repository\EventoHistoricoRepository;
use App\Cobranca\Repository\ObrigacaoRepository;
use App\Cobranca\Service\RegistrarEventoHistorico;
use App\Cobranca\UseCase\EditarAcordoUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Edição de Acordo (Ajuste 7, Fatia 4): reajusta as parcelas AINDA NÃO PAGAS de um acordo ATIVO.
 * Guardas: parcela com pagamento alocado é congelada (INV-C), só acordo Ativo edita (INV-D), o
 * conjunto de substituídas não muda (INV-E), caso encerrado bloqueia (INV-H) e Σ parcelas fecha
 * com o total (INV-B). Tudo auditado num evento `AcordoEditado`.
 */
#[CoversClass(EditarAcordoUseCase::class)]
final class EditarAcordoUseCaseTest extends TestCase
{
    private AcordoRepository&MockObject $acordoRepository;
    private ObrigacaoRepository&MockObject $obrigacaoRepository;
    private AlocacaoPagamentoRepository&MockObject $alocacaoRepository;
    private EventoHistoricoRepository&MockObject $eventoRepository;
    private EditarAcordoUseCase $sut;
    private Tenant $tenant;
    private User $editadoPor;

    protected function setUp(): void
    {
        $this->acordoRepository = $this->createMock(AcordoRepository::class);
        $this->obrigacaoRepository = $this->createMock(ObrigacaoRepository::class);
        $this->alocacaoRepository = $this->createMock(AlocacaoPagamentoRepository::class);
        $this->eventoRepository = $this->createMock(EventoHistoricoRepository::class);
        $this->sut = new EditarAcordoUseCase(
            $this->acordoRepository,
            $this->obrigacaoRepository,
            $this->alocacaoRepository,
            new RegistrarEventoHistorico($this->eventoRepository),
        );
        $this->tenant = new Tenant();
        $this->editadoPor = new User();
    }

    #[Test]
    public function alteraAdicionaERemoveParcelasNaoPagas(): void
    {
        $caso = $this->novoCaso();
        $acordo = $this->novoAcordo($caso);
        $p1 = $this->parcelaExistente($caso, $acordo, 1, 'Parcela 1/2', 30000, '2026-09-01');
        $p2 = $this->parcelaExistente($caso, $acordo, 2, 'Parcela 2/2', 30000, '2026-10-01');

        $this->acordoRepository->method('findOneByIdDoTenant')->with(7, $this->tenant)->willReturn($acordo);
        // Nenhuma parcela tem pagamento alocado → todas editáveis.
        $this->alocacaoRepository->method('somasPorObrigacaoDosCasos')->willReturn([]);

        $removidas = [];
        $this->obrigacaoRepository->method('remover')->willReturnCallback(
            function (Obrigacao $o) use (&$removidas): void { $removidas[] = $o; }
        );
        // `salvar` recebe tanto a alterada quanto a nova; a nova é a que ainda não tem id.
        $salvas = [];
        $this->obrigacaoRepository->method('salvar')->willReturnCallback(
            function (Obrigacao $o) use (&$salvas): void { $salvas[] = $o; }
        );
        $this->eventoRepository->expects($this->once())->method('salvar')
            ->with(self::isInstanceOf(EventoHistorico::class), true);

        // p1 alterada (valor e data), p2 REMOVIDA (ausente do input), uma parcela NOVA entra.
        $input = $this->novoInput(7, 60000, [
            $this->linha(1, 'Parcela 1/2 (ajustada)', 20000, '2026-09-15'),
            $this->linha(null, 'Parcela 2/2', 40000, '2026-11-01'),
        ]);

        $this->sut->executar($input, $this->tenant, $this->editadoPor);

        // Alterada in-place.
        self::assertSame('Parcela 1/2 (ajustada)', $p1->getDescricao());
        self::assertSame(20000, $p1->getValorOriginal());
        self::assertSame('2026-09-15', $p1->getVencimentoOriginal()->format('Y-m-d'));
        // A ausente foi removida.
        self::assertSame([$p2], $removidas);
        // A alterada foi persistida (sem virar outra linha).
        self::assertContains($p1, $salvas);
        // A nova nasce ligada ao acordo, caso e tenant certos.
        $novas = array_values(array_filter($salvas, static fn (Obrigacao $o): bool => $o->getId() === null));
        self::assertCount(1, $novas);
        self::assertSame($acordo, $novas[0]->getAcordoOrigem());
        self::assertSame($caso, $novas[0]->getCaso());
        self::assertSame($this->tenant, $novas[0]->getTenant());
        self::assertSame(40000, $novas[0]->getValorOriginal());
        self::assertSame($this->editadoPor, $novas[0]->getCriadoPor());
        // Snapshot atualizado (INV-B): Σ parcelas == total.
        self::assertSame(60000, $acordo->getValorTotalNegociado());
    }

    #[Test]
    public function recomputaSnapshotDaEntradaPelaLinhaChamadaEntrada(): void
    {
        // D8: a entrada é uma linha como as outras; o snapshot é derivado dela.
        $caso = $this->novoCaso();
        $acordo = $this->novoAcordo($caso);
        $this->parcelaExistente($caso, $acordo, 1, 'Entrada', 40000, '2026-08-01');
        $this->parcelaExistente($caso, $acordo, 2, 'Parcela 1/1', 60000, '2026-09-01');

        $this->acordoRepository->method('findOneByIdDoTenant')->willReturn($acordo);
        $this->alocacaoRepository->method('somasPorObrigacaoDosCasos')->willReturn([]);

        $input = $this->novoInput(7, 90000, [
            $this->linha(1, 'Entrada', 30000, '2026-08-01'),
            $this->linha(2, 'Parcela 1/1', 60000, '2026-09-01'),
        ]);

        $this->sut->executar($input, $this->tenant, $this->editadoPor);

        self::assertSame(90000, $acordo->getValorTotalNegociado());
        self::assertSame(30000, $acordo->getValorEntrada(), 'entrada derivada da linha "Entrada"');
    }

    #[Test]
    public function congelaParcelaComPagamentoAlocadoQuandoTentaAlterar(): void
    {
        // INV-C: parcela com pagamento não muda de valor/vencimento/descrição.
        $caso = $this->novoCaso();
        $acordo = $this->novoAcordo($caso);
        $this->parcelaExistente($caso, $acordo, 1, 'Parcela 1/1', 30000, '2026-09-01');

        $this->acordoRepository->method('findOneByIdDoTenant')->willReturn($acordo);
        $this->alocacaoRepository->method('somasPorObrigacaoDosCasos')->willReturn([1 => 15000]);
        $this->obrigacaoRepository->expects($this->never())->method('remover');
        $this->eventoRepository->expects($this->never())->method('salvar');

        $this->expectException(ObrigacaoComPagamentoException::class);

        $this->sut->executar(
            $this->novoInput(7, 20000, [$this->linha(1, 'Parcela 1/1', 20000, '2026-09-01')]),
            $this->tenant,
            $this->editadoPor,
        );
    }

    #[Test]
    public function congelaParcelaComPagamentoAlocadoQuandoTentaRemover(): void
    {
        // INV-C: remover uma parcela paga apagaria a contrapartida de um pagamento real.
        $caso = $this->novoCaso();
        $acordo = $this->novoAcordo($caso);
        $this->parcelaExistente($caso, $acordo, 1, 'Parcela 1/2', 30000, '2026-09-01');
        $this->parcelaExistente($caso, $acordo, 2, 'Parcela 2/2', 30000, '2026-10-01');

        $this->acordoRepository->method('findOneByIdDoTenant')->willReturn($acordo);
        $this->alocacaoRepository->method('somasPorObrigacaoDosCasos')->willReturn([2 => 30000]);
        $this->obrigacaoRepository->expects($this->never())->method('remover');
        $this->eventoRepository->expects($this->never())->method('salvar');

        $this->expectException(ObrigacaoComPagamentoException::class);

        // A parcela 2 (paga) foi omitida do input → tentativa de remoção.
        $this->sut->executar(
            $this->novoInput(7, 30000, [$this->linha(1, 'Parcela 1/2', 30000, '2026-09-01')]),
            $this->tenant,
            $this->editadoPor,
        );
    }

    #[Test]
    public function aceitaParcelaPagaQuandoVemInalterada(): void
    {
        // A parcela paga pode (e deve) ser reenviada intacta — a UI a manda travada.
        $caso = $this->novoCaso();
        $acordo = $this->novoAcordo($caso);
        $paga = $this->parcelaExistente($caso, $acordo, 1, 'Parcela 1/2', 30000, '2026-09-01');
        $this->parcelaExistente($caso, $acordo, 2, 'Parcela 2/2', 30000, '2026-10-01');

        $this->acordoRepository->method('findOneByIdDoTenant')->willReturn($acordo);
        $this->alocacaoRepository->method('somasPorObrigacaoDosCasos')->willReturn([1 => 30000]);
        $this->eventoRepository->expects($this->once())->method('salvar');

        $this->sut->executar(
            $this->novoInput(7, 70000, [
                $this->linha(1, 'Parcela 1/2', 30000, '2026-09-01'),
                $this->linha(2, 'Parcela 2/2', 40000, '2026-10-15'),
            ]),
            $this->tenant,
            $this->editadoPor,
        );

        self::assertSame(30000, $paga->getValorOriginal(), 'a paga permanece intacta');
        self::assertSame(70000, $acordo->getValorTotalNegociado());
    }

    #[Test]
    public function congelaParcelaPagaQuandoMudaSomenteADescricao(): void
    {
        // Malha fina do INV-C: só a descrição muda — valor e vencimento idênticos.
        $caso = $this->novoCaso();
        $acordo = $this->novoAcordo($caso);
        $paga = $this->parcelaExistente($caso, $acordo, 1, 'Parcela 1/1', 30000, '2026-09-01');

        $this->acordoRepository->method('findOneByIdDoTenant')->willReturn($acordo);
        $this->alocacaoRepository->method('somasPorObrigacaoDosCasos')->willReturn([1 => 30000]);
        $this->eventoRepository->expects($this->never())->method('salvar');

        $this->expectException(ObrigacaoComPagamentoException::class);

        try {
            $this->sut->executar(
                $this->novoInput(7, 30000, [$this->linha(1, 'Renomeada à revelia', 30000, '2026-09-01')]),
                $this->tenant,
                $this->editadoPor,
            );
        } finally {
            self::assertSame('Parcela 1/1', $paga->getDescricao(), 'a descrição da paga não pode mudar');
        }
    }

    #[Test]
    public function congelaParcelaPagaQuandoMudaSomenteOVencimento(): void
    {
        // Malha fina do INV-C: só o vencimento muda — descrição e valor idênticos.
        $caso = $this->novoCaso();
        $acordo = $this->novoAcordo($caso);
        $paga = $this->parcelaExistente($caso, $acordo, 1, 'Parcela 1/1', 30000, '2026-09-01');

        $this->acordoRepository->method('findOneByIdDoTenant')->willReturn($acordo);
        $this->alocacaoRepository->method('somasPorObrigacaoDosCasos')->willReturn([1 => 30000]);
        $this->eventoRepository->expects($this->never())->method('salvar');

        $this->expectException(ObrigacaoComPagamentoException::class);

        try {
            $this->sut->executar(
                $this->novoInput(7, 30000, [$this->linha(1, 'Parcela 1/1', 30000, '2027-01-01')]),
                $this->tenant,
                $this->editadoPor,
            );
        } finally {
            self::assertSame('2026-09-01', $paga->getVencimentoOriginal()->format('Y-m-d'), 'o vencimento da paga não pode mudar');
        }
    }

    #[Test]
    public function recusaAlterarParcelaJaSubstituidaPorOutroAcordoVigente(): void
    {
        // Invariável 14: a parcela virou "original" de um acordo B vigente — ela voltaria ao saldo se
        // B fosse rompido. Quem a gere é B, não este acordo.
        $caso = $this->novoCaso();
        $acordo = $this->novoAcordo($caso);
        $parcela = $this->parcelaExistente($caso, $acordo, 1, 'Parcela 1/1', 30000, '2026-09-01');
        $acordoB = (new Acordo())->setTenant($this->tenant)->setCaso($caso);
        self::assertTrue($acordoB->getStatus()->ehVigente(), 'B nasce ativo = vigente');
        $parcela->setAcordoSubstituto($acordoB);

        $this->acordoRepository->method('findOneByIdDoTenant')->willReturn($acordo);
        $this->alocacaoRepository->method('somasPorObrigacaoDosCasos')->willReturn([]);
        $this->obrigacaoRepository->expects($this->never())->method('remover');
        $this->eventoRepository->expects($this->never())->method('salvar');

        $this->expectException(ObrigacaoDeAcordoException::class);

        $this->sut->executar(
            $this->novoInput(7, 10000, [$this->linha(1, 'Parcela 1/1', 10000, '2026-09-01')]),
            $this->tenant,
            $this->editadoPor,
        );
    }

    #[Test]
    public function recusaRemoverParcelaJaSubstituidaPorOutroAcordoVigente(): void
    {
        // O caminho destrutivo: omitir a linha apagaria fisicamente a dívida que o acordo B guarda.
        $caso = $this->novoCaso();
        $acordo = $this->novoAcordo($caso);
        $substituida = $this->parcelaExistente($caso, $acordo, 1, 'Parcela 1/2', 30000, '2026-09-01');
        $this->parcelaExistente($caso, $acordo, 2, 'Parcela 2/2', 30000, '2026-10-01');
        $acordoB = (new Acordo())->setTenant($this->tenant)->setCaso($caso);
        $substituida->setAcordoSubstituto($acordoB);

        $this->acordoRepository->method('findOneByIdDoTenant')->willReturn($acordo);
        $this->alocacaoRepository->method('somasPorObrigacaoDosCasos')->willReturn([]);
        $this->obrigacaoRepository->expects($this->never())->method('remover');
        $this->eventoRepository->expects($this->never())->method('salvar');

        $this->expectException(ObrigacaoDeAcordoException::class);

        // A parcela 1 (substituída por B) foi omitida → tentativa de remoção.
        $this->sut->executar(
            $this->novoInput(7, 30000, [$this->linha(2, 'Parcela 2/2', 30000, '2026-10-01')]),
            $this->tenant,
            $this->editadoPor,
        );
    }

    #[Test]
    public function aceitaParcelaSubstituidaQuandoVemInalteradaEEditaAsDemais(): void
    {
        // A UI reenvia TODAS as linhas, inclusive as travadas. Travar a linha inalterada deixaria o
        // acordo inteiro ineditável — o guard só vale para quem de fato mudou (espelha o INV-C).
        $caso = $this->novoCaso();
        $acordo = $this->novoAcordo($caso);
        $substituida = $this->parcelaExistente($caso, $acordo, 1, 'Parcela 1/2', 30000, '2026-09-01');
        $outra = $this->parcelaExistente($caso, $acordo, 2, 'Parcela 2/2', 30000, '2026-10-01');
        $acordoB = (new Acordo())->setTenant($this->tenant)->setCaso($caso);
        $substituida->setAcordoSubstituto($acordoB);

        $this->acordoRepository->method('findOneByIdDoTenant')->willReturn($acordo);
        $this->alocacaoRepository->method('somasPorObrigacaoDosCasos')->willReturn([]);
        $this->eventoRepository->expects($this->once())->method('salvar');

        $this->sut->executar(
            $this->novoInput(7, 70000, [
                // A travada volta INTACTA…
                $this->linha(1, 'Parcela 1/2', 30000, '2026-09-01'),
                // …e a outra é alterada normalmente.
                $this->linha(2, 'Parcela 2/2', 40000, '2026-10-20'),
            ]),
            $this->tenant,
            $this->editadoPor,
        );

        self::assertSame(30000, $substituida->getValorOriginal(), 'a substituída segue intacta');
        self::assertSame(40000, $outra->getValorOriginal(), 'a outra parcela foi editada');
        self::assertSame(70000, $acordo->getValorTotalNegociado());
    }

    #[Test]
    public function aceitaParcelaSubstituidaPorAcordoJaRompido(): void
    {
        // Se o acordo B foi rompido, a parcela voltou a ser gerida por ESTE acordo → editável.
        $caso = $this->novoCaso();
        $acordo = $this->novoAcordo($caso);
        $parcela = $this->parcelaExistente($caso, $acordo, 1, 'Parcela 1/1', 30000, '2026-09-01');
        $acordoB = (new Acordo())->setTenant($this->tenant)->setCaso($caso);
        $acordoB->romper('Devedor parou de pagar');
        $parcela->setAcordoSubstituto($acordoB);

        $this->acordoRepository->method('findOneByIdDoTenant')->willReturn($acordo);
        $this->alocacaoRepository->method('somasPorObrigacaoDosCasos')->willReturn([]);
        $this->eventoRepository->expects($this->once())->method('salvar');

        $this->sut->executar(
            $this->novoInput(7, 10000, [$this->linha(1, 'Parcela 1/1', 10000, '2026-09-01')]),
            $this->tenant,
            $this->editadoPor,
        );

        self::assertSame(10000, $parcela->getValorOriginal());
    }

    #[Test]
    public function rejeitaAMesmaParcelaEnviadaDuasVezes(): void
    {
        // Duplicata inflaria a soma (e o snapshot) sem alterar o conjunto real → INV-B mentiria no banco.
        $caso = $this->novoCaso();
        $acordo = $this->novoAcordo($caso);
        $this->parcelaExistente($caso, $acordo, 1, 'Parcela 1/1', 30000, '2026-09-01');

        $this->acordoRepository->method('findOneByIdDoTenant')->willReturn($acordo);
        $this->alocacaoRepository->method('somasPorObrigacaoDosCasos')->willReturn([]);
        $this->eventoRepository->expects($this->never())->method('salvar');

        $this->expectException(ParcelamentoInvalidoException::class);

        $this->sut->executar(
            $this->novoInput(7, 30000, [
                $this->linha(1, 'Parcela 1/1', 10000, '2026-09-01'),
                $this->linha(1, 'Parcela 1/1', 20000, '2026-09-01'),
            ]),
            $this->tenant,
            $this->editadoPor,
        );
    }

    #[Test]
    public function registraSnapshotDaParcelaRemovidaNoEvento(): void
    {
        // Hard delete: sem o retrato no evento, a auditoria não saberia o que sumiu.
        $caso = $this->novoCaso();
        $acordo = $this->novoAcordo($caso);
        $this->parcelaExistente($caso, $acordo, 1, 'Parcela 1/2', 30000, '2026-09-01');
        $this->parcelaExistente($caso, $acordo, 2, 'Parcela 2/2', 25000, '2026-10-01');

        $this->acordoRepository->method('findOneByIdDoTenant')->willReturn($acordo);
        $this->alocacaoRepository->method('somasPorObrigacaoDosCasos')->willReturn([]);

        $evento = null;
        $this->eventoRepository->method('salvar')->willReturnCallback(
            function (EventoHistorico $e) use (&$evento): void { $evento = $e; }
        );

        $this->sut->executar(
            $this->novoInput(7, 30000, [$this->linha(1, 'Parcela 1/2', 30000, '2026-09-01')]),
            $this->tenant,
            $this->editadoPor,
        );

        $dados = $evento?->getDados();
        self::assertSame(1, $dados['removidas']);
        self::assertSame([[
            'obrigacaoId' => 2,
            'descricao' => 'Parcela 2/2',
            'valorOriginal' => 25000,
            'vencimentoOriginal' => '2026-10-01',
        ]], $dados['removidas_detalhe']);
        // Antes/depois (spec §8): o evento registra os dois lados.
        self::assertSame(2, $dados['antes']['qtd_parcelas']);
        self::assertSame(30000, $dados['depois']['total_negociado']);
    }

    #[Test]
    public function rejeitaAcordoNaoAtivo(): void
    {
        // INV-D: rompido/cancelado/cumprido é congelado.
        $caso = $this->novoCaso();
        $acordo = $this->novoAcordo($caso);
        $acordo->romper('Parou de pagar');

        $this->acordoRepository->method('findOneByIdDoTenant')->willReturn($acordo);
        $this->eventoRepository->expects($this->never())->method('salvar');

        $this->expectException(AcordoNaoAtivoException::class);

        $this->sut->executar(
            $this->novoInput(7, 10000, [$this->linha(null, 'P', 10000, '2026-09-01')]),
            $this->tenant,
            $this->editadoPor,
        );
    }

    #[Test]
    public function rejeitaCasoEncerrado(): void
    {
        $caso = $this->novoCaso()->setStatus(StatusCaso::Encerrado);
        $acordo = $this->novoAcordo($caso);

        $this->acordoRepository->method('findOneByIdDoTenant')->willReturn($acordo);
        $this->eventoRepository->expects($this->never())->method('salvar');

        $this->expectException(CasoEncerradoException::class);

        $this->sut->executar(
            $this->novoInput(7, 10000, [$this->linha(null, 'P', 10000, '2026-09-01')]),
            $this->tenant,
            $this->editadoPor,
        );
    }

    #[Test]
    public function rejeitaAcordoDeOutroTenant(): void
    {
        $this->acordoRepository->method('findOneByIdDoTenant')->willReturn(null);
        $this->eventoRepository->expects($this->never())->method('salvar');

        $this->expectException(AcordoNaoEncontradoException::class);

        $this->sut->executar(
            $this->novoInput(999, 10000, [$this->linha(null, 'P', 10000, '2026-09-01')]),
            $this->tenant,
            $this->editadoPor,
        );
    }

    #[Test]
    public function rejeitaParcelaQueNaoPertenceAEsteAcordo(): void
    {
        // Guarda anti-IDOR interno: id de obrigação de outro acordo/caso não pode ser sequestrado.
        $caso = $this->novoCaso();
        $acordo = $this->novoAcordo($caso);
        $this->parcelaExistente($caso, $acordo, 1, 'Parcela 1/1', 30000, '2026-09-01');

        $this->acordoRepository->method('findOneByIdDoTenant')->willReturn($acordo);
        $this->alocacaoRepository->method('somasPorObrigacaoDosCasos')->willReturn([]);
        $this->eventoRepository->expects($this->never())->method('salvar');

        $this->expectException(ObrigacaoNaoEncontradaException::class);

        $this->sut->executar(
            $this->novoInput(7, 30000, [$this->linha(4242, 'Intrusa', 30000, '2026-09-01')]),
            $this->tenant,
            $this->editadoPor,
        );
    }

    #[Test]
    public function rejeitaTotalQueNaoFecha(): void
    {
        // INV-B revalidado no servidor (o callback do DTO cobre o caminho HTTP; aqui é a rede final).
        $caso = $this->novoCaso();
        $acordo = $this->novoAcordo($caso);
        $this->parcelaExistente($caso, $acordo, 1, 'Parcela 1/1', 30000, '2026-09-01');

        $this->acordoRepository->method('findOneByIdDoTenant')->willReturn($acordo);
        $this->alocacaoRepository->method('somasPorObrigacaoDosCasos')->willReturn([]);
        $this->eventoRepository->expects($this->never())->method('salvar');

        $this->expectException(ParcelamentoInvalidoException::class);

        $this->sut->executar(
            $this->novoInput(7, 99999, [$this->linha(1, 'Parcela 1/1', 30000, '2026-09-01')]),
            $this->tenant,
            $this->editadoPor,
        );
    }

    private function novoCaso(): CasoCobranca
    {
        $caso = (new CasoCobranca())->setTenant($this->tenant);
        (new \ReflectionProperty(CasoCobranca::class, 'id'))->setValue($caso, 50);

        return $caso;
    }

    private function novoAcordo(CasoCobranca $caso): Acordo
    {
        $acordo = (new Acordo())->setTenant($this->tenant)->setCaso($caso);
        (new \ReflectionProperty(Acordo::class, 'id'))->setValue($acordo, 7);

        return $acordo;
    }

    private function parcelaExistente(CasoCobranca $caso, Acordo $acordo, int $id, string $descricao, int $valor, string $vencimento): Obrigacao
    {
        $o = (new Obrigacao())
            ->setTenant($this->tenant)
            ->setCaso($caso)
            ->setDescricao($descricao)
            ->setValorOriginal($valor)
            ->setVencimentoOriginal(new \DateTimeImmutable($vencimento))
            ->setAcordoOrigem($acordo);
        (new \ReflectionProperty(Obrigacao::class, 'id'))->setValue($o, $id);
        $acordo->getParcelas()->add($o);

        return $o;
    }

    /** @param ParcelaEdicaoInput[] $parcelas */
    private function novoInput(int $acordoId, ?int $total, array $parcelas): EditarAcordoInput
    {
        $input = new EditarAcordoInput();
        $input->acordoId = $acordoId;
        $input->valorTotalNegociado = $total;
        $input->parcelas = $parcelas;

        return $input;
    }

    private function linha(?int $obrigacaoId, string $descricao, int $valor, string $vencimento): ParcelaEdicaoInput
    {
        $linha = new ParcelaEdicaoInput();
        $linha->obrigacaoId = $obrigacaoId;
        $linha->descricao = $descricao;
        $linha->valor = $valor;
        $linha->vencimento = new \DateTimeImmutable($vencimento);

        return $linha;
    }
}
