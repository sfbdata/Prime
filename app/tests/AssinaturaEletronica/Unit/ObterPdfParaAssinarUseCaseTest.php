<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Unit;

use App\AssinaturaEletronica\Armazenamento\ChavesDeAssinaturaEletronica;
use App\AssinaturaEletronica\DTO\DadosDoRascunho;
use App\AssinaturaEletronica\DTO\PdfParaEntregar;
use App\AssinaturaEletronica\Entity\DocumentoParaAssinar;
use App\AssinaturaEletronica\Entity\Signatario;
use App\AssinaturaEletronica\Entity\VersaoDoPdf;
use App\AssinaturaEletronica\Enum\OrigemDaVersao;
use App\AssinaturaEletronica\Enum\TipoDeEvento;
use App\AssinaturaEletronica\Exception\AcaoRecusada;
use App\AssinaturaEletronica\Exception\LinkInvalido;
use App\AssinaturaEletronica\Exception\MuitosPdfsGerados;
use App\AssinaturaEletronica\Exception\MuitosPdfsSendoGerados;
use App\AssinaturaEletronica\Exception\PdfDoDocumentoSendoGerado;
use App\AssinaturaEletronica\Pdf\ModoDaFolha;
use App\AssinaturaEletronica\Pdf\PerfilDoPdf;
use App\AssinaturaEletronica\Repository\VersaoDoPdfRepository;
use App\AssinaturaEletronica\Service\ChaveDaAssinatura;
use App\AssinaturaEletronica\Service\ConferenciaDePdf\ConferenciaDePdf;
use App\AssinaturaEletronica\Service\FolhaDoDocumento;
use App\AssinaturaEletronica\Service\LogoDaBluejusNoPdf;
use App\AssinaturaEletronica\Service\ManifestoDoDocumento;
use App\AssinaturaEletronica\Service\PdfDaAssinatura;
use App\AssinaturaEletronica\Service\PdfParaAssinarCongelado;
use App\AssinaturaEletronica\Service\SerializacaoDaConferencia;
use App\AssinaturaEletronica\Service\VagasDeGeracaoDoPdf;
use App\AssinaturaEletronica\UseCase\ObterPdfParaAssinarUseCase;
use App\Shared\Armazenamento\DiretorioTemporarioPrivado;
use App\Shared\Armazenamento\RemocaoAposTransacao;
use App\Shared\Doctrine\Transacao\DestinoDaTransacao;
use App\Shared\Doctrine\Transacao\TransacaoComArquivoNovo;
use App\Tests\AssinaturaEletronica\Support\OficinaDosUseCasesDaAssinatura;
use App\Tests\AssinaturaEletronica\Support\TravasContadas;
use App\Tests\AssinaturaEletronica\Unit\Pdf\FolhaDeTeste;
use App\Tests\AssinaturaEletronica\Unit\Pdf\PdfLido;
use App\Tests\Shared\Doubles\ConsultaDeDestinoFixa;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;

/**
 * "Baixar PDF para assinar" da tela pública (etapa 5, bloco 5C; E5-1) com o repositório dos documentos e o das versões
 * dublados (a oficina dos UseCases) e o PDF de verdade (montador, folha e manifesto) sobre o disco em memória.
 *
 * Com versão guardada, entrega essa versão sem gerar; sem versão, gera no modo assinar e congela a versão
 * `para_assinar` uma vez só, com o evento na trilha. Só gov.br e ICP, na vez; sem trava do documento, sem vaga ou sem
 * cota não gera; o desenho acima do teto vira o leve, e os dois acima do teto não guardam nada; na corrida, quem perde
 * entrega a última versão e apaga o próprio arquivo.
 */
#[CoversClass(ObterPdfParaAssinarUseCase::class)]
#[CoversClass(PdfParaAssinarCongelado::class)]
#[CoversClass(PdfDaAssinatura::class)]
#[CoversClass(FolhaDoDocumento::class)]
final class ObterPdfParaAssinarUseCaseTest extends TestCase
{
    use OficinaDosUseCasesDaAssinatura;

    private const SHA = 'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';
    private const NOME_CONGELADO = 'para_assinar_Contrato_de_honorarios.pdf';

