<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\DTO\EditarObrigacaoInput;
use App\Cobranca\Entity\Acordo;
use App\Cobranca\Entity\Carteira;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\EventoHistorico;
use App\Cobranca\Entity\ObjetoCobranca;
use App\Cobranca\Entity\Obrigacao;
use App\Cobranca\Enum\FormaHonorarios;
use App\Cobranca\Enum\StatusAcordo;
use App\Cobranca\Enum\StatusCaso;
use App\Cobranca\Enum\TipoEventoHistorico;
use App\Cobranca\Exception\CasoEncerradoException;
use App\Cobranca\Exception\ObrigacaoDeAcordoException;
use App\Cobranca\Exception\ObrigacaoNaoEncontradaException;
use App\Cobranca\Exception\ValorAbaixoDoAlocadoException;
use App\Cobranca\Repository\AlocacaoPagamentoRepository;
use App\Cobranca\Repository\EventoHistoricoRepository;
use App\Cobranca\Repository\ObrigacaoRepository;
use App\Cobranca\Service\CalculadoraEncargos;
use App\Cobranca\Service\ConversorTaxaEncargo;
use App\Cobranca\Service\RegistrarEventoHistorico;
use App\Cobranca\Service\ResolvedorConfigEncargos;
use App\Cobranca\UseCase\EditarObrigacaoUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(EditarObrigacaoUseCase::class)]
final class EditarObrigacaoUseCaseTest extends TestCase
{
    private ObrigacaoRepository&MockObject $obrigacaoRepository;
    private AlocacaoPagamentoRepository&MockObject $alocacaoRepository;
    private EventoHistoricoRepository&MockObject $eventoRepository;
    private EditarObrigacaoUseCase $sut;
    private Tenant $tenant;
    private User $usuario;

    protected function setUp(): void
    {
        $this->obrigacaoRepository = $this->createMock(ObrigacaoRepository::class);
        $this->alocacaoRepository = $this->createMock(AlocacaoPagamentoRepository::class);
        $this->eventoRepository = $this->createMock(EventoHistoricoRepository::class);
        // O serviço é final (não mockável): usa-se o REAL com o repositório de eventos mockado. O motor
        // de encargos e o resolver da cascata também são finais mas PUROS → instâncias reais.
        $registrarEvento = new RegistrarEventoHistorico($this->eventoRepository);
        $this->sut = new EditarObrigacaoUseCase(
            $this->obrigacaoRepository,
            $this->alocacaoRepository,
            $registrarEvento,
            new CalculadoraEncargos(),
            new ResolvedorConfigEncargos(),
            // Task 7 (spec taxa-por-obrigacao): ConversorTaxaEncargo também é PURO (sem I/O) — real.
            new ConversorTaxaEncargo(new CalculadoraEncargos()),
        );
        $this->tenant = new Tenant();
        $this->usuario = new User();
    }

    private function obrigacaoAtiva(): Obrigacao
    {
        $caso = (new CasoCobranca())->setTenant($this->tenant);

        return (new Obrigacao())
            ->setTenant($this->tenant)
            ->setCaso($caso)
            ->setDescricao('Boleto errado')
            ->setValorOriginal(10000)
            ->setEncargosReconhecidos(0)
            ->setVencimentoOriginal(new \DateTimeImmutable('2026-01-10'))
            ->setReferenciaExterna('REF-OLD');
    }

    private function inputValido(): EditarObrigacaoInput
    {
        $input = new EditarObrigacaoInput();
        $input->obrigacaoId = 5;
        $input->descricao = '  Boleto correto  ';
        $input->valorOriginal = 25000;
        $input->vencimentoOriginal = new \DateTimeImmutable('2026-03-15');
        $input->referenciaExterna = '  REF-NEW  ';
        // Task 7 (spec taxa-por-obrigacao): sem taxa informada, os quatro modos ficam no default
        // 'herda' (`EntradaTaxaEncargos`) — a obrigação herda a config do caso (aqui neutra, sem
        // carteira: `obrigacaoAtiva()`/`inputValido()` não usam `casoTopLife()`).
        $input->motivo = '  valor digitado errado  ';

        return $input;
    }

    /**
     * Caso com carteira "TOPLIFE" (juros 1% a.m., multa 2%, carência 30, honorários 20% sobre base
     * composta), para os cenários em que o recálculo precisa produzir encargos > 0 de forma
     * determinística. Grafo em memória — o resolver lê só os getters de config, sem persistência.
     *
     * T1 (cascata ao vivo sem snapshot): a alíquota de honorários mora na CARTEIRA (herdada pelo
     * objeto/caso sem override) — o snapshot do CASO (`formaHonorarios`/`percentualHonorarios`) virou
     * coluna-sombra morta e não é mais lida pelo resolvedor.
     */
    private function casoTopLife(): CasoCobranca
    {
        $carteira = (new Carteira())
            ->setTaxaJurosMensalBp(100)
            ->setTaxaMultaBp(200)
            ->setCarenciaHonorariosDias(30)
            ->setFormaHonorarios(FormaHonorarios::AcrescidoDivida)
            ->setPercentualHonorarios('20.00');

        $objeto = (new ObjetoCobranca())->setCarteira($carteira);

        return (new CasoCobranca())
            ->setTenant($this->tenant)
            ->setObjeto($objeto);
    }

