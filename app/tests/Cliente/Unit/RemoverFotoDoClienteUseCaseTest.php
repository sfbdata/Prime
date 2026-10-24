<?php

declare(strict_types=1);

namespace App\Tests\Cliente\Unit;

use App\Cliente\Armazenamento\ChavesDeCliente;
use App\Cliente\DTO\FotoDoClienteInput;
use App\Cliente\Entity\Cliente;
use App\Cliente\Entity\ClienteHistorico;
use App\Cliente\Entity\ClientePF;
use App\Cliente\Exception\ClienteNaoEncontradoException;
use App\Cliente\Exception\FotoDoClienteRecusadaException;
use App\Cliente\Exception\SemPermissaoParaEditarClienteException;
use App\Cliente\Repository\ClienteHistoricoRepository;
use App\Cliente\Repository\ClienteRepository;
use App\Cliente\Service\ClienteDaPastaParaFoto;
use App\Cliente\Service\HistoricoDoCliente;
use App\Cliente\UseCase\RemoverFotoDoClienteUseCase;
use App\Entity\Auth\User;
use App\Entity\Permission\AccessRequest;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaMensagem;
use App\Pasta\Repository\PastaRepository;
use App\Pasta\Service\RegistroDoSistemaNaPasta;
use App\Service\PermissionChecker;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\RemocaoAposTransacao;
use App\Tests\Shared\Doubles\ArmazenamentoEmMemoria;
use App\Tests\Shared\Doubles\LoggerEmMemoria;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * "Remover foto" do cabeçalho da pasta: sem confirmação, o cliente deixa de apontar para a foto, a pasta e o cadastro
 * registram, e o arquivo sai do disco só depois do flush. As guardas são as mesmas da troca
 * ({@see TrocarFotoDoClienteUseCaseTest}); aqui ficam as que importam para quem só remove.
 */
#[CoversClass(RemoverFotoDoClienteUseCase::class)]
final class RemoverFotoDoClienteUseCaseTest extends TestCase
{
    private ArmazenamentoEmMemoria $armazenamento;
    private LoggerEmMemoria $logger;
    private Tenant $tenant;
    private Pasta $pasta;
    private ClientePF $cliente;
    private User $autor;

    /** @var list<object> */
    private array $persistidos = [];

    /** @var list<ClienteHistorico> */
    private array $historico = [];

    /** @var list<string> */
    private array $ordem = [];

    protected function setUp(): void
    {
        $this->armazenamento = new ArmazenamentoEmMemoria();
        $this->logger = new LoggerEmMemoria();
        $this->tenant = new Tenant();
        (new \ReflectionProperty(Tenant::class, 'id'))->setValue($this->tenant, 1);
        $this->autor = (new User())->setFullName('Ana Souza');
        $this->cliente = (new ClientePF())->setNomeCompleto('Gleisson Bruno');
        $this->cliente->setTenant($this->tenant);
        (new \ReflectionProperty(Cliente::class, 'id'))->setValue($this->cliente, 5);
        $this->pasta = (new Pasta())->setNup('0001')->setTenant($this->tenant);
        (new \ReflectionProperty(Pasta::class, 'id'))->setValue($this->pasta, 9);
        $this->pasta->addCliente($this->cliente);
    }

    #[TestDox('Remove: o cliente fica sem foto, a pasta e o cadastro registram, e o arquivo sai DEPOIS do flush')]
    public function testRemove(): void
    {
        $chave = $this->comFoto('atual.jpg');
        $this->armazenamento->aoExcluir = function (): void {
            $this->ordem[] = 'excluir';
        };

        $saida = $this->sut()->executar($this->input());

        self::assertNull($this->cliente->getFoto());
        self::assertNull($saida->nome);
        self::assertSame(['flush', 'excluir'], $this->ordem);
        self::assertFalse($this->armazenamento->existe($chave));
        $cartoes = array_values(array_filter($this->persistidos, static fn (object $o): bool => $o instanceof PastaMensagem));
        self::assertCount(1, $cartoes);
        self::assertStringContainsString('Foto do cliente removida', (string) $cartoes[0]->getConteudo());
        self::assertSame(['Ana Souza removeu foto'], array_map(static fn (ClienteHistorico $h): string => $h->getTexto(), $this->historico));
    }

