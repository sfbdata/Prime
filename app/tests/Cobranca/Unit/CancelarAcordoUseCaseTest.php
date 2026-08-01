<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\DTO\CancelarAcordoInput;
use App\Cobranca\Entity\Acordo;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\EventoHistorico;
use App\Cobranca\Entity\Obrigacao;
use App\Cobranca\Enum\StatusAcordo;
use App\Cobranca\Exception\AcordoComParcelaPagaException;
use App\Cobranca\Exception\AcordoComParcelasRenegociadasException;
use App\Cobranca\Exception\AcordoNaoAtivoException;
use App\Cobranca\Exception\AcordoNaoEncontradoException;
use App\Cobranca\Repository\AcordoRepository;
use App\Cobranca\Repository\AlocacaoPagamentoRepository;
use App\Cobranca\Repository\EventoHistoricoRepository;
use App\Cobranca\Repository\ObrigacaoRepository;
use App\Cobranca\Service\RegistrarEventoHistorico;
use App\Cobranca\Service\RestauradorObrigacoesOriginais;
use App\Cobranca\UseCase\CancelarAcordoUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(CancelarAcordoUseCase::class)]
#[CoversClass(RestauradorObrigacoesOriginais::class)]
final class CancelarAcordoUseCaseTest extends TestCase
{
    private AcordoRepository&MockObject $acordoRepository;
    private ObrigacaoRepository&MockObject $obrigacaoRepository;
    private AlocacaoPagamentoRepository&MockObject $alocacaoRepository;
    private EventoHistoricoRepository&MockObject $eventoRepository;
    private CancelarAcordoUseCase $sut;
    private Tenant $tenant;
    private User $usuario;

    protected function setUp(): void
    {
        $this->acordoRepository = $this->createMock(AcordoRepository::class);
        $this->obrigacaoRepository = $this->createMock(ObrigacaoRepository::class);
        $this->alocacaoRepository = $this->createMock(AlocacaoPagamentoRepository::class);
        // O serviço é final: usa-se o REAL com o repositório de eventos mockado (flush único).
        $this->eventoRepository = $this->createMock(EventoHistoricoRepository::class);
        $registrarEvento = new RegistrarEventoHistorico($this->eventoRepository);
        // Idem: o restaurador é final e não tem dependência externa além do repositório já mockado.
        $restaurador = new RestauradorObrigacoesOriginais($this->obrigacaoRepository);

        $this->sut = new CancelarAcordoUseCase(
            $this->acordoRepository,
            $this->obrigacaoRepository,
            $this->alocacaoRepository,
            $restaurador,
            $registrarEvento,
        );
        $this->tenant = new Tenant();
        $this->usuario = new User();
    }

    #[Test]
    #[TestDox('Ajuste 9: cancelar acordo cujas parcelas outro acordo vigente renegociou é recusado')]
    public function recusaCancelarAcordoComParcelasRenegociadas(): void
    {
        // Cancelar é o mesmo vetor do romper: deixa o acordo NÃO vigente. Se um acordo B vigente guarda
        // parcelas de A como dívida original, a dívida entraria duas vezes no saldo (§2.1).
        $caso = (new CasoCobranca())->setTenant($this->tenant);
        $acordoA = (new Acordo())->setTenant($this->tenant)->setCaso($caso);
        $acordoB = (new Acordo())->setTenant($this->tenant)->setCaso($caso);
        $parcelaRenegociada = (new Obrigacao())->setTenant($this->tenant)->setCaso($caso);
        $parcelaRenegociada->setAcordoOrigem($acordoA)->setAcordoSubstituto($acordoB);

        $this->acordoRepository->method('findOneByIdDoTenant')->willReturn($acordoA);
        $this->obrigacaoRepository
            ->method('parcelasRenegociadasPorAcordoVigente')
            ->with($acordoA)
            ->willReturn([$parcelaRenegociada]);
        $this->acordoRepository->expects($this->never())->method('salvar');
        $this->obrigacaoRepository->expects($this->never())->method('remover');
        $this->eventoRepository->expects($this->never())->method('salvar');

        $input = new CancelarAcordoInput();
        $input->acordoId = 80;

        try {
            $this->sut->executar($input, $this->tenant, $this->usuario);
            self::fail('Deveria ter recusado o cancelamento.');
        } catch (AcordoComParcelasRenegociadasException) {
            self::assertSame(StatusAcordo::Ativo, $acordoA->getStatus(), 'O acordo não pode mudar de status.');
        }
    }

