<?php

declare(strict_types=1);

namespace App\Tests\Central\Unit;

use App\Central\Exception\PastaDaIaNaoEncontradaException;
use App\Central\Exception\SemAcessoACentralException;
use App\Central\Repository\ParticipanteRepository;
use App\Central\UseCase\DescreverPastaDoContextoDaIaUseCase;
use App\Cliente\Entity\ClientePF;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Repository\PastaRepository;
use App\Tests\Central\Support\MontaEncaminhamentoTrait;
use App\Tests\Inteligencia\Support\DefineId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/** O rótulo da barra "Pasta N" da conversa com a IA (PR 4b): as guardas da pergunta, sem montar contexto. */
#[CoversClass(DescreverPastaDoContextoDaIaUseCase::class)]
final class DescreverPastaDoContextoDaIaUseCaseTest extends TestCase
{
    use MontaEncaminhamentoTrait;

    private Tenant $tenant;
    private Pasta $pasta;
    private User $eu;

    protected function setUp(): void
    {
        $this->tenant = new Tenant();
        DefineId::em($this->tenant, 7);
        $this->pasta = $this->pastaDe($this->tenant);
        $this->eu = $this->pessoa(1, 'Ana Lima');
    }

    /** @param list<int> $quemVe @param list<User> $ativos */
    private function useCase(array $quemVe = [1], ?array $ativos = null): DescreverPastaDoContextoDaIaUseCase
    {
        $pastas = $this->createMock(PastaRepository::class);
        // Como o repositório de verdade: só devolve a pasta do escritório pedido.
        $pastas->method('findOneBy')->willReturnCallback(
            fn (array $c): ?Pasta => ($c['id'] ?? null) === 50 && ($c['tenant'] ?? null) === $this->pasta->getTenant() ? $this->pasta : null,
        );

        return new DescreverPastaDoContextoDaIaUseCase(
            $this->acessoACentral($ativos ?? [$this->eu], $this->createStub(ParticipanteRepository::class)),
            $pastas,
            $this->acessoAPasta($quemVe),
        );
    }

    #[TestDox('Pasta que eu vejo: só pastaId, NUP e o cliente principal')]
    public function testOk(): void
    {
        $cliente = new ClientePF();
        $cliente->setTenant($this->tenant);
        $cliente->setNomeCompleto('Maria da Silva');
        $this->pasta->addCliente($cliente);

        $saida = $this->useCase()->executar($this->eu, $this->tenant, 50);

        self::assertSame(50, $saida->pastaId);
        self::assertSame('PASTA-50', $saida->nup);
        self::assertSame('MARIA DA SILVA', $saida->cliente);
        self::assertSame(['pastaId', 'nup', 'cliente'], array_keys(get_object_vars($saida)));
    }

    #[TestDox('Pasta sem cliente: cliente nulo (a barra mostra só o NUP)')]
    public function testSemCliente(): void
    {
        self::assertNull($this->useCase()->executar($this->eu, $this->tenant, 50)->cliente);
    }

    #[TestDox('Sem vínculo ativo no escritório: SemAcessoACentralException (403), antes de olhar a pasta')]
    public function testSemVinculo(): void
    {
        $this->expectException(SemAcessoACentralException::class);
        $this->useCase(ativos: [])->executar($this->eu, $this->tenant, 50);
    }

    #[TestDox('Pasta de OUTRO escritório: o mesmo 404 da inexistente')]
    public function testOutroTenant(): void
    {
        $outro = new Tenant();
        DefineId::em($outro, 99);
        $this->pasta->setTenant($outro);

        $this->expectException(PastaDaIaNaoEncontradaException::class);
        $this->expectExceptionMessage('Não encontrei essa pasta ou você não tem acesso.');
        $this->useCase()->executar($this->eu, $this->tenant, 50);
    }

    #[TestDox('Pasta inexistente ou id inválido: 404')]
    public function testInexistente(): void
    {
        foreach ([999, 0, -4] as $id) {
            try {
                $this->useCase()->executar($this->eu, $this->tenant, $id);
                self::fail("Devia recusar $id.");
            } catch (PastaDaIaNaoEncontradaException $e) {
                self::assertSame(PastaDaIaNaoEncontradaException::MENSAGEM, $e->getMessage());
            }
        }
    }

    #[TestDox('Pasta excluída (lápide): 404')]
    public function testExcluida(): void
    {
        $this->pasta->marcarExcluida($this->eu, new \DateTimeImmutable());

        $this->expectException(PastaDaIaNaoEncontradaException::class);
        $this->useCase()->executar($this->eu, $this->tenant, 50);
    }

    #[TestDox('Pasta que eu não vejo (cadeado/permissão): o mesmo 404, sem dizer que existe')]
    public function testSemAcesso(): void
    {
        try {
            $this->useCase(quemVe: [])->executar($this->eu, $this->tenant, 50);
            self::fail('Devia recusar.');
        } catch (PastaDaIaNaoEncontradaException $e) {
            self::assertSame(PastaDaIaNaoEncontradaException::MENSAGEM, $e->getMessage());
            self::assertStringNotContainsString('PASTA-50', $e->getMessage());
        }
    }
}
