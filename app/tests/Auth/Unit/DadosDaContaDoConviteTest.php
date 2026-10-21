<?php

declare(strict_types=1);

namespace App\Tests\Auth\Unit;

use App\Auth\Service\DadosDaContaDoConvite;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(DadosDaContaDoConvite::class)]
final class DadosDaContaDoConviteTest extends TestCase
{
    /** @return iterable<string, array{string, ?string}> */
    public static function senhasRecusadas(): iterable
    {
        yield 'vazia' => ['', 'A senha deve ter ao menos 8 caracteres.'];
        yield 'sete caracteres' => ['abcdefg', 'A senha deve ter ao menos 8 caracteres.'];
        yield 'acima de 4096 bytes com acento' => [str_repeat('ç', 2100), 'A senha é longa demais.'];
    }

    #[DataProvider('senhasRecusadas')]
    #[TestDox('Senha fora do mínimo de 8 ou acima do limite em bytes do hasher é recusada')]
    public function testSenhaRecusada(string $senha, string $mensagem): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($mensagem);

        DadosDaContaDoConvite::exigirSenha($senha);
    }

    #[TestDox('Senha de 8 caracteres passa')]
    public function testSenhaMinimaPassa(): void
    {
        DadosDaContaDoConvite::exigirSenha('abcdefgh');
        $this->addToAssertionCount(1);
    }

    #[TestDox('Nome digitado vem sem espaços nas pontas; em branco cai no nome do convite')]
    public function testNome(): void
    {
        self::assertSame('Ana Souza', DadosDaContaDoConvite::nome('  Ana Souza ', 'Outro Nome'));
        self::assertSame('Nome do Convite', DadosDaContaDoConvite::nome('   ', ' Nome do Convite '));
    }

    /** @return iterable<string, array{string, ?string}> */
    public static function nomesRecusados(): iterable
    {
        yield 'tudo em branco e convite sem nome' => ['   ', null];
        yield 'vazio e convite com nome em branco' => ['', '  '];
    }

    #[DataProvider('nomesRecusados')]
    #[TestDox('Nome em branco sem nome no convite é recusado')]
    public function testNomeRecusado(string $digitado, ?string $doConvite): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Nome completo é obrigatório.');

        DadosDaContaDoConvite::nome($digitado, $doConvite);
    }
}