    #[Test]
    public function editaTodosOsCamposERegistraEventoComAntesEDepois(): void
    {
        $obrigacao = $this->obrigacaoAtiva();
        $this->obrigacaoRepository->method('findOneByIdDoTenant')->with(5, $this->tenant)->willReturn($obrigacao);
        $this->alocacaoRepository->method('totalAlocadoEmObrigacoes')->willReturn(0);

        $capturado = null;
        $this->eventoRepository->expects($this->once())->method('salvar')
            ->willReturnCallback(function (EventoHistorico $e, bool $flush) use (&$capturado): void {
                $capturado = $e;
                self::assertTrue($flush, 'o evento fecha a transação com flush');
            });

        $resultado = $this->sut->executar($this->inputValido(), $this->tenant, $this->usuario);

        // Campos corrigidos (com trim de descrição/referência).
        self::assertSame('Boleto correto', $resultado->getDescricao());
        self::assertSame(25000, $resultado->getValorOriginal());
        self::assertSame('2026-03-15', $resultado->getVencimentoOriginal()->format('Y-m-d'));
        // Sem taxa própria (input->modoJuros/... = 'herda' por padrão) e caso sem carteira (neutra): o
        // cache materializado é zero — cada componente no seu campo, o agregado é o derivado (INV-E1).
        self::assertSame(0, $resultado->getJuros());
        self::assertSame(0, $resultado->getMulta());
        self::assertSame(0, $resultado->getCorrecao());
        self::assertSame(0, $resultado->getEncargosReconhecidos());
        self::assertSame('REF-NEW', $resultado->getReferenciaExterna());
        self::assertSame(25000, $resultado->valorExigivel());
        // Task 7: sem taxa informada, as quatro colunas de override continuam null (herda).
        self::assertNull($resultado->getTaxaJurosMensalBp());
        self::assertNull($resultado->getTaxaMultaBp());

        // Evento com antes/depois e motivo.
        self::assertInstanceOf(EventoHistorico::class, $capturado);
        self::assertSame(TipoEventoHistorico::ObrigacaoEditada, $capturado->getTipo());
        self::assertSame('Obrigação corrigida: valor digitado errado', $capturado->getDescricao());
        $dados = $capturado->getDados();
        self::assertSame(10000, $dados['antes']['valorOriginal']);
        self::assertSame(25000, $dados['depois']['valorOriginal']);
        self::assertSame('REF-OLD', $dados['antes']['referenciaExterna']);
        self::assertSame('valor digitado errado', $dados['motivo']);

        // O snapshot registra os TRÊS componentes: sem eles o histórico diz que "os encargos mudaram"
        // mas não qual deles, que é exatamente a pergunta que se faz depois.
        self::assertSame(0, $dados['antes']['juros']);
        self::assertSame(0, $dados['depois']['juros']);
        self::assertSame(0, $dados['depois']['multa']);
        self::assertSame(0, $dados['depois']['correcao']);
        // O agregado continua no snapshot para o histórico antigo seguir legível.
        self::assertSame(0, $dados['depois']['encargosReconhecidos']);
        // Task 7 (spec taxa-por-obrigacao): o snapshot agora também explica a TAXA (antes/depois) — aqui
        // sem override em nenhum dos dois lados (herda o caso nos dois momentos).
        self::assertArrayHasKey('taxaJurosMensalBp', $dados['antes']);
        self::assertNull($dados['antes']['taxaJurosMensalBp']);
        self::assertNull($dados['depois']['taxaJurosMensalBp']);
        self::assertNull($dados['antes']['taxaMultaBp']);
        self::assertNull($dados['depois']['taxaMultaBp']);
    }

    #[Test]
    public function rejeitaObrigacaoDeOutroTenant(): void
    {
        $this->obrigacaoRepository->method('findOneByIdDoTenant')->willReturn(null);
        $this->eventoRepository->expects($this->never())->method('salvar');

        $this->expectException(ObrigacaoNaoEncontradaException::class);

        $this->sut->executar($this->inputValido(), $this->tenant, $this->usuario);
    }