    #[Test]
    #[TestDox('§D4: acordo com pagamento alocado numa parcela é recusado, e NADA é escrito')]
    public function recusaCancelarQuandoAlgumaParcelaTemPagamento(): void
    {
        // Cancelado, o acordo deixa de ser vigente e suas parcelas saem do exigível — e a
        // `CalculadoraSaldo` só abate alocações de obrigações EXIGÍVEIS. O valor já recebido pararia de
        // descontar e a dívida original voltaria cheia: dinheiro recebido sumindo da conta, em silêncio.
        $caso = (new CasoCobranca())->setTenant($this->tenant);
        $acordo = (new Acordo())->setTenant($this->tenant)->setCaso($caso);
        $parcela = (new Obrigacao())->setTenant($this->tenant)->setCaso($caso);
        $parcela->setAcordoOrigem($acordo);
        $original = $this->obrigacaoCongelada($caso, $acordo);

        $this->acordoRepository->method('findOneByIdDoTenant')->willReturn($acordo);
        $this->obrigacaoRepository->method('parcelasRenegociadasPorAcordoVigente')->willReturn([]);
        $this->obrigacaoRepository->method('parcelasDoAcordo')->willReturn([$parcela]);
        $this->obrigacaoRepository->method('substituidasPorAcordo')->willReturn([$original]);
        $this->alocacaoRepository->method('existeAlocacaoEmObrigacoes')->willReturn(true);

        $this->acordoRepository->expects($this->never())->method('salvar');
        $this->obrigacaoRepository->expects($this->never())->method('remover');
        $this->obrigacaoRepository->expects($this->never())->method('salvar');
        $this->eventoRepository->expects($this->never())->method('salvar');

        $input = new CancelarAcordoInput();
        $input->acordoId = 80;

        try {
            $this->sut->executar($input, $this->tenant, $this->usuario);
            self::fail('Deveria ter recusado o cancelamento.');
        } catch (AcordoComParcelaPagaException) {
            self::assertSame(StatusAcordo::Ativo, $acordo->getStatus(), 'O acordo não pode mudar de status.');
            self::assertSame($acordo, $original->getAcordoSubstituto(), 'A original não pode ser solta.');
            self::assertTrue($original->encargosCongelados(), 'A original não pode ser descongelada.');
        }
    }

    #[Test]
    #[TestDox('Cancelar NÃO apaga nada do banco — o acordo some da tela, não do sistema')]
    public function naoApagaOAcordoNemAsParcelas(): void
    {
        // A 1ª versão desta mudança APAGAVA acordo e parcelas, e isso criava um defeito de dinheiro: o
        // importador de inadimplência procura o acordo pelo número externo e, não achando, cria um novo
        // já ATIVO — ressuscitando as parcelas enquanto as originais seguem exigíveis, ou seja, a mesma
        // dívida contada duas vezes. A linha sobrevivente é a memória de que aquele número foi cancelado.
        $caso = (new CasoCobranca())->setTenant($this->tenant);
        $acordo = (new Acordo())->setTenant($this->tenant)->setCaso($caso);
        $parcelaA = (new Obrigacao())->setTenant($this->tenant)->setCaso($caso)->setValorOriginal(2383);
        $parcelaB = (new Obrigacao())->setTenant($this->tenant)->setCaso($caso)->setValorOriginal(2366);
        $parcelaA->setAcordoOrigem($acordo);
        $parcelaB->setAcordoOrigem($acordo);
        $this->prepararCancelamentoLimpo($acordo, parcelas: [$parcelaA, $parcelaB]);

        $this->acordoRepository->expects($this->never())->method('remover');
        $this->obrigacaoRepository->expects($this->never())->method('remover');
        $this->acordoRepository->expects($this->once())->method('salvar')->with($acordo);

        $input = new CancelarAcordoInput();
        $input->acordoId = 85;

        $resultado = $this->sut->executar($input, $this->tenant, $this->usuario);

        self::assertSame(StatusAcordo::Cancelado, $resultado->getStatus());
        self::assertNotNull($parcelaB->getAcordoOrigem(), 'As parcelas continuam existindo (invariável 14).');
        self::assertSame($acordo, $parcelaA->getAcordoOrigem(), 'O vínculo da parcela não pode ser desfeito.');
    }

