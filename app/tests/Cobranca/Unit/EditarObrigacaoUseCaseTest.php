<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\DTO\EditarObrigacaoInput;
use App\Cobranca\Entity\Acordo;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\EventoHistorico;
use App\Cobranca\Entity\Obrigacao;
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
use App\Cobranca\Service\RegistrarEventoHistorico;
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
        // O serviço é final (não mockável): usa-se o REAL com o repositório de eventos mockado.
        $registrarEvento = new RegistrarEventoHistorico($this->eventoRepository);
        $this->sut = new EditarObrigacaoUseCase($this->obrigacaoRepository, $this->alocacaoRepository, $registrarEvento);
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

        $antesDaEdicao = new \DateTimeImmutable();
        $resultado = $this->sut->executar($this->inputValido(), $this->tenant, $this->usuario);

        self::assertTrue($resultado->encargosCongelados(), 'editar à mão congela: a obrigação para de crescer');
        $congeladoEm = $resultado->getEncargosCongeladosEm();
        self::assertNotNull($congeladoEm);
        self::assertGreaterThanOrEqual($antesDaEdicao, $congeladoEm, 'o congelamento é do momento da edição');

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
     * Editar SEM mexer nos encargos não pode congelar nem achatar o split.
     *
     * Este form manda descrição, valor, vencimento, referência e encargos juntos, e reenvia tudo
     * sempre. Se os encargos fossem gravados incondicionalmente, o congelamento tiraria a obrigação
     * do cron PARA SEMPRE por causa de um typo corrigido na descrição, já que não há UI de
     * descongelar. A spec §8 condiciona o congelamento a editar VALORES à mão, não a qualquer edição.
     */
    #[Test]
    public function editarSemMexerNosEncargosNaoCongelaNemAchataOSplit(): void
    {
        $obrigacao = $this->obrigacaoAtiva();
        $obrigacao->definirEncargos(100, 200, 50, 900, new \DateTimeImmutable('2026-02-01'));

        $this->obrigacaoRepository->method('findOneByIdDoTenant')->willReturn($obrigacao);
        $this->alocacaoRepository->method('totalAlocadoEmObrigacoes')->willReturn(0);
        $this->eventoRepository->method('salvar');

        // Só a descrição muda; os encargos reenviados são EXATAMENTE os que já estão lá (é o que o
        // modal faz: pré-preenche os três a partir da linha e o gestor não os toca).
        $input = $this->inputValido();
        $input->descricao = 'Descrição corrigida';
        $input->juros = 100;
        $input->multa = 200;
        $input->correcao = 50;

        $resultado = $this->sut->executar($input, $this->tenant, $this->usuario);

        self::assertSame('Descrição corrigida', $resultado->getDescricao());
        self::assertFalse($resultado->encargosCongelados(), 'edição que não toca dinheiro não congela');
        self::assertNull($resultado->getEncargosCongeladosEm());

        // O split sobrevive intacto — nada foi achatado em `juros`.
        self::assertSame(100, $resultado->getJuros());
        self::assertSame(200, $resultado->getMulta(), 'a multa não pode ser zerada por uma edição de texto');
        self::assertSame(50, $resultado->getCorrecao());
        self::assertSame(900, $resultado->getHonorarios());
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
     * Honorários são materializados pelo motor de cálculo e a UI de edição NÃO os edita. Se o UseCase
     * passasse 0 para `definirEncargos`, cada correção de encargo apagaria a dívida de honorários do
     * escritório — dinheiro sumindo por efeito colateral de outra tela.
     */
    #[Test]
    public function editarEncargosPreservaOsHonorariosMaterializados(): void
    {
        $obrigacao = $this->obrigacaoAtiva();
        $obrigacao->definirEncargos(100, 200, 50, 4321, new \DateTimeImmutable('2026-02-01'));

        $this->obrigacaoRepository->method('findOneByIdDoTenant')->willReturn($obrigacao);
        $this->alocacaoRepository->method('totalAlocadoEmObrigacoes')->willReturn(0);
        $this->eventoRepository->method('salvar');

        $resultado = $this->sut->executar($this->inputValido(), $this->tenant, $this->usuario);

        self::assertSame(4321, $resultado->getHonorarios(), 'a edição de encargos não pode zerar os honorários');
        // E os honorários seguem FORA do exigível (INV-E2): 25000 + 300 + 150 + 50.
        self::assertSame(25500, $resultado->valorExigivel());
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