    private VersaoDoPdfRepository $versoes;
    /** @var list<VersaoDoPdf> */
    private array $versoesNaBase = [];
    private TravasContadas $lojaDeTravas;
    private int $cotas = 0;
    private bool $cabe = true;
    private int $teto = PdfDaAssinatura::TETO_PARA_O_MOTOR;

    protected function setUp(): void
    {
        FolhaDeTeste::exigirFontes($this);
        $this->montarOficina();
        $this->lojaDeTravas = new TravasContadas();
        $this->versoes = $this->createMock(VersaoDoPdfRepository::class);
        $this->versoes->method('ultima')->willReturnCallback(fn (DocumentoParaAssinar $d): ?VersaoDoPdf => $this->versoesDe($d)[0] ?? null);
        $this->versoes->method('proximaOrdem')->willReturnCallback(fn (DocumentoParaAssinar $d): int => (($this->versoesDe($d)[0] ?? null)?->getOrdem() ?? 0) + 1);
        $this->versoes->method('salvar')->willReturnCallback(function (VersaoDoPdf $versao): void {
            $this->versoesNaBase[] = $versao;
        });
    }

    #[TestDox('com versão guardada (o original já assinado): entrega essa versão como "{nome} - para assinar.pdf", sem gerar, travar nem gastar cota')]
    public function testComVersaoAnteriorEntregaSemGerar(): void
    {
        $bia = $this->comGovbr(comOriginal: true);

        $pdf = $this->useCase()->executar($this->chave->token($bia), $this->cota());

        self::assertEquals(ChavesDeAssinaturaEletronica::versao($this->versoesNaBase[0]), $pdf->chave);
        self::assertSame('Contrato de honorários - para assinar.pdf', $pdf->nome);
        self::assertNull($pdf->pedacos);
        self::assertSame([], $this->disco->gravadas, 'nada gerado');
        self::assertSame(0, $this->cotas, 'a cota não é gasta');
        self::assertSame([], $this->lojaDeTravas->renovacoes, 'nenhuma trava tomada');
        self::assertSame([], $this->gravados);
    }

    #[TestDox('sem versão: gera no modo assinar e congela a versão para_assinar (sha256, tamanho, nome e análise do motor); a segunda chamada entrega a congelada sem gerar')]
    public function testSemVersaoCongelaUmaVez(): void
    {
        $bia = $this->comGovbr();

        $primeiro = $this->useCase()->executar($this->chave->token($bia), $this->cota());

        self::assertCount(1, $this->versoesNaBase);
        $versao = $this->versoesNaBase[0];
        $bytes = $this->disco->ler(ChavesDeAssinaturaEletronica::versao($versao));
        self::assertStringStartsWith('%PDF-', $bytes);
        self::assertSame(OrigemDaVersao::ParaAssinar, $versao->getOrigem());
        self::assertSame(1, $versao->getOrdem());
        self::assertSame(hash('sha256', $bytes), $versao->getSha256());
        self::assertSame(\strlen($bytes), $versao->getTamanho());
        self::assertSame(self::NOME_CONGELADO, $versao->getNomeOriginal());
        self::assertEquals(SerializacaoDaConferencia::paraJson((new ConferenciaDePdf())->conferir($bytes)), $versao->getAnalise());
        self::assertEquals(PdfParaEntregar::guardado(ChavesDeAssinaturaEletronica::versao($versao), self::NOME_CONGELADO), $primeiro);

        $segundo = $this->useCase()->executar($this->chave->token($bia), $this->cota());

        self::assertEquals($primeiro, $segundo);
        self::assertCount(1, $this->disco->gravadas, 'gerado uma vez só');
        self::assertCount(1, $this->versoesNaBase);
        self::assertSame(1, $this->cotas, 'só o pedido que gera gasta a cota');
    }