    #[Test]
    #[TestDox('§D5: a original é DESCONGELADA — os juros voltam a correr')]
    public function descongelaAsObrigacoesOriginais(): void
    {
        // É o defeito que o dono viu em 01/08: as 4 taxas voltaram ao saldo com os juros parados,
        // porque `EncargosVivos::hidratar` pula obrigação congelada.
        $caso = (new CasoCobranca())->setTenant($this->tenant);
        $acordo = (new Acordo())->setTenant($this->tenant)->setCaso($caso);
        $original = $this->obrigacaoCongelada($caso, $acordo);

        $this->prepararCancelamentoLimpo($acordo, substituidas: [$original]);

        $input = new CancelarAcordoInput();
        $input->acordoId = 85;

        $this->sut->executar($input, $this->tenant, $this->usuario);

        self::assertFalse($original->encargosCongelados(), 'A original tem de voltar a crescer ao vivo.');
    }

    #[Test]
    #[TestDox('O vínculo acordoSubstituto é PRESERVADO — é ele que impede dívida em dobro se o acordo voltar')]
    public function preservaOVinculoComAObrigacaoOriginal(): void
    {
        // Contra-intuitivo de propósito. A original já volta ao saldo sem apagar nada (`doCasoExigiveis`
        // inclui a substituída por acordo NÃO vigente) e a tela já a trata como dívida normal
        // (`substituidaPorAcordo` é vigente-aware). Apagar o vínculo só teria um efeito: se a
        // contabilidade voltar a trazer o acordo — e a decisão do dono é que "o sistema segue a
        // planilha" —, o importador o reativa e o sistema não tem mais como saber quais originais
        // aquele acordo substituía. As duas dívidas passariam a somar juntas.
        $caso = (new CasoCobranca())->setTenant($this->tenant);
        $acordo = (new Acordo())->setTenant($this->tenant)->setCaso($caso);
        $original = $this->obrigacaoCongelada($caso, $acordo);

        $this->prepararCancelamentoLimpo($acordo, substituidas: [$original]);

        $input = new CancelarAcordoInput();
        $input->acordoId = 85;

        $this->sut->executar($input, $this->tenant, $this->usuario);

        self::assertSame(
            $acordo,
            $original->getAcordoSubstituto(),
            'O vínculo tem de sobreviver ao cancelamento, senão o acordo volta e a dívida conta em dobro.',
        );
    }

    #[Test]
    #[TestDox('INV-C2: a original LIQUIDADA continua congelada — não se põe juros sobre dívida paga')]
    public function naoDescongelaOriginalLiquidada(): void
    {
        $caso = (new CasoCobranca())->setTenant($this->tenant);
        $acordo = (new Acordo())->setTenant($this->tenant)->setCaso($caso);
        $liquidada = (new Obrigacao())->setTenant($this->tenant)->setCaso($caso)->setValorOriginal(17000);
        $liquidada->setAcordoSubstituto($acordo);
        // `liquidar()` é o único ponto do código que congela; quem desfaz é `reabrir()`.
        $liquidada->liquidar(799, 340, 0, 2721, new \DateTimeImmutable('2026-07-18'));
        $this->prepararCancelamentoLimpo($acordo, substituidas: [$liquidada]);

        $input = new CancelarAcordoInput();
        $input->acordoId = 85;

        $this->sut->executar($input, $this->tenant, $this->usuario);

        self::assertTrue($liquidada->encargosCongelados(), 'A liquidada NÃO pode ser descongelada.');
        self::assertTrue($liquidada->estaLiquidada());
    }

