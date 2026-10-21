<?php

declare(strict_types=1);

namespace App\Tests\Cliente\Unit;

use App\Cliente\Entity\Cliente;
use App\Cliente\Entity\ClienteDocumento;
use App\Cliente\Entity\ClientePF;
use App\Cliente\Exception\ClienteNaoEncontradoException;
use App\Cliente\Exception\DocumentoDaPastaNaoEncontradoException;
use App\Cliente\Exception\SemPermissaoParaEditarClienteException;
use App\Cliente\Repository\ClienteHistoricoRepository;
use App\Cliente\Repository\DocumentoVinculadoRepository;
use App\Cliente\Service\DocumentosVisiveisDoCadastro;
use App\Cliente\Service\HistoricoDoCliente;
use App\Cliente\Service\RegrasDosEncontrados;
use App\Cliente\UseCase\VincularDocumentoDaPastaAoClienteUseCase;
use App\Entity\Auth\User;
use App\Entity\Permission\AccessRequest;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Repository\PastaDocumentoRepository;
use App\Service\PermissionChecker;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * "Vincular ao cadastro" (F-A, spec cliente-inteligencia-cadastral §2.3): quem edita o cliente e vê a pasta vincula um
 * "Encontrado"; o POST repete as regras da tela (CPF válido, categoria que o cadastro ainda não mostra). O caminho com o
 * banco (índice único na corrida) está no functional do controller.
 */
#[CoversClass(VincularDocumentoDaPastaAoClienteUseCase::class)]
#[CoversClass(RegrasDosEncontrados::class)]
final class VincularDocumentoDaPastaAoClienteUseCaseTest extends TestCase
{
    private const CPF_VALIDO = '529.982.247-25';

    #[TestDox('vincula o documento de identificação da pasta: cria o vínculo sem arquivo próprio')]
    public function testVincula(): void
    {
        [$cliente, $documento] = $this->clienteNaPasta(self::CPF_VALIDO, ClienteDocumento::CATEGORIA_IDENTIFICACAO);

        self::assertTrue($this->sut($documento)->executar($cliente, 5, new User(), self::tenant(1)));

        $vinculo = $cliente->getDocumentos()->first();
        self::assertInstanceOf(ClienteDocumento::class, $vinculo);
        self::assertSame($documento, $vinculo->getPastaDocumento());
    }

    #[TestDox('o mesmo documento já vinculado: não duplica (false)')]
    public function testJaVinculado(): void
    {
        [$cliente, $documento] = $this->clienteNaPasta(self::CPF_VALIDO, ClienteDocumento::CATEGORIA_IDENTIFICACAO);

        self::assertFalse($this->sut($documento, vinculados: [5])->executar($cliente, 5, new User(), self::tenant(1)));
        self::assertCount(0, $cliente->getDocumentos());
    }

    #[TestDox('cliente de outro escritório: não encontrado')]
    public function testOutroEscritorio(): void
    {
        [$cliente, $documento] = $this->clienteNaPasta(self::CPF_VALIDO, ClienteDocumento::CATEGORIA_IDENTIFICACAO);

        $this->expectException(ClienteNaoEncontradoException::class);
        $this->sut($documento)->executar($cliente, 5, new User(), self::tenant(2));
    }

    #[TestDox('sem permissão de editar o cliente: recusa')]
    public function testSemEdicao(): void
    {
        [$cliente, $documento] = $this->clienteNaPasta(self::CPF_VALIDO, ClienteDocumento::CATEGORIA_IDENTIFICACAO);

        $this->expectException(SemPermissaoParaEditarClienteException::class);
        $this->sut($documento, podeEditar: false)->executar($cliente, 5, new User(), self::tenant(1));
    }

