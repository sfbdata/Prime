<?php

declare(strict_types=1);

namespace App\Tests\Blog\Unit;

use App\Blog\Armazenamento\ChavesDoBlog;
use App\Blog\Entity\ArtigoDoBlog;
use App\Blog\Entity\ImagemDoArtigo;
use App\Blog\Enum\BlogsDoArtigo;
use App\Blog\Exception\ArtigoDoBlogNaoEncontrado;
use App\Blog\Exception\ArtigoDoBlogRecusado;
use App\Blog\Exception\ImagemDoArtigoNaoEncontrada;
use App\Blog\Repository\ArtigoDoBlogRepository;
use App\Blog\Repository\ImagemDoArtigoRepository;
use App\Blog\UseCase\EnviarImagemDoArtigoUseCase;
use App\Blog\UseCase\ObterImagemDoArtigoUseCase;
use App\Shared\Armazenamento\CategoriaDeArquivo;
use App\Shared\Armazenamento\RemocaoAposTransacao;
use App\Shared\Audit\EscopoDeAuditoriaDePlataforma;
use App\Shared\Doctrine\Transacao\DestinoDaTransacao;
use App\Shared\Doctrine\Transacao\TransacaoComArquivoNovo;
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

/**
 * Envio de imagem do artigo (spec painel-artigos-blogs, seção 4). A `TransacaoComArquivoNovo` é a REAL; o EntityManager
 * e a conexão são dublês que registram o flush e o COMMIT (padrão do EnviarVersaoDeDocumentoJuridicoUseCaseTest). O
 * armazenamento é o dublê em memória, que guarda escopo e categoria da chave. As imagens são geradas pelo GD.
 */
#[CoversClass(EnviarImagemDoArtigoUseCase::class)]
#[CoversClass(ObterImagemDoArtigoUseCase::class)]
#[CoversClass(ChavesDoBlog::class)]
final class EnviarImagemDoArtigoUseCaseTest extends TestCase
{
    private ArmazenamentoEmMemoria $armazenamento;
    private EscopoDeAuditoriaDePlataforma $escopo;
    private ArtigoDoBlog $artigo;
    private string $dirTemp;

    /** @var list<ImagemDoArtigo> */
    private array $salvas = [];

    /** @var list<string> */
    private array $ordem = [];

