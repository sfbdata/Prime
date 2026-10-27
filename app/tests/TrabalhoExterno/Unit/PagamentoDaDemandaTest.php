<?php

declare(strict_types=1);

namespace App\Tests\TrabalhoExterno\Unit;

use App\Entity\Notificacao;
use App\Entity\Tenant\Tenant;
use App\Entity\Auth\User;
use App\Service\NotificacaoService;
use App\Service\PermissionChecker;
use App\Tenant\Service\SituacaoDaConta;
use App\TrabalhoExterno\Entity\ConfiguracaoCentral;
use App\TrabalhoExterno\Entity\DemandaExterna;
use App\TrabalhoExterno\Entity\DocumentoDemanda;
use App\TrabalhoExterno\Entity\PropostaDemanda;
use App\TrabalhoExterno\Enum\AcaoDemanda;
use App\TrabalhoExterno\Enum\Perfil;
use App\TrabalhoExterno\Enum\Situacao;
use App\TrabalhoExterno\Enum\TipoMensagem;
use App\TrabalhoExterno\Exception\AcaoNaoPermitida;
use App\TrabalhoExterno\Exception\DadosInvalidos;
use App\TrabalhoExterno\Exception\DemandaNaoEncontrada;
use App\TrabalhoExterno\Exception\TransicaoInvalida;
use App\TrabalhoExterno\Repository\ConviteDemandaRepository;
use App\TrabalhoExterno\Repository\CurriculoRepository;
use App\TrabalhoExterno\Repository\DemandaExternaRepository;
use App\TrabalhoExterno\Repository\DestinatariosDosAvisosRepository;
use App\TrabalhoExterno\Repository\PreferenciaDoUsuarioRepository;
use App\TrabalhoExterno\Service\ApresentadorDoPagamento;
use App\TrabalhoExterno\Service\ArmazenadorDeDocumentos;
use App\TrabalhoExterno\Service\AvisosDoTrabalhoExterno;
use App\TrabalhoExterno\Service\Contexto;
use App\TrabalhoExterno\Service\LocalizadorDeDemanda;
use App\TrabalhoExterno\Service\PixDaDemanda;
use App\TrabalhoExterno\Service\PoliticaDaDemanda;
use App\TrabalhoExterno\Service\PoliticaDaVaga;
use App\TrabalhoExterno\Service\QrCodeEmSvg;
use App\TrabalhoExterno\Service\ResolvedorDePerfil;
use App\TrabalhoExterno\Service\TransacaoDoComprovante;
use App\TrabalhoExterno\UseCase\AjustarValorDoPagamentoUseCase;
use App\TrabalhoExterno\UseCase\AvisarPagamentoNoChatUseCase;
use App\TrabalhoExterno\Repository\ConfiguracaoCentralRepository;
use App\TrabalhoExterno\Service\RegistrarPagamentoDaDemandaService;
use App\TrabalhoExterno\UseCase\ConfirmarRecebimentoDoPixUseCase;
use App\TrabalhoExterno\UseCase\EnviarComprovanteDePagamentoUseCase;
use App\TrabalhoExterno\UseCase\RecusarComprovanteDePagamentoUseCase;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\RemocaoAposTransacao;
use Psr\Log\NullLogger;
use App\Shared\Armazenamento\ArmazenamentoDeArquivos;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * A aba Pagamento (desenho 25/10, spec `trabalho-externo-25-10-26.md`): a regra da última proposta aceita, a
 * confirmação do recebimento (idempotente), o comprovante, a apresentação por perfil e os casos de uso.
 */
#[CoversClass(DemandaExterna::class)]
#[CoversClass(ApresentadorDoPagamento::class)]
#[CoversClass(ConfirmarRecebimentoDoPixUseCase::class)]
#[CoversClass(EnviarComprovanteDePagamentoUseCase::class)]
#[CoversClass(AvisarPagamentoNoChatUseCase::class)]
#[CoversClass(AjustarValorDoPagamentoUseCase::class)]
#[CoversClass(RecusarComprovanteDePagamentoUseCase::class)]
final class PagamentoDaDemandaTest extends TestCase
{
    private DemandaExterna $demanda;
    private int $salvos = 0;
    /** @var list<array{u: int, tipo: string, mensagem: ?string}> */
    private array $avisados = [];

    protected function setUp(): void
    {
        $this->demanda = Apoio::demanda(Situacao::Execucao);
    }

    #[TestDox('a proposta aceita é a ÚLTIMA aceita; enviada, recusada e substituída não valem; sem aceite, sem valor')]
    public function testUltimaPropostaAceita(): void
    {
        $m = Apoio::autor(Perfil::Master);
        $d = Apoio::demanda(Situacao::Analise);
        self::assertNull($d->propostaAceita());
        self::assertNull(ApresentadorDoPagamento::valorDaDemanda($d));

        $d->enviarProposta(new PropostaDemanda($d, 1, '100.00', null, null, new \DateTimeImmutable(), null, null, null, $m), $m);
        self::assertNull($d->propostaAceita(), 'enviada ainda não é aceita');
        self::assertNull(ApresentadorDoPagamento::valorDaDemanda($d));

        $d->aplicar(AcaoDemanda::AceitarProposta, Apoio::autor(Perfil::Solicitante), null);
        self::assertSame('100.00', $d->propostaAceita()?->getValor());

        // Reaberta e com uma versão nova aceita depois: vale a última aceita.
        $d->aplicar(AcaoDemanda::IniciarExecucao, $m, null);
        $d->aplicar(AcaoDemanda::EnviarParaValidacao, $m, null);
        $d->aplicar(AcaoDemanda::Concluir, $m, null);
        $d->aplicar(AcaoDemanda::Reabrir, $m, 'Mais um serviço');
        $d->enviarProposta(new PropostaDemanda($d, 2, '250.50', null, 'Parcelado', new \DateTimeImmutable(), null, null, null, $m), $m);
        self::assertSame('100.00', $d->propostaAceita()?->getValor(), 'a v1 aceita segue sendo a última ACEITA enquanto a v2 não for aceita');
        $d->aplicar(AcaoDemanda::AceitarProposta, Apoio::autor(Perfil::Solicitante), null);
        self::assertSame('250.50', $d->propostaAceita()?->getValor());
        self::assertSame('R$ 250,50', ApresentadorDoPagamento::valorFormatado((string) ApresentadorDoPagamento::valorDaDemanda($d)));
    }

