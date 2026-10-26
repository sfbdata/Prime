<?php

declare(strict_types=1);

namespace App\Tests\Inteligencia\Unit;

use App\Entity\Auth\User;
use App\Inteligencia\Armazenamento\ChavesDeInteligencia;
use App\Inteligencia\Entity\VersaoDeDocumentoJuridico;
use App\Inteligencia\Enum\DocumentoJuridico;
use App\Inteligencia\Exception\DocumentoJuridicoRecusadoException;
use App\Inteligencia\Exception\QuestaoJuridicaNaoEncontradaException;
use App\Inteligencia\Exception\VersaoDeDocumentoJuridicoNaoEncontradaException;
use App\Inteligencia\Repository\VersaoDeDocumentoJuridicoRepository;
use App\Inteligencia\UseCase\EnviarVersaoDeDocumentoJuridicoUseCase;
use App\Inteligencia\UseCase\ObterVersaoDeDocumentoJuridicoUseCase;
use App\Shared\Armazenamento\CategoriaDeArquivo;
use App\Shared\Armazenamento\RemocaoAposTransacao;
use App\Shared\Audit\EscopoDeAuditoriaDePlataforma;
use App\Shared\Doctrine\Transacao\DestinoDaTransacao;
use App\Shared\Doctrine\Transacao\TransacaoComArquivoNovo;
use App\Tests\Inteligencia\Support\CatalogoJuridicoDeTeste;
use App\Tests\Shared\Doubles\ArmazenamentoEmMemoria;
use App\Tests\Shared\Doubles\ConsultaDeDestinoFixa;
use App\Tests\Shared\Doubles\LoggerEmMemoria;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Envio da versão ajustada de um documento jurídico. A `TransacaoComArquivoNovo` é a REAL; o EntityManager e a conexão
 * são dublês que registram o persist, o COMMIT e o escopo de auditoria no momento do flush (padrão do
 * TrocarFotoDoClienteUseCaseTest). O armazenamento é o dublê em memória, que guarda escopo e categoria da chave.
 */
#[CoversClass(EnviarVersaoDeDocumentoJuridicoUseCase::class)]
#[CoversClass(ObterVersaoDeDocumentoJuridicoUseCase::class)]
#[CoversClass(VersaoDeDocumentoJuridico::class)]
#[CoversClass(ChavesDeInteligencia::class)]
final class EnviarVersaoDeDocumentoJuridicoUseCaseTest extends TestCase
{
    private ArmazenamentoEmMemoria $armazenamento;
    private EscopoDeAuditoriaDePlataforma $escopo;
    private string $dirTemp;

    /** @var list<object> */
    private array $adicionadas = [];

    /** @var list<string> */
    private array $ordem = [];