    protected function setUp(): void
    {
        $this->armazenamento = new ArmazenamentoEmMemoria();
        $this->escopo = new EscopoDeAuditoriaDePlataforma();
        $this->artigo = new ArtigoDoBlog('meu-artigo', BlogsDoArtigo::Normal, null, new \DateTimeImmutable('2026-11-01'));
        $this->dirTemp = sys_get_temp_dir() . '/blog-imagem-' . bin2hex(random_bytes(6));
        mkdir($this->dirTemp, 0o700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dirTemp . '/*') ?: [] as $arquivo) {
            @unlink($arquivo);
        }
        @rmdir($this->dirTemp);
    }

    #[TestDox('PNG válido: grava com chave global da categoria própria e registra tipo, tamanho, largura, altura, nome e alt; confirma no escopo de plataforma')]
    public function testPngValido(): void
    {
        $png = self::imagemGd('png', 3, 2);

        $imagem = $this->sut()->executar(7, $this->upload($png, 'C:\\fotos\\tela do painel.png'), ' Tela do painel ');

        self::assertSame([$imagem], $this->salvas);
        self::assertSame(['flush', 'commit'], $this->ordem);
        self::assertCount(1, $this->armazenamento->gravadas);
        $chave = $this->armazenamento->gravadas[0];
        self::assertTrue($chave->escopo->ehGlobal());
        self::assertSame(CategoriaDeArquivo::BLOG_IMAGEM, $chave->categoria);
        self::assertStringEndsWith('.png', $chave->nome);
        self::assertSame($png, $this->armazenamento->ler($chave), 'sem compressão: os bytes enviados');
        self::assertSame($this->artigo, $imagem->getArtigo());
        self::assertSame($chave->nome, $imagem->getChave());
        self::assertSame('image/png', $imagem->getMime());
        self::assertSame([3, 2], [$imagem->getLarguraPx(), $imagem->getAlturaPx()]);
        self::assertSame(\strlen($png), $imagem->getTamanhoBytes());
        self::assertSame('tela do painel.png', $imagem->getNomeOriginal());
        self::assertSame('Tela do painel', $imagem->getAlt());
        self::assertSame('2026-11-03 10:00', $imagem->getCriadaEm()->format('Y-m-d H:i'));
        self::assertTrue(ChavesDoBlog::imagem($imagem)->ehIgualA($chave), 'a leitura monta a mesma chave da gravação');
    }

    #[TestDox('JPEG e WEBP: o tipo e a extensão saem do CONTEÚDO, não do nome enviado')]
    public function testTipoPeloConteudo(): void
    {
        $jpg = $this->sut()->executar(7, $this->upload(self::imagemGd('jpeg', 4, 4), 'foto.png'), null);
        self::assertSame(['image/jpeg', 'jpg'], [$jpg->getMime(), $jpg->extensao()]);
        self::assertStringEndsWith('.jpg', $jpg->getChave());
        self::assertNull($jpg->getAlt());

        if (!\function_exists('imagewebp')) {
            return;
        }
        $webp = $this->sut()->executar(7, $this->upload(self::imagemGd('webp', 5, 6), 'arte.jpg'), '');
        self::assertSame(['image/webp', 'webp', 5, 6], [$webp->getMime(), $webp->extensao(), $webp->getLarguraPx(), $webp->getAlturaPx()]);
    }

    /** @return iterable<string, array{\Closure(): string, string, string}> */
    public static function recusados(): iterable
    {
        yield 'GIF' => [static fn (): string => self::imagemGd('gif', 2, 2), 'anim.gif', 'Envie uma imagem JPG, PNG ou WEBP.'];
        yield 'HTML com nome .png' => [static fn (): string => "<html><script>alert(1)</script></html>\n", 'tela.png', 'Envie uma imagem JPG, PNG ou WEBP.'];
        yield 'SVG' => [static fn (): string => '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"></svg>', 'logo.svg', 'Envie uma imagem JPG, PNG ou WEBP.'];
        yield 'vazio' => [static fn (): string => '', 'vazio.png', 'A imagem está vazia.'];
        yield 'PNG sem largura e altura' => [static fn (): string => self::pngComCabecalho(0, 0), 'zero.png', 'Não foi possível ler a largura e a altura da imagem. Envie outro arquivo.'];
        yield 'PNG com mais de 10000 px de lado' => [static fn (): string => self::pngComCabecalho(10001, 20), 'enorme.png', 'A imagem passa de 10000 pixels de lado. Reduza e envie de novo.'];
    }

    /** @param \Closure(): string $conteudo */
    #[DataProvider('recusados')]
    #[TestDox('recusado ($_dataName): 422 no campo imagem, e nada gravado')]
    public function testRecusado(\Closure $conteudo, string $nome, string $mensagem): void
    {
        try {
            $this->sut()->executar(7, $this->upload($conteudo(), $nome), null);
            self::fail('deveria recusar');
        } catch (ArtigoDoBlogRecusado $e) {
            self::assertSame('imagem', $e->campo);
            self::assertSame($mensagem, $e->getMessage());
        }
        $this->semNadaGravado();
    }

    #[TestDox('acima de 5 MB: recusado antes de ler o conteúdo, nada gravado')]
    public function testAcimaDe5Mb(): void
    {
        $caminho = $this->dirTemp . '/grande';
        $h = fopen($caminho, 'wb');
        self::assertIsResource($h);
        fwrite($h, self::imagemGd('png', 2, 2));
        ftruncate($h, EnviarImagemDoArtigoUseCase::TAMANHO_MAXIMO + 1);
        fclose($h);

        try {
            $this->sut()->executar(7, new UploadedFile($caminho, 'grande.png', null, null, true), null);
            self::fail('deveria recusar');
        } catch (ArtigoDoBlogRecusado $e) {
            self::assertSame('A imagem passa de 5 MB.', $e->getMessage());
        }
        $this->semNadaGravado();
    }

    #[TestDox('upload recusado pelo PHP (UPLOAD_ERR_INI_SIZE): recusado, nada gravado')]
    public function testUploadRecusadoPeloPhp(): void
    {
        $caminho = $this->dirTemp . '/parcial';
        file_put_contents($caminho, 'x');

        $this->expectException(ArtigoDoBlogRecusado::class);
        try {
            $this->sut()->executar(7, new UploadedFile($caminho, 'a.png', null, \UPLOAD_ERR_INI_SIZE, true), null);
        } finally {
            $this->semNadaGravado();
        }
    }

    #[TestDox('artigo que não existe: não encontrado, nada gravado')]
    public function testArtigoInexistente(): void
    {
        $this->expectException(ArtigoDoBlogNaoEncontrado::class);
        try {
            $this->sut()->executar(99, $this->upload(self::imagemGd('png', 2, 2), 'a.png'), null);
        } finally {
            $this->semNadaGravado();
        }
    }

    #[TestDox('flush que falha: a exceção sobe e o arquivo novo sai (a transação comprovadamente não confirmou)')]
    public function testFlushQueFalhaApagaOArquivoNovo(): void
    {
        $recusa = new \RuntimeException('flush recusado');

        try {
            $this->sut($recusa)->executar(7, $this->upload(self::imagemGd('png', 2, 2), 'a.png'), null);
            self::fail('deveria lançar');
        } catch (\RuntimeException $e) {
            self::assertSame($recusa, $e);
        }
        self::assertCount(1, $this->armazenamento->gravadas);
        self::assertFalse($this->armazenamento->existe($this->armazenamento->gravadas[0]), 'o arquivo novo sai');
    }

    #[TestDox('obter: devolve a imagem pelo id; id que não existe é não encontrado')]
    public function testObter(): void
    {
        $imagem = new ImagemDoArtigo($this->artigo, 'abc.png', 'a.png', 'image/png', 1, 1, 1, new \DateTimeImmutable());
        $repo = $this->createStub(ImagemDoArtigoRepository::class);
        $repo->method('porId')->willReturnCallback(static fn (int $id): ?ImagemDoArtigo => $id === 3 ? $imagem : null);
        $sut = new ObterImagemDoArtigoUseCase($repo);

        self::assertSame($imagem, $sut->executar(3));
        $this->expectException(ImagemDoArtigoNaoEncontrada::class);
        $sut->executar(4);
    }

    private function semNadaGravado(): void
    {
        self::assertSame([], $this->armazenamento->gravadas);
        self::assertSame([], $this->salvas);
        self::assertSame([], $this->ordem);
    }

    private function sut(?\Throwable $noFlush = null): EnviarImagemDoArtigoUseCase
    {
        $logger = new LoggerEmMemoria();
        $artigos = $this->createStub(ArtigoDoBlogRepository::class);
        $artigos->method('porId')->willReturnCallback(fn (int $id): ?ArtigoDoBlog => $id === 7 ? $this->artigo : null);
        $imagens = $this->createStub(ImagemDoArtigoRepository::class);
        $imagens->method('salvar')->willReturnCallback(function (ImagemDoArtigo $i, bool $flush = false): void {
            self::assertFalse($flush, 'quem confirma é a transação com arquivo novo');
            $this->salvas[] = $i;
        });
        $transacao = new TransacaoComArquivoNovo($this->em($noFlush), new ConsultaDeDestinoFixa(DestinoDaTransacao::NaoConfirmada), new RemocaoAposTransacao($this->armazenamento, $logger), $logger);

        return new EnviarImagemDoArtigoUseCase($artigos, $imagens, $this->armazenamento, $transacao, new MockClock('2026-11-03 10:00:00'), $this->escopo);
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

    private static function imagemGd(string $formato, int $largura, int $altura): string
    {
        $gd = imagecreatetruecolor($largura, $altura);
        self::assertNotFalse($gd);
        ob_start();
        match ($formato) {
            'png' => imagepng($gd),
            'jpeg' => imagejpeg($gd),
            'gif' => imagegif($gd),
            'webp' => imagewebp($gd),
        };

        return (string) ob_get_clean();
    }

    /** Assinatura e cabeçalho IHDR de um PNG com as medidas dadas, sem os dados da imagem (o getimagesize só lê isto). */
    private static function pngComCabecalho(int $largura, int $altura): string
    {
        $ihdr = 'IHDR' . pack('NN', $largura, $altura) . "\x08\x02\x00\x00\x00";

        return "\x89PNG\r\n\x1a\n" . pack('N', 13) . $ihdr . pack('N', crc32($ihdr)) . pack('N', 0) . 'IEND' . pack('N', crc32('IEND'));
    }
}