    #[TestDox('confirmar o recebimento: pagoEm, marco "pago" com "PIX R$ x", registro do desenho e SEM o "Pagamento registrado." genérico')]
    public function testConfirmarNaEntidade(): void
    {
        $d = $this->demanda;
        $d->confirmarRecebimentoDoPix(Apoio::autor(), 'R$ 100,00');

        self::assertNotNull($d->getPagoEm());
        $pago = array_values(array_filter($d->getHistorico(), static fn ($h): bool => $h->getPara() === Situacao::MARCO_PAGO));
        self::assertCount(1, $pago);
        self::assertSame('PIX R$ 100,00', $pago[0]->getMotivo());
        $textos = array_map(static fn ($m): string => $m->getTexto(), $d->getMensagens());
        self::assertContains('✅ Pagamento de R$ 100,00 confirmado via PIX. A execução pode começar.', $textos);
        self::assertNotContains('Pagamento registrado.', $textos);
        self::assertSame(TipoMensagem::Pagamento, $this->ultima($d)->getTipo());
    }

    #[TestDox('a segunda confirmação é recusada (409) e não duplica marco nem mensagem')]
    public function testConfirmarDuasVezes(): void
    {
        $d = $this->demanda;
        $d->confirmarRecebimentoDoPix(Apoio::autor(), 'R$ 100,00');
        $marcos = count($d->getHistorico());
        $mensagens = count($d->getMensagens());

        $this->expectException(TransicaoInvalida::class);
        try {
            $d->confirmarRecebimentoDoPix(Apoio::autor(), 'R$ 100,00');
        } finally {
            self::assertCount($marcos, $d->getHistorico());
            self::assertCount($mensagens, $d->getMensagens());
        }
    }

    #[TestDox('a confirmação respeita as origens da ação RegistrarPagamento: em análise, não')]
    public function testConfirmarForaDaSituacao(): void
    {
        $this->expectException(TransicaoInvalida::class);
        Apoio::demanda(Situacao::Analise)->confirmarRecebimentoDoPix(Apoio::autor(), 'R$ 1,00');
    }

    #[TestDox('o registro "Pagamento registrado." do botão genérico segue igual e ele também marca como pago uma vez só')]
    public function testAcaoGenericaIntacta(): void
    {
        $d = $this->demanda;
        $d->aplicar(AcaoDemanda::RegistrarPagamento, Apoio::autor(), null);

        self::assertNotNull($d->getPagoEm());
        self::assertSame('Pagamento registrado.', $this->ultima($d)->getTexto());
        $this->expectException(TransicaoInvalida::class);
        $d->aplicar(AcaoDemanda::RegistrarPagamento, Apoio::autor(), null);
    }

    #[TestDox('comprovante: um por demanda, nunca depois de pago, e o registro do desenho sai com o nome do arquivo')]
    public function testComprovanteNaEntidade(): void
    {
        $d = $this->demanda;
        self::assertNull($d->comprovante());

        $d->enviarComprovante($this->comprovante($d, 'pix.pdf'), Apoio::autor(Perfil::Solicitante));
        self::assertSame('pix.pdf', $d->comprovante()?->getNomeOriginal());
        self::assertSame('💳 Comprovante de pagamento PIX enviado (pix.pdf). Aguardando confirmação.', $this->ultima($d)->getTexto());
        self::assertSame(TipoMensagem::Pagamento, $this->ultima($d)->getTipo());

        try {
            $d->enviarComprovante($this->comprovante($d, 'outro.pdf'), Apoio::autor(Perfil::Solicitante));
            self::fail('o segundo comprovante é recusado');
        } catch (TransicaoInvalida) {
            self::assertSame('pix.pdf', $d->comprovante()?->getNomeOriginal());
        }

        $pago = Apoio::demanda(Situacao::Execucao);
        $pago->confirmarRecebimentoDoPix(Apoio::autor(), 'R$ 100,00');
        $this->expectException(TransicaoInvalida::class);
        $pago->enviarComprovante($this->comprovante($pago, 'tarde.pdf'), Apoio::autor(Perfil::Solicitante));
    }

    #[TestDox('apresentação: sem proposta aceita, sem valor, QR nem confirmação (texto do desenho para o vazio)')]
    public function testSemPropostaAceita(): void
    {
        $d = Apoio::demanda(Situacao::Analise);
        $bloco = $this->apresentador()->bloco(Apoio::contexto(Perfil::Master), $d);

        self::assertTrue($bloco['semValor']);
        self::assertFalse($bloco['temValor']);
        self::assertFalse($bloco['mostraQr']);
        self::assertSame('', $bloco['qr']);
        self::assertSame('', $bloco['cod']);
        self::assertFalse($bloco['podeConfirmar']);
        self::assertFalse($bloco['podeComprovante']);
        self::assertSame(['Aguardando pagamento', '#0a6fa0', '#e5f1f7', 'bi-qr-code'], [$bloco['stTxt'], $bloco['stCor'], $bloco['stBg'], $bloco['stIc']]);
    }

