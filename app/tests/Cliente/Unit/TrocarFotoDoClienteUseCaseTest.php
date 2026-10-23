<?php

declare(strict_types=1);

namespace App\Tests\Cliente\Unit;

use App\Cliente\Armazenamento\ChavesDeCliente;
use App\Cliente\DTO\FotoDoClienteInput;
use App\Cliente\Entity\Cliente;
use App\Cliente\Entity\ClienteHistorico;
use App\Cliente\Entity\ClientePF;
use App\Cliente\Exception\ClienteNaoEncontradoException;
use App\Cliente\Exception\FotoDoClienteInvalidaException;
use App\Cliente\Exception\FotoDoClienteRecusadaException;
use App\Cliente\Exception\SemPermissaoParaEditarClienteException;
use App\Cliente\Repository\ClienteHistoricoRepository;
use App\Cliente\Repository\ClienteRepository;
use App\Cliente\Service\ClienteDaPastaParaFoto;
use App\Cliente\Service\HistoricoDoCliente;
use App\Cliente\UseCase\TrocarFotoDoClienteUseCase;
use App\Entity\Auth\User;
use App\Entity\Permission\AccessRequest;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaMensagem;
use App\Pasta\Exception\PastaDeOutroEscritorioException;
use App\Pasta\Exception\SemPermissaoParaVerPastaException;
use App\Pasta\Repository\PastaRepository;
use App\Pasta\Service\RegistroDoSistemaNaPasta;
use App\Service\PermissionChecker;
use App\Shared\Armazenamento\CategoriaDeArquivo;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\RemocaoAposTransacao;
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
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * "Salvar foto" do cabeçalho da pasta: guardas (escritório, pasta visível, cliente na pasta, edição do cliente,
 * lápide), imagem conferida pelo conteúdo, gravação no escopo do escritório, cartão no Registro da pasta, linha no
 * Histórico do cadastro e a anterior apagada só depois do COMMIT. A `TransacaoComArquivoNovo` é a REAL; EntityManager
 * e conexão são dublês que se comportam como a transação (o desenho de `CopiarDocumentosDaPastaUseCaseTest`).
 */
#[CoversClass(TrocarFotoDoClienteUseCase::class)]
#[CoversClass(ClienteDaPastaParaFoto::class)]
final class TrocarFotoDoClienteUseCaseTest extends TestCase
{
    private const TRES_MB = 3 * 1024 * 1024;

    private ArmazenamentoEmMemoria $armazenamento;
    private LoggerEmMemoria $logger;
    private Tenant $tenant;
    private Pasta $pasta;
    private ClientePF $cliente;
    private User $autor;
    private string $dirTemp;

    /** @var list<object> */
    private array $persistidos = [];

    /** @var list<ClienteHistorico> */
    private array $historico = [];

    /** @var list<string> */
    private array $ordem = [];