    #[Test]
    #[TestDox('INV-C4: o evento é AUTOCONTIDO — é a única coisa que sobra do acordo na tela')]
    public function registraEventoAutocontido(): void
    {
        // O acordo cancelado some da tela e a rota dele dá 404. Se a linha do histórico não trouxer
        // número, quantidade de parcelas e valor, não sobra como saber o que foi cancelado.
        $caso = (new CasoCobranca())->setTenant($this->tenant);
        $acordo = (new Acordo())->setTenant($this->tenant)->setCaso($caso);
        $parcelaA = (new Obrigacao())->setTenant($this->tenant)->setCaso($caso)->setValorOriginal(2383);
        $parcelaB = (new Obrigacao())->setTenant($this->tenant)->setCaso($caso)->setValorOriginal(2366);
        $original = $this->obrigacaoCongelada($caso, $acordo);

        $this->prepararCancelamentoLimpo($acordo, parcelas: [$parcelaA, $parcelaB], substituidas: [$original]);

        $evento = null;
        $this->eventoRepository
            ->expects($this->once())
            ->method('salvar')
            ->willReturnCallback(function (EventoHistorico $e, bool $flush) use (&$evento): void {
                self::assertTrue($flush, 'O evento fecha a transação com flush único.');
                $evento = $e;
            });

        $input = new CancelarAcordoInput();
        $input->acordoId = 85;
        $input->motivo = 'Não consta na contabilidade';

        $this->sut->executar($input, $this->tenant, $this->usuario);

        self::assertNotNull($evento);
        self::assertStringContainsString('2 parcela(s)', $evento->getDescricao());
        $dados = $evento->getDados();
        self::assertSame(2, $dados['parcelas']);
        self::assertSame(4749, $dados['valor_parcelas_centavos'], 'Σ das parcelas, para o histórico.');
        self::assertSame(1, $dados['obrigacoes_descongeladas']);
        self::assertSame('Não consta na contabilidade', $dados['motivo']);
    }

    #[Test]
    #[TestDox('Acordo sem parcelas renegociadas cancela normalmente (o caminho de todo dado novo)')]
    public function cancelaNormalmenteQuandoNenhumaParcelaFoiRenegociada(): void
    {
        $caso = (new CasoCobranca())->setTenant($this->tenant);
        $acordo = (new Acordo())->setTenant($this->tenant)->setCaso($caso);

        $this->prepararCancelamentoLimpo($acordo);

        $input = new CancelarAcordoInput();
        $input->acordoId = 80;

        self::assertSame(StatusAcordo::Cancelado, $this->sut->executar($input, $this->tenant, $this->usuario)->getStatus());
    }

    #[Test]
    public function cancelaAcordoAtivoComMotivo(): void
    {
        $caso = (new CasoCobranca())->setTenant($this->tenant);
        $acordo = (new Acordo())->setTenant($this->tenant)->setCaso($caso);

        $this->acordoRepository
            ->expects($this->once())
            ->method('findOneByIdDoTenant')
            ->with(85, $this->tenant)
            ->willReturn($acordo);
        $this->obrigacaoRepository->method('parcelasRenegociadasPorAcordoVigente')->willReturn([]);
        $this->alocacaoRepository->method('existeAlocacaoEmObrigacoes')->willReturn(false);

        // Acordo persistido sem flush; o evento fecha a transação com flush: true.
        $this->acordoRepository->expects($this->once())->method('salvar')->with($acordo);
        $this->eventoRepository
            ->expects($this->once())
            ->method('salvar')
            ->with(self::isInstanceOf(EventoHistorico::class), true);

        $input = new CancelarAcordoInput();
        $input->acordoId = 85;
        $input->motivo = '  Firmado por engano  ';

        $resultado = $this->sut->executar($input, $this->tenant, $this->usuario);

        self::assertSame($acordo, $resultado);
        self::assertSame(StatusAcordo::Cancelado, $resultado->getStatus());
        self::assertFalse($resultado->estaAtivo());
        // Motivo normalizado (trim) e registrado no acordo.
        self::assertSame('Firmado por engano', $resultado->getMotivoCancelamento());
    }