    #[TestDox('apresentação em 3 estados: aguardando (QR e código), comprovante enviado (âmbar) e pago (verde, sem QR nem código)')]
    public function testTresEstados(): void
    {
        $d = $this->demanda;
        $master = Apoio::contexto(Perfil::Master);
        $aguarda = $this->apresentador()->bloco($master, $d);

        self::assertTrue($aguarda['temValor']);
        self::assertSame('R$ 100,00', $aguarda['valor']);
        self::assertSame('À vista', $aguarda['forma']);
        self::assertSame('TE-0007', $aguarda['txid']);
        self::assertSame('aguarda', $aguarda['estado']);
        self::assertTrue($aguarda['mostraQr']);
        self::assertSame((new PixDaDemanda())->gerar('TE-0007', '100.00'), $aguarda['cod']);
        self::assertStringStartsWith('<svg ', $aguarda['qr']);
        self::assertSame('Confirmar o recebimento de R$ 100,00 via PIX?', $aguarda['confirmacao']);

        $d->enviarComprovante($this->comprovante($d, 'pix.pdf'), Apoio::autor(Perfil::Solicitante));
        $conf = $this->apresentador()->bloco($master, $d);
        self::assertSame('conf', $conf['estado']);
        self::assertMatchesRegularExpression('/^Comprovante enviado em \d{2}\/\d{2}\/\d{4} \d{2}:\d{2} · aguardando confirmação do BlueJus$/u', $conf['stTxt']);
        self::assertSame(['#9a5b00', '#fff8e6', 'bi-hourglass-split'], [$conf['stCor'], $conf['stBg'], $conf['stIc']]);
        self::assertSame('pix.pdf', $conf['comprovante']['nome']);
        self::assertTrue($conf['mostraQr'], 'enquanto não pago, o QR segue');
        self::assertTrue($conf['podeConfirmar']);

        $d->confirmarRecebimentoDoPix(Apoio::autor(), 'R$ 100,00');
        $pago = $this->apresentador()->bloco($master, $d);
        self::assertSame('pago', $pago['estado']);
        self::assertMatchesRegularExpression('/^Pagamento confirmado em \d{2}\/\d{2}\/\d{4} \d{2}:\d{2}$/', $pago['stTxt']);
        self::assertSame(['#1e7b45', '#e9f6ee', 'bi-check-circle-fill'], [$pago['stCor'], $pago['stBg'], $pago['stIc']]);
        self::assertFalse($pago['mostraQr']);
        self::assertSame('', $pago['qr']);
        self::assertSame('', $pago['cod'], 'pago esconde o código copia-e-cola');
        self::assertFalse($pago['podeConfirmar']);
        self::assertSame('R$ 100,00', $pago['valor'], 'o valor segue na tela');
    }

    #[TestDox('apresentação por perfil: o Convidado não recebe o bloco; o Solicitante envia comprovante e não confirma; o interno confirma e não envia')]
    public function testPorPerfil(): void
    {
        $d = $this->demanda;
        $ap = $this->apresentador();

        self::assertNull($ap->bloco(Apoio::contexto(Perfil::Convidado), $d));

        $sol = $ap->bloco(Apoio::contexto(Perfil::Solicitante), $d);
        self::assertTrue($sol['podeComprovante']);
        self::assertFalse($sol['podeConfirmar']);

        $master = $ap->bloco(Apoio::contexto(Perfil::Master), $d);
        self::assertFalse($master['podeComprovante']);
        self::assertTrue($master['podeConfirmar']);

        $equipeSemPoder = $ap->bloco(Apoio::contexto(Perfil::Equipe), $d);
        self::assertFalse($equipeSemPoder['podeConfirmar'], 'a Equipe segue o "Alterar a situação" da configuração (padrão desligado)');
        $cfg = new ConfiguracaoCentral();
        $cfg->definirEquipe('situacao', true);
        self::assertTrue($ap->bloco(Apoio::contexto(Perfil::Equipe, null, $cfg), $d)['podeConfirmar']);
        self::assertFalse($ap->bloco(Apoio::contexto(Perfil::Equipe, null, $cfg), $d)['podeComprovante']);
    }

    #[TestDox('a prévia do Master nunca oferece escrita: nada de comprovante nem confirmação')]
    public function testPreviaNaoEscreve(): void
    {
        $d = $this->demanda;
        $previa = new Contexto(Apoio::usuario(99), Apoio::tenant(1), Perfil::Solicitante, new ConfiguracaoCentral(), null, true);
        $bloco = $this->apresentador()->bloco($previa, $d);

        self::assertTrue($bloco['temValor']);
        self::assertFalse($bloco['podeComprovante']);
        self::assertFalse($bloco['podeConfirmar']);
        $master = new Contexto(Apoio::usuario(99), Apoio::tenant(1), Perfil::Master, new ConfiguracaoCentral(), null, true);
        self::assertFalse($this->apresentador()->bloco($master, $d)['podeConfirmar']);
    }