    #[Test]
    public function rejeitaEdicaoEmCasoEncerrado(): void
    {
        $obrigacao = $this->obrigacaoAtiva();
        $obrigacao->getCaso()->setStatus(StatusCaso::Encerrado);
        $this->obrigacaoRepository->method('findOneByIdDoTenant')->willReturn($obrigacao);
        $this->eventoRepository->expects($this->never())->method('salvar');

        $this->expectException(CasoEncerradoException::class);

        $this->sut->executar($this->inputValido(), $this->tenant, $this->usuario);
    }

    #[Test]
    public function rejeitaEdicaoDeParcelaDeAcordoVigente(): void
    {
        $obrigacao = $this->obrigacaoAtiva()->setAcordoOrigem((new Acordo())->setStatus(StatusAcordo::Ativo));
        $this->obrigacaoRepository->method('findOneByIdDoTenant')->willReturn($obrigacao);
        $this->eventoRepository->expects($this->never())->method('salvar');

        $this->expectException(ObrigacaoDeAcordoException::class);

        $this->sut->executar($this->inputValido(), $this->tenant, $this->usuario);
    }

    #[Test]
    public function rejeitaEdicaoDeObrigacaoSubstituidaPorAcordoVigente(): void
    {
        $obrigacao = $this->obrigacaoAtiva()->setAcordoSubstituto((new Acordo())->setStatus(StatusAcordo::Ativo));
        $this->obrigacaoRepository->method('findOneByIdDoTenant')->willReturn($obrigacao);
        $this->eventoRepository->expects($this->never())->method('salvar');

        $this->expectException(ObrigacaoDeAcordoException::class);

        $this->sut->executar($this->inputValido(), $this->tenant, $this->usuario);
    }

    #[Test]
    public function editarObrigacaoLiquidadaQueElevaOExigivelAReabre(): void
    {
        // Reajuste retroativo de dívida PAGA (spec §12/§6.3): a obrigação foi quitada (paga 100,00) e
        // agora é reajustada para valer 250,00. Como o exigível vivo (25000) passa do pago (10000), ela
        // REABRE — volta a Viva, o snapshot da liquidação é descartado e o saldo sobe pela diferença.
        $obrigacao = $this->obrigacaoAtiva()->liquidar(0, 0, 0, 0, new \DateTimeImmutable('2026-07-15'));
        self::assertTrue($obrigacao->estaLiquidada(), 'pré-condição: liquidada');
        $this->obrigacaoRepository->method('findOneByIdDoTenant')->willReturn($obrigacao);
        $this->alocacaoRepository->method('totalAlocadoEmObrigacoes')->willReturn(10000); // pagou o original
        $this->eventoRepository->expects($this->once())->method('salvar');

        $resultado = $this->sut->executar($this->inputValido(), $this->tenant, $this->usuario);

        self::assertFalse($resultado->estaLiquidada(), 'reajuste acima do pago REABRE a obrigação (volta a Viva)');
        self::assertNull($resultado->getLiquidadaEm());
        self::assertFalse($resultado->encargosCongelados(), 'reaberta recalcula ao vivo');
        self::assertSame(25000, $resultado->getValorOriginal());
    }

    #[Test]
    public function editarObrigacaoLiquidadaSemElevarOExigivelRespeitaOSnapshot(): void
    {
        // Editar só a descrição de uma dívida PAGA (sem elevar o exigível acima do pago) NÃO a reabre nem
        // recalcula: o snapshot da liquidação é respeitado (INV-V2), mesmo que o form traga encargos.
        $obrigacao = $this->obrigacaoAtiva()->liquidar(1360, 340, 0, 3740, new \DateTimeImmutable('2026-07-15'));
        $this->obrigacaoRepository->method('findOneByIdDoTenant')->willReturn($obrigacao);
        // Pago o exigível cheio (10000 + 1360 + 340 = 11700); a edição mantém o valor original.
        $this->alocacaoRepository->method('totalAlocadoEmObrigacoes')->willReturn(11700);
        $this->eventoRepository->method('salvar');

        $input = $this->inputValido();
        $input->valorOriginal = 10000; // mesmo valor → exigível vivo não sobe acima do pago
        $resultado = $this->sut->executar($input, $this->tenant, $this->usuario);

        self::assertTrue($resultado->estaLiquidada(), 'segue liquidada (não reabriu)');
        self::assertSame(1360, $resultado->getJuros(), 'snapshot da liquidação preservado (INV-V2)');
        self::assertSame(340, $resultado->getMulta());
    }

    #[Test]
    public function permiteEdicaoQuandoOAcordoFoiCancelado(): void
    {
        // A original voltou ao saldo (acordo substituto CANCELADO) → travava antes do fix, editável agora.
        $obrigacao = $this->obrigacaoAtiva()->setAcordoSubstituto((new Acordo())->setStatus(StatusAcordo::Cancelado));
        $this->obrigacaoRepository->method('findOneByIdDoTenant')->willReturn($obrigacao);
        $this->alocacaoRepository->method('totalAlocadoEmObrigacoes')->willReturn(0);
        $this->eventoRepository->expects($this->once())->method('salvar');

        $resultado = $this->sut->executar($this->inputValido(), $this->tenant, $this->usuario);

        self::assertSame(25000, $resultado->getValorOriginal(), 'obrigação de acordo cancelado volta a ser editável');
    }