    /** @return iterable<string, array{0: callable(Cliente, PastaDocumento): void, 1?: bool}> */
    public static function naoEncontrados(): iterable
    {
        yield 'documento na lixeira' => [static fn (Cliente $c, PastaDocumento $d) => $d->marcarExcluido(new User(), new \DateTimeImmutable())];
        yield 'categoria fora da F-A' => [static fn (Cliente $c, PastaDocumento $d) => $d->setCategoria('PROCURACAO')];
        yield 'cliente fora da pasta' => [static fn (Cliente $c, PastaDocumento $d) => $d->getPasta()?->removeCliente($c)];
        yield 'quem pede não vê a pasta' => [static fn (Cliente $c, PastaDocumento $d) => null, false];
        yield 'CPF inválido (regra da tela)' => [static fn (Cliente $c, PastaDocumento $d) => $c instanceof ClientePF ? $c->setCpf('111.111.111-11') : null];
        yield 'categoria que o cadastro já mostra (regra da tela)' => [static function (Cliente $c, PastaDocumento $d): void {
            $proprio = new ClienteDocumento();
            $proprio->setCategoria(ClienteDocumento::CATEGORIA_IDENTIFICACAO);
            $c->getDocumentos()->add($proprio);
        }];
    }

    /** @param callable(Cliente, PastaDocumento): void $estragar */
    #[DataProvider('naoEncontrados')]
    #[TestDox('não é "Encontrado" ($_dataName): não encontrado e nada é gravado')]
    public function testNaoEncontrado(callable $estragar, bool $veAPasta = true): void
    {
        [$cliente, $documento] = $this->clienteNaPasta(self::CPF_VALIDO, ClienteDocumento::CATEGORIA_IDENTIFICACAO);
        $estragar($cliente, $documento);
        $antes = $cliente->getDocumentos()->count();

        try {
            $this->sut($documento, veAPasta: $veAPasta)->executar($cliente, 5, new User(), self::tenant(1));
            self::fail('devia recusar');
        } catch (DocumentoDaPastaNaoEncontradoException) {
            self::assertCount($antes, $cliente->getDocumentos());
        }
    }

    /** @param list<int> $vinculados */
    private function sut(PastaDocumento $documento, array $vinculados = [], bool $podeEditar = true, bool $veAPasta = true): VincularDocumentoDaPastaAoClienteUseCase
    {
        $docs = $this->createStub(PastaDocumentoRepository::class);
        $docs->method('findByIdAndTenant')->willReturnCallback(static fn (int $id): ?PastaDocumento => $id === 5 ? $documento : null);
        $repoVinculados = $this->createStub(DocumentoVinculadoRepository::class);
        $repoVinculados->method('idsDePastaDocumentoVinculados')->willReturn($vinculados);
        $permissoes = $this->createStub(PermissionChecker::class);
        $permissoes->method('canAccessResource')->willReturnCallback(
            static fn (User $u, ?Tenant $t, string $recurso): bool => $recurso === AccessRequest::RESOURCE_CLIENTE ? $podeEditar : $veAPasta,
        );

        return new VincularDocumentoDaPastaAoClienteUseCase(
            $docs,
            $repoVinculados,
            $this->createStub(EntityManagerInterface::class),
            $permissoes,
            new HistoricoDoCliente($this->createStub(ClienteHistoricoRepository::class)),
            new RegrasDosEncontrados(new DocumentosVisiveisDoCadastro($permissoes)),
        );
    }

    /** @return array{0: Cliente, 1: PastaDocumento} */
    private function clienteNaPasta(string $cpf, string $categoria): array
    {
        $cliente = (new ClientePF())->setNomeCompleto('José da Silva')->setCpf($cpf);
        $cliente->setTenant(self::tenant(1));
        (new \ReflectionProperty(Cliente::class, 'id'))->setValue($cliente, 1);
        $pasta = (new Pasta())->setNup('0001')->setTenant(self::tenant(1));
        (new \ReflectionProperty(Pasta::class, 'id'))->setValue($pasta, 9);
        $pasta->addCliente($cliente);
        $documento = (new PastaDocumento())->setPasta($pasta)->setTenant(self::tenant(1))->setCategoria($categoria)->setNomeOriginal('rg.pdf')->setTitulo('RG')->setMimeType('application/pdf');
        (new \ReflectionProperty(PastaDocumento::class, 'id'))->setValue($documento, 5);

        return [$cliente, $documento];
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