    #[Test]
    public function cancelaAcordoAtivoSemMotivo(): void
    {
        // Motivo é OPCIONAL no cancelamento (diferente do rompimento): ausente vira null.
        $caso = (new CasoCobranca())->setTenant($this->tenant);
        $acordo = (new Acordo())->setTenant($this->tenant)->setCaso($caso);

        $this->prepararCancelamentoLimpo($acordo);
        $this->eventoRepository
            ->expects($this->once())
            ->method('salvar')
            ->with(self::isInstanceOf(EventoHistorico::class), true);

        $input = new CancelarAcordoInput();
        $input->acordoId = 85;
        // Sem motivo informado (default null).

        $resultado = $this->sut->executar($input, $this->tenant, $this->usuario);

        self::assertSame(StatusAcordo::Cancelado, $resultado->getStatus());
        self::assertNull($resultado->getMotivoCancelamento());
    }

    #[Test]
    public function rejeitaAcordoNaoEncontrado(): void
    {
        // Acordo inexistente OU de outro escritório: findOneByIdDoTenant devolve null.
        $this->acordoRepository->method('findOneByIdDoTenant')->willReturn(null);
        $this->acordoRepository->expects($this->never())->method('salvar');
        $this->eventoRepository->expects($this->never())->method('salvar');

        $this->expectException(AcordoNaoEncontradoException::class);

        $input = new CancelarAcordoInput();
        $input->acordoId = 999;

        $this->sut->executar($input, $this->tenant, $this->usuario);
    }

    #[Test]
    public function rejeitaAcordoNaoAtivo(): void
    {
        // Acordo já rompido não transiciona (só um acordo ativo cancela).
        $caso = (new CasoCobranca())->setTenant($this->tenant);
        $acordo = (new Acordo())->setTenant($this->tenant)->setCaso($caso);
        $acordo->romper('Rompido antes');

        $this->acordoRepository->method('findOneByIdDoTenant')->willReturn($acordo);
        $this->acordoRepository->expects($this->never())->method('salvar');
        $this->eventoRepository->expects($this->never())->method('salvar');

        $this->expectException(AcordoNaoAtivoException::class);

        $input = new CancelarAcordoInput();
        $input->acordoId = 85;

        $this->sut->executar($input, $this->tenant, $this->usuario);
    }

    /** Uma original substituída pelo acordo e CONGELADA — o estado que a migration de 21/07 deixou. */
    private function obrigacaoCongelada(CasoCobranca $caso, Acordo $acordo): Obrigacao
    {
        $original = (new Obrigacao())->setTenant($this->tenant)->setCaso($caso)->setValorOriginal(17000);
        $original->setAcordoSubstituto($acordo);
        $original->congelarEncargos(new \DateTimeImmutable('2026-07-21 21:04:26'));

        return $original;
    }

    /**
     * Cenário sem nenhum impedimento: nada renegociado, nenhum pagamento nas parcelas.
     *
     * Parcelas e substituídas são declaradas pelos mocks das QUERIES (`parcelasDoAcordo` /
     * `substituidasPorAcordo`), não pelas coleções inversas do acordo — é assim que o UseCase as lê,
     * justamente porque a coleção inversa nasce vazia quando o acordo é criado na mesma unidade de
     * trabalho.
     *
     * @param Obrigacao[] $parcelas
     * @param Obrigacao[] $substituidas
     */
    private function prepararCancelamentoLimpo(Acordo $acordo, array $parcelas = [], array $substituidas = []): void
    {
        $this->acordoRepository->method('findOneByIdDoTenant')->willReturn($acordo);
        $this->obrigacaoRepository->method('parcelasRenegociadasPorAcordoVigente')->willReturn([]);
        $this->obrigacaoRepository->method('parcelasDoAcordo')->willReturn($parcelas);
        $this->obrigacaoRepository->method('substituidasPorAcordo')->willReturn($substituidas);
        $this->alocacaoRepository->method('existeAlocacaoEmObrigacoes')->willReturn(false);
    }
}
