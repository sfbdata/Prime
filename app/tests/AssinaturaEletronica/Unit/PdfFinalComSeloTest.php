<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Unit;

use App\AssinaturaEletronica\Entity\DocumentoParaAssinar;
use App\AssinaturaEletronica\Enum\SituacaoDoDocumento;
use App\AssinaturaEletronica\Event\PdfFinalGuardado;
use App\AssinaturaEletronica\Exception\DocumentoNaoEncontrado;
use App\AssinaturaEletronica\Service\FolhaDoDocumento;
use App\AssinaturaEletronica\Service\PdfDaAssinatura;
use App\AssinaturaEletronica\Service\PdfFinalComSelo;
use App\AssinaturaEletronica\Service\PdfNoTemporario;
use App\AssinaturaEletronica\Service\Selo\ClasseDaFalhaNoSelo;
use App\AssinaturaEletronica\Service\Selo\FalhaNoSelo;
use App\AssinaturaEletronica\Service\Selo\ResultadoDoSelo;
use App\AssinaturaEletronica\Service\Selo\SeladorDePdf;
use App\AssinaturaEletronica\Service\Selo\SeladorDePdfNaoConfigurado;
use App\AssinaturaEletronica\Service\Selo\SeloDoPdfFinal;
use App\Shared\Armazenamento\CategoriaDeArquivo;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\DiretorioTemporarioPrivado;
use App\Shared\Armazenamento\EscopoDeArquivo;
use App\Shared\Armazenamento\RemocaoAposTransacao;
use App\Shared\Doctrine\Transacao\DestinoDaTransacao;
use App\Shared\Doctrine\Transacao\TransacaoComArquivoNovo;
use App\Tests\AssinaturaEletronica\Support\OficinaDosUseCasesDaAssinatura;
use App\Tests\AssinaturaEletronica\Unit\Pdf\FolhaDeTeste;
use App\Tests\AssinaturaEletronica\Unit\Pdf\PdfLido;
use App\Tests\Shared\Doubles\ConsultaDeDestinoFixa;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * O PDF final com selo do documento concluído (E3-4): gerado num arquivo temporário, guardado uma vez com o sha256,
 * {@see PdfFinalGuardado} depois de guardar; falha antes do COMMIT não deixa arquivo; dois pedidos ao mesmo tempo
 * terminam com o arquivo do primeiro e sem órfão.
 *
 * O selo (etapa 8, E8-4) entre gerar e gravar, com o selador dublado: o selado é o que vai ao armazenamento e ao
 * `hash_final`; sem configuração ou com a falha definitiva, o original; a falha que tenta de novo não deixa nada; a
 * trilha do selo só vai com quem guarda. Sem `$selador`, vale o não configurado.
 */
#[CoversClass(PdfFinalComSelo::class)]
#[CoversClass(PdfNoTemporario::class)]
#[CoversClass(PdfDaAssinatura::class)]
#[CoversClass(FolhaDoDocumento::class)]
#[CoversClass(SeloDoPdfFinal::class)]
#[CoversClass(ResultadoDoSelo::class)]
final class PdfFinalComSeloTest extends TestCase
{
    use OficinaDosUseCasesDaAssinatura;

    private const SELO = "\n%selo de teste\n%%EOF\n";

    /** @var list<object> */
    private array $ouvidos = [];

    private bool $ouvinteQueFalha = false;

    private SeladorDePdf $selador;

    /** @var list<string> os bytes que o selador dublado recebeu, um por pedido */
    private array $paraSelar = [];

    protected function setUp(): void
    {
        FolhaDeTeste::exigirFontes($this);
        $this->montarOficina();
        $this->selador = new SeladorDePdfNaoConfigurado();
    }