    #[TestDox('o congelamento grava pdf_para_assinar_gerado na trilha, no nome do signatário e com o sha256 do PDF')]
    public function testEventoNaTrilha(): void
    {
        $bia = $this->comGovbr();

        $this->useCase()->executar($this->chave->token($bia), $this->cota());

        self::assertSame([TipoDeEvento::PdfParaAssinarGerado->value], $this->tiposGravados());
        $evento = $this->ultimoGravado(TipoDeEvento::PdfParaAssinarGerado);
        self::assertSame($bia, $evento->getSignatario());
        self::assertNull($evento->getUsuario());
        self::assertSame($this->versoesNaBase[0]->getSha256(), $evento->getDados()['sha256'] ?? null);
    }

    #[TestDox('as travas da geração (a do documento e a vaga) são renovadas a cada pedaço e liberadas no fim')]
    public function testTravasRenovadasELiberadas(): void
    {
        $bia = $this->comGovbr();

        $this->useCase()->executar($this->chave->token($bia), $this->cota());

        self::assertGreaterThan(1, $this->lojaDeTravas->renovacoesDe('assinatura_pdf_documento_1'));
        self::assertGreaterThan(1, $this->lojaDeTravas->renovacoesDe('assinatura_pdf_vaga_0'));
        self::assertTrue($this->trava('assinatura_pdf_documento_1')->acquire(false), 'a trava do documento foi liberada');
        self::assertTrue($this->trava('assinatura_pdf_vaga_0')->acquire(false), 'a vaga foi liberada');
    }

    #[TestDox('corrida: outro pedido congelou entre a geração e a trava; este entrega a versão dele e apaga o próprio arquivo')]
    public function testCorrida(): void
    {
        $bia = $this->comGovbr();
        $this->aoTravar = function (DocumentoParaAssinar $d): void {
            $this->disco->semear(ChavesDeAssinaturaEletronica::versao($outra = VersaoDoPdf::paraAssinar($d, 1, 'do-outro-pedido.pdf', self::NOME_CONGELADO, self::SHA, 9, ['v' => 1], new \DateTimeImmutable())), '%PDF-');
            $this->versoesNaBase[] = $outra;
        };

        $pdf = $this->useCase()->executar($this->chave->token($bia), $this->cota());

        self::assertSame('do-outro-pedido.pdf', $pdf->chave?->nome);
        self::assertSame(self::NOME_CONGELADO, $pdf->nome);
        self::assertCount(1, $this->versoesNaBase, 'nenhuma versão nova');
        self::assertCount(1, $this->disco->gravadas);
        self::assertFalse($this->disco->existe($this->disco->gravadas[0]), 'o arquivo deste pedido não fica órfão');
        self::assertSame([], $this->gravados, 'quem grava o evento é quem congelou');
        self::assertSame([], $this->logger->doNivel('error'));
    }

    #[TestDox('corrida: entre a geração e a trava, outro pedido congelou a v1 e Ana devolveu a v2; este entrega a v2 (a última) como "{nome} - para assinar.pdf" e apaga o próprio arquivo')]
    public function testCorridaComDevolvida(): void
    {
        $bia = $this->comGovbr(anaComIcp: true);
        $this->aoTravar = function (DocumentoParaAssinar $d): void {
            $agora = new \DateTimeImmutable(self::AGORA);
            $congelada = VersaoDoPdf::paraAssinar($d, 1, 'do-outro-pedido.pdf', self::NOME_CONGELADO, self::SHA, 9, ['v' => 1], $agora);
            $devolvida = VersaoDoPdf::devolvida($d->getSignatarios()[0], 2, 'devolvida-pela-ana.pdf', 'Contrato assinado.pdf', self::SHA, 9, ['v' => 1], $agora);
            foreach ([$congelada, $devolvida] as $versao) {
                $this->disco->semear(ChavesDeAssinaturaEletronica::versao($versao), '%PDF-');
                $this->versoesNaBase[] = $versao;
            }
        };

        $pdf = $this->useCase()->executar($this->chave->token($bia), $this->cota());

        self::assertSame('devolvida-pela-ana.pdf', $pdf->chave?->nome);
        self::assertSame('Contrato de honorários - para assinar.pdf', $pdf->nome);
        self::assertCount(2, $this->versoesNaBase, 'nenhuma versão nova');
        self::assertCount(1, $this->disco->gravadas);
        self::assertFalse($this->disco->existe($this->disco->gravadas[0]), 'o arquivo deste pedido não fica órfão');
        self::assertSame([], $this->gravados, 'nenhum evento deste pedido');
        self::assertSame([], $this->logger->doNivel('error'));
    }

