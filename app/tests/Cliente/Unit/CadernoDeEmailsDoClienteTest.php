<?php

declare(strict_types=1);

namespace App\Tests\Cliente\Unit;

use App\Cliente\Entity\ClienteEmail;
use App\Cliente\Entity\ClientePF;
use App\Cliente\Enum\RotuloDoEmail;
use App\Cliente\Exception\EmailDoClienteNaoEncontradoException;
use App\Cliente\Exception\EmailDoClienteRecusadoException;
use App\Cliente\Service\CadernoDeEmailsDoCliente;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * As regras da lista de e-mails do cliente (spec push-avisar-cliente §1 e §5): limite de 5, principal
 * único, normalização, validações na ordem do desenho e o item virtual do cadastro.
 */
#[CoversClass(CadernoDeEmailsDoCliente::class)]
#[CoversClass(ClienteEmail::class)]
final class CadernoDeEmailsDoClienteTest extends TestCase
{
    private Tenant $tenant;
    private User $usuario;
    private \DateTimeImmutable $agora;

    protected function setUp(): void
    {
        $this->tenant = new Tenant();
        $this->usuario = new User();
        $this->agora = new \DateTimeImmutable('2026-10-19 14:30');
    }

    private function cliente(string $emailDaFicha = ''): ClientePF
    {
        $cliente = new ClientePF();
        $cliente->setNomeCompleto('Maria da Silva');
        $cliente->setEmail($emailDaFicha);
        $cliente->setTenant($this->tenant);

        return $cliente;
    }

    /** @param list<ClienteEmail> $linhas */
    private function caderno(ClientePF $cliente, array $linhas = []): CadernoDeEmailsDoCliente
    {
        return new CadernoDeEmailsDoCliente($this->tenant, $cliente, $linhas);
    }

    /** @return list<string> */
    private function enderecos(CadernoDeEmailsDoCliente $caderno): array
    {
        return array_map(static fn (ClienteEmail $e): string => $e->getEmail(), $caderno->itens());
    }

    #[TestDox('Normaliza (minúsculo, sem espaço) e o primeiro cadastrado vira principal, recebendo avisos')]
    public function testNormalizaEPrimeiroPrincipal(): void
    {
        $caderno = $this->caderno($this->cliente());

        self::assertSame('maria@cliente.com.br', $caderno->adicionar('  Maria@Cliente.COM.br ', RotuloDoEmail::Trabalho, $this->usuario, $this->agora));
        $caderno->adicionar('outro@cliente.com.br', RotuloDoEmail::Pessoal, $this->usuario, $this->agora);

        [$primeiro, $segundo] = $caderno->itens();
        self::assertTrue($primeiro->ehPrincipal());
        self::assertTrue($primeiro->recebeAvisos());
        self::assertSame(RotuloDoEmail::Trabalho, $primeiro->getRotulo());
        self::assertFalse($segundo->ehPrincipal());
        self::assertCount(2, $caderno->novas());
    }

    /** @return iterable<string, array{string}> */
    public static function invalidos(): iterable
    {
        yield 'sem arroba' => ['maria.cliente.com.br'];
        yield 'sem domínio' => ['maria@cliente'];
        yield 'vazio' => ['   '];
        yield 'com espaço' => ['ma ria@cliente.com.br'];
        yield 'longo demais' => [str_repeat('a', 180) . '@cliente.com.br'];
    }

    #[DataProvider('invalidos')]
    #[TestDox('E-mail inválido: "Digite um e-mail válido."')]
    public function testInvalido(string $email): void
    {
        $this->expectException(EmailDoClienteRecusadoException::class);
        $this->expectExceptionMessage('Digite um e-mail válido.');

        $this->caderno($this->cliente())->adicionar($email, RotuloDoEmail::Pessoal, $this->usuario, $this->agora);
    }

    #[TestDox('Repetido (mesmo depois de normalizar): "Este e-mail já está cadastrado."')]
    public function testRepetido(): void
    {
        $caderno = $this->caderno($this->cliente());
        $caderno->adicionar('maria@cliente.com.br', RotuloDoEmail::Pessoal, $this->usuario, $this->agora);

        $this->expectExceptionMessage('Este e-mail já está cadastrado.');
        $caderno->adicionar('MARIA@cliente.com.br', RotuloDoEmail::Outro, $this->usuario, $this->agora);
    }

    #[TestDox('Limite de 5 por cliente: o sexto é recusado com "Limite de 5 e-mails por cliente." (inválido é checado antes)')]
    public function testLimite(): void
    {
        $caderno = $this->caderno($this->cliente());
        for ($i = 1; $i <= 5; ++$i) {
            $caderno->adicionar('email' . $i . '@cliente.com.br', RotuloDoEmail::Pessoal, $this->usuario, $this->agora);
        }

        try {
            $caderno->adicionar('invalido', RotuloDoEmail::Pessoal, $this->usuario, $this->agora);
            self::fail('o inválido vem antes do limite');
        } catch (EmailDoClienteRecusadoException $e) {
            self::assertSame('Digite um e-mail válido.', $e->getMessage());
        }

        $this->expectExceptionMessage('Limite de 5 e-mails por cliente.');
        $caderno->adicionar('sexto@cliente.com.br', RotuloDoEmail::Pessoal, $this->usuario, $this->agora);
    }