    #[TestDox('concluído: gera, guarda a chave e o sha256 do arquivo, avisa PdfFinalGuardado; o segundo pedido só devolve a chave')]
    public function testConcluidoGuardaUmaVez(): void
    {
        $documento = $this->concluido();

        $chave = $this->servico()->garantir($documento);

        $pdf = $this->disco->ler($chave);
        self::assertStringStartsWith('%PDF-', $pdf);
        self::assertSame($chave->nome, $documento->getArquivoFinalChave());
        self::assertSame(hash('sha256', $pdf), $documento->getHashFinal());
        self::assertSame(1, (new PdfLido($pdf))->paginas(), 'sem conteúdo, só o manifesto (dc:406)');
        self::assertEquals([new PdfFinalGuardado(1)], $this->ouvidos);

        self::assertEquals($chave, $this->servico()->garantir($documento));
        self::assertCount(1, $this->disco->gravadas, 'gerado uma vez só');
        self::assertCount(1, $this->ouvidos);
    }

    #[TestDox('quem ouve PdfFinalGuardado falha depois do COMMIT: o PDF continua guardado e a chave volta; a falha vai para o log')]
    public function testOuvinteQueFalhaNaoImpedeAEntrega(): void
    {
        $documento = $this->concluido();
        $this->ouvinteQueFalha = true;

        $chave = $this->servico()->garantir($documento);

        self::assertSame($chave->nome, $documento->getArquivoFinalChave());
        self::assertTrue($this->disco->existe($chave));
        $erros = $this->logger->doNivel('error');
        self::assertCount(1, $erros);
        self::assertSame(['documento' => 1, 'erro' => 'ouvinte quebrado'], $erros[0]['contexto']);
    }

    #[TestDox('quem chama pela web é avisado a cada pedaço gerado (para renovar as travas); o guardado não gera nem avisa')]
    public function testAvisaACadaPedacoGerado(): void
    {
        $documento = $this->concluido();
        $pedacos = 0;
        $aCadaPedaco = static function () use (&$pedacos): void {
            ++$pedacos;
        };

        $chave = $this->servico()->garantir($documento, $aCadaPedaco);

        $paginas = (new PdfLido($this->disco->ler($chave)))->paginas();
        self::assertSame($paginas + 2, $pedacos, 'o início, uma por página e o fim');
        $this->servico()->garantir($documento, $aCadaPedaco);
        self::assertSame($paginas + 2, $pedacos, 'já guardado: nenhum pedaço');
    }

    #[TestDox('já guardado: devolve a chave guardada sem gerar nem avisar')]
    public function testJaGuardado(): void
    {
        $documento = $this->concluido();
        $documento->guardarArquivoFinal('ja-guardado.pdf', str_repeat('a', 64));

        $chave = $this->servico()->garantir($documento);

        self::assertSame('ja-guardado.pdf', $chave->nome);
        self::assertSame([], $this->disco->gravadas);
        self::assertSame([], $this->ouvidos);
    }

    #[TestDox('documento que não está concluído não tem PDF final: falha sem gerar nada')]
    public function testNaoConcluido(): void
    {
        $documento = $this->documentoEnviado();

        try {
            $this->servico()->garantir($documento);
            self::fail('só o concluído tem PDF final');
        } catch (\LogicException $e) {
            self::assertStringContainsString('concluído', $e->getMessage());
        }

        self::assertSame([], $this->disco->gravadas);
        self::assertNull($documento->getArquivoFinalChave());
    }

    #[TestDox('flush que falha antes do COMMIT: a transação real apaga o PDF gravado e a falha sobe, sem aviso')]
    public function testFalhaAntesDoCommitApagaOArquivo(): void
    {
        $documento = $this->concluido();
        $recusa = new \RuntimeException('flush recusado');
        $this->transacaoReal = new TransacaoComArquivoNovo($this->emQueFalhaNoFlush($recusa), new ConsultaDeDestinoFixa(DestinoDaTransacao::NaoConfirmada), new RemocaoAposTransacao($this->disco, $this->logger), $this->logger);

        try {
            $this->servico()->garantir($documento);
            self::fail('a falha do flush deveria subir');
        } catch (\RuntimeException $e) {
            self::assertSame($recusa, $e);
        }

        self::assertCount(1, $this->disco->gravadas, 'o PDF chegou ao disco antes do flush');
        self::assertFalse($this->disco->existe($this->disco->gravadas[0]), 'não fica órfão no disco');
        self::assertSame([], $this->ouvidos);
    }