    #[TestDox('Confirmar: Master confirma, avisa as partes e grava uma vez; o Solicitante leva 403; a segunda chamada, 409 sem duplicar')]
    public function testCasoDeUsoConfirmar(): void
    {
        $confirmar = $this->confirmar();

        try {
            $confirmar->executar(Apoio::contexto(Perfil::Solicitante), 'TE-0007');
            self::fail('o Solicitante não confirma');
        } catch (AcaoNaoPermitida) {
        }
        self::assertSame(0, $this->salvos);
        self::assertNull($this->demanda->getPagoEm());

        $confirmar->executar(Apoio::contexto(Perfil::Master), 'TE-0007');
        self::assertSame(1, $this->salvos);
        self::assertNotNull($this->demanda->getPagoEm());
        $registros = array_values(array_filter($this->demanda->getMensagens(), static fn ($m): bool => str_contains($m->getTexto(), 'confirmado via PIX')));
        self::assertCount(1, $registros);

        try {
            $confirmar->executar(Apoio::contexto(Perfil::Master), 'TE-0007');
            self::fail('a segunda confirmação é recusada');
        } catch (TransicaoInvalida) {
        }
        self::assertSame(1, $this->salvos, 'não gravou de novo');
        self::assertCount(1, array_filter($this->demanda->getMensagens(), static fn ($m): bool => str_contains($m->getTexto(), 'confirmado via PIX')));
        self::assertCount(1, array_filter($this->demanda->getHistorico(), static fn ($h): bool => $h->getPara() === Situacao::MARCO_PAGO));
    }

    #[TestDox('Banco confirma: leva a demanda a Pago com o autor "Sistema (banco)", uma vez só; pago antes pelo botão ou cancelada não muda nada')]
    public function testRegistrarPeloBanco(): void
    {
        $registro = $this->registro();

        self::assertTrue($registro->registrarPeloBanco($this->demanda, 'R$ 1.500,00'));
        self::assertNotNull($this->demanda->getPagoEm());
        $ultima = $this->demanda->getMensagens()[array_key_last($this->demanda->getMensagens())];
        self::assertSame('Sistema (banco)', $ultima->getNomeAutor());
        self::assertNull($ultima->getAutor());
        self::assertStringContainsString('Pagamento de R$ 1.500,00 confirmado via PIX', $ultima->getTexto());

        self::assertFalse($registro->registrarPeloBanco($this->demanda, 'R$ 1.500,00'), 'já pago: no-op');
        self::assertSame(1, $this->salvos);
        self::assertCount(1, array_filter($this->demanda->getHistorico(), static fn ($h): bool => $h->getPara() === Situacao::MARCO_PAGO));

        $cancelada = Apoio::demanda(Situacao::Cancelada);
        self::assertFalse($registro->registrarPeloBanco($cancelada, 'R$ 1,00'), 'cancelada não muda');
        self::assertNull($cancelada->getPagoEm());
    }

    #[TestDox('Confirmar: demanda de outro escritório não existe (o Solicitante de fora leva 404), e sem proposta aceita não há o que confirmar (409)')]
    public function testConfirmarOutroEscritorioESemProposta(): void
    {
        $this->expectException(DemandaNaoEncontrada::class);
        try {
            $this->confirmar()->executar(Apoio::contexto(Perfil::Solicitante, Apoio::tenant(2)), 'TE-0007');
        } finally {
            self::assertNull($this->demanda->getPagoEm());
        }
    }

    #[TestDox('Confirmar sem proposta aceita: 409 e nada grava')]
    public function testConfirmarSemProposta(): void
    {
        // Aceita pelo fluxo, e a proposta volta para "enviada": o estado do pedido vale, o valor não existe mais.
        $ref = new \ReflectionProperty($this->demanda->propostaAceita(), 'situacao');
        $ref->setValue($this->demanda->propostaAceita(), \App\TrabalhoExterno\Enum\SituacaoProposta::Enviada);

        $this->expectException(TransicaoInvalida::class);
        try {
            $this->confirmar()->executar(Apoio::contexto(Perfil::Master), 'TE-0007');
        } finally {
            self::assertNull($this->demanda->getPagoEm());
            self::assertSame(0, $this->salvos);
        }
    }

    #[TestDox('Enviar comprovante: só o Solicitante (o Master, a Equipe e o Convidado levam 403); sem arquivo, 422; já pago ou já enviado, 409')]
    public function testCasoDeUsoComprovante(): void
    {
        $enviar = $this->enviarComprovante();
        $arquivo = new UploadedFile(__FILE__, 'x.pdf', 'application/pdf', null, true);

        foreach ([Perfil::Master, Perfil::Equipe] as $perfil) {
            try {
                $enviar->executar(Apoio::contexto($perfil), 'TE-0007', $arquivo);
                self::fail("$perfil->value não envia comprovante");
            } catch (AcaoNaoPermitida) {
            }
        }
        try {
            $enviar->executar(Apoio::contexto(Perfil::Solicitante), 'TE-0007', null);
            self::fail('sem arquivo');
        } catch (DadosInvalidos $e) {
            self::assertSame('Nenhum arquivo enviado.', $e->getMessage());
        }

        $this->demanda->enviarComprovante($this->comprovante($this->demanda, 'ja.pdf'), Apoio::autor(Perfil::Solicitante));
        try {
            $enviar->executar(Apoio::contexto(Perfil::Solicitante), 'TE-0007', $arquivo);
            self::fail('o segundo comprovante é recusado antes de gravar qualquer arquivo');
        } catch (TransicaoInvalida) {
        }

        $pago = Apoio::demanda(Situacao::Execucao);
        $pago->confirmarRecebimentoDoPix(Apoio::autor(), 'R$ 100,00');
        $this->demanda = $pago;
        try {
            $enviar->executar(Apoio::contexto(Perfil::Solicitante), 'TE-0007', $arquivo);
            self::fail('pago não recebe comprovante');
        } catch (TransicaoInvalida) {
        }
        self::assertSame(0, $this->salvos);
    }

    #[TestDox('Enviar comprovante: o Solicitante de outro escritório não acha a demanda (404)')]
    public function testComprovanteOutroEscritorio(): void
    {
        $this->expectException(DemandaNaoEncontrada::class);
        $this->enviarComprovante()->executar(Apoio::contexto(Perfil::Solicitante, Apoio::tenant(2)), 'TE-0007', new UploadedFile(__FILE__, 'x.pdf', 'application/pdf', null, true));
    }