    protected function setUp(): void
    {
        $this->armazenamento = new ArmazenamentoEmMemoria();
        $this->escopo = new EscopoDeAuditoriaDePlataforma();
        $this->dirTemp = sys_get_temp_dir() . '/doc-juridico-' . bin2hex(random_bytes(6));
        mkdir($this->dirTemp, 0o700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dirTemp . '/*') ?: [] as $arquivo) {
            @unlink($arquivo);
        }
        @rmdir($this->dirTemp);
    }

    #[TestDox('docx válido: grava com chave global da categoria própria e registra documento, questão, nome original, tipo, tamanho, quem e quando; confirma no escopo de plataforma')]
    public function testDocxValido(): void
    {
        $master = (new User())->setFullName('Dra. Master')->setRoles(['ROLE_SUPER_ADMIN']);
        $docx = (string) file_get_contents(\dirname(__DIR__, 3) . '/resources/legal/termos-de-uso.docx');

        $versao = $this->sut()->executar(DocumentoJuridico::TermosDeUso, 'segunda', $this->upload($docx, 'Termos ajustados.docx'), $master);

        self::assertSame([$versao], $this->adicionadas);
        self::assertSame(['flush', 'commit'], $this->ordem);
        self::assertCount(1, $this->armazenamento->gravadas);
        $chave = $this->armazenamento->gravadas[0];
        self::assertTrue($chave->escopo->ehGlobal());
        self::assertSame(CategoriaDeArquivo::INTELIGENCIA_DOCUMENTO_JURIDICO, $chave->categoria);
        self::assertStringEndsWith('.docx', $chave->nome);
        self::assertSame($docx, $this->armazenamento->ler($chave));
        self::assertSame(DocumentoJuridico::TermosDeUso, $versao->getDocumento());
        self::assertSame('segunda', $versao->getQuestao());
        self::assertSame('Termos ajustados.docx', $versao->getNomeOriginal());
        self::assertSame($chave->nome, $versao->getNomeArquivo());
        self::assertSame('application/vnd.openxmlformats-officedocument.wordprocessingml.document', $versao->getMimeType());
        self::assertSame(strlen($docx), $versao->getTamanhoBytes());
        self::assertSame('Dra. Master', $versao->getEnviadaPorNome());
        self::assertSame('2026-10-24 10:00', $versao->getEnviadaEm()->format('Y-m-d H:i'));
        self::assertTrue(ChavesDeInteligencia::versaoDeDocumentoJuridico($versao)->ehIgualA($chave), 'a leitura monta a mesma chave da gravação');
    }

    #[TestDox('pdf, txt e md válidos, sem questão (envio solto); o nome original perde o caminho')]
    public function testOutrosTipos(): void
    {
        $sut = $this->sut();
        $pdf = $sut->executar(DocumentoJuridico::PoliticaDePrivacidade, null, $this->upload("%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n", 'C:\\pasta\\politica.pdf'), self::master());
        $txt = $sut->executar(DocumentoJuridico::AceiteDaIa, null, $this->upload("Texto do aceite ajustado.\n", 'aceite.txt'), self::master());
        $md = $sut->executar(DocumentoJuridico::RascunhoDaPoliticaDeIa, null, $this->upload("# Rascunho\n\nTexto.\n", 'rascunho.MD'), self::master());

        self::assertSame(['politica.pdf', 'application/pdf'], [$pdf->getNomeOriginal(), $pdf->getMimeType()]);
        self::assertNull($pdf->getQuestao());
        self::assertSame('text/plain', $txt->getMimeType());
        self::assertStringEndsWith('.md', $md->getNomeArquivo());
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function recusados(): iterable
    {
        yield 'HTML com extensão .pdf' => ["<html><body><script>alert(1)</script></body></html>\n", 'politica.pdf', 'Envie um arquivo .docx, .pdf, .md, .txt ou .odt; o conteúdo precisa ser do tipo da extensão.'];
        yield 'extensão fora da lista (.html)' => ["texto\n", 'politica.html', 'Envie um arquivo .docx, .pdf, .md, .txt ou .odt; o conteúdo precisa ser do tipo da extensão.'];
        yield 'sem extensão' => ["texto\n", 'politica', 'Envie um arquivo .docx, .pdf, .md, .txt ou .odt; o conteúdo precisa ser do tipo da extensão.'];
        yield 'PDF com extensão .docx' => ["%PDF-1.4\n%%EOF\n", 'termos.docx', 'Envie um arquivo .docx, .pdf, .md, .txt ou .odt; o conteúdo precisa ser do tipo da extensão.'];
        yield 'formato antigo .doc' => ["texto qualquer\n", 'termos.doc', 'Envie um arquivo .docx, .pdf, .md, .txt ou .odt; o conteúdo precisa ser do tipo da extensão.'];
        yield 'vazio' => ['', 'aceite.txt', 'O arquivo está vazio.'];
    }

    #[DataProvider('recusados')]
    #[TestDox('recusado ($_dataName): a mensagem certa e nada gravado')]
    public function testRecusado(string $conteudo, string $nome, string $mensagem): void
    {
        try {
            $this->sut()->executar(DocumentoJuridico::TermosDeUso, null, $this->upload($conteudo, $nome), self::master());
            self::fail('deveria recusar');
        } catch (DocumentoJuridicoRecusadoException $e) {
            self::assertSame($mensagem, $e->getMessage());
        }
        $this->semNadaGravado();
    }

    #[TestDox('acima de 20 MB: recusado antes de ler o conteúdo, nada gravado')]
    public function testAcimaDe20Mb(): void
    {
        $caminho = $this->dirTemp . '/grande';
        $h = fopen($caminho, 'wb');
        self::assertIsResource($h);
        ftruncate($h, EnviarVersaoDeDocumentoJuridicoUseCase::TAMANHO_MAXIMO + 1);
        fclose($h);

        $this->expectExceptionObject(new DocumentoJuridicoRecusadoException('O arquivo passa de 20 MB.'));
        try {
            $this->sut()->executar(DocumentoJuridico::TermosDeUso, null, new UploadedFile($caminho, 'grande.pdf', null, null, true), self::master());
        } finally {
            $this->semNadaGravado();
        }
    }

    #[TestDox('upload recusado pelo PHP (UPLOAD_ERR_INI_SIZE): recusado, nada gravado')]
    public function testUploadRecusadoPeloPhp(): void
    {
        $caminho = $this->dirTemp . '/parcial';
        file_put_contents($caminho, 'x');

        $this->expectException(DocumentoJuridicoRecusadoException::class);
        try {
            $this->sut()->executar(DocumentoJuridico::TermosDeUso, null, new UploadedFile($caminho, 'termos.pdf', null, \UPLOAD_ERR_INI_SIZE, true), self::master());
        } finally {
            $this->semNadaGravado();
        }
    }

    #[TestDox('questão que não pede o documento é recusada; questão fora do catálogo é não encontrada; nada gravado')]
    public function testQuestaoErrada(): void
    {
        try {
            $this->sut()->executar(DocumentoJuridico::TermosDeUso, 'primeira', $this->upload("x\n", 'a.txt'), self::master());
            self::fail('deveria recusar');
        } catch (DocumentoJuridicoRecusadoException $e) {
            self::assertSame('Esta questão não pede este documento.', $e->getMessage());
        }
        try {
            $this->sut()->executar(DocumentoJuridico::TermosDeUso, 'nao_existe', $this->upload("x\n", 'a.txt'), self::master());
            self::fail('deveria recusar');
        } catch (QuestaoJuridicaNaoEncontradaException) {
            $this->addToAssertionCount(1);
        }
        $this->semNadaGravado();
    }

    #[TestDox('flush que falha: a exceção sobe e o arquivo novo sai (a transação comprovadamente não confirmou)')]
    public function testFlushQueFalhaApagaOArquivoNovo(): void
    {
        $recusa = new \RuntimeException('flush recusado');

        try {
            $this->sut($recusa)->executar(DocumentoJuridico::TermosDeUso, null, $this->upload("Termos de uso ajustados.\n", 'a.txt'), self::master());
            self::fail('deveria lançar');
        } catch (\RuntimeException $e) {
            self::assertSame($recusa, $e);
        }
        self::assertCount(1, $this->armazenamento->gravadas);
        self::assertFalse($this->armazenamento->existe($this->armazenamento->gravadas[0]), 'o arquivo novo sai');
    }

    #[TestDox('quem não é Master: AccessDenied antes de gravar; e não baixa versão')]
    public function testNaoMaster(): void
    {
        $comum = (new User())->setRoles(['ROLE_ADMIN']);
        try {
            $this->sut()->executar(DocumentoJuridico::TermosDeUso, null, $this->upload("x\n", 'a.txt'), $comum);
            self::fail('deveria recusar');
        } catch (AccessDeniedException) {
            $this->semNadaGravado();
        }

        $repo = $this->createMock(VersaoDeDocumentoJuridicoRepository::class);
        $repo->expects($this->never())->method('porId');
        $this->expectException(AccessDeniedException::class);
        (new ObterVersaoDeDocumentoJuridicoUseCase($repo))->executar(1, $comum);
    }

    #[TestDox('baixar: a chave global da versão com o nome original; versão que não existe é não encontrada')]
    public function testObterVersao(): void
    {
        $versao = new VersaoDeDocumentoJuridico(DocumentoJuridico::TermosDeUso, null, 'termos.pdf', 'abc123.pdf', 'application/pdf', 3, self::master(), new \DateTimeImmutable());
        $repo = $this->createMock(VersaoDeDocumentoJuridicoRepository::class);
        $repo->method('porId')->willReturnCallback(static fn (int $id): ?VersaoDeDocumentoJuridico => $id === 7 ? $versao : null);
        $sut = new ObterVersaoDeDocumentoJuridicoUseCase($repo);

        $saida = $sut->executar(7, self::master());

        self::assertSame('termos.pdf', $saida->nomeOriginal);
        self::assertSame('abc123.pdf', $saida->chave->nome);
        self::assertTrue($saida->chave->escopo->ehGlobal());
        self::assertSame(CategoriaDeArquivo::INTELIGENCIA_DOCUMENTO_JURIDICO, $saida->chave->categoria);
        $this->expectException(VersaoDeDocumentoJuridicoNaoEncontradaException::class);
        $sut->executar(8, self::master());
    }

    private function semNadaGravado(): void
    {
        self::assertSame([], $this->armazenamento->gravadas);
        self::assertSame([], $this->adicionadas);
        self::assertSame([], $this->ordem);
    }

    private function sut(?\Throwable $noFlush = null): EnviarVersaoDeDocumentoJuridicoUseCase
    {
        $logger = new LoggerEmMemoria();
        $repo = $this->createStub(VersaoDeDocumentoJuridicoRepository::class);
        $repo->method('adicionar')->willReturnCallback(function (VersaoDeDocumentoJuridico $v): void {
            $this->adicionadas[] = $v;
        });
        $transacao = new TransacaoComArquivoNovo($this->em($noFlush), new ConsultaDeDestinoFixa(DestinoDaTransacao::NaoConfirmada), new RemocaoAposTransacao($this->armazenamento, $logger), $logger);

        return new EnviarVersaoDeDocumentoJuridicoUseCase(CatalogoJuridicoDeTeste::criar(), $repo, $this->armazenamento, $transacao, new MockClock('2026-10-24 10:00:00'), $this->escopo);
    }

    /** EntityManager + conexão que se comportam como a transação real; o flush confere o escopo de plataforma. */
    private function em(?\Throwable $noFlush): EntityManagerInterface
    {
        $nivel = 0;
        $conn = $this->createStub(Connection::class);
        $conn->method('getTransactionNestingLevel')->willReturnCallback(static function () use (&$nivel): int {
            return $nivel;
        });
        $conn->method('beginTransaction')->willReturnCallback(static function () use (&$nivel): void {
            ++$nivel;
        });
        $conn->method('rollBack')->willReturnCallback(static function () use (&$nivel): void {
            --$nivel;
        });
        $conn->method('commit')->willReturnCallback(function () use (&$nivel): void {
            --$nivel;
            $this->ordem[] = 'commit';
        });
        $conn->method('fetchOne')->willReturn('11117396');

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($conn);
        $em->method('flush')->willReturnCallback(function () use ($noFlush): void {
            if ($noFlush !== null) {
                throw $noFlush;
            }
            self::assertTrue($this->escopo->estaLigado(), 'o flush acontece no escopo de plataforma');
            self::assertNull($this->escopo->contaAlvo());
            $this->ordem[] = 'flush';
        });

        return $em;
    }

    /** Upload real em modo de teste (pula `is_uploaded_file`), sobre um arquivo com o conteúdo dado. */
    private function upload(string $conteudo, string $nome): UploadedFile
    {
        $caminho = $this->dirTemp . '/' . bin2hex(random_bytes(6));
        file_put_contents($caminho, $conteudo);

        return new UploadedFile($caminho, $nome, null, null, true);
    }

    private static function master(): User
    {
        return (new User())->setRoles(['ROLE_SUPER_ADMIN']);
    }
}