    #[Test]
    public function rejeitaValorExigivelAbaixoDoAlocado(): void
    {
        $obrigacao = $this->obrigacaoAtiva();
        $this->obrigacaoRepository->method('findOneByIdDoTenant')->willReturn($obrigacao);
        // Já foi pago/alocado R$ 300,00 nesta obrigação; o novo exigível (R$ 255,00) fica abaixo.
        $this->alocacaoRepository->method('totalAlocadoEmObrigacoes')->willReturn(30000);
        $this->eventoRepository->expects($this->never())->method('salvar');

        $this->expectException(ValorAbaixoDoAlocadoException::class);

        $this->sut->executar($this->inputValido(), $this->tenant, $this->usuario);
    }

    #[Test]
    public function editarAMaoNaoCongelaMaisMantemAObrigacaoViva(): void
    {
        // Encargos AO VIVO (D6): editar à mão NÃO congela mais — a obrigação segue Viva e a leitura
        // recalcula (o valor digitado é só cache). Não há mais cron para "desfazer a correção".
        $obrigacao = $this->obrigacaoAtiva();
        self::assertFalse($obrigacao->encargosCongelados(), 'pré-condição: a obrigação é Viva');

        $this->obrigacaoRepository->method('findOneByIdDoTenant')->willReturn($obrigacao);
        $this->alocacaoRepository->method('totalAlocadoEmObrigacoes')->willReturn(0);

        $capturado = null;
        $this->eventoRepository->expects($this->once())->method('salvar')
            ->willReturnCallback(function (EventoHistorico $e, bool $flush) use (&$capturado): void {
                $capturado = $e;
            });

        $resultado = $this->sut->executar($this->inputValido(), $this->tenant, $this->usuario);

        self::assertFalse($resultado->encargosCongelados(), 'editar à mão NÃO congela: a obrigação segue Viva');
        self::assertNull($resultado->getEncargosCongeladosEm());

        // O histórico ainda registra a edição; o congelamento fica null antes e depois.
        self::assertInstanceOf(EventoHistorico::class, $capturado);
        $dados = $capturado->getDados();
        self::assertNull($dados['antes']['encargosCongeladosEm']);
        self::assertNull($dados['depois']['encargosCongeladosEm'], 'editar não congela no modelo ao vivo');
    }

    /**
     * F6: editar uma obrigação AUTOMÁTICA sem mexer nos encargos RECALCULA o split para hoje (estilo
     * planilha, TODAY()) — sem congelar e sem achatar. O ponto histórico que este teste guarda ("a multa
     * não vira lixo espúrio via ponte deprecada") continua valendo: cada componente vai para o seu campo,
     * e a multa fixa (2% do principal) aparece inteira, nunca somada dentro de `juros`.
     */
    #[Test]
    public function editarObrigacaoAutomaticaRecalculaOSplitSemCongelarNemAchatar(): void
    {
        // Carteira TOPLIFE + vencimento MUITO antigo → recálculo determinístico e positivo. A multa é
        // fixa (independe do atraso), então é o caso ideal para provar que o split não foi achatado.
        $caso = $this->casoTopLife();
        $obrigacao = (new Obrigacao())
            ->setTenant($this->tenant)
            ->setCaso($caso)
            ->setDescricao('Boleto automático')
            ->setValorOriginal(100000)
            ->setVencimentoOriginal(new \DateTimeImmutable('2020-01-01'));
        self::assertFalse($obrigacao->encargosCongelados(), 'pré-condição: automática');
        self::assertSame(0, $obrigacao->getMulta(), 'pré-condição: ainda não materializada');

        $this->obrigacaoRepository->method('findOneByIdDoTenant')->willReturn($obrigacao);
        $this->alocacaoRepository->method('totalAlocadoEmObrigacoes')->willReturn(0);
        $this->eventoRepository->method('salvar');

        // Só a descrição muda; sem taxa própria informada (herda), o motor recalcula do zero.
        $input = $this->inputValido();
        $input->descricao = 'Descrição corrigida';
        $input->valorOriginal = 100000;
        $input->vencimentoOriginal = new \DateTimeImmutable('2020-01-01');

        $resultado = $this->sut->executar($input, $this->tenant, $this->usuario);

        self::assertSame('Descrição corrigida', $resultado->getDescricao());
        // Automática: recalcula na hora, NÃO congela.
        self::assertFalse($resultado->encargosCongelados(), 'editar obrigação automática não congela');
        self::assertNull($resultado->getEncargosCongeladosEm());
        // O split é REAL e não foi achatado: cada componente no seu campo.
        self::assertGreaterThan(0, $resultado->getJuros(), 'os juros de mora crescem com o atraso longo');
        self::assertSame(2000, $resultado->getMulta(), 'a multa fixa (2%) aparece inteira, não achatada em zero nem somada em juros');
        self::assertSame(0, $resultado->getCorrecao());
        self::assertGreaterThan(0, $resultado->getHonorarios());
    }

