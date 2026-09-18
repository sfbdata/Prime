<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit;

use App\Shared\Armazenamento\ArmazenamentoDeArquivos;
use App\Shared\Armazenamento\ArmazenamentoLocal;
use App\Shared\Armazenamento\ArquivoArmazenado;
use App\Shared\Armazenamento\CategoriaDeArquivo;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\EscopoDeArquivo;
use App\Shared\Armazenamento\Exception\FalhaDeArmazenamento;
use App\Shared\Armazenamento\FonteDeConteudo;
use App\Shared\Armazenamento\MetadadosDeArquivo;
use App\Shared\Armazenamento\NovoArquivo;
use App\Shared\Armazenamento\ResolvedorDeCaminhoLocal;
use App\Shared\Http\FonteDeUploadHttp;
use App\Tests\Shared\Doubles\ArmazenamentoEmMemoria;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\Exception\CannotWriteFileException;
use Symfony\Component\HttpFoundation\File\Exception\ExtensionFileException;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\Exception\FormSizeFileException;
use Symfony\Component\HttpFoundation\File\Exception\IniSizeFileException;
use Symfony\Component\HttpFoundation\File\Exception\NoFileException;
use Symfony\Component\HttpFoundation\File\Exception\NoTmpDirFileException;
use Symfony\Component\HttpFoundation\File\Exception\PartialFileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * A ponte HTTP → armazenamento (E2.4A). O que se prova aqui é INV-10 e o ciclo de vida do
 * temporário; os consumidores provam escopo e categoria nos próprios testes.
 */
#[CoversClass(FonteDeUploadHttp::class)]
final class FonteDeUploadHttpTest extends TestCase
{
    private const PDF = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";

    /** @var list<string> */
    private array $criados = [];

    protected function tearDown(): void
    {
        foreach ($this->criados as $caminho) {
            $this->removerArvore($caminho);
        }
    }

    #[TestDox('upload válido: grava o conteúdo, com a extensão adivinhada do conteúdo, e consome a origem')]
    public function testUploadValidoGravaEConsomeAOrigem(): void
    {
        $origem        = $this->arquivoCom(self::PDF);
        $armazenamento = new ArmazenamentoEmMemoria();

        $upload     = FonteDeUploadHttp::de($this->upload($origem, 'contrato.pdf'));
        $armazenado = $upload->gravarEm($armazenamento, $this->novo($upload->extensao));

        self::assertSame('pdf', $upload->extensao);
        self::assertStringEndsWith('.pdf', $armazenado->chave->nome);
        self::assertSame(self::PDF, $armazenamento->ler($armazenado->chave));
        self::assertSame(\strlen(self::PDF), $armazenado->tamanhoBytes);
        self::assertFileDoesNotExist($origem, 'o temporário do PHP tem de ser consumido, como no move() de hoje');
    }

    #[TestDox('a extensão nunca vem do nome enviado pelo cliente')]
    public function testExtensaoNaoVemDoNomeDoCliente(): void
    {
        $upload = FonteDeUploadHttp::de($this->upload($this->arquivoCom(self::PDF), 'malicioso.php'));

        self::assertSame('pdf', $upload->extensao);
    }

    #[TestDox('conteúdo sem extensão conhecida cai em bin, como o guessExtension() ?? "bin" de hoje')]
    public function testConteudoSemExtensaoConhecidaViraBin(): void
    {
        $upload     = FonteDeUploadHttp::de($this->upload($this->arquivoCom("\x00\x01\x02\x03\xff\xfe"), 'dados'));
        $armazenado = $upload->gravarEm(new ArmazenamentoEmMemoria(), $this->novo($upload->extensao));

        self::assertSame('bin', $upload->extensao);
        self::assertStringEndsWith('.bin', $armazenado->chave->nome);
    }

