<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Unit;

use App\AssinaturaEletronica\DTO\EntradaDaAssinatura;
use App\AssinaturaEletronica\DTO\LocalizacaoDoSignatario;
use App\AssinaturaEletronica\Exception\AcaoRecusada;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * A localização do "Assinar" Reforçado (contrato da etapa 7, seções 5.2 e 6): números do navegador, latitude de -90 a
 * 90, longitude de -180 a 180 e precisão em metros, guardados como texto decimal com as casas das colunas da M1.
 */
#[CoversClass(LocalizacaoDoSignatario::class)]
#[CoversClass(EntradaDaAssinatura::class)]
final class LocalizacaoDoSignatarioTest extends TestCase
{
    #[TestDox('números do navegador viram texto com 8 casas (precisão com 2) e o texto da tela tem 5 casas')]
    public function testAceita(): void
    {
        $local = LocalizacaoDoSignatario::deJson(['latitude' => -15.7938806, 'longitude' => -47.8827763, 'precisao' => 12.5]);

        self::assertSame(['-15.79388060', '-47.88277630', '12.50'], [$local->latitude, $local->longitude, $local->precisao]);
        self::assertSame('-15.79388, -47.88278', $local->texto());
        self::assertSame(
            ['90.00000000', '-180.00000000', '0.00'],
            (static fn (LocalizacaoDoSignatario $l): array => [$l->latitude, $l->longitude, $l->precisao])(LocalizacaoDoSignatario::deJson(['latitude' => 90, 'longitude' => -180, 'precisao' => 0])),
        );
        self::assertNull(LocalizacaoDoSignatario::deJson(['latitude' => 1, 'longitude' => 2])->precisao, 'sem precisão, nula');
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidas(): iterable
    {
        yield 'latitude acima de 90' => [['latitude' => 90.0001, 'longitude' => 0, 'precisao' => 5]];
        yield 'latitude abaixo de -90' => [['latitude' => -91, 'longitude' => 0, 'precisao' => 5]];
        yield 'longitude acima de 180' => [['latitude' => 0, 'longitude' => 180.5, 'precisao' => 5]];
        yield 'precisão negativa' => [['latitude' => 0, 'longitude' => 0, 'precisao' => -1]];
        yield 'precisão acima da coluna' => [['latitude' => 0, 'longitude' => 0, 'precisao' => 100000000]];
        yield 'latitude em texto' => [['latitude' => '-15.79', 'longitude' => 0, 'precisao' => 5]];
        yield 'longitude booleana' => [['latitude' => 0, 'longitude' => true, 'precisao' => 5]];
        yield 'sem longitude' => [['latitude' => 0, 'precisao' => 5]];
    }

    /** @param array<string, mixed> $json */
    #[DataProvider('invalidas')]
    #[TestDox('recusa: $_dataName')]
    public function testRecusa(array $json): void
    {
        $this->expectExceptionObject(new AcaoRecusada('assinatura_eletronica.erro.localizacao_invalida'));

        LocalizacaoDoSignatario::deJson($json);
    }

    #[TestDox('no corpo do "Assinar": sem localização, "Permita o acesso..."; localização que não é objeto, dados inválidos')]
    public function testNoCorpoDoAssinar(): void
    {
        $semLocal = EntradaDaAssinatura::deJson(['nome' => 'Ana Lima']);
        try {
            $semLocal->localizacao();
            self::fail('deveria recusar');
        } catch (AcaoRecusada $e) {
            self::assertSame('assinatura_eletronica.erro.assinar_localizacao', $e->getMessage());
        }

        self::assertSame('1.00000, 2.00000', EntradaDaAssinatura::deJson(['localizacao' => ['latitude' => 1, 'longitude' => 2, 'precisao' => 3]])->localizacao()->texto());

        $this->expectExceptionObject(new AcaoRecusada('assinatura_eletronica.erro.dados_invalidos'));
        EntradaDaAssinatura::deJson(['localizacao' => '1,2']);
    }

    #[TestDox('no corpo do "Assinar": sem selfie, "Envie a selfie com o documento."')]
    public function testSemSelfie(): void
    {
        $this->expectExceptionObject(new AcaoRecusada('assinatura_eletronica.erro.assinar_selfie'));

        EntradaDaAssinatura::deJson(['selfie' => ''])->selfie();
    }
}
