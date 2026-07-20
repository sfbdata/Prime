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
        // F4: os encargos entram separados (soma 500 — o mesmo agregado do contrato anterior).
        $input->juros = 300;
        $input->multa = 150;
        $input->correcao = 50;
        $input->motivo = '  valor digitado errado  ';

        return $input;
    }

    /**
     * Caso com carteira "TOPLIFE" (juros 1% a.m., multa 2%, carência 30, honorários 20% sobre base
     * composta), para os cenários em que o recálculo precisa produzir encargos > 0 de forma
     * determinística. Grafo em memória — o resolver lê só os getters de config, sem persistência.
     */
    private function casoTopLife(): CasoCobranca
    {
        $carteira = (new Carteira())
            ->setTaxaJurosMensalBp(100)
            ->setTaxaMultaBp(200)
            ->setCarenciaHonorariosDias(30);

        $objeto = (new ObjetoCobranca())->setCarteira($carteira);

        return (new CasoCobranca())
            ->setTenant($this->tenant)
            ->setObjeto($objeto)
            ->setFormaHonorarios(FormaHonorarios::AcrescidoDivida)
            ->setPercentualHonorarios('20.00');
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
        // Cada componente no seu campo; o agregado é o derivado (INV-E1).
        self::assertSame(300, $resultado->getJuros());
        self::assertSame(150, $resultado->getMulta());
        self::assertSame(50, $resultado->getCorrecao());
        self::assertSame(500, $resultado->getEncargosReconhecidos());
        self::assertSame('REF-NEW', $resultado->getReferenciaExterna());
        self::assertSame(25500, $resultado->valorExigivel());

        // Evento com antes/depois e motivo.
        self::assertInstanceOf(EventoHistorico::class, $capturado);
        self::assertSame(TipoEventoHistorico::ObrigacaoEditada, $capturado->getTipo());
        self::assertSame('Obrigação corrigida: valor digitado errado', $capturado->getDescricao());
        $dados = $capturado->getDados();
        self::assertSame(10000, $dados['antes']['valorOriginal']);
        self::assertSame(25000, $dados['depois']['valorOriginal']);
        self::assertSame('REF-OLD', $dados['antes']['referenciaExterna']);
        self::assertSame('valor digitado errado', $dados['motivo']);

        // O snapshot passa a registrar os TRÊS componentes: sem eles o histórico diz que "os encargos
        // mudaram" mas não qual deles, que é exatamente a pergunta que se faz depois.
        self::assertSame(0, $dados['antes']['juros']);
        self::assertSame(300, $dados['depois']['juros']);
        self::assertSame(150, $dados['depois']['multa']);
        self::assertSame(50, $dados['depois']['correcao']);
        // O agregado continua no snapshot para o histórico antigo seguir legível.
        self::assertSame(500, $dados['depois']['encargosReconhecidos']);
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
    public function editarCongelaOsEncargosParaQueOCronNaoDesfacaACorrecao(): void
    {
        // Sem o congelamento (spec §5/§8, INV-E4), o cron `app:cobranca:atualizar-encargos` passaria
        // de madrugada e sobrescreveria o valor que o gestor acabou de corrigir à mão.
        $obrigacao = $this->obrigacaoAtiva();
        self::assertFalse($obrigacao->encargosCongelados(), 'pré-condição: a obrigação nasce recalculável');

        $this->obrigacaoRepository->method('findOneByIdDoTenant')->willReturn($obrigacao);
        $this->alocacaoRepository->method('totalAlocadoEmObrigacoes')->willReturn(0);

        $capturado = null;
        $this->eventoRepository->expects($this->once())->method('salvar')
            ->willReturnCallback(function (EventoHistorico $e, bool $flush) use (&$capturado): void {
                $capturado = $e;
            });

        $resultado = $this->sut->executar($this->inputValido(), $this->tenant, $this->usuario);

        self::assertTrue($resultado->encargosCongelados(), 'editar à mão congela: a obrigação para de crescer');
        $congeladoEm = $resultado->getEncargosCongeladosEm();
        self::assertNotNull($congeladoEm);
        // O congelamento é datado em HOJE (referência do dia, hora zerada — coerente com o cron).
        self::assertSame(
            (new \DateTimeImmutable('today'))->format('Y-m-d'),
            $congeladoEm->format('Y-m-d'),
            'o congelamento é do dia da edição',
        );

        // O histórico tem de registrar a transição: sem isso ninguém explica depois por que aquela
        // obrigação parou de acompanhar os juros.
        self::assertInstanceOf(EventoHistorico::class, $capturado);
        $dados = $capturado->getDados();
        self::assertNull($dados['antes']['encargosCongeladosEm'], 'antes da edição não havia congelamento');
        self::assertSame(
            $congeladoEm->format('Y-m-d H:i:s'),
            $dados['depois']['encargosCongeladosEm'],
            'o snapshot "depois" tem de trazer o congelamento gravado',
        );
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

        // Só a descrição muda; os encargos reenviados são os MESMOS de agora (0/0/0) → não é mexida manual.
        $input = $this->inputValido();
        $input->descricao = 'Descrição corrigida';
        $input->valorOriginal = 100000;
        $input->vencimentoOriginal = new \DateTimeImmutable('2020-01-01');
        $input->juros = 0;
        $input->multa = 0;
        $input->correcao = 0;

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
     * Regressão do achado I5 da revisão da F2: a ponte deprecada `setEncargosReconhecidos()` jogava o
     * agregado inteiro em `juros` e ZERAVA multa/correção. Aqui o agregado é o MESMO antes e depois
     * (350) e só a COMPOSIÇÃO muda — com a ponte no caminho, este teste fica vermelho duas vezes: nem
     * detectaria a mudança (compara pelo agregado) nem gravaria o split.
     */
    #[Test]
    public function recomporOsEncargosMantendoOAgregadoAindaAssimGravaECongela(): void
    {
        $obrigacao = $this->obrigacaoAtiva();
        $obrigacao->definirEncargos(100, 200, 50, 900, new \DateTimeImmutable('2026-02-01'));

        $this->obrigacaoRepository->method('findOneByIdDoTenant')->willReturn($obrigacao);
        $this->alocacaoRepository->method('totalAlocadoEmObrigacoes')->willReturn(0);
        $this->eventoRepository->method('salvar');

        $input = $this->inputValido();
        // Mesma soma (350), outra composição: dinheiro trocou de categoria.
        $input->juros = 200;
        $input->multa = 100;
        $input->correcao = 50;

        $resultado = $this->sut->executar($input, $this->tenant, $this->usuario);

        self::assertSame(200, $resultado->getJuros());
        self::assertSame(100, $resultado->getMulta());
        self::assertSame(50, $resultado->getCorrecao());
        self::assertSame(350, $resultado->getEncargosReconhecidos(), 'o agregado não mudou — a composição sim');
        self::assertTrue($resultado->encargosCongelados(), 'recompor à mão é editar dinheiro: congela');
    }

    /**
     * F6: os honorários não têm campo na UI de edição. Ao digitar juros/multa/correção à mão, o motor
     * RECOMPÕE os honorários sobre a base digitada — não preserva o valor velho (que ficaria defasado da
     * base nova) nem os zera (o que apagaria a dívida). Com config de 20% e atraso longo, o resultado é
     * determinístico sobre a base composta.
     */
    #[Test]
    public function editarEncargosManuaisRecomputaOsHonorariosPeloMotor(): void
    {
        $caso = $this->casoTopLife();
        $obrigacao = (new Obrigacao())
            ->setTenant($this->tenant)
            ->setCaso($caso)
            ->setDescricao('Boleto errado')
            ->setValorOriginal(10000)
            ->setVencimentoOriginal(new \DateTimeImmutable('2020-01-01'));
        // Honorários VELHOS (4321), para provar que são SUBSTITUÍDOS pelo recálculo, não preservados.
        $obrigacao->definirEncargos(100, 200, 50, 4321, new \DateTimeImmutable('2020-06-01'));

        $this->obrigacaoRepository->method('findOneByIdDoTenant')->willReturn($obrigacao);
        $this->alocacaoRepository->method('totalAlocadoEmObrigacoes')->willReturn(0);
        $this->eventoRepository->method('salvar');

        // inputValido: juros 300, multa 150, correção 50, valorOriginal 25000. Vencimento antigo p/ atraso longo.
        $input = $this->inputValido();
        $input->vencimentoOriginal = new \DateTimeImmutable('2020-01-01');

        $resultado = $this->sut->executar($input, $this->tenant, $this->usuario);

        // Base composta digitada = 25000 + 300 + 150 + 50 = 25500 · 20% = 5100 (independe do atraso além da carência).
        self::assertSame(5100, $resultado->getHonorarios(), 'honorários recompostos pelo motor sobre a base digitada');
        self::assertNotSame(4321, $resultado->getHonorarios(), 'não é o valor velho preservado');
        // E os honorários seguem FORA do exigível (INV-E2): 25000 + 300 + 150 + 50.
        self::assertSame(25500, $resultado->valorExigivel());
        self::assertTrue($resultado->encargosCongelados(), 'editar dinheiro à mão congela');
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

        // Edita SÓ o vencimento (para muito no passado); não toca nos encargos (0/0/0 = os atuais).
        $input = $this->inputValido();
        $input->valorOriginal = 100000;
        $input->vencimentoOriginal = new \DateTimeImmutable('2020-01-01');
        $input->juros = 0;
        $input->multa = 0;
        $input->correcao = 0;

        $resultado = $this->sut->executar($input, $this->tenant, $this->usuario);

        self::assertGreaterThan(0, $resultado->getJuros(), 'mudar o vencimento para o passado recalcula os juros na hora');
        self::assertSame(2000, $resultado->getMulta(), 'a multa (2%) entra com o atraso');
        self::assertFalse($resultado->encargosCongelados(), 'automática segue recalculável (o cron a faz crescer)');
    }

    /**
     * F6/INV-E4: uma obrigação TRAVADA (encargos digitados à mão) não recalcula, nem quando o gestor
     * muda o vencimento sem tocar nos valores. Só uma nova digitação manual mudaria os encargos.
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

        // Muda o vencimento para muito no passado, mas REENVIA os encargos iguais aos atuais (não é mexida).
        $input = $this->inputValido();
        $input->valorOriginal = 100000;
        $input->vencimentoOriginal = new \DateTimeImmutable('2020-01-01');
        $input->juros = 100;
        $input->multa = 200;
        $input->correcao = 50;

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
     * O guard do exigível roda ANTES de mutar: se ele falhasse depois, a obrigação ficaria suja em
     * memória à mercê de um flush alheio na mesma request. A soma dos TRÊS é o exigível novo (INV-E1).
     */
    #[Test]
    public function guardDoExigivelSomaOsTresEncargosENaoMutaAoRejeitar(): void
    {
        $obrigacao = $this->obrigacaoAtiva();
        $this->obrigacaoRepository->method('findOneByIdDoTenant')->willReturn($obrigacao);
        // Alocado R$ 260,00; o novo exigível seria 25000 + 300 + 150 + 50 = 25500 (< 26000) → rejeita.
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
        $input->juros = 0;
        $input->multa = 0;
        $input->correcao = 0;

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
        $input->juros = 0;
        $input->multa = 0;
        $input->correcao = 0;

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
        $input->juros = 0;
        $input->multa = 0;
        $input->correcao = 0;

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
}