    #[TestDox('Enviar no chat: só o interno, com o texto do desenho e o valor da proposta aceita, enquanto não pago')]
    public function testEnviarNoChat(): void
    {
        $avisar = new AvisarPagamentoNoChatUseCase($this->localizador(), new PoliticaDaDemanda(), $this->repositorio(), $this->avisos());

        try {
            $avisar->executar(Apoio::contexto(Perfil::Solicitante), 'TE-0007');
            self::fail('o Solicitante não avisa');
        } catch (AcaoNaoPermitida) {
        }
        $avisar->executar(Apoio::contexto(Perfil::Master), 'TE-0007');

        $ultima = $this->ultima($this->demanda);
        self::assertSame('💳 Pagamento disponível: R$ 100,00 via PIX para BlueJus Gestão e Tecnologia LTDA (CNPJ 66.803.163/0001-10), identificação TE-0007. Veja o QR Code na aba Pagamento.', $ultima->getTexto());
        self::assertSame(TipoMensagem::Pagamento, $ultima->getTipo());
        self::assertSame(1, $this->salvos);

        $this->demanda->confirmarRecebimentoDoPix(Apoio::autor(), 'R$ 100,00');
        $this->expectException(AcaoNaoPermitida::class);
        $avisar->executar(Apoio::contexto(Perfil::Master), 'TE-0007');
    }

    #[TestDox('o registro de pagamento não se apaga e, para o Convidado, nem existe no chat')]
    public function testMensagemDePagamentoEOConvidado(): void
    {
        $d = $this->demanda;
        $d->confirmarRecebimentoDoPix(Apoio::autor(), 'R$ 100,00');
        $registro = $this->ultima($d);

        self::assertFalse($registro->podeSerApagada());
        $politica = new PoliticaDaDemanda();
        self::assertTrue($politica->mensagemVisivel(Apoio::contexto(Perfil::Solicitante), $d, $registro));
        self::assertTrue($politica->mensagemVisivel(Apoio::contexto(Perfil::Master), $d, $registro));
        self::assertFalse($politica->mensagemVisivel(Apoio::contexto(Perfil::Convidado), $d, $registro));
    }

    #[TestDox('Ajustar valor: o Master troca o valor; efetivo, QR e chat usam o novo; marco com antigo, novo e motivo; mensagem de sistema sem o motivo')]
    public function testAjustarValorMaster(): void
    {
        $this->ajustar()->executar(Apoio::contexto(Perfil::Master), 'TE-0007', '1.234,56', 'Desconto combinado por telefone');

        $d = $this->demanda;
        self::assertSame('1234.56', $d->valorEfetivoDoPagamento());
        self::assertSame('1234.56', $d->getValorPagamentoAjustado());
        self::assertSame('100.00', $d->propostaAceita()?->getValor(), 'a proposta aceita não muda');
        self::assertSame(1, $this->salvos);
        $marcos = array_values(array_filter($d->getHistorico(), static fn ($h): bool => $h->getPara() === Situacao::MARCO_VALOR));
        self::assertCount(1, $marcos);
        self::assertSame('De R$ 100,00 para R$ 1.234,56. Motivo: Desconto combinado por telefone', $marcos[0]->getMotivo());
        self::assertSame('Usuário 99', $marcos[0]->getNomeAutor());
        $ultima = $this->ultima($d);
        self::assertSame('Valor do pagamento alterado de R$ 100,00 para R$ 1.234,56.', $ultima->getTexto());
        self::assertSame(TipoMensagem::Pagamento, $ultima->getTipo());
        self::assertStringNotContainsString('Desconto', $ultima->getTexto());

        $bloco = $this->apresentador()->bloco(Apoio::contexto(Perfil::Master), $d);
        self::assertSame('R$ 1.234,56', $bloco['valor']);
        self::assertSame((new PixDaDemanda())->gerar('TE-0007', '1234.56'), $bloco['cod']);
        self::assertSame('Confirmar o recebimento de R$ 1.234,56 via PIX?', $bloco['confirmacao']);
        self::assertTrue($bloco['podeEditarValor']);
        self::assertFalse($this->apresentador()->bloco(Apoio::contexto(Perfil::Solicitante), $d)['podeEditarValor']);
        self::assertFalse($this->apresentador()->bloco(Apoio::contexto(Perfil::Equipe), $d)['podeEditarValor']);

        // A confirmação registra o valor efetivo.
        $this->confirmar()->executar(Apoio::contexto(Perfil::Master), 'TE-0007');
        $pago = array_values(array_filter($d->getHistorico(), static fn ($h): bool => $h->getPara() === Situacao::MARCO_PAGO));
        self::assertSame('PIX R$ 1.234,56', $pago[0]->getMotivo());
    }

    #[TestDox('Ajustar valor: Equipe e Solicitante levam 403 (mesmo com "Alterar a situação"); prévia também; nada grava')]
    public function testAjustarValorSoMaster(): void
    {
        $cfg = new ConfiguracaoCentral();
        $cfg->definirEquipe('situacao', true);
        foreach ([Apoio::contexto(Perfil::Equipe, null, $cfg), Apoio::contexto(Perfil::Solicitante), new Contexto(Apoio::usuario(99), Apoio::tenant(1), Perfil::Master, new ConfiguracaoCentral(), null, true)] as $ctx) {
            try {
                $this->ajustar()->executar($ctx, 'TE-0007', '200,00', 'Motivo suficiente');
                self::fail('só o Master ajusta');
            } catch (AcaoNaoPermitida) {
            }
        }
        self::assertSame(0, $this->salvos);
        self::assertNull($this->demanda->getValorPagamentoAjustado());
    }