    #[TestDox('a vez muda entre a geração e a trava (o outro signatário recusou): link inválido, como antes de gerar, e a transação apaga o arquivo novo')]
    public function testVezMudaDentroDaTransacao(): void
    {
        $bia = $this->comGovbr();
        $this->transacaoReal = new TransacaoComArquivoNovo($this->emDeTransacao(), new ConsultaDeDestinoFixa(DestinoDaTransacao::NaoConfirmada), new RemocaoAposTransacao($this->disco, $this->logger), $this->logger);
        $this->aoTravar = function (DocumentoParaAssinar $d): void {
            $d->recusar($d->getSignatarios()[0], 'Valor errado', null, null, new \DateTimeImmutable(self::AGORA));
        };

        try {
            $this->useCase()->executar($this->chave->token($bia), $this->cota());
            self::fail('a vez de Bia acabou com a recusa de Ana');
        } catch (LinkInvalido $e) {
            self::assertSame(LinkInvalido::CHAVE, $e->getMessage());
        }

        self::assertSame([], $this->versoesNaBase);
        self::assertCount(1, $this->disco->gravadas);
        self::assertFalse($this->disco->existe($this->disco->gravadas[0]), 'não fica órfão no disco');
    }

    #[TestDox('signatário Simples: link inválido, sem ler versão nem gerar')]
    public function testSimplesLinkInvalido(): void
    {
        $this->comGovbr(comOriginal: true);
        $ana = $this->naBase[0]->getSignatarios()[0];

        $this->assertLinkInvalido($ana);
    }

    #[TestDox('fora da vez (em ordem, aguardando o anterior): link inválido, sem gerar')]
    public function testForaDaVez(): void
    {
        $bia = $this->comGovbr(emOrdem: true);

        $this->assertLinkInvalido($bia);
    }

    #[TestDox('token desconhecido: link inválido')]
    public function testTokenDesconhecido(): void
    {
        $this->comGovbr();

        $this->expectException(LinkInvalido::class);
        $this->useCase()->executar(str_repeat('b', 64), $this->cota());
    }

    #[TestDox('outro pedido já gera o PDF deste documento: PdfDoDocumentoSendoGerado, sem vaga nem cota')]
    public function testDocumentoSendoGerado(): void
    {
        $bia = $this->comGovbr();
        $outroPedido = $this->trava('assinatura_pdf_documento_1');
        self::assertTrue($outroPedido->acquire(false));

        $this->assertNaoGerou(PdfDoDocumentoSendoGerado::class, $bia);
        self::assertTrue($this->trava('assinatura_pdf_vaga_0')->acquire(false), 'nenhuma vaga ocupada');
    }

    #[TestDox('as duas vagas ocupadas: MuitosPdfsSendoGerados, sem gastar a cota; a trava do documento é liberada')]
    public function testSemVaga(): void
    {
        $bia = $this->comGovbr();
        $ocupadas = [$this->trava('assinatura_pdf_vaga_0'), $this->trava('assinatura_pdf_vaga_1')];
        self::assertTrue($ocupadas[0]->acquire(false) && $ocupadas[1]->acquire(false));

        $this->assertNaoGerou(MuitosPdfsSendoGerados::class, $bia);
        self::assertTrue($this->trava('assinatura_pdf_documento_1')->acquire(false));
    }

    #[TestDox('cota estourada: MuitosPdfsGerados; a vaga e a trava do documento são liberadas')]
    public function testSemCota(): void
    {
        $bia = $this->comGovbr();
        $this->cabe = false;

        $this->assertNaoGerou(MuitosPdfsGerados::class, $bia);
        self::assertSame(1, $this->cotas);
        self::assertTrue($this->trava('assinatura_pdf_vaga_0')->acquire(false));
        self::assertTrue($this->trava('assinatura_pdf_documento_1')->acquire(false));
    }

