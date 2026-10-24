<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Cliente\Entity\ClientePF;
use App\Entity\Auth\User;
use App\Entity\Permission\AccessRequest;
use App\Entity\Tenant\Tenant;
use App\Pasta\Armazenamento\ChavesDePasta;
use App\Pasta\DTO\FotoDaPastaInput;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaMensagem;
use App\Pasta\Exception\FotoDaPastaInvalidaException;
use App\Pasta\Exception\FotoDaPastaRecusadaException;
use App\Pasta\Exception\PastaDeOutroEscritorioException;
use App\Pasta\Exception\SemPermissaoParaEditarPastaException;
use App\Pasta\Exception\SemPermissaoParaVerPastaException;
use App\Pasta\Repository\PastaRepository;
use App\Pasta\Service\PastaParaFoto;
use App\Pasta\Service\RegistroDoSistemaNaPasta;
use App\Pasta\UseCase\RemoverFotoDaPastaUseCase;
use App\Pasta\UseCase\TrocarFotoDaPastaUseCase;
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
 * Foto do cabeçalho da pasta SEM cliente cadastrado: guardas (escritório, ver e editar a pasta, lápide, pasta com
 * cliente), a mesma regra de imagem da foto do cliente, gravação no escopo do escritório (PASTA_FOTO), cartão no
 * Registro da pasta e a anterior apagada só depois do COMMIT. A `TransacaoComArquivoNovo` é a REAL; EntityManager e
 * conexão são dublês que se comportam como a transação (o molde de `TrocarFotoDoClienteUseCaseTest`).
 */
#[CoversClass(TrocarFotoDaPastaUseCase::class)]
#[CoversClass(RemoverFotoDaPastaUseCase::class)]
#[CoversClass(PastaParaFoto::class)]
final class FotoDaPastaUseCaseTest extends TestCase
{
    private ArmazenamentoEmMemoria $armazenamento;
    private LoggerEmMemoria $logger;
    private Tenant $tenant;
    private Pasta $pasta;
    private User $autor;
    private string $dirTemp;

    /** @var list<object> */
    private array $persistidos = [];

    /** @var list<string> */
    private array $ordem = [];