    #[TestDox('Ajustar valor: outro escritório não acha a demanda (404)')]
    public function testAjustarValorOutroEscritorio(): void
    {
        $this->expectException(DemandaNaoEncontrada::class);
        $this->ajustar()->executar(Apoio::contexto(Perfil::Solicitante, Apoio::tenant(2)), 'TE-0007', '200,00', 'Motivo suficiente');
    }

    #[TestDox('Ajustar valor: pago, com comprovante, valor igual ao efetivo e sem proposta aceita são 409 e não gravam')]
    public function testAjustarValorBloqueios409(): void
    {
        $master = Apoio::contexto(Perfil::Master);
        $d = $this->demanda;

        try {
            $this->ajustar()->executar($master, 'TE-0007', '100,00', 'Motivo suficiente');
            self::fail('igual ao efetivo');
        } catch (TransicaoInvalida) {
        }
        try {
            $this->ajustar()->executar($master, 'TE-0007', '100', 'Motivo suficiente');
            self::fail('igual ao efetivo (sem casas)');
        } catch (TransicaoInvalida) {
        }

        $d->enviarComprovante($this->comprovante($d, 'pix.pdf'), Apoio::autor(Perfil::Solicitante));
        try {
            $this->ajustar()->executar($master, 'TE-0007', '300,00', 'Motivo suficiente');
            self::fail('com comprovante');
        } catch (TransicaoInvalida) {
        }
        self::assertFalse($this->apresentador()->bloco($master, $d)['podeEditarValor']);

        $pago = Apoio::demanda(Situacao::Execucao);
        $pago->confirmarRecebimentoDoPix(Apoio::autor(), 'R$ 100,00');
        $this->demanda = $pago;
        try {
            $this->ajustar()->executar($master, 'TE-0007', '300,00', 'Motivo suficiente');
            self::fail('pago');
        } catch (TransicaoInvalida) {
        }

        $this->demanda = Apoio::demanda(Situacao::Analise);
        try {
            $this->ajustar()->executar($master, 'TE-0007', '300,00', 'Motivo suficiente');
            self::fail('sem proposta aceita');
        } catch (TransicaoInvalida) {
        }
        self::assertSame(0, $this->salvos);
        self::assertNull($pago->getValorPagamentoAjustado());
    }

    #[TestDox('Ajustar valor: valor inválido e motivo fora de 10 a 300 são 422 (DadosInvalidos) e não gravam')]
    public function testAjustarValorEntradaInvalida(): void
    {
        $master = Apoio::contexto(Perfil::Master);
        foreach (['', 'abc', '0', '0,00', '-5,00', '10,999', '10000000,00', '1,2,3', '12,5x', 'R$'] as $valor) {
            try {
                $this->ajustar()->executar($master, 'TE-0007', $valor, 'Motivo suficiente');
                self::fail("valor inválido: $valor");
            } catch (DadosInvalidos) {
            }
        }
        foreach (['', 'curto', str_repeat('x', 301), '         x         '] as $motivo) {
            try {
                $this->ajustar()->executar($master, 'TE-0007', '200,00', $motivo);
                self::fail('motivo fora de 10 a 300');
            } catch (DadosInvalidos) {
            }
        }
        self::assertSame(0, $this->salvos);
        $this->ajustar()->executar($master, 'TE-0007', '200,00', str_repeat('x', 300));
        self::assertSame('200.00', $this->demanda->valorEfetivoDoPagamento());
    }

    #[TestDox('lerValor: aceita "1.234,56", "1234,56", "1234.56", "R$ 1.500" e o teto; recusa o resto')]
    public function testLerValor(): void
    {
        foreach ([['1.234,56', '1234.56'], ['1234,56', '1234.56'], ['1234.56', '1234.56'], ['R$ 1.500', '1500.00'], ['1.500', '1500.00'], ['0,5', '0.50'], ['9.999.999,99', '9999999.99'], ['  250  ', '250.00']] as [$entrada, $saida]) {
            self::assertSame($saida, AjustarValorDoPagamentoUseCase::lerValor($entrada), $entrada);
        }
        $this->expectException(DadosInvalidos::class);
        AjustarValorDoPagamentoUseCase::lerValor('10.000.000,00');
    }

    #[TestDox('trocar a proposta aceita zera o ajuste: o valor volta a ser o da nova proposta')]
    public function testTrocaDePropostaZeraOAjuste(): void
    {
        $m = Apoio::autor(Perfil::Master);
        $d = Apoio::demanda(Situacao::Aceita);
        $d->ajustarValorDoPagamento($m, '80.00', 'Motivo suficiente');
        self::assertSame('80.00', $d->valorEfetivoDoPagamento());

        // Reabre e aceita uma proposta nova: a base mudou, o ajuste sobre a antiga não vale.
        $d->aplicar(AcaoDemanda::IniciarExecucao, $m, null);
        $d->aplicar(AcaoDemanda::EnviarParaValidacao, $m, null);
        $d->aplicar(AcaoDemanda::Concluir, $m, null);
        $d->aplicar(AcaoDemanda::Reabrir, $m, 'Mais um serviço');
        $d->enviarProposta(new PropostaDemanda($d, 2, '250.50', null, null, new \DateTimeImmutable(), null, null, null, $m), $m);
        self::assertSame('80.00', $d->valorEfetivoDoPagamento(), 'enquanto a nova não é aceita, o ajuste vale');
        $d->aplicar(AcaoDemanda::AceitarProposta, Apoio::autor(Perfil::Solicitante), null);
        self::assertNull($d->getValorPagamentoAjustado());
        self::assertSame('250.50', $d->valorEfetivoDoPagamento());
    }