    #[TestDox('o desenho passa do teto e o leve cabe: congela o leve (a imagem de 1240 x 1754 px) com o evento, chama a cada pedaço nas duas tentativas e não deixa arquivo')]
    public function testLeveQuandoODesenhoPassaDoTeto(): void
    {
        $bia = $this->comGovbr();
        $pdf = $this->pdfDaAssinatura();
        $desenho = iterator_to_array($pdf->pedacos($bia->getDocumento(), ModoDaFolha::Assinar), false);
        $leve = iterator_to_array($pdf->pedacos($bia->getDocumento(), ModoDaFolha::Assinar, PerfilDoPdf::Leve), false);
        $this->teto = intdiv(\strlen(implode('', $desenho)) + \strlen(implode('', $leve)), 2);
        self::assertGreaterThan($this->teto, \strlen(implode('', $desenho)), 'o desenho passa do teto');
        self::assertLessThan($this->teto, \strlen(implode('', $leve)), 'o leve cabe');
        self::assertGreaterThan(0, $antesDoTeto = self::quantosCabem($desenho, $this->teto), 'a primeira tentativa gera algum pedaço');
        $area = DiretorioTemporarioPrivado::doProcesso(PdfParaAssinarCongelado::AREA)->caminho();
        $antes = glob($area . '/*') ?: [];
        $pedacos = 0;

        $versao = $this->congelado($pdf)->congelar($bia, static function () use (&$pedacos): void {
            ++$pedacos;
        });

        $bytes = $this->disco->ler(ChavesDeAssinaturaEletronica::versao($versao));
        self::assertSame([[1240, 1754]], (new PdfLido($bytes))->medidasDasImagens(), 'o leve');
        self::assertLessThanOrEqual($this->teto, \strlen($bytes));
        self::assertSame([$versao], $this->versoesNaBase);
        self::assertSame(OrigemDaVersao::ParaAssinar, $versao->getOrigem());
        self::assertSame(hash('sha256', $bytes), $versao->getSha256());
        self::assertSame(\strlen($bytes), $versao->getTamanho());
        self::assertSame([TipoDeEvento::PdfParaAssinarGerado->value], $this->tiposGravados());
        self::assertSame($versao->getSha256(), $this->ultimoGravado(TipoDeEvento::PdfParaAssinarGerado)->getDados()['sha256'] ?? null);
        self::assertSame($antesDoTeto + \count($leve), $pedacos, 'a cada pedaço, nas duas tentativas');
        self::assertCount(1, $this->disco->gravadas, 'só o leve vai para o armazenamento');
        self::assertSame($antes, glob($area . '/*') ?: [], 'as duas áreas temporárias saíram com os arquivos');
    }

    #[TestDox('PDF acima do teto no desenho e no leve: recusa com o texto do catálogo público, sem congelar, sem arquivo no disco nem na área temporária')]
    public function testAcimaDoTeto(): void
    {
        $bia = $this->comGovbr();
        $this->teto = 100;
        $area = DiretorioTemporarioPrivado::doProcesso(PdfParaAssinarCongelado::AREA)->caminho();
        $antes = glob($area . '/*') ?: [];

        try {
            $this->useCase()->executar($this->chave->token($bia), $this->cota());
            self::fail('acima do teto não congela');
        } catch (AcaoRecusada $e) {
            self::assertSame(PdfParaAssinarCongelado::GRANDE_DEMAIS, $e->getMessage());
        }

        self::assertSame([], $this->versoesNaBase);
        self::assertSame([], $this->disco->gravadas);
        self::assertSame([], $this->gravados);
        self::assertSame($antes, glob($area . '/*') ?: [], 'a área temporária saiu com o arquivo');
        self::assertTrue($this->trava('assinatura_pdf_documento_1')->acquire(false));
        self::assertTrue($this->trava('assinatura_pdf_vaga_0')->acquire(false));
    }

    #[TestDox('o teto padrão é o único do PDF para o motor (19 MiB) e o texto da recusa está no catálogo público')]
    public function testTetoEOTexto(): void
    {
        self::assertSame(PdfDaAssinatura::TETO_PARA_O_MOTOR, (new \ReflectionParameter([PdfParaAssinarCongelado::class, '__construct'], 'teto'))->getDefaultValue());
        self::assertSame(19 * 1024 * 1024, PdfDaAssinatura::TETO_PARA_O_MOTOR);
        self::assertSame(
            'O PDF deste documento passa de 19 MB, o limite para assinar com certificado. Avise quem enviou o documento para assinar.',
            self::tradutorDaAssinatura()->trans(PdfParaAssinarCongelado::GRANDE_DEMAIS),
        );
    }