    /**
     * O coração do pedido do dono (F6): criar com vencimento futuro, editar para o passado → os juros
     * recalculam NA HORA (estilo planilha), sem esperar o cron. Obrigação automática ⇒ não congela.
     */
    #[Test]
    public function editarVencimentoParaOPassadoRecalculaOsJurosNaHora(): void
    {
        $caso = $this->casoTopLife();
        $obrigacao = (new Obrigacao())
            ->setTenant($this->tenant)
            ->setCaso($caso)
            ->setDescricao('Boleto recente')
            ->setValorOriginal(100000)
            ->setVencimentoOriginal(new \DateTimeImmutable('2099-01-01')); // futuro: nasce sem atraso
        self::assertSame(0, $obrigacao->getJuros(), 'pré-condição: sem atraso, sem juros');

        $this->obrigacaoRepository->method('findOneByIdDoTenant')->willReturn($obrigacao);
        $this->alocacaoRepository->method('totalAlocadoEmObrigacoes')->willReturn(0);
        $this->eventoRepository->method('salvar');

        // Edita SÓ o vencimento (para muito no passado); sem taxa própria informada (herda).
        $input = $this->inputValido();
        $input->valorOriginal = 100000;
        $input->vencimentoOriginal = new \DateTimeImmutable('2020-01-01');

        $resultado = $this->sut->executar($input, $this->tenant, $this->usuario);

        self::assertGreaterThan(0, $resultado->getJuros(), 'mudar o vencimento para o passado recalcula os juros na hora');
        self::assertSame(2000, $resultado->getMulta(), 'a multa (2%) entra com o atraso');
        self::assertFalse($resultado->encargosCongelados(), 'automática segue recalculável (o cron a faz crescer)');
    }

    /**
     * INV-V2: uma obrigação CONGELADA (Liquidada-coberta/Substituída) não recalcula o cache ao editar,
     * nem quando o gestor muda o vencimento — mesmo que a taxa própria seja gravada (o override fica
     * registrado, mas o snapshot já materializado é respeitado). Só a reabertura desfaz o congelamento.
     */
    #[Test]
    public function obrigacaoTravadaNaoRecalculaAoEditarOVencimento(): void
    {
        $caso = $this->casoTopLife();
        $obrigacao = (new Obrigacao())
            ->setTenant($this->tenant)
            ->setCaso($caso)
            ->setDescricao('Boleto travado')
            ->setValorOriginal(100000)
            ->setVencimentoOriginal(new \DateTimeImmutable('2026-01-10'));
        $obrigacao->definirEncargos(100, 200, 50, 900, new \DateTimeImmutable('2026-02-01'));
        $obrigacao->congelarEncargos(new \DateTimeImmutable('2026-02-01'));
        self::assertTrue($obrigacao->encargosCongelados(), 'pré-condição: travada');

        $this->obrigacaoRepository->method('findOneByIdDoTenant')->willReturn($obrigacao);
        $this->alocacaoRepository->method('totalAlocadoEmObrigacoes')->willReturn(0);
        $this->eventoRepository->method('salvar');

        // Muda o vencimento para muito no passado; sem taxa própria informada (herda).
        $input = $this->inputValido();
        $input->valorOriginal = 100000;
        $input->vencimentoOriginal = new \DateTimeImmutable('2020-01-01');

        $resultado = $this->sut->executar($input, $this->tenant, $this->usuario);

        // O vencimento mudou, mas os encargos NÃO: a obrigação está travada.
        self::assertSame('2020-01-01', $resultado->getVencimentoOriginal()->format('Y-m-d'));
        self::assertSame(100, $resultado->getJuros(), 'travada: mudar o vencimento não recalcula os juros');
        self::assertSame(200, $resultado->getMulta());
        self::assertSame(50, $resultado->getCorrecao());
        self::assertSame(900, $resultado->getHonorarios(), 'honorários travados intactos');
        self::assertTrue($resultado->encargosCongelados(), 'continua travada');
    }