    #[TestDox('Principal único: tornar outro principal desmarca o anterior; remover o principal promove o primeiro que sobra')]
    public function testPrincipalUnico(): void
    {
        $caderno = $this->caderno($this->cliente());
        $caderno->adicionar('a@cliente.com.br', RotuloDoEmail::Pessoal, $this->usuario, $this->agora);
        $caderno->adicionar('b@cliente.com.br', RotuloDoEmail::Pessoal, $this->usuario, $this->agora);
        $caderno->adicionar('c@cliente.com.br', RotuloDoEmail::Pessoal, $this->usuario, $this->agora);
        [$a, $b, $c] = $caderno->itens();

        $caderno->tornarPrincipal($c);
        self::assertSame([false, false, true], array_map(static fn (ClienteEmail $e): bool => $e->ehPrincipal(), $caderno->itens()));

        $caderno->remover($c, $this->agora);
        self::assertSame(['a@cliente.com.br', 'b@cliente.com.br'], $this->enderecos($caderno));
        self::assertTrue($a->ehPrincipal());
        self::assertFalse($b->ehPrincipal());
    }

    #[TestDox('Item virtual: sem linha, o e-mail válido da ficha aparece como "Cadastro · principal", sem ser gravado')]
    public function testItemVirtualDoCadastro(): void
    {
        $caderno = $this->caderno($this->cliente(' Maria@Ficha.com.br '));

        $itens = $caderno->itens();
        self::assertCount(1, $itens);
        self::assertSame('maria@ficha.com.br', $itens[0]->getEmail());
        self::assertSame(RotuloDoEmail::Cadastro, $itens[0]->getRotulo());
        self::assertTrue($itens[0]->ehPrincipal());
        self::assertNull($itens[0]->getId());
        self::assertSame([], $caderno->novas(), 'mostrar não grava');

        self::assertSame([], $this->caderno($this->cliente('sem-email-valido'))->itens(), 'ficha com e-mail inválido: sem item virtual');
    }

    #[TestDox('Item virtual: a primeira alteração (cadastrar outro) o materializa; ele segue principal e o novo não')]
    public function testMaterializaAoCadastrar(): void
    {
        $caderno = $this->caderno($this->cliente('maria@ficha.com.br'));

        $caderno->adicionar('outro@cliente.com.br', RotuloDoEmail::Familiar, $this->usuario, $this->agora);

        self::assertSame(['maria@ficha.com.br', 'outro@cliente.com.br'], array_map(static fn (ClienteEmail $e): string => $e->getEmail(), $caderno->novas()));
        self::assertTrue($caderno->itens()[0]->ehPrincipal());
        self::assertSame($this->usuario, $caderno->itens()[0]->getCriadoPor());
        self::assertFalse($caderno->itens()[1]->ehPrincipal());
    }

    #[TestDox('Item virtual: o id 0 o materializa (pausar); remover marca a linha e ele não volta como virtual')]
    public function testItemVirtualPeloIdZeroNaoRessuscita(): void
    {
        $cliente = $this->cliente('maria@ficha.com.br');
        $caderno = $this->caderno($cliente);

        $item = $caderno->item(CadernoDeEmailsDoCliente::ID_DO_CADASTRO, $this->usuario, $this->agora);
        $item->definirRecebimento(false);
        self::assertCount(1, $caderno->novas());

        $caderno->remover($item, $this->agora);
        self::assertTrue($item->estaRemovido());
        self::assertSame([], $caderno->apagadas(), 'a linha do cadastro não sai do banco');
        self::assertSame([], $caderno->itens());

        // Lido de novo do banco: a linha marcada impede o item virtual de voltar.
        $relido = $this->caderno($cliente, [$item]);
        self::assertSame([], $relido->itens());

        // Cadastrar o mesmo endereço de novo restaura a linha (sem violar o UNIQUE).
        $relido->adicionar('maria@ficha.com.br', RotuloDoEmail::Pessoal, $this->usuario, $this->agora);
        self::assertSame([], $relido->novas());
        self::assertFalse($item->estaRemovido());
        self::assertTrue($item->ehPrincipal());
        self::assertSame(RotuloDoEmail::Pessoal, $item->getRotulo());
    }

    #[TestDox('Id que não é do cliente (ou id 0 sem item virtual): EmailDoClienteNaoEncontradoException')]
    public function testIdAlheio(): void
    {
        $caderno = $this->caderno($this->cliente());

        $this->expectException(EmailDoClienteNaoEncontradoException::class);
        $caderno->item(CadernoDeEmailsDoCliente::ID_DO_CADASTRO, $this->usuario, $this->agora);
    }

    #[TestDox('Remover um e-mail comum (não do cadastro) tira a linha: vai para apagadas')]
    public function testRemoverComum(): void
    {
        $cliente = $this->cliente();
        $linha = new ClienteEmail($this->tenant, $cliente, 'a@cliente.com.br', RotuloDoEmail::Pessoal, true, null, $this->agora);
        (new \ReflectionProperty(ClienteEmail::class, 'id'))->setValue($linha, 7);
        $caderno = $this->caderno($cliente, [$linha]);

        $caderno->remover($caderno->item(7, $this->usuario, $this->agora), $this->agora);

        self::assertSame([$linha], $caderno->apagadas());
        self::assertSame([], $caderno->itens());
    }
}