    #[TestDox('FolhaDoDocumento::de: sem modo, rascunho no rascunho e final nos demais; com modo, o pedido')]
    public function testModoDaFolha(): void
    {
        $rascunho = $this->rascunho();
        $enviado = $this->documentoEnviado();
        $folha = $this->folha();

        self::assertSame(ModoDaFolha::Rascunho, $folha->de($rascunho)->modo);
        self::assertSame(ModoDaFolha::Final, $folha->de($enviado)->modo);
        self::assertSame(ModoDaFolha::Assinar, $folha->de($enviado, ModoDaFolha::Assinar)->modo);
        self::assertSame(ModoDaFolha::Final, $folha->de($rascunho, ModoDaFolha::Final)->modo);
    }

    #[TestDox('o nome do arquivo: assinado_ no final e no rascunho, para_assinar_ no modo assinar (dc:425)')]
    public function testNomeDoArquivoPeloModo(): void
    {
        $documento = $this->rascunho();
        $pdf = $this->pdfDaAssinatura();

        self::assertSame('assinado_Contrato_de_honorarios.pdf', $pdf->nomeDoArquivo($documento));
        self::assertSame('assinado_Contrato_de_honorarios.pdf', $pdf->nomeDoArquivo($documento, ModoDaFolha::Rascunho));
        self::assertSame(self::NOME_CONGELADO, $pdf->nomeDoArquivo($documento, ModoDaFolha::Assinar));
    }

    private function useCase(): ObterPdfParaAssinarUseCase
    {
        $pdf = $this->pdfDaAssinatura();

        return new ObterPdfParaAssinarUseCase($this->link(), $this->versoes, $this->congelado($pdf), $pdf, new VagasDeGeracaoDoPdf(new LockFactory($this->lojaDeTravas)), self::tradutorDaAssinatura(), $this->relogio);
    }

    private function congelado(PdfDaAssinatura $pdf): PdfParaAssinarCongelado
    {
        return new PdfParaAssinarCongelado(
            $pdf,
            $this->disco,
            $this->documentos,
            $this->versoes,
            $this->link(),
            $this->trilha(),
            new ConferenciaDePdf(),
            new RemocaoAposTransacao($this->disco, $this->logger),
            $this->relogio,
            $this->teto,
        );
    }

    /**
     * Quantos pedaços, do começo, cabem juntos no teto: os que a tentativa gera antes de parar.
     *
     * @param list<string> $pedacos
     */
    private static function quantosCabem(array $pedacos, int $teto): int
    {
        $soma = 0;
        foreach ($pedacos as $i => $pedaco) {
            $soma += \strlen($pedaco);
            if ($soma > $teto) {
                return $i;
            }
        }

        return \count($pedacos);
    }

    /** @return \Closure(): bool a cota de quem pede, contada */
    private function cota(): \Closure
    {
        return function (): bool {
            ++$this->cotas;

            return $this->cabe;
        };
    }

    /** Uma trava da loja da geração, como outro pedido a tomaria. */
    private function trava(string $nome): LockInterface
    {
        return (new LockFactory($this->lojaDeTravas))->createLock($nome);
    }