    /**
     * O guard do exigível roda ANTES de mutar os campos de cadastro: se ele falhasse depois, a obrigação
     * ficaria suja em memória à mercê de um flush alheio na mesma request. A soma dos TRÊS é o exigível
     * novo (INV-E1). Task 7: a TAXA (override) já foi gravada antes do guard — só descrição/valor/
     * vencimento/referência/cache continuam preservados quando o guard rejeita (ver asserts abaixo).
     */
    #[Test]
    public function guardDoExigivelSomaOsTresEncargosENaoMutaAoRejeitar(): void
    {
        $obrigacao = $this->obrigacaoAtiva();
        $this->obrigacaoRepository->method('findOneByIdDoTenant')->willReturn($obrigacao);
        // Alocado R$ 260,00; o novo exigível seria 25000 + 0 + 0 + 0 = 25000 (< 26000) → rejeita.
        $this->alocacaoRepository->method('totalAlocadoEmObrigacoes')->willReturn(26000);
        $this->eventoRepository->expects($this->never())->method('salvar');

        try {
            $this->sut->executar($this->inputValido(), $this->tenant, $this->usuario);
            self::fail('o guard do exigível deveria ter barrado a edição');
        } catch (ValorAbaixoDoAlocadoException) {
            // esperado
        }

        self::assertSame('Boleto errado', $obrigacao->getDescricao(), 'nada pode ter sido mutado antes do guard');
        self::assertSame(10000, $obrigacao->getValorOriginal());
        self::assertSame(0, $obrigacao->getEncargosReconhecidos());
        self::assertFalse($obrigacao->encargosCongelados());
    }

    /**
     * Fix pós-revisão (Task 7): a taxa (override) é gravada na obrigação ANTES do guard do exigível
     * (necessário — o cálculo do exigível novo depende da taxa nova). Isso significa que, quando o guard
     * REJEITA a edição, a obrigação já está mutada EM MEMÓRIA — mas nada disso pode chegar ao banco. Este
     * teste trava o invariante "rejeição não persiste": mesmo com um override de taxa presente (multa 10%
     * elevando o exigível de 25000 para 27500, ainda abaixo dos 50000 já alocados), o `eventoRepository`
     * (único ponto de flush do UseCase — não há `obrigacaoRepository->salvar` em editar) NUNCA é chamado.
     */
    #[Test]
    public function editarComOverrideDeTaxaQueRejeitaNoGuardNaoPersisteATaxaNova(): void
    {
        $obrigacao = $this->obrigacaoAtiva();
        $this->obrigacaoRepository->method('findOneByIdDoTenant')->willReturn($obrigacao);
        // R$ 500,00 já alocados; o novo exigível com o override (25000 + 2500 de multa = 27500) ainda
        // fica abaixo — o guard tem de barrar mesmo com a taxa nova em jogo.
        $this->alocacaoRepository->method('totalAlocadoEmObrigacoes')->willReturn(50000);
        $this->eventoRepository->expects($this->never())->method('salvar');

        $input = $this->inputValido();
        $input->modoMulta = 'percent';
        $input->multaBp = 1000; // 10% de 25000 = 2500 (base Principal, herdada — caso neutro)

        try {
            $this->sut->executar($input, $this->tenant, $this->usuario);
            self::fail('o guard do exigível deveria ter barrado a edição mesmo com override de taxa');
        } catch (ValorAbaixoDoAlocadoException) {
            // esperado
        }

        // A taxa FOI mutada em memória (dependência taxa→exigível, documentada no UseCase) — mas o
        // eventoRepository->salvar (única porta de flush do editar) nunca foi chamado (assert acima),
        // então nada foi persistido: a mutação em memória morre com a exceção, sem alcançar o banco.
        self::assertSame(1000, $obrigacao->getTaxaMultaBp(), 'a taxa é mutada em memória antes do guard, por necessidade de cálculo');
        // O resto do cadastro (fora da taxa) segue intocado até o guard passar.
        self::assertSame('Boleto errado', $obrigacao->getDescricao());
        self::assertSame(10000, $obrigacao->getValorOriginal());
    }

    /**
     * Fix pós-revisão (Task 7, Menor 1): a auditoria da mudança de TAXA ponta-a-ponta. Editar COM um
     * override de multa grava o antes (herdava, null) e o depois (override novo, 200 bp) no snapshot do
     * evento — sem isso o histórico mostra "o exigível mudou" mas não explica que foi uma taxa nova.
     */
    #[Test]
    public function editarComOverrideDeTaxaAuditaODepoisDiferenteDoAntesNasChavesDeTaxa(): void
    {
        $obrigacao = $this->obrigacaoAtiva();
        $this->obrigacaoRepository->method('findOneByIdDoTenant')->willReturn($obrigacao);
        $this->alocacaoRepository->method('totalAlocadoEmObrigacoes')->willReturn(0);

        $capturado = null;
        $this->eventoRepository->expects($this->once())->method('salvar')
            ->willReturnCallback(function (EventoHistorico $e) use (&$capturado): void {
                $capturado = $e;
            });

        $input = $this->inputValido();
        $input->modoMulta = 'percent';
        $input->multaBp = 200; // override explícito (2%): antes null (herdava), depois 200

        $this->sut->executar($input, $this->tenant, $this->usuario);

        self::assertInstanceOf(EventoHistorico::class, $capturado);
        $dados = $capturado->getDados();
        self::assertNull($dados['antes']['taxaMultaBp'], 'antes da edição a obrigação herdava (sem override próprio)');
        self::assertSame(200, $dados['depois']['taxaMultaBp'], 'depois grava o override novo gravado nesta edição');
        self::assertNotSame($dados['antes']['taxaMultaBp'], $dados['depois']['taxaMultaBp'], 'auditoria prova depois != antes na chave de taxa');
    }