    #[TestDox('corrida: outro pedido guardou antes da trava; este apaga o próprio arquivo e devolve a chave do outro')]
    public function testCorrida(): void
    {
        $documento = $this->concluido();
        $this->aoTravar = static function (DocumentoParaAssinar $d): void {
            $d->guardarArquivoFinal('do-outro-pedido.pdf', str_repeat('b', 64)); // o que a releitura traz
        };

        $chave = $this->servico()->garantir($documento);

        self::assertSame('do-outro-pedido.pdf', $chave->nome);
        self::assertSame(str_repeat('b', 64), $documento->getHashFinal());
        self::assertCount(1, $this->disco->gravadas);
        self::assertFalse($this->disco->existe($this->disco->gravadas[0]), 'o arquivo deste pedido não fica órfão');
        self::assertSame([], $this->logger->doNivel('error'), 'remoção sem falha não registra erro');
        self::assertSame([], $this->ouvidos, 'quem avisa é quem guardou');
    }

    #[TestDox('o documento some do escritório entre a leitura e a trava: DocumentoNaoEncontrado, sem guardar nem avisar')]
    public function testDocumentoSumiu(): void
    {
        $documento = $this->concluido();
        $this->documentoSumiu = true;

        $this->expectException(DocumentoNaoEncontrado::class);
        try {
            $this->servico()->garantir($documento);
        } finally {
            self::assertNull($documento->getArquivoFinalChave());
            self::assertSame([], $this->ouvidos);
        }
    }

    #[TestDox('selado: guarda os bytes selados e o sha256 deles, grava "PDF final selado" na trilha e avisa PdfFinalGuardado')]
    public function testSeladoGuardaOSelado(): void
    {
        $documento = $this->concluido();
        $this->selando(static fn (string $pdf): string => $pdf . self::SELO);

        $chave = $this->servico()->garantir($documento);

        self::assertCount(1, $this->paraSelar);
        self::assertStringStartsWith('%PDF-', $this->paraSelar[0]);
        $guardado = $this->disco->ler($chave);
        self::assertSame($this->paraSelar[0] . self::SELO, $guardado, 'o que vai ao armazenamento é o selado');
        self::assertSame(hash('sha256', $guardado), $documento->getHashFinal());
        self::assertNotSame(hash('sha256', $this->paraSelar[0]), $documento->getHashFinal());
        self::assertSame(['pdf_selado'], $this->tiposGravados());
        self::assertNull($this->gravados[0]->getUsuario());
        self::assertSame([], $this->logger->doNivel('error'));
        self::assertEquals([new PdfFinalGuardado(1)], $this->ouvidos);
    }

    #[TestDox('o validador responde "não configurado": guarda o original e não grava nada na trilha')]
    public function testNaoConfiguradoGuardaOOriginal(): void
    {
        $documento = $this->concluido();
        $this->selando(static fn (): ?string => null);

        $chave = $this->servico()->garantir($documento);

        self::assertCount(1, $this->paraSelar);
        self::assertSame($this->paraSelar[0], $this->disco->ler($chave));
        self::assertSame(hash('sha256', $this->paraSelar[0]), $documento->getHashFinal());
        self::assertSame([], $this->tiposGravados());
        self::assertSame([], $this->logger->doNivel('error'));
    }

    #[TestDox('selador não configurado no PHP: guarda o PDF gerado, sem pedir, e não grava nada na trilha')]
    public function testSeladorNaoConfigurado(): void
    {
        $documento = $this->concluido();

        $chave = $this->servico()->garantir($documento);

        self::assertSame(hash('sha256', $this->disco->ler($chave)), $documento->getHashFinal());
        self::assertSame([], $this->tiposGravados());
        self::assertSame([], $this->logger->doNivel('error'));
    }

    /** @return iterable<string, array{FalhaNoSelo, bool}> a falha e se é a última tentativa */
    public static function falhasQueFicamSemOSelo(): iterable
    {
        yield 'definitiva (422 nao_selavel), na primeira tentativa' => [new FalhaNoSelo('nao_selavel', ClasseDaFalhaNoSelo::Definitiva, 422), false];
        yield 'passageira (503 ocupado), na última tentativa' => [new FalhaNoSelo('ocupado', ClasseDaFalhaNoSelo::Passageira, 503), true];
    }