    /**
     * INV-10: o arquivo publicado tem de ser legível por quem serve (nginx/worker), e não herdar o
     * `0600` do `tempnam()`. Contra o disco de verdade, não contra o dublê.
     *
     * **Duas barreiras garantem isso**, e este teste cai só quando as duas somem: o `chmod` do
     * `UploadedFile::move()` e o `normalizarModo()` do `ArmazenamentoLocal`. A origem e o destino
     * estão no mesmo sistema de arquivos, então o `rename()` levaria o `0600` adiante sem elas.
     */
    #[TestDox('INV-10: o arquivo publicado nasce com modo 0666 & ~umask')]
    public function testArquivoPublicadoTemOModoDoUpload(): void
    {
        $raiz          = $this->diretorio();
        $armazenamento = new ArmazenamentoLocal(new ResolvedorDeCaminhoLocal(
            uploadsDir: $raiz . '/pastas',
            clientesUploadsDir: $raiz . '/clientes',
            chamadosUploadsDir: $raiz . '/chamados',
            justificativasUploadsDir: $raiz . '/justificativas',
            fotosPerfilDir: $raiz . '/perfil',
            cobrancasUploadsDir: $raiz . '/cobrancas',
            kanbanUploadsDir: $raiz . '/kanban',
        ));

        $origem = $this->arquivoCom(self::PDF);
        chmod($origem, 0o600);

        $upload     = FonteDeUploadHttp::de($this->upload($origem, 'contrato.pdf'));
        $armazenado = $upload->gravarEm($armazenamento, $this->novo($upload->extensao));

        $publicado = $raiz . '/clientes/' . $armazenado->chave->nome;
        clearstatcache(true, $publicado);

        self::assertSame(self::PDF, file_get_contents($publicado));
        self::assertSame(0o666 & ~umask(), fileperms($publicado) & 0o777);
        self::assertSame('application/pdf', $armazenado->mimeType);
    }

    /** @return iterable<string, array{int, class-string<FileException>}> */
    public static function errosDeUpload(): iterable
    {
        yield 'UPLOAD_ERR_INI_SIZE'   => [\UPLOAD_ERR_INI_SIZE, IniSizeFileException::class];
        yield 'UPLOAD_ERR_FORM_SIZE'  => [\UPLOAD_ERR_FORM_SIZE, FormSizeFileException::class];
        yield 'UPLOAD_ERR_PARTIAL'    => [\UPLOAD_ERR_PARTIAL, PartialFileException::class];
        yield 'UPLOAD_ERR_NO_FILE'    => [\UPLOAD_ERR_NO_FILE, NoFileException::class];
        yield 'UPLOAD_ERR_CANT_WRITE' => [\UPLOAD_ERR_CANT_WRITE, CannotWriteFileException::class];
        yield 'UPLOAD_ERR_NO_TMP_DIR' => [\UPLOAD_ERR_NO_TMP_DIR, NoTmpDirFileException::class];
        yield 'UPLOAD_ERR_EXTENSION'  => [\UPLOAD_ERR_EXTENSION, ExtensionFileException::class];
    }

    /**
     * INV-10: a mesma exceção tipada do `UploadedFile::move()`. O caminho vazio é o que o PHP
     * entrega num upload com erro — sem o `isValid()` antes, `guessExtension()` estouraria com
     * uma exceção do componente Mime, que não diz nada sobre o que houve.
     *
     * @param class-string<FileException> $esperada
     */
    #[DataProvider('errosDeUpload')]
    #[TestDox('INV-10: upload com erro lança a exceção tipada do $_dataName')]
    public function testUploadComErroLancaAExcecaoTipada(int $erro, string $esperada): void
    {
        $this->expectException($esperada);

        FonteDeUploadHttp::de(new UploadedFile('', 'enorme.pdf', null, $erro, true));
    }

    /**
     * INV-10: a prova de origem. Fora do modo de teste, um arquivo que não chegou por upload HTTP
     * (`is_uploaded_file()` falso) é recusado — e continua onde estava. Uma ponte que trocasse o
     * `move()` por `rename()` aceitaria qualquer caminho do servidor como se fosse upload.
     *
     * **Há duas barreiras, e este teste só cai quando as duas somem** (medido na E2.4A): o
     * `isValid()` de `de()` e o `move()` de `gravarEm()`, que também chama `is_uploaded_file()`.
     * Tirar só uma delas deixa este teste verde — a outra recusa. Não remova nenhuma confiando
     * nele: a de `de()` é a que faz o erro sair antes de qualquer leitura (ver o teste acima).
     */
    #[TestDox('INV-10: arquivo que não veio de upload HTTP é recusado e fica intacto')]
    public function testArquivoQueNaoVeioDeUploadEhRecusado(): void
    {
        $origem        = $this->arquivoCom(self::PDF);
        $armazenamento = new ArmazenamentoEmMemoria();

        try {
            FonteDeUploadHttp::de(new UploadedFile($origem, 'forjado.pdf', null, null, false))
                ->gravarEm($armazenamento, $this->novo('pdf'));
            self::fail('Arquivo fora de upload HTTP foi aceito.');
        } catch (FileException) {
            // esperado
        }

        self::assertFileExists($origem);
        self::assertSame(self::PDF, file_get_contents($origem));
    }