    #[TestDox('Enviar no chat usa o valor ajustado')]
    public function testEnviarNoChatUsaOAjustado(): void
    {
        $this->ajustar()->executar(Apoio::contexto(Perfil::Master), 'TE-0007', '150,00', 'Motivo suficiente');
        $avisar = new AvisarPagamentoNoChatUseCase($this->localizador(), new PoliticaDaDemanda(), $this->repositorio(), $this->avisos());
        $avisar->executar(Apoio::contexto(Perfil::Master), 'TE-0007');

        self::assertStringContainsString('Pagamento disponível: R$ 150,00 via PIX', $this->ultima($this->demanda)->getTexto());
    }

    #[TestDox('Recusar comprovante: o Master tira o comprovante, grava marco com arquivo e motivo, mensagem de sistema sem motivo, aviso com motivo e remove o arquivo')]
    public function testRecusarComprovanteMaster(): void
    {
        $this->demanda->enviarComprovante($this->comprovante($this->demanda, 'pix.pdf'), Apoio::autor(Perfil::Solicitante));
        $armazenamento = $this->createMock(ArmazenamentoDeArquivos::class);
        $armazenamento->method('existe')->willReturn(true);
        $armazenamento->expects(self::once())->method('excluir')->with(self::isInstanceOf(ChaveDeArquivo::class));

        $this->recusar($armazenamento)->executar(Apoio::contexto(Perfil::Master), 'TE-0007', '  Valor diferente do combinado  ');

        $d = $this->demanda;
        self::assertNull($d->comprovante());
        self::assertSame(1, $this->salvos);
        $marcos = array_values(array_filter($d->getHistorico(), static fn ($h): bool => $h->getPara() === Situacao::MARCO_COMPROVANTE_RECUSADO));
        self::assertCount(1, $marcos);
        self::assertSame('Arquivo: pix.pdf. Motivo: Valor diferente do combinado', $marcos[0]->getMotivo());
        $ultima = $this->ultima($d);
        self::assertSame('Comprovante de pagamento recusado. Envie um novo comprovante na aba Pagamento.', $ultima->getTexto());
        self::assertSame(TipoMensagem::Pagamento, $ultima->getTipo());
        self::assertStringNotContainsString('Valor diferente', $ultima->getTexto());

        // Volta a "aguardando", o Solicitante reenvia e o Master edita o valor.
        $bloco = $this->apresentador()->bloco(Apoio::contexto(Perfil::Master), $d);
        self::assertSame(['aguarda', false, true], [$bloco['estado'], $bloco['podeRecusar'], $bloco['podeEditarValor']]);
        self::assertTrue($this->apresentador()->bloco(Apoio::contexto(Perfil::Solicitante), $d)['podeComprovante']);
        $d->enviarComprovante($this->comprovante($d, 'novo.pdf'), Apoio::autor(Perfil::Solicitante));
        self::assertTrue($this->apresentador()->bloco(Apoio::contexto(Perfil::Master), $d)['podeRecusar']);
        self::assertFalse($this->apresentador()->bloco(Apoio::contexto(Perfil::Solicitante), $d)['podeRecusar']);
    }

    #[TestDox('Recusar comprovante: o aviso de andamento leva o motivo e a orientação; o chat não')]
    public function testRecusarComprovanteAvisoComMotivo(): void
    {
        $this->demanda->enviarComprovante($this->comprovante($this->demanda, 'pix.pdf'), Apoio::autor(Perfil::Solicitante));
        $this->demanda->retirarAndamentosParaAvisar();
        $this->demanda->recusarComprovante(Apoio::autor(Perfil::Master), 'Valor diferente do combinado');

        $andamentos = $this->demanda->retirarAndamentosParaAvisar();
        self::assertCount(1, $andamentos);
        self::assertSame('Comprovante de pagamento recusado. Motivo: Valor diferente do combinado. Envie um novo comprovante na aba Pagamento.', $andamentos[0]['texto']);
        self::assertStringNotContainsString('Valor diferente', $andamentos[0]['mensagem']->getTexto());
    }

    #[TestDox('Recusar comprovante: Equipe, Solicitante e prévia levam 403; outro escritório, 404; nada grava nem apaga')]
    public function testRecusarComprovanteSoMaster(): void
    {
        $this->demanda->enviarComprovante($this->comprovante($this->demanda, 'pix.pdf'), Apoio::autor(Perfil::Solicitante));
        $cfg = new ConfiguracaoCentral();
        $cfg->definirEquipe('situacao', true);
        $armazenamento = $this->createMock(ArmazenamentoDeArquivos::class);
        $armazenamento->expects(self::never())->method('excluir');
        foreach ([Apoio::contexto(Perfil::Equipe, null, $cfg), Apoio::contexto(Perfil::Solicitante), new Contexto(Apoio::usuario(99), Apoio::tenant(1), Perfil::Master, new ConfiguracaoCentral(), null, true)] as $ctx) {
            try {
                $this->recusar($armazenamento)->executar($ctx, 'TE-0007', 'Motivo suficiente');
                self::fail('só o Master recusa');
            } catch (AcaoNaoPermitida) {
            }
        }
        self::assertSame(0, $this->salvos);
        self::assertNotNull($this->demanda->comprovante());

        $this->expectException(DemandaNaoEncontrada::class);
        $this->recusar($armazenamento)->executar(Apoio::contexto(Perfil::Solicitante, Apoio::tenant(2)), 'TE-0007', 'Motivo suficiente');
    }

