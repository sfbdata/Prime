<?php

declare(strict_types=1);

namespace App\Tests\Profile\Unit;

use App\Entity\Auth\User;
use App\Profile\Entity\UserProfile;
use App\Profile\Enum\Genero;
use App\Profile\Service\TratamentoDeAdvogado;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/** "Dr./Dra." do desenho 04 (trat()), sem adivinhar pelo nome (spec genero-no-cadastro, regra 4). */
#[CoversClass(TratamentoDeAdvogado::class)]
final class TratamentoDeAdvogadoTest extends TestCase
{
    /** @return iterable<string, array{?string, ?Genero, ?string}> */
    public static function casos(): iterable
    {
        yield 'advogada' => ['12345', Genero::Feminino, 'dra'];
        yield 'advogado' => ['12345', Genero::Masculino, 'dr'];
        yield 'advogado sem gênero' => ['12345', null, null];
        yield 'advogado com o legado nao_informado' => ['12345', Genero::NaoInformado, null];
        yield 'não advogada com gênero' => [null, Genero::Feminino, null];
        yield 'OAB em branco' => ['  ', Genero::Masculino, null];
    }

    #[DataProvider('casos')]
    #[TestDox('$_dataName')]
    public function testTratamento(?string $oab, ?Genero $genero, ?string $esperado): void
    {
        $user = (new User())->setFullName('Ana Maria Lima');
        $user->setOabNumero($oab);
        if ($genero !== null) {
            UserProfile::daContaNova($user, $genero);
        }

        self::assertSame($esperado, TratamentoDeAdvogado::de($user));
    }

    #[TestDox('Não adivinha pelo nome: advogada com nome terminado em "a" e sem perfil fica sem título')]
    public function testNaoAdivinhaPeloNome(): void
    {
        $user = (new User())->setFullName('Mariana Souza');
        $user->setOabNumero('999');

        self::assertNull(TratamentoDeAdvogado::de($user));
    }
}