    /**
     * F5 (spec §5/§12): editar uma obrigação PAGA é permitido — "quitada" é conceito de LEITURA
     * (`alocado >= exigível`), não estado persistido, e o único limite é o guard `< totalAlocado`.
     * Aqui o valor de uma obrigação com R$100,00 já recebidos SOBE para R$150,00: passa folgado, os
     * campos são gravados e o evento sai. E o UseCase de editar NÃO reescreve as alocações — sua única
     * conversa com o repositório de alocações é a LEITURA do total (a reconciliação do recebido é papel
     * separado do CorrigirPagamentoUseCase).
     */
    #[Test]
    public function editaObrigacaoPagaAumentandoOValorPassaNoGuardENaoRealocaPagamento(): void
    {
        $obrigacao = $this->obrigacaoAtiva(); // valorOriginal 10000, encargos 0 → exigível 10000
        $this->obrigacaoRepository->method('findOneByIdDoTenant')->willReturn($obrigacao);
        // A ÚNICA interação com o repositório de alocações é ler o total (R$100,00 já alocados): o
        // UseCase de editar nunca chama método de escrita de `AlocacaoPagamento`.
        $this->alocacaoRepository->expects($this->once())
            ->method('totalAlocadoEmObrigacoes')->willReturn(10000);
        $this->eventoRepository->expects($this->once())->method('salvar');

        $input = $this->inputValido();
        $input->valorOriginal = 15000;

        $resultado = $this->sut->executar($input, $this->tenant, $this->usuario);

        self::assertSame(15000, $resultado->getValorOriginal(), 'o valor de uma obrigação paga pode ser aumentado');
        // Exigível novo = 15000 (> 10000 alocado): a obrigação deixa de estar quitada e volta a ter saldo.
        self::assertSame(15000, $resultado->valorExigivel());
    }

    /**
     * F5: reduzir o exigível de uma obrigação paga até EXATAMENTE o total alocado é o limite legítimo —
     * fica quitada com saldo zero. O guard é `<`, não `<=`; este cenário prova a fronteira exata.
     */
    #[Test]
    public function editaObrigacaoPagaReduzindoAteExatamenteOAlocadoEhPermitido(): void
    {
        $obrigacao = $this->obrigacaoAtiva();
        $this->obrigacaoRepository->method('findOneByIdDoTenant')->willReturn($obrigacao);
        $this->alocacaoRepository->method('totalAlocadoEmObrigacoes')->willReturn(10000);
        $this->eventoRepository->expects($this->once())->method('salvar');

        $input = $this->inputValido();
        $input->valorOriginal = 10000; // exigível novo == alocado (10000): limite exato

        $resultado = $this->sut->executar($input, $this->tenant, $this->usuario);

        self::assertSame(10000, $resultado->valorExigivel(), 'exigível novo == alocado (limite exato) é permitido');
    }

    /**
     * F5: reduzir o exigível ABAIXO do alocado é bloqueado — apagaria dinheiro já recebido. Não basta
     * o TIPO da exceção: "seguro inalcançável não é seguro" (lição do projeto), então asserimos a
     * MENSAGEM que o gestor lê, e provamos que a obrigação não foi mutada antes do guard.
     */
    #[Test]
    public function editaObrigacaoPagaReduzindoAbaixoDoAlocadoBloqueiaComMensagem(): void
    {
        $obrigacao = $this->obrigacaoAtiva();
        $this->obrigacaoRepository->method('findOneByIdDoTenant')->willReturn($obrigacao);
        $this->alocacaoRepository->method('totalAlocadoEmObrigacoes')->willReturn(10000);
        $this->eventoRepository->expects($this->never())->method('salvar');

        $input = $this->inputValido();
        $input->valorOriginal = 9900; // exigível novo 9900 < 10000 alocado → bloqueia

        $this->expectException(ValorAbaixoDoAlocadoException::class);
        $this->expectExceptionMessage('ficaria abaixo do total já pago/alocado (10000 centavos)');

        try {
            $this->sut->executar($input, $this->tenant, $this->usuario);
        } finally {
            // O guard roda ANTES de mutar: mesmo lançando, a obrigação continua com o valor original.
            self::assertSame(10000, $obrigacao->getValorOriginal(), 'nada pode ter sido mutado antes do guard');
            self::assertFalse($obrigacao->encargosCongelados());
        }
    }

