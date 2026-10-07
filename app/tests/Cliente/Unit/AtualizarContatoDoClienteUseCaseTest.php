<?php

declare(strict_types=1);

namespace App\Tests\Cliente\Unit;

use App\Cliente\DTO\AtualizarContatoDoClienteInput;
use App\Cliente\Entity\ClientePF;
use App\Cliente\Exception\ClienteNaoEncontradoException;
use App\Cliente\Exception\ContatoInvalidoException;
use App\Cliente\Repository\ClienteRepository;
use App\Cliente\UseCase\AtualizarContatoDoClienteUseCase;
use App\Entity\Tenant\Tenant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;

/**
 * Edição inline de UM dos 3 slots de contato do cliente (janela "Detalhes do
 * cliente"). Validador real (`Validation::createValidator()`), repositório
 * mockado: o que importa aqui é o valor que chega ao setter e se grava ou não.
 */
#[CoversClass(AtualizarContatoDoClienteUseCase::class)]
final class AtualizarContatoDoClienteUseCaseTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function telefones(): iterable
    {
        yield 'celular só dígitos'        => ['61984004003', '(61) 98400-4003'];
        yield 'celular já mascarado'      => ['(61) 98400-4003', '(61) 98400-4003'];
        yield 'fixo de 10 dígitos'        => ['61 3425-8844', '(61) 3425-8844'];
        yield 'com espaços em volta'      => ['  61984004003 ', '(61) 98400-4003'];
    }

    #[DataProvider('telefones')]
    #[TestDox('celular: grava com a máscara do desenho ($valor → $esperado)')]
    public function testCelularGravaComMascara(string $valor, string $esperado): void
    {
        [$useCase, $cliente, $tenant] = $this->montar(gravaUmaVez: true);

        $useCase->executar(new AtualizarContatoDoClienteInput(10, 'celular', $valor), $tenant);

        self::assertSame($esperado, $cliente->getTelefoneCelular());
    }

    #[TestDox('fixo: grava no slot do fixo, sem tocar o celular')]
    public function testFixoGravaNoSlotDoFixo(): void
    {
        [$useCase, $cliente, $tenant] = $this->montar(gravaUmaVez: true);
        $cliente->setTelefoneCelular('(61) 98400-4003');

        $useCase->executar(new AtualizarContatoDoClienteInput(10, 'fixo', '6134258844'), $tenant);

        self::assertSame('(61) 3425-8844', $cliente->getTelefoneFixo());
        self::assertSame('(61) 98400-4003', $cliente->getTelefoneCelular());
    }

    #[TestDox('telefone vazio remove (vira nulo)')]
    public function testTelefoneVazioRemove(): void
    {
        [$useCase, $cliente, $tenant] = $this->montar(gravaUmaVez: true);
        $cliente->setTelefoneFixo('(61) 3425-8844');

        $useCase->executar(new AtualizarContatoDoClienteInput(10, 'fixo', '   '), $tenant);

        self::assertNull($cliente->getTelefoneFixo());
    }

    #[TestDox('e-mail: validado e gravado em minúsculas')]
    public function testEmailGravaMinusculo(): void
    {
        [$useCase, $cliente, $tenant] = $this->montar(gravaUmaVez: true);

        $useCase->executar(new AtualizarContatoDoClienteInput(10, 'email', ' Maria.Silva@Exemplo.COM.br '), $tenant);

        self::assertSame('maria.silva@exemplo.com.br', $cliente->getEmail());
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidos(): iterable
    {
        yield 'telefone sem DDD'        => ['celular', '98400-4003'];
        yield 'telefone longo demais'   => ['fixo', '5561984004003'];
        yield 'telefone sem dígito'     => ['celular', 'abc'];
        yield 'e-mail sem arroba'       => ['email', 'maria.exemplo.com'];
        yield 'e-mail sem domínio'      => ['email', 'maria@exemplo'];
        yield 'e-mail vazio'            => ['email', ''];
        yield 'campo desconhecido'      => ['cpf', '12345678901'];
    }

    #[DataProvider('invalidos')]
    #[TestDox('recusa $campo = "$valor" e não grava nada')]
    public function testValorInvalidoNaoGrava(string $campo, string $valor): void
    {
        [$useCase, $cliente, $tenant] = $this->montar(gravaUmaVez: false);
        $cliente->setTelefoneCelular('(61) 98400-4003');
        $cliente->setTelefoneFixo('(61) 3425-8844');

        try {
            $useCase->executar(new AtualizarContatoDoClienteInput(10, $campo, $valor), $tenant);
            self::fail('devia recusar');
        } catch (ContatoInvalidoException) {
        }

        self::assertSame('(61) 98400-4003', $cliente->getTelefoneCelular());
        self::assertSame('(61) 3425-8844', $cliente->getTelefoneFixo());
        self::assertSame('original@exemplo.com', $cliente->getEmail());
    }

    #[TestDox('cliente de outro escritório: não encontrado, nada gravado')]
    public function testOutroTenantNaoEncontrado(): void
    {
        [$useCase, $cliente] = $this->montar(gravaUmaVez: false);
        $outro = $this->tenant(99);

        $this->expectException(ClienteNaoEncontradoException::class);
        try {
            $useCase->executar(new AtualizarContatoDoClienteInput(10, 'email', 'novo@exemplo.com'), $outro);
        } finally {
            self::assertSame('original@exemplo.com', $cliente->getEmail());
        }
    }

    #[TestDox('cliente inexistente: não encontrado')]
    public function testInexistente(): void
    {
        $repo = $this->createMock(ClienteRepository::class);
        $repo->method('find')->willReturn(null);
        $repo->expects(self::never())->method('save');
        $useCase = new AtualizarContatoDoClienteUseCase($repo, Validation::createValidator());

        $this->expectException(ClienteNaoEncontradoException::class);
        $useCase->executar(new AtualizarContatoDoClienteInput(10, 'celular', '61984004003'), $this->tenant(1));
    }

    // ----------------------------------------------------------------- helpers

    /** @return array{AtualizarContatoDoClienteUseCase, ClientePF, Tenant} */
    private function montar(bool $gravaUmaVez): array
    {
        $tenant  = $this->tenant(1);
        $cliente = new ClientePF();
        $cliente->setTenant($tenant);
        $cliente->setEmail('original@exemplo.com');

        $repo = $this->createMock(ClienteRepository::class);
        $repo->method('find')->with(10)->willReturn($cliente);
        $repo->expects($gravaUmaVez ? self::once() : self::never())
            ->method('save')
            ->with($cliente, true);

        return [new AtualizarContatoDoClienteUseCase($repo, Validation::createValidator()), $cliente, $tenant];
    }

    private function tenant(int $id): Tenant
    {
        $tenant = new Tenant();
        $ref    = new \ReflectionProperty(Tenant::class, 'id');
        $ref->setValue($tenant, $id);

        return $tenant;
    }
}