    /**
     * Documento enviado às 9h com código, Ana (Simples, ou ICP com `$anaComIcp`) e Bia (gov.br com CPF), sem conteúdo
     * (a folha é só o manifesto); com `$comOriginal`, o original já assinado guardado no rascunho. Devolve Bia.
     */
    private function comGovbr(bool $emOrdem = false, bool $comOriginal = false, bool $anaComIcp = false): Signatario
    {
        $momento = new \DateTimeImmutable('2026-11-09 08:00:00');
        $documento = new DocumentoParaAssinar($this->tenant, null, $momento);
        $documento->aplicarRascunho(DadosDoRascunho::deJson([
            'nome' => 'Contrato de honorários',
            'emOrdem' => $emOrdem,
            'signatarios' => [
                ['nome' => 'Ana Lima', 'email' => 'ana@exemplo.com'] + ($anaComIcp ? ['nivel' => 'icp'] : []),
                ['nome' => 'Bia Souza', 'email' => 'bia@exemplo.com', 'nivel' => 'govbr', 'cpf' => self::CPF_ANA],
            ],
        ]), [], $momento);
        (new \ReflectionProperty(DocumentoParaAssinar::class, 'id'))->setValue($documento, 1);
        foreach ($documento->getSignatarios() as $i => $signatario) {
            (new \ReflectionProperty(Signatario::class, 'id'))->setValue($signatario, 11 + $i);
        }
        if ($comOriginal) {
            $original = VersaoDoPdf::original($documento, 1, 'original.pdf', 'Contrato assinado.pdf', self::SHA, 5, ['v' => 1], $momento);
            $this->versoesNaBase[] = $original;
            $this->disco->semear(ChavesDeAssinaturaEletronica::versao($original), '%PDF-');
        }
        $documento->enviar(str_repeat('f', 64), new \DateTimeImmutable('2026-11-09 09:00:00'));
        $documento->definirCodigo('BJA-TEST-0000-0001');
        foreach ($documento->getSignatarios() as $signatario) {
            $signatario->definirTokenHash(ChaveDaAssinatura::hashDoToken($this->chave->token($signatario)));
        }
        $this->naBase[] = $documento;

        return $documento->getSignatarios()[1];
    }

    private function folha(): FolhaDoDocumento
    {
        $tradutor = self::tradutorDaAssinatura();

        return new FolhaDoDocumento($this->disco, new LogoDaBluejusNoPdf(\dirname(__DIR__, 3)), new ManifestoDoDocumento($tradutor, $this->eventosDaTrilha), self::urls(), $this->relogio, $tradutor);
    }

    /**
     * As versões do documento, da última para a primeira (a de maior ordem primeiro).
     *
     * @return list<VersaoDoPdf>
     */
    private function versoesDe(DocumentoParaAssinar $documento): array
    {
        $doDocumento = array_values(array_filter($this->versoesNaBase, static fn (VersaoDoPdf $v): bool => $v->getDocumento() === $documento));
        usort($doDocumento, static fn (VersaoDoPdf $a, VersaoDoPdf $b): int => $b->getOrdem() <=> $a->getOrdem());

        return $doDocumento;
    }

    private function assertLinkInvalido(Signatario $signatario): void
    {
        try {
            $this->useCase()->executar($this->chave->token($signatario), $this->cota());
            self::fail('esperava o link inválido');
        } catch (LinkInvalido) {
        }

        self::assertSame([], $this->disco->gravadas);
        self::assertSame(0, $this->cotas);
        self::assertSame([], $this->lojaDeTravas->renovacoes, 'nenhuma trava tomada');
    }

    /** @param class-string<\Throwable> $recusa */
    private function assertNaoGerou(string $recusa, Signatario $signatario): void
    {
        try {
            $this->useCase()->executar($this->chave->token($signatario), $this->cota());
            self::fail('esperava ' . $recusa);
        } catch (\Throwable $e) {
            self::assertInstanceOf($recusa, $e);
        }

        self::assertSame([], $this->disco->gravadas);
        self::assertSame([], $this->versoesNaBase);
        self::assertSame([], $this->gravados);
    }

    /** EntityManager e conexão como a transação real os usa (nível, BEGIN, ROLLBACK). */
    private function emDeTransacao(): EntityManagerInterface
    {
        $nivel = 0;
        $conexao = $this->createStub(Connection::class);
        $conexao->method('getTransactionNestingLevel')->willReturnCallback(static function () use (&$nivel): int {
            return $nivel;
        });
        $conexao->method('beginTransaction')->willReturnCallback(static function () use (&$nivel): void {
            ++$nivel;
        });
        $conexao->method('rollBack')->willReturnCallback(static function () use (&$nivel): void {
            --$nivel;
        });
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($conexao);

        return $em;
    }
}
