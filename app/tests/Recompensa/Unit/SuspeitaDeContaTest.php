<?php

declare(strict_types=1);

namespace App\Tests\Recompensa\Unit;

use App\Recompensa\Service\SuspeitaDeConta;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(SuspeitaDeConta::class)]
final class SuspeitaDeContaTest extends TestCase
{
    #[TestDox('sem nenhum sinal em comum não há suspeita')]
    public function testLimpa(): void
    {
        self::assertSame([], SuspeitaDeConta::motivos([1], [2], ['11111111000111'], ['22222222000122'], 'a.adv.br', 'b.adv.br'));
        self::assertSame([], SuspeitaDeConta::motivos([], [], [], [], null, null));
    }

    #[TestDox('conta no mesmo escritório de quem indicou é suspeita, em qualquer um dos vínculos')]
    public function testMesmoEscritorio(): void
    {
        self::assertSame(['escritorio'], SuspeitaDeConta::motivos([4, 9], [3, 9], [], [], null, null));
    }

    #[TestDox('mesmo CNPJ de escritório é suspeito, com ou sem pontuação, em qualquer escritório de cada lado')]
    public function testMesmoCnpj(): void
    {
        self::assertSame(['cnpj'], SuspeitaDeConta::motivos([1], [2], ['12345678000199'], ['11111111000111', '12.345.678/0001-99'], null, null));
    }

    /** @return iterable<string, array{list<string|null>, list<string|null>}> */
    public static function cnpjsQueNaoCasam(): iterable
    {
        yield 'os dois nulos' => [[null], [null]];
        yield 'os dois vazios' => [[''], ['']];
        yield 'só pontuação' => [['..-'], ['./-']];
        yield 'um nulo, outro com valor' => [[null], ['12345678000199']];
        yield 'sem escritório' => [[], []];
    }

    /**
     * @param list<string|null> $conta
     * @param list<string|null> $indicador
     */
    #[DataProvider('cnpjsQueNaoCasam')]
    #[TestDox('CNPJ nulo ou vazio nunca casa')]
    public function testCnpjNuloNaoCasa(array $conta, array $indicador): void
    {
        self::assertSame([], SuspeitaDeConta::motivos([1], [2], $conta, $indicador, null, null));
    }

    #[TestDox('mesmo domínio corporativo de e-mail é suspeito, sem diferenciar maiúscula nem espaço')]
    public function testMesmoDominio(): void
    {
        self::assertSame(['dominio'], SuspeitaDeConta::motivos([], [], [], [], 'banca.adv.br', ' Banca.ADV.br '));
    }

    /** @return iterable<string, array{string}> */
    public static function provedoresPublicos(): iterable
    {
        foreach (['gmail.com', 'hotmail.com', 'hotmail.com.br', 'outlook.com', 'yahoo.com.br', 'icloud.com', 'bol.com.br', 'uol.com.br', 'live.com', 'msn.com', 'terra.com.br', 'ig.com.br', 'globo.com', 'globomail.com', 'proton.me', 'protonmail.com', 'zoho.com'] as $d) {
            yield $d => [$d];
        }
    }

    #[DataProvider('provedoresPublicos')]
    #[TestDox('domínio de provedor público igual não é suspeita')]
    public function testProvedorPublico(string $dominio): void
    {
        self::assertSame([], SuspeitaDeConta::motivos([], [], [], [], $dominio, $dominio));
    }

    #[TestDox('domínio vazio ou diferente não é suspeita; nome que só contém o do provedor (gmailbanca.com) continua suspeito')]
    public function testDominioVazioEDiferente(): void
    {
        self::assertSame([], SuspeitaDeConta::motivos([], [], [], [], '', ''));
        self::assertSame([], SuspeitaDeConta::motivos([], [], [], [], 'a.com', 'b.com'));
        self::assertSame(['dominio'], SuspeitaDeConta::motivos([], [], [], [], 'gmailbanca.com', 'gmailbanca.com'));
    }

    #[TestDox('vários sinais juntos saem na ordem escritório, CNPJ, domínio')]
    public function testOrdem(): void
    {
        self::assertSame(['escritorio', 'cnpj', 'dominio'], SuspeitaDeConta::motivos([1], [1], ['12345678000199'], ['12345678000199'], 'x.adv.br', 'x.adv.br'));
    }

    #[TestDox('daLinha lê as chaves da linha do repositório')]
    public function testDaLinha(): void
    {
        $linha = ['escritorios_conta' => [1], 'escritorios_indicador' => [1], 'cnpjs_conta' => [], 'cnpjs_indicador' => [], 'dominio_conta' => 'g.com', 'dominio_indicador' => 'h.com'];

        self::assertSame(['escritorio'], SuspeitaDeConta::daLinha($linha));
    }
}