    protected function setUp(): void
    {
        $this->dirTemp = sys_get_temp_dir() . '/foto-cliente-' . bin2hex(random_bytes(6));
        mkdir($this->dirTemp, 0o700, true);
        $this->armazenamento = new ArmazenamentoEmMemoria();
        $this->logger = new LoggerEmMemoria();
        $this->tenant = self::tenant(1);
        $this->autor = (new User())->setFullName('Ana Souza');
        $this->cliente = (new ClientePF())->setNomeCompleto('Gleisson Bruno');
        $this->cliente->setTenant($this->tenant);
        (new \ReflectionProperty(Cliente::class, 'id'))->setValue($this->cliente, 5);
        $this->pasta = (new Pasta())->setNup('0001')->setTenant($this->tenant);
        (new \ReflectionProperty(Pasta::class, 'id'))->setValue($this->pasta, 9);
        $this->pasta->addCliente($this->cliente);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dirTemp . '/*') ?: [] as $arquivo) {
            @unlink($arquivo);
        }
        @rmdir($this->dirTemp);
    }

    #[TestDox('JPEG válido: grava no escopo do escritório (CLIENTE_FOTO), aponta o cliente, registra na pasta e no cadastro')]
    public function testTrocaGravaERegistra(): void
    {
        $saida = $this->sut()->executar($this->input($this->jpeg()));

        $chave = $this->armazenamento->ultimaGravada();
        self::assertSame(CategoriaDeArquivo::CLIENTE_FOTO, $chave->categoria);
        self::assertSame(1, $chave->escopo->tenantIdOuNull());
        self::assertSame($chave->nome, $this->cliente->getFoto());
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}\.jpg$/', (string) $this->cliente->getFoto());
        self::assertNotNull($this->cliente->getFotoAtualizadaEm());
        self::assertSame([5, $chave->nome], [$saida->clienteId, $saida->nome]);

        $cartoes = array_values(array_filter($this->persistidos, static fn (object $o): bool => $o instanceof PastaMensagem));
        self::assertCount(1, $cartoes);
        self::assertSame('cadastro', $cartoes[0]->getSistema());
        self::assertStringContainsString('Foto do cliente alterada', (string) $cartoes[0]->getConteudo());
        self::assertSame(['Ana Souza alterou foto'], array_map(static fn (ClienteHistorico $h): string => $h->getTexto(), $this->historico));
    }

    #[TestDox('PNG e WebP também valem; a extensão sai do conteúdo')]
    public function testPngEWebp(): void
    {
        $this->sut()->executar($this->input($this->png()));
        self::assertStringEndsWith('.png', (string) $this->cliente->getFoto());

        $this->sut()->executar($this->input($this->webp()));
        self::assertStringEndsWith('.webp', (string) $this->cliente->getFoto());
    }

    #[TestDox('Ordem: grava a nova, COMMIT, e só depois apaga a anterior (pelo nome guardado, não pela chave nova)')]
    public function testAnteriorSaiDepoisDoCommit(): void
    {
        $antiga = $this->comFotoAnterior('antiga.jpg');
        $this->armazenamento->aoExcluir = function (): void {
            $this->ordem[] = 'excluir-anterior';
        };

        $this->sut()->executar($this->input($this->jpeg()));

        self::assertSame(['commit', 'excluir-anterior'], $this->ordem);
        self::assertFalse($this->armazenamento->existe($antiga));
        self::assertTrue($this->armazenamento->existe($this->armazenamento->ultimaGravada()), 'a recém-gravada fica');
        self::assertEquals([$antiga], $this->armazenamento->excluidas);
    }

    #[TestDox('Banco recusa o flush: a exceção sobe, a nova sai do disco e a anterior FICA')]
    public function testFlushQueFalhaNaoApagaAAnterior(): void
    {
        $antiga = $this->comFotoAnterior('antiga.jpg');
        $recusa = new \RuntimeException('flush recusado');

        try {
            $this->sut(noFlush: $recusa)->executar($this->input($this->jpeg()));
            self::fail('A recusa do banco tinha de subir.');
        } catch (\RuntimeException $e) {
            self::assertSame($recusa, $e);
        }

        self::assertTrue($this->armazenamento->existe($antiga));
        self::assertFalse($this->armazenamento->existe($this->armazenamento->ultimaGravada()), 'a nova não fica órfã');
        self::assertNotContains($antiga, $this->armazenamento->excluidas);
    }

    #[TestDox('Disco falha ao apagar a anterior depois do COMMIT: a troca vale e o órfão vira registro')]
    public function testFalhaAoApagarAAnteriorViraRegistro(): void
    {
        $antiga = $this->comFotoAnterior('antiga.jpg');
        $this->armazenamento->falhaAoExcluir = static fn (): \Throwable => new \RuntimeException('disco ilegível');

        $this->sut()->executar($this->input($this->jpeg()));

        self::assertSame($this->armazenamento->ultimaGravada()->nome, $this->cliente->getFoto());
        self::assertTrue($this->armazenamento->existe($antiga));
        self::assertNotEmpty($this->logger->doNivel('error'));
    }

    /** @return iterable<string, array{string, string}> */
    public static function imagensRecusadas(): iterable
    {
        yield 'GIF' => ['gif', '/não permitido/i'];
        yield 'PDF' => ['pdf', '/não permitido/i'];
        yield 'JPEG de mentira (só o cabeçalho, sem imagem)' => ['jpegFalso', '/não permitido/i'];
        yield 'acima de 3 MB' => ['jpegGrande', '/3 MB/'];
    }

    #[DataProvider('imagensRecusadas')]
    #[TestDox('Imagem recusada ($_dataName): 422 sem gravar nada nem mexer no cliente')]
    public function testImagemRecusada(string $tipo, string $mensagem): void
    {
        $conteudo = match ($tipo) {
            'gif'        => "GIF89a\x01\x00\x01\x00\x80\x00\x00\x00\x00\x00\xff\xff\xff!\xf9\x04\x01\x00\x00\x00\x00,\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02D\x01\x00;",
            'pdf'        => "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n",
            'jpegFalso'  => "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00\xFF\xD9",
            'jpegGrande' => str_pad($this->jpeg(), self::TRES_MB + 1, "\0"),
        };

        try {
            $this->sut()->executar($this->input($conteudo));
            self::fail('Esperava a recusa.');
        } catch (FotoDoClienteInvalidaException $e) {
            self::assertMatchesRegularExpression($mensagem, $e->getMessage());
        }

        self::assertSame([], $this->armazenamento->gravadas);
        self::assertNull($this->cliente->getFoto());
        self::assertSame([], $this->persistidos);
    }

    #[TestDox('Sem arquivo: recusa sem gravar')]
    public function testSemArquivo(): void
    {
        $this->expectException(FotoDoClienteInvalidaException::class);
        $this->sut()->executar(new FotoDoClienteInput(9, 5, $this->autor, $this->tenant));
    }

    /** @return iterable<string, array{class-string<\Throwable>, \Closure(self): void, 2?: bool, 3?: bool}> */
    public static function guardas(): iterable
    {
        yield 'pasta de outro escritório' => [PastaDeOutroEscritorioException::class, static fn (self $t) => $t->pasta->setTenant(self::tenant(2))];
        yield 'cliente de outro escritório' => [ClienteNaoEncontradoException::class, static fn (self $t) => $t->cliente->setTenant(self::tenant(2))];
        yield 'cliente fora da pasta' => [ClienteNaoEncontradoException::class, static fn (self $t) => $t->pasta->removeCliente($t->cliente)];
        yield 'quem pede não vê a pasta' => [SemPermissaoParaVerPastaException::class, static fn (self $t) => null, false, true];
        yield 'quem pede não edita o cliente' => [SemPermissaoParaEditarClienteException::class, static fn (self $t) => null, true, false];
        yield 'pasta excluída (lápide)' => [FotoDoClienteRecusadaException::class, static fn (self $t) => $t->pasta->marcarExcluida($t->autor, new \DateTimeImmutable())];
    }

    /**
     * @param class-string<\Throwable> $excecao
     * @param \Closure(self): void     $estragar
     */
    #[DataProvider('guardas')]
    #[TestDox('Recusa ($_dataName) antes de gravar qualquer coisa')]
    public function testGuardas(string $excecao, \Closure $estragar, bool $veAPasta = true, bool $editaOCliente = true): void
    {
        $estragar($this);

        try {
            $this->sut(veAPasta: $veAPasta, editaOCliente: $editaOCliente)->executar($this->input($this->jpeg()));
            self::fail('Esperava a recusa.');
        } catch (\Throwable $e) {
            self::assertInstanceOf($excecao, $e);
        }

        self::assertSame([], $this->armazenamento->gravadas);
        self::assertNull($this->cliente->getFoto());
        self::assertSame([], $this->historico);
    }

    // ---------------------------------------------------------------- helpers

    private function sut(bool $veAPasta = true, bool $editaOCliente = true, ?\Throwable $noFlush = null): TrocarFotoDoClienteUseCase
    {
        $em = $this->em($noFlush);
        $pastas = $this->createStub(PastaRepository::class);
        $pastas->method('find')->willReturnCallback(fn (mixed $id): ?Pasta => $id === 9 ? $this->pasta : null);
        $clientes = $this->createStub(ClienteRepository::class);
        $clientes->method('find')->willReturnCallback(fn (mixed $id): ?Cliente => $id === 5 ? $this->cliente : null);
        $permissoes = $this->createStub(PermissionChecker::class);
        $permissoes->method('canAccessResource')->willReturnCallback(
            static fn (User $u, ?Tenant $t, string $recurso, int $id, string $acao): bool => $recurso === AccessRequest::RESOURCE_PASTA ? $veAPasta && $acao === AccessRequest::ACTION_VIEW : $editaOCliente,
        );
        $repoHistorico = $this->createStub(ClienteHistoricoRepository::class);
        $repoHistorico->method('registrar')->willReturnCallback(function (ClienteHistorico $h): void {
            $this->historico[] = $h;
        });
        $remocao = new RemocaoAposTransacao($this->armazenamento, $this->logger);

        return new TrocarFotoDoClienteUseCase(
            new ClienteDaPastaParaFoto($pastas, $clientes, $permissoes),
            $this->armazenamento,
            new TransacaoComArquivoNovo($em, new ConsultaDeDestinoFixa(DestinoDaTransacao::NaoConfirmada), $remocao, $this->logger),
            $remocao,
            new RegistroDoSistemaNaPasta($em),
            new HistoricoDoCliente($repoHistorico),
        );
    }

    /** EntityManager + conexão que se comportam como a transação real; `persist` e o COMMIT ficam registrados. */
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
        $em->method('persist')->willReturnCallback(function (object $entidade): void {
            $this->persistidos[] = $entidade;
        });
        if ($noFlush !== null) {
            $em->method('flush')->willThrowException($noFlush);
        }

        return $em;
    }

    private function comFotoAnterior(string $nome): ChaveDeArquivo
    {
        $this->cliente->trocarFoto($nome, new \DateTimeImmutable('-1 day'));
        $chave = ChavesDeCliente::fotoPorNome($this->cliente, $nome);
        $this->armazenamento->semear($chave, 'antiga');

        return $chave;
    }

    /** Upload real em modo de teste (pula `is_uploaded_file`), sobre um arquivo com conteúdo real. */
    private function input(string $conteudo): FotoDoClienteInput
    {
        $caminho = $this->dirTemp . '/' . bin2hex(random_bytes(6));
        file_put_contents($caminho, $conteudo);

        return new FotoDoClienteInput(9, 5, $this->autor, $this->tenant, new UploadedFile($caminho, 'foto.jpg', null, null, true));
    }

    private function jpeg(): string
    {
        ob_start();
        imagejpeg(imagecreatetruecolor(4, 4), null, 88);

        return (string) ob_get_clean();
    }

    private function png(): string
    {
        ob_start();
        imagepng(imagecreatetruecolor(2, 2));

        return (string) ob_get_clean();
    }

    private function webp(): string
    {
        // RIFF/WEBP com um chunk VP8L mínimo (1x1): o GD do container não escreve WebP.
        $vp8l = "\x2f\x00\x00\x00\x00\x07\x10\x11\x11\x88\x88\xfe\x07\x00";
        $chunk = 'VP8L' . pack('V', \strlen($vp8l)) . $vp8l;

        return 'RIFF' . pack('V', 4 + \strlen($chunk)) . 'WEBP' . $chunk;
    }

    private static function tenant(int $id): Tenant
    {
        static $tenants = [];
        if (!isset($tenants[$id])) {
            $tenants[$id] = new Tenant();
            (new \ReflectionProperty(Tenant::class, 'id'))->setValue($tenants[$id], $id);
        }

        return $tenants[$id];
    }
}