    /**
     * Ajuste 1 INTACTO (prova por mutação): editar SÓ o vencimento de uma automática, sem taxa própria
     * informada (herda), recalcula os 4 na hora e NÃO congela.
     */
    #[Test]
    public function editarSoOVencimentoComHonorarioVazioRecalculaENaoCongela(): void
    {
        $caso = $this->casoTopLife();
        $obrigacao = (new Obrigacao())
            ->setTenant($this->tenant)
            ->setCaso($caso)
            ->setDescricao('Boleto recente')
            ->setValorOriginal(100000)
            ->setVencimentoOriginal(new \DateTimeImmutable('2099-01-01')); // futuro: nasce sem atraso
        self::assertSame(0, $obrigacao->getJuros(), 'pré-condição: sem atraso, sem juros');

        $this->obrigacaoRepository->method('findOneByIdDoTenant')->willReturn($obrigacao);
        $this->alocacaoRepository->method('totalAlocadoEmObrigacoes')->willReturn(0);
        $this->eventoRepository->method('salvar');

        // Edita SÓ o vencimento (para o passado); sem taxa própria informada (herda).
        $input = $this->inputValido();
        $input->valorOriginal = 100000;
        $input->vencimentoOriginal = new \DateTimeImmutable('2020-01-01');

        $resultado = $this->sut->executar($input, $this->tenant, $this->usuario);

        self::assertGreaterThan(0, $resultado->getJuros(), 'mudar o vencimento recalcula os juros na hora (Ajuste 1)');
        self::assertSame(2000, $resultado->getMulta(), 'a multa (2%) entra com o atraso');
        self::assertGreaterThan(0, $resultado->getHonorarios(), 'o motor recalcula o honorário do dia');
        self::assertFalse($resultado->encargosCongelados(), 'sem override: continua Viva (D6)');
    }

    /**
     * INV-E2 (prova por mutação): o guard `< totalAlocado` olha SÓ valorOriginal + juros + multa + correção.
     * Uma taxa de honorários GIGANTE não pode fazer o guard "passar" um exigível que caiu abaixo do já
     * pago — honorário não é dívida do credor. Aqui o exigível real (só j+m+c, herdados = 0) é R$100,00
     * e já há R$200,00 alocados: tem de bloquear, mesmo com um honorário enorme materializado no cache.
     */
    #[Test]
    public function honorarioNaoEntraNoGuardDoExigivel(): void
    {
        $obrigacao = $this->obrigacaoAtiva(); // valorOriginal 10000, encargos 0
        $this->obrigacaoRepository->method('findOneByIdDoTenant')->willReturn($obrigacao);
        $this->alocacaoRepository->method('totalAlocadoEmObrigacoes')->willReturn(20000);
        $this->eventoRepository->expects($this->never())->method('salvar');

        $input = $this->inputValido();
        $input->valorOriginal = 10000;
        // Taxa de honorários gigante (9999,99%): se ENTRASSE no exigível, o guard passaria. Não pode.
        $input->modoHonorarios = 'percent';
        $input->honorariosBp = 500000;

        $this->expectException(ValorAbaixoDoAlocadoException::class);

        $this->sut->executar($input, $this->tenant, $this->usuario);
    }

    /**
     * INV-E2: o honorário fica FORA do `valorExigivel()`. Mesmo com uma taxa de honorários alta, o
     * exigível é só valorOriginal + juros + multa + correção — o honorário não infla o saldo.
     */
    #[Test]
    public function honorarioAltoNaoInflaOExigivel(): void
    {
        $obrigacao = $this->obrigacaoAtiva();
        $this->obrigacaoRepository->method('findOneByIdDoTenant')->willReturn($obrigacao);
        $this->alocacaoRepository->method('totalAlocadoEmObrigacoes')->willReturn(0);
        $this->eventoRepository->method('salvar');

        $input = $this->inputValido();
        $input->valorOriginal = 10000;
        // Taxa de honorários de 9999,99% sobre a base composta (10000, já que j/m/c herdados = 0):
        // valorDeTaxa(10000, 999999) = 999999 exato (10000/10000 = 1) — reproduz o mesmo dinheiro do
        // teste legado, agora expresso como TAXA em vez de valor digitado.
        $input->modoHonorarios = 'percent';
        $input->honorariosBp = 999999;

        $resultado = $this->sut->executar($input, $this->tenant, $this->usuario);

        self::assertSame(999999, $resultado->getHonorarios());
        self::assertSame(10000, $resultado->valorExigivel(), 'exigível = original + j + m + c (herdados = 0); honorário fora (INV-E2)');
    }
}