    #[DataProvider('falhasQueFicamSemOSelo')]
    #[TestDox('falha $_dataName: guarda o original, grava "sem o selo" com o código e faz logger->error')]
    public function testFalhaGuardaOOriginalSemOSelo(FalhaNoSelo $falha, bool $ultimaTentativa): void
    {
        $documento = $this->concluido();
        $this->selando(static fn (): never => throw $falha);

        $chave = $this->servico()->garantir($documento, null, $ultimaTentativa);

        self::assertSame($this->paraSelar[0], $this->disco->ler($chave));
        self::assertSame(hash('sha256', $this->paraSelar[0]), $documento->getHashFinal());
        self::assertSame(['selo_nao_aplicado'], $this->tiposGravados());
        self::assertSame($falha->codigo, $this->gravados[0]->getDados()['motivo'] ?? null);
        $erros = $this->logger->doNivel('error');
        self::assertCount(1, $erros);
        self::assertSame($falha->codigo, $erros[0]['contexto']['codigo'] ?? null);
        self::assertEquals([new PdfFinalGuardado(1)], $this->ouvidos);
    }

    #[TestDox('sem o selo e a transação falha antes do COMMIT: nada de "guardado sem o selo" no log, e o PDF gravado sai')]
    public function testFalhaNoCommitSemOSeloNaoAvisa(): void
    {
        $documento = $this->concluido();
        $this->selando(static fn (): never => throw new FalhaNoSelo('nao_selavel', ClasseDaFalhaNoSelo::Definitiva, 422));
        $recusa = new \RuntimeException('flush recusado');
        $this->transacaoReal = new TransacaoComArquivoNovo($this->emQueFalhaNoFlush($recusa), new ConsultaDeDestinoFixa(DestinoDaTransacao::NaoConfirmada), new RemocaoAposTransacao($this->disco, $this->logger), $this->logger);

        try {
            $this->servico()->garantir($documento);
            self::fail('a falha do flush deveria subir');
        } catch (\RuntimeException $e) {
            self::assertSame($recusa, $e);
        }

        $sobreOSelo = array_filter($this->logger->registros, static fn (array $r): bool => str_contains($r['mensagem'], 'sem o selo'));
        self::assertSame([], $sobreOSelo, 'o aviso só sai depois do COMMIT');
        self::assertFalse($this->disco->existe($this->disco->gravadas[0]));
        self::assertSame([], $this->ouvidos);
    }

    #[TestDox('falha do selo que tenta de novo: sobe antes da transação, sem nada no armazenamento nem na trilha, e a área temporária sai')]
    public function testFalhaNoSeloNaoGuardaNada(): void
    {
        $documento = $this->concluido();
        $falha = new FalhaNoSelo('ocupado', ClasseDaFalhaNoSelo::Passageira, 503);
        $areaDoPedido = null;
        $this->selando(static function (string $pdf) use ($falha, &$areaDoPedido): never {
            $areaDoPedido = self::areaComOPdf($pdf);

            throw $falha;
        });
        $this->aoTravar = static fn (): never => self::fail('a falha do selo não chega à transação');

        try {
            $this->servico()->garantir($documento);
            self::fail('a falha do selo deveria subir');
        } catch (FalhaNoSelo $e) {
            self::assertSame($falha, $e);
        }

        self::assertCount(1, $this->paraSelar, 'gerou e pediu o selo');
        self::assertSame([], $this->disco->gravadas);
        self::assertSame([], $this->gravados);
        self::assertNull($documento->getArquivoFinalChave());
        self::assertNull($documento->getHashFinal());
        self::assertSame([], $this->ouvidos);
        self::assertNotNull($areaDoPedido, 'durante o pedido do selo, o PDF estava na área temporária');
        self::assertDirectoryDoesNotExist($areaDoPedido, 'a área temporária deste pedido foi liberada');
    }