    protected function setUp(): void
    {
        $this->dirTemp = sys_get_temp_dir() . '/foto-pasta-' . bin2hex(random_bytes(6));
        mkdir($this->dirTemp, 0o700, true);
        $this->armazenamento = new ArmazenamentoEmMemoria();
        $this->logger = new LoggerEmMemoria();
        $this->tenant = self::tenant(1);
        $this->autor = (new User())->setFullName('Ana Souza');
        $this->pasta = (new Pasta())->setNup('0001')->setTenant($this->tenant);
        (new \ReflectionProperty(Pasta::class, 'id'))->setValue($this->pasta, 9);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dirTemp . '/*') ?: [] as $arquivo) {
            @unlink($arquivo);
        }
        @rmdir($this->dirTemp);
    }

    #[TestDox('trocar: grava no escopo do escritório (PASTA_FOTO), aponta a pasta e registra "Dados da pasta: Foto do cliente alterada"')]
    public function testTrocaGravaERegistra(): void
    {
        $saida = $this->trocar()->executar($this->input($this->jpeg()));

        $chave = $this->armazenamento->ultimaGravada();
        self::assertSame([CategoriaDeArquivo::PASTA_FOTO, 1], [$chave->categoria, $chave->escopo->tenantIdOuNull()]);
        self::assertSame($chave->nome, $this->pasta->getFoto());
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}\.jpg$/', (string) $this->pasta->getFoto());
        self::assertNotNull($this->pasta->getFotoAtualizadaEm());
        self::assertSame([9, $chave->nome], [$saida->pastaId, $saida->nome]);
        $cartoes = $this->cartoes();
        self::assertCount(1, $cartoes);
        self::assertSame('pasta', $cartoes[0]->getSistema());
        self::assertStringContainsString('Foto do cliente alterada', (string) $cartoes[0]->getConteudo());
    }

    #[TestDox('trocar: grava a nova, COMMIT, e só depois apaga a anterior (pelo nome guardado)')]
    public function testAnteriorSaiDepoisDoCommit(): void
    {
        $antiga = $this->comFotoAnterior('antiga.jpg');
        $this->armazenamento->aoExcluir = function (): void {
            $this->ordem[] = 'excluir-anterior';
        };

        $this->trocar()->executar($this->input($this->jpeg()));

        self::assertSame(['commit', 'excluir-anterior'], $this->ordem);
        self::assertFalse($this->armazenamento->existe($antiga));
        self::assertTrue($this->armazenamento->existe($this->armazenamento->ultimaGravada()), 'a recém-gravada fica');
    }

    #[TestDox('trocar: o banco recusa o flush, a exceção sobe, a nova sai do disco e a anterior FICA')]
    public function testFlushQueFalhaNaoApagaAAnterior(): void
    {
        $antiga = $this->comFotoAnterior('antiga.jpg');
        $recusa = new \RuntimeException('flush recusado');

        try {
            $this->trocar(noFlush: $recusa)->executar($this->input($this->jpeg()));
            self::fail('A recusa do banco tinha de subir.');
        } catch (\RuntimeException $e) {
            self::assertSame($recusa, $e);
        }

        self::assertTrue($this->armazenamento->existe($antiga));
        self::assertFalse($this->armazenamento->existe($this->armazenamento->ultimaGravada()), 'a nova não fica órfã');
    }

    #[TestDox('trocar: GIF e arquivo ausente são recusados (422) sem gravar nada')]
    public function testImagemRecusada(): void
    {
        $gif = "GIF89a\x01\x00\x01\x00\x80\x00\x00\x00\x00\x00\xff\xff\xff!\xf9\x04\x01\x00\x00\x00\x00,\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02D\x01\x00;";
        foreach ([$this->input($gif), new FotoDaPastaInput(9, $this->autor, $this->tenant)] as $input) {
            try {
                $this->trocar()->executar($input);
                self::fail('Esperava a recusa.');
            } catch (FotoDaPastaInvalidaException) {
            }
        }

        self::assertSame([], $this->armazenamento->gravadas);
        self::assertNull($this->pasta->getFoto());
        self::assertSame([], $this->persistidos);
    }

    #[TestDox('trocar: pasta COM cliente cadastrado recusa (a foto nova é do cliente), sem gravar')]
    public function testPastaComClienteRecusa(): void
    {
        $cliente = new ClientePF();
        $cliente->setTenant($this->tenant);
        $this->pasta->addCliente($cliente);

        $this->expectException(FotoDaPastaRecusadaException::class);
        try {
            $this->trocar()->executar($this->input($this->jpeg()));
        } finally {
            self::assertSame([], $this->armazenamento->gravadas);
        }
    }

    /** @return iterable<string, array{class-string<\Throwable>, \Closure(self): void, 2?: bool, 3?: bool}> */
    public static function guardas(): iterable
    {
        yield 'pasta de outro escritório' => [PastaDeOutroEscritorioException::class, static fn (self $t) => $t->pasta->setTenant(self::tenant(2))];
        yield 'quem pede não vê a pasta' => [SemPermissaoParaVerPastaException::class, static fn (self $t) => null, false, false];
        yield 'quem pede não edita a pasta' => [SemPermissaoParaEditarPastaException::class, static fn (self $t) => null, true, false];
        yield 'pasta excluída (lápide)' => [FotoDaPastaRecusadaException::class, static fn (self $t) => $t->pasta->marcarExcluida($t->autor, new \DateTimeImmutable())];
    }

    /**
     * @param class-string<\Throwable> $excecao
     * @param \Closure(self): void     $estragar
     */
    #[DataProvider('guardas')]
    #[TestDox('Recusa ($_dataName) antes de gravar ou remover qualquer coisa')]
    public function testGuardas(string $excecao, \Closure $estragar, bool $ve = true, bool $edita = true): void
    {
        $antiga = $this->comFotoAnterior('antiga.jpg');
        $estragar($this);

        foreach ([fn () => $this->trocar($ve, $edita)->executar($this->input($this->jpeg())), fn () => $this->remover($ve, $edita)->executar($this->input(null))] as $acao) {
            try {
                $acao();
                self::fail('Esperava a recusa.');
            } catch (\Throwable $e) {
                self::assertInstanceOf($excecao, $e);
            }
        }

        self::assertSame([], $this->armazenamento->gravadas);
        self::assertSame('antiga.jpg', $this->pasta->getFoto());
        self::assertTrue($this->armazenamento->existe($antiga));
    }

    #[TestDox('remover: a pasta deixa de apontar, registra "Foto do cliente removida" e o arquivo sai depois do flush')]
    public function testRemover(): void
    {
        $antiga = $this->comFotoAnterior('antiga.jpg');

        $saida = $this->remover()->executar($this->input(null));

        self::assertNull($saida->nome);
        self::assertNull($this->pasta->getFoto());
        self::assertFalse($this->armazenamento->existe($antiga));
        self::assertStringContainsString('Foto do cliente removida', (string) $this->cartoes()[0]->getConteudo());
    }

    #[TestDox('remover: pasta sem foto não muda nada nem registra')]
    public function testRemoverSemFoto(): void
    {
        $this->remover()->executar($this->input(null));

        self::assertSame([], $this->persistidos);
        self::assertSame([], $this->armazenamento->excluidas);
    }

    // ---------------------------------------------------------------- helpers

    private function guarda(bool $ve, bool $edita): PastaParaFoto
    {
        $pastas = $this->createStub(PastaRepository::class);
        $pastas->method('find')->willReturnCallback(fn (mixed $id): ?Pasta => $id === 9 ? $this->pasta : null);
        $permissoes = $this->createStub(PermissionChecker::class);
        $permissoes->method('canAccessResource')->willReturnCallback(
            static fn (User $u, ?Tenant $t, string $recurso, int $id, string $acao): bool => $recurso === AccessRequest::RESOURCE_PASTA
                && ($acao === AccessRequest::ACTION_VIEW ? $ve : $edita),
        );

        return new PastaParaFoto($pastas, $permissoes);
    }

    private function trocar(bool $ve = true, bool $edita = true, ?\Throwable $noFlush = null): TrocarFotoDaPastaUseCase
    {
        $em = $this->em($noFlush);
        $remocao = new RemocaoAposTransacao($this->armazenamento, $this->logger);

        return new TrocarFotoDaPastaUseCase(
            $this->guarda($ve, $edita),
            $this->armazenamento,
            new TransacaoComArquivoNovo($em, new ConsultaDeDestinoFixa(DestinoDaTransacao::NaoConfirmada), $remocao, $this->logger),
            $remocao,
            new RegistroDoSistemaNaPasta($em),
        );
    }

    private function remover(bool $ve = true, bool $edita = true): RemoverFotoDaPastaUseCase
    {
        $em = $this->em(null);

        return new RemoverFotoDaPastaUseCase(
            $this->guarda($ve, $edita),
            $em,
            new RemocaoAposTransacao($this->armazenamento, $this->logger),
            new RegistroDoSistemaNaPasta($em),
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

    /** @return list<PastaMensagem> */
    private function cartoes(): array
    {
        return array_values(array_filter($this->persistidos, static fn (object $o): bool => $o instanceof PastaMensagem));
    }

    private function comFotoAnterior(string $nome): ChaveDeArquivo
    {
        $this->pasta->trocarFoto($nome, new \DateTimeImmutable('-1 day'));
        $chave = ChavesDePasta::fotoPorNome($this->pasta, $nome);
        $this->armazenamento->semear($chave, 'antiga');

        return $chave;
    }

    /** Upload real em modo de teste (pula `is_uploaded_file`); `null` = pedido sem arquivo (remover). */
    private function input(?string $conteudo): FotoDaPastaInput
    {
        if ($conteudo === null) {
            return new FotoDaPastaInput(9, $this->autor, $this->tenant);
        }
        $caminho = $this->dirTemp . '/' . bin2hex(random_bytes(6));
        file_put_contents($caminho, $conteudo);

        return new FotoDaPastaInput(9, $this->autor, $this->tenant, new UploadedFile($caminho, 'foto.jpg', null, null, true));
    }

    private function jpeg(): string
    {
        ob_start();
        imagejpeg(imagecreatetruecolor(4, 4), null, 88);

        return (string) ob_get_clean();
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
