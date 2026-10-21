<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Cliente\Entity\ClientePF;
use App\Cliente\Enum\RotuloDoEmail;
use App\Cliente\Exception\EmailDoClienteEmConflitoException;
use App\Cliente\Exception\EmailDoClienteRecusadoException;
use App\Cliente\Repository\ClienteEmailRepository;
use App\Cliente\Service\CadernoDeEmailsDoCliente;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Service\EmailsDoClienteDaPasta;
use Doctrine\DBAL\Driver\AbstractException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * As escritas nos e-mails do cliente (spec push-avisar-cliente, revisão item 3): a operação roda dentro
 * da transação com o cliente travado, o principal antigo é desmarcado antes do flush e a violação de
 * UNIQUE vira 409 (EmailDoClienteEmConflitoException), nunca 500.
 */
#[CoversClass(EmailsDoClienteDaPasta::class)]
final class EmailsDoClienteDaPastaTest extends TestCase
{
    private Tenant $tenant;
    private Pasta $pasta;

    protected function setUp(): void
    {
        $this->tenant = new Tenant();
        $cliente = new ClientePF();
        $cliente->setNomeCompleto('Maria');
        $cliente->setEmail('');
        $cliente->setTenant($this->tenant);
        $this->pasta = new Pasta();
        $this->pasta->setTenant($this->tenant);
        $this->pasta->addCliente($cliente);
    }

    #[TestDox('A operação roda DENTRO da trava; o principal novo (sem id) faz desmarcar todos antes do flush; as linhas novas vão para gravar')]
    public function testOperacaoDentroDaTrava(): void
    {
        $repositorio = $this->createMock(ClienteEmailRepository::class);
        $travado = false;
        $repositorio->method('listarDoCliente')->willReturn([]);
        $repositorio->expects(self::once())->method('comClienteTravado')->willReturnCallback(function ($cliente, callable $operacao) use (&$travado): mixed {
            $travado = true;

            return $operacao();
        });
        $repositorio->expects(self::once())->method('limparPrincipaisExceto')->with(self::anything(), null);
        $repositorio->expects(self::once())->method('gravar')->with(self::countOf(1), []);

        $resultado = (new EmailsDoClienteDaPasta($repositorio))->alterar($this->pasta, $this->tenant, function (CadernoDeEmailsDoCliente $caderno) use (&$travado): string {
            self::assertTrue($travado, 'lê e altera o caderno só com o cliente travado');

            return $caderno->adicionar('a@cliente.com.br', RotuloDoEmail::Pessoal, new User(), new \DateTimeImmutable());
        });

        self::assertSame('a@cliente.com.br', $resultado);
    }

    #[TestDox('Violação de UNIQUE no flush vira EmailDoClienteEmConflitoException (409)')]
    public function testUniqueViraConflito(): void
    {
        $repositorio = $this->createStub(ClienteEmailRepository::class);
        $repositorio->method('comClienteTravado')->willThrowException(
            new UniqueConstraintViolationException(new class ('duplicado') extends AbstractException {}, null),
        );

        $this->expectException(EmailDoClienteEmConflitoException::class);
        $this->expectExceptionMessage('Os e-mails do cliente mudaram em outra tela ao mesmo tempo. Recarregue e tente de novo.');
        (new EmailsDoClienteDaPasta($repositorio))->alterar($this->pasta, $this->tenant, static fn (): null => null);
    }

    #[TestDox('Pasta sem cliente: 422 "A pasta não tem cliente vinculado."')]
    public function testSemCliente(): void
    {
        $pasta = new Pasta();
        $pasta->setTenant($this->tenant);

        $this->expectException(EmailDoClienteRecusadoException::class);
        $this->expectExceptionMessage('A pasta não tem cliente vinculado.');
        (new EmailsDoClienteDaPasta($this->createStub(ClienteEmailRepository::class)))->alterar($pasta, $this->tenant, static fn (): null => null);
    }
}