    #[TestDox('corrida: outro pedido guardou antes da trava; o selo deste pedido não vai à trilha e o arquivo dele sai')]
    public function testCorridaNaoRegistraOSelo(): void
    {
        $documento = $this->concluido();
        $this->selando(static fn (string $pdf): string => $pdf . self::SELO);
        $this->aoTravar = static function (DocumentoParaAssinar $d): void {
            $d->guardarArquivoFinal('do-outro-pedido.pdf', str_repeat('b', 64));
        };

        $chave = $this->servico()->garantir($documento);

        self::assertSame('do-outro-pedido.pdf', $chave->nome);
        self::assertSame(str_repeat('b', 64), $documento->getHashFinal());
        self::assertSame([], $this->gravados, 'quem registra o selo é quem guarda');
        self::assertSame([], $this->logger->doNivel('error'));
        self::assertCount(1, $this->disco->gravadas);
        self::assertFalse($this->disco->existe($this->disco->gravadas[0]));
    }

    /** O selador dublado: guarda em `$paraSelar` os bytes que recebe e responde com `$selar`. */
    private function selando(\Closure $selar): void
    {
        $guardar = function (string $pdf): void {
            $this->paraSelar[] = $pdf;
        };
        $this->selador = new class ($guardar, $selar) implements SeladorDePdf {
            public function __construct(private readonly \Closure $guardar, private readonly \Closure $selar)
            {
            }

            public function selar(string $pdf): ?string
            {
                ($this->guardar)($pdf);

                return ($this->selar)($pdf);
            }
        };
    }

    /**
     * A área temporária em que está o PDF com estes bytes. O diretório das áreas é de todo processo do mesmo usuário
     * (outras suítes e o worker, no mesmo contêiner): a área deste pedido sai pelo conteúdo, não pela contagem.
     */
    private static function areaComOPdf(string $pdf): ?string
    {
        $sha256 = hash('sha256', $pdf);
        foreach (glob(DiretorioTemporarioPrivado::doProcesso('assinaturapdf')->caminho() . '/*/*.pdf') ?: [] as $arquivo) {
            if (@hash_file('sha256', $arquivo) === $sha256) {
                return \dirname($arquivo);
            }
        }

        return null;
    }

    private function servico(): PdfFinalComSelo
    {
        $eventos = new EventDispatcher();
        $eventos->addListener(PdfFinalGuardado::class, function (PdfFinalGuardado $evento): void {
            $this->ouvidos[] = $evento;
        });

        if ($this->ouvinteQueFalha) {
            $eventos->addListener(PdfFinalGuardado::class, static function (): void {
                throw new \RuntimeException('ouvinte quebrado');
            });
        }

        $selo = new SeloDoPdfFinal($this->selador, $this->trilha(), $this->logger);

        return new PdfFinalComSelo($this->pdfDaAssinatura(), $selo, $this->disco, $this->documentos, new RemocaoAposTransacao($this->disco, $this->logger), $eventos, $this->logger);
    }

    /** O documento enviado, com código e as duas assinaturas (rubricas no disco): concluído. */
    private function concluido(): DocumentoParaAssinar
    {
        $documento = $this->documentoEnviado();
        $documento->definirCodigo('BJA-TEST-0000-0001');
        foreach ($documento->getSignatarios() as $i => $signatario) {
            $rubrica = 'rubrica-' . $i . '.png';
            $this->disco->semear(new ChaveDeArquivo(EscopoDeArquivo::deTenant(9), CategoriaDeArquivo::ASSINATURA_DOCUMENTO, $rubrica), (string) base64_decode(self::PNG_1X1, true));
            $documento->assinar($signatario, $signatario->getNome(), $i === 0 ? self::CPF_ANA : '11144477735', $rubrica, 'simples', '203.0.113.7', 'Navegador de teste', new \DateTimeImmutable('2026-11-09 09:3' . $i . ':00'));
        }
        self::assertSame(SituacaoDoDocumento::Concluido, $documento->getSituacao());

        return $documento;
    }

    /** EntityManager e conexão como a transação real os usa (nível, BEGIN, ROLLBACK); o `flush` falha. */
    private function emQueFalhaNoFlush(\Throwable $falha): EntityManagerInterface
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
        $em->method('flush')->willThrowException($falha);

        return $em;
    }
}