    /**
     * O que se prova é "não sobra temporário", não qual barreira o apaga: são duas (D9) — o
     * `liberar()` em `finally` e o destrutor do `ArquivoTemporarioPossuido`, que roda quando a
     * pilha desempilha. Tirar só o `finally` deixa este teste verde, e está certo que deixe.
     */
    #[TestDox('falha do storage propaga, e o temporário da ponte não fica para trás')]
    public function testFalhaDoStoragePropagaELiberaOTemporario(): void
    {
        $temporarios = $this->diretorio();
        $espiao      = $this->espiao(new FalhaDeArmazenamento('disco cheio'));

        $upload = FonteDeUploadHttp::de($this->upload($this->arquivoCom(self::PDF), 'contrato.pdf'), $temporarios);

        try {
            $upload->gravarEm($espiao, $this->novo($upload->extensao));
            self::fail('A falha do storage foi engolida.');
        } catch (FalhaDeArmazenamento $e) {
            self::assertSame('disco cheio', $e->getMessage());
        }

        self::assertSame($temporarios, $espiao->diretorioDaOrigem, 'o temporário nem nasceu no diretório informado');
        self::assertSame([], $this->arquivosEm($temporarios), 'o temporário possuído vazou');
    }

    #[TestDox('upload gravado com sucesso também não deixa temporário')]
    public function testSucessoNaoDeixaTemporario(): void
    {
        $temporarios = $this->diretorio();
        $espiao      = $this->espiao();
        $upload      = FonteDeUploadHttp::de($this->upload($this->arquivoCom(self::PDF), 'contrato.pdf'), $temporarios);

        $upload->gravarEm($espiao, $this->novo($upload->extensao));

        self::assertSame($temporarios, $espiao->diretorioDaOrigem);
        self::assertSame([], $this->arquivosEm($temporarios));
    }

    /**
     * Depois do `move()` o PHP não apaga mais o arquivo no fim da requisição. Se o processo morrer
     * antes da gravação, o que sobrar não pode ser legível por outros usuários da máquina (DT-7).
     */
    #[TestDox('por padrão o upload espera a gravação num diretório privado do uid efetivo (0700)')]
    public function testTemporarioPadraoMoraEmDiretorioPrivado(): void
    {
        $espiao = $this->espiao();
        $upload = FonteDeUploadHttp::de($this->upload($this->arquivoCom(self::PDF), 'contrato.pdf'));

        $upload->gravarEm($espiao, $this->novo($upload->extensao));

        self::assertSame(sys_get_temp_dir() . '/jusprime-upload-' . posix_geteuid(), $espiao->diretorioDaOrigem);
        self::assertSame(0o700, $espiao->modoDoDiretorio);
    }

    /**
     * "Privado" é conferido, não suposto. Um diretório com permissão para grupo ou outros é
     * recusado ANTES de o upload sair do lugar — degradar em silêncio deixaria atestado legível.
     */
    #[TestDox('diretório temporário que não é privado é recusado, e o upload fica onde estava')]
    public function testDiretorioNaoPrivadoEhRecusado(): void
    {
        $exposto = $this->diretorio();
        chmod($exposto, 0o755);
        $origem = $this->arquivoCom(self::PDF);
        $espiao = $this->espiao();

        $upload = FonteDeUploadHttp::de($this->upload($origem, 'contrato.pdf'), $exposto);

        try {
            $upload->gravarEm($espiao, $this->novo($upload->extensao));
            self::fail('Diretório temporário exposto foi aceito.');
        } catch (FalhaDeArmazenamento $e) {
            self::assertStringContainsString('não é privado', $e->getMessage());
        }

        self::assertNull($espiao->diretorioDaOrigem, 'nada podia ter chegado ao storage');
        self::assertFileExists($origem, 'o upload não podia ter saído do lugar');
        self::assertSame([], $this->arquivosEm($exposto));
    }

