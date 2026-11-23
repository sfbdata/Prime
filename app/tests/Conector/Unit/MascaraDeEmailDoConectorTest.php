<?php

declare(strict_types=1);

namespace App\Tests\Conector\Unit;

use App\Conector\Service\MascaraDeEmailDoConector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(MascaraDeEmailDoConector::class)]
final class MascaraDeEmailDoConectorTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function emails(): iterable
    {
        yield 'gmail' => ['joao@gmail.com', 'j***@g***.com'];
        yield 'escritório' => ['ana@silva.adv.br', 'a***@s***.adv.br'];
        yield 'acento e espaço' => [' érica.souza@exemplo.com.br ', 'é***@e***.com.br'];
        yield 'sem arroba' => ['joao.gmail.com', '***'];
        yield 'sem nome' => ['@gmail.com', '***'];
        yield 'domínio sem ponto' => ['joao@localhost', '***'];
        yield 'domínio só ponto no fim' => ['joao@gmail.', '***'];
        yield 'vazio' => ['', '***'];
    }

    #[DataProvider('emails')]
    #[TestDox('máscara do conector (6.9, decisão 35): letra inicial do nome e do domínio, mais o final do domínio')]
    public function testMascara(string $email, string $esperado): void
    {
        self::assertSame($esperado, MascaraDeEmailDoConector::mascarar($email));
    }
}