    #[TestDox('Banco recusa o flush: a exceção sobe e o arquivo FICA')]
    public function testFlushQueFalhaNaoApagaOArquivo(): void
    {
        $chave = $this->comFoto('atual.jpg');

        try {
            $this->sut(noFlush: new \RuntimeException('flush recusado'))->executar($this->input());
            self::fail('A recusa do banco tinha de subir.');
        } catch (\RuntimeException) {
        }

        self::assertTrue($this->armazenamento->existe($chave));
        self::assertSame([], $this->armazenamento->excluidas);
    }

    #[TestDox('Cliente sem foto: nada muda, nada é registrado, nada é apagado')]
    public function testSemFotoNaoFazNada(): void
    {
        $this->sut()->executar($this->input());

        self::assertSame([], $this->ordem);
        self::assertSame([], $this->persistidos);
        self::assertSame([], $this->historico);
    }

    #[TestDox('Quem não edita o cliente, cliente fora da pasta e lápide: recusa e o arquivo fica')]
    public function testGuardas(): void
    {
        $chave = $this->comFoto('atual.jpg');

        $recusas = [];
        try {
            $this->sut(editaOCliente: false)->executar($this->input());
        } catch (SemPermissaoParaEditarClienteException $e) {
            $recusas[] = $e::class;
        }
        $this->pasta->marcarExcluida($this->autor, new \DateTimeImmutable());
        try {
            $this->sut()->executar($this->input());
        } catch (FotoDoClienteRecusadaException $e) {
            $recusas[] = $e::class;
        }
        $this->pasta->removeCliente($this->cliente);
        try {
            $this->sut()->executar($this->input());
        } catch (ClienteNaoEncontradoException $e) {
            $recusas[] = $e::class;
        }

        self::assertSame([SemPermissaoParaEditarClienteException::class, FotoDoClienteRecusadaException::class, ClienteNaoEncontradoException::class], $recusas);
        self::assertSame('atual.jpg', $this->cliente->getFoto());
        self::assertTrue($this->armazenamento->existe($chave));
        self::assertSame([], $this->historico);
    }

    private function sut(bool $editaOCliente = true, ?\Throwable $noFlush = null): RemoverFotoDoClienteUseCase
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function (object $entidade): void {
            $this->persistidos[] = $entidade;
        });
        $em->method('flush')->willReturnCallback(function () use ($noFlush): void {
            if ($noFlush !== null) {
                throw $noFlush;
            }
            $this->ordem[] = 'flush';
        });
        $pastas = $this->createStub(PastaRepository::class);
        $pastas->method('find')->willReturnCallback(fn (mixed $id): ?Pasta => $id === 9 ? $this->pasta : null);
        $clientes = $this->createStub(ClienteRepository::class);
        $clientes->method('find')->willReturnCallback(fn (mixed $id): ?Cliente => $id === 5 ? $this->cliente : null);
        $permissoes = $this->createStub(PermissionChecker::class);
        $permissoes->method('canAccessResource')->willReturnCallback(
            static fn (User $u, ?Tenant $t, string $recurso): bool => $recurso === AccessRequest::RESOURCE_PASTA || $editaOCliente,
        );
        $repoHistorico = $this->createStub(ClienteHistoricoRepository::class);
        $repoHistorico->method('registrar')->willReturnCallback(function (ClienteHistorico $h): void {
            $this->historico[] = $h;
        });

        return new RemoverFotoDoClienteUseCase(
            new ClienteDaPastaParaFoto($pastas, $clientes, $permissoes),
            $em,
            new RemocaoAposTransacao($this->armazenamento, $this->logger),
            new RegistroDoSistemaNaPasta($em),
            new HistoricoDoCliente($repoHistorico),
        );
    }

    private function comFoto(string $nome): ChaveDeArquivo
    {
        $this->cliente->trocarFoto($nome, new \DateTimeImmutable('-1 day'));
        $chave = ChavesDeCliente::fotoPorNome($this->cliente, $nome);
        $this->armazenamento->semear($chave, 'foto');

        return $chave;
    }

    private function input(): FotoDoClienteInput
    {
        return new FotoDoClienteInput(9, 5, $this->autor, $this->tenant);
    }
}