    /**
     * `tempnam()` não falha quando não consegue escrever no diretório pedido: cai em silêncio no
     * temporário do sistema. Lá o arquivo ficaria fora do diretório privado — então a ponte recusa.
     */
    #[TestDox('temporário que não nasce no diretório privado é recusado')]
    public function testTempnamForaDoDiretorioEhRecusado(): void
    {
        if (posix_geteuid() === 0) {
            self::markTestSkipped('root escreve em diretório sem permissão; o desvio do tempnam não acontece');
        }

        $semEscrita = $this->diretorio();
        chmod($semEscrita, 0o500); // privado, mas sem escrita
        $origem = $this->arquivoCom(self::PDF);
        $espiao = $this->espiao();

        $upload = FonteDeUploadHttp::de($this->upload($origem, 'contrato.pdf'), $semEscrita);

        try {
            $upload->gravarEm($espiao, $this->novo($upload->extensao));
            self::fail('Temporário fora do diretório privado foi aceito.');
        } catch (FalhaDeArmazenamento $e) {
            self::assertStringContainsString('não nasceu', $e->getMessage());
        } finally {
            chmod($semEscrita, 0o700);
        }

        self::assertNull($espiao->diretorioDaOrigem);
        self::assertFileExists($origem, 'o upload não podia ter saído do lugar');
    }

    #[TestDox('o mesmo upload não pode ser gravado duas vezes')]
    public function testUsoUnico(): void
    {
        $armazenamento = new ArmazenamentoEmMemoria();
        $upload        = FonteDeUploadHttp::de($this->upload($this->arquivoCom(self::PDF), 'contrato.pdf'));
        $upload->gravarEm($armazenamento, $this->novo('pdf'));

        $this->expectException(\LogicException::class);

        $upload->gravarEm($armazenamento, $this->novo('pdf'));
    }

    private function novo(string $extensao): NovoArquivo
    {
        return new NovoArquivo(EscopoDeArquivo::deTenant(7), CategoriaDeArquivo::CLIENTE_DOCUMENTO, $extensao);
    }

    private function upload(string $caminho, string $nome): UploadedFile
    {
        return new UploadedFile($caminho, $nome, null, null, true);
    }

    private function arquivoCom(string $conteudo): string
    {
        $caminho = $this->diretorio() . '/upload-do-php';
        file_put_contents($caminho, $conteudo);

        return $caminho;
    }

    private function diretorio(): string
    {
        $caminho = sys_get_temp_dir() . '/e2-ponte-' . bin2hex(random_bytes(6));
        mkdir($caminho, 0o700, true);
        $this->criados[] = $caminho;

        return $caminho;
    }

    /**
     * Delega ao dublê em memória e fotografa, na hora da gravação, de onde a fonte veio. Com
     * `$falha`, fotografa e então recusa.
     */
    private function espiao(?\Throwable $falha = null): ArmazenamentoDeArquivos
    {
        return new class ($falha) implements ArmazenamentoDeArquivos {
            public ?string $diretorioDaOrigem = null;
            public ?int $modoDoDiretorio = null;
            private ArmazenamentoEmMemoria $memoria;

            public function __construct(private readonly ?\Throwable $falha)
            {
                $this->memoria = new ArmazenamentoEmMemoria();
            }

            public function gravar(ChaveDeArquivo|NovoArquivo $destino, FonteDeConteudo $fonte): ArquivoArmazenado
            {
                $this->diretorioDaOrigem = \dirname((string) $fonte->caminhoLocalOuNull());
                $this->modoDoDiretorio   = fileperms($this->diretorioDaOrigem) & 0o777;

                if ($this->falha !== null) {
                    throw $this->falha;
                }

                return $this->memoria->gravar($destino, $fonte);
            }

            public function abrir(ChaveDeArquivo $chave): mixed { return $this->memoria->abrir($chave); }
            public function ler(ChaveDeArquivo $chave): string { return $this->memoria->ler($chave); }
            public function existe(ChaveDeArquivo $chave): bool { return $this->memoria->existe($chave); }
            public function excluir(ChaveDeArquivo $chave): void { $this->memoria->excluir($chave); }
            public function metadados(ChaveDeArquivo $chave): ?MetadadosDeArquivo { return $this->memoria->metadados($chave); }
        };
    }

    /** @return list<string> */
    private function arquivosEm(string $diretorio): array
    {
        return array_values(array_diff(scandir($diretorio) ?: [], ['.', '..']));
    }

    private function removerArvore(string $caminho): void
    {
        if (!is_dir($caminho)) {
            @unlink($caminho);

            return;
        }

        foreach (scandir($caminho) ?: [] as $entrada) {
            if ($entrada !== '.' && $entrada !== '..') {
                $this->removerArvore($caminho . '/' . $entrada);
            }
        }

        @rmdir($caminho);
    }
}