    #[TestDox('Recusar comprovante: sem comprovante e pago são 409; motivo curto ou longo, 422; nada grava nem apaga')]
    public function testRecusarComprovanteBloqueios(): void
    {
        $master = Apoio::contexto(Perfil::Master);
        $armazenamento = $this->createMock(ArmazenamentoDeArquivos::class);
        $armazenamento->expects(self::never())->method('excluir');
        $recusar = $this->recusar($armazenamento);

        try {
            $recusar->executar($master, 'TE-0007', 'Motivo suficiente');
            self::fail('sem comprovante');
        } catch (TransicaoInvalida) {
        }

        $this->demanda->enviarComprovante($this->comprovante($this->demanda, 'pix.pdf'), Apoio::autor(Perfil::Solicitante));
        foreach (['curto', str_repeat('a', 301), '         '] as $motivo) {
            try {
                $recusar->executar($master, 'TE-0007', $motivo);
                self::fail('motivo inválido');
            } catch (DadosInvalidos) {
            }
        }

        $this->confirmar()->executar($master, 'TE-0007');
        try {
            $recusar->executar($master, 'TE-0007', 'Motivo suficiente');
            self::fail('pago');
        } catch (TransicaoInvalida) {
        }
        self::assertNotNull($this->demanda->comprovante());
        self::assertSame(1, $this->salvos, 'só a confirmação gravou');
    }

    private function recusar(ArmazenamentoDeArquivos $armazenamento): RecusarComprovanteDePagamentoUseCase
    {
        return new RecusarComprovanteDePagamentoUseCase($this->localizador(), $this->repositorio(), $this->avisos(), new RemocaoAposTransacao($armazenamento, new NullLogger()));
    }

    private function ajustar(): AjustarValorDoPagamentoUseCase
    {
        return new AjustarValorDoPagamentoUseCase($this->localizador(), $this->repositorio(), $this->avisos());
    }

    private function ultima(DemandaExterna $d): \App\TrabalhoExterno\Entity\MensagemDemanda
    {
        $mensagens = $d->getMensagens();

        return $mensagens[array_key_last($mensagens)];
    }

    private function comprovante(DemandaExterna $d, string $nome): DocumentoDemanda
    {
        return new DocumentoDemanda($d, $nome, 'a1b2c3.pdf', 10, 'application/pdf', Apoio::autor(Perfil::Solicitante), null, true);
    }

    private function apresentador(): ApresentadorDoPagamento
    {
        return new ApresentadorDoPagamento(new PoliticaDaDemanda(), new PixDaDemanda(), new QrCodeEmSvg());
    }

    private function confirmar(): ConfirmarRecebimentoDoPixUseCase
    {
        return new ConfirmarRecebimentoDoPixUseCase($this->localizador(), new PoliticaDaDemanda(), $this->repositorio(), $this->registro());
    }

    private function registro(): RegistrarPagamentoDaDemandaService
    {
        $configuracoes = $this->createStub(ConfiguracaoCentralRepository::class);
        $configuracoes->method('atual')->willReturn(new ConfiguracaoCentral());

        return new RegistrarPagamentoDaDemandaService($this->repositorio(), $this->avisos(), $configuracoes);
    }

    private function enviarComprovante(): EnviarComprovanteDePagamentoUseCase
    {
        $transacao = $this->createMock(TransacaoDoComprovante::class);
        $transacao->method('executar')->willReturnCallback(static fn (\Closure $passos): mixed => $passos());

        return new EnviarComprovanteDePagamentoUseCase(
            $this->localizador(),
            new PoliticaDaDemanda(),
            new ArmazenadorDeDocumentos($this->createStub(ArmazenamentoDeArquivos::class)),
            $this->repositorio(),
            $this->avisos(),
            $transacao,
        );
    }

    private function repositorio(): DemandaExternaRepository
    {
        $r = $this->createMock(DemandaExternaRepository::class);
        $r->method('daCentralPorId')->willReturnCallback(fn (int $id): ?DemandaExterna => $id === 7 ? $this->demanda : null);
        $r->method('doEscritorioPorId')->willReturnCallback(fn (int $id, Tenant $tenant): ?DemandaExterna => $id === 7 && $tenant->getId() === $this->demanda->getTenant()->getId() ? $this->demanda : null);
        $r->method('comADemandaTravada')->willReturnCallback(static fn (DemandaExterna $d, callable $trabalho): mixed => $trabalho());
        $r->method('salvar')->willReturnCallback(function (): void {
            ++$this->salvos;
        });

        return $r;
    }

    private function localizador(): LocalizadorDeDemanda
    {
        return new LocalizadorDeDemanda($this->repositorio(), $this->createStub(ConviteDemandaRepository::class), new PoliticaDaDemanda(), new SituacaoDaConta());
    }

    private function avisos(): AvisosDoTrabalhoExterno
    {
        $notificacoes = $this->createMock(NotificacaoService::class);
        $notificacoes->method('criar')->willReturnCallback(function (User $u, Tenant $t, string $tipo, string $titulo, ?string $mensagem = null): Notificacao {
            $this->avisados[] = ['u' => (int) $u->getId(), 'tipo' => $tipo, 'mensagem' => $mensagem];

            return (new Notificacao())->setUsuario($u)->setTenant($t)->setTipo($tipo)->setTitulo($titulo)->setMensagem($mensagem);
        });

        return new AvisosDoTrabalhoExterno(
            $notificacoes,
            $this->createStub(DestinatariosDosAvisosRepository::class),
            $this->createStub(PreferenciaDoUsuarioRepository::class),
            $this->createStub(CurriculoRepository::class),
            new ResolvedorDePerfil($this->createStub(PermissionChecker::class)),
            new PoliticaDaVaga(new PoliticaDaDemanda()),
            $this->createStub(ConviteDemandaRepository::class),
            new PoliticaDaDemanda(),
        );
    }
}
