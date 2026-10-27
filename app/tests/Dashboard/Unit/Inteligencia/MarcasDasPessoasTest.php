<?php

declare(strict_types=1);

namespace App\Tests\Dashboard\Unit\Inteligencia;

use App\Dashboard\Exception\PreferenciaInvalidaException;
use App\Dashboard\Preferencia\CatalogoDePreferenciasDoDashboard;
use App\Dashboard\Preferencia\MarcasDasPessoas;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(MarcasDasPessoas::class)]
#[Group('dashboard')]
final class MarcasDasPessoasTest extends TestCase
{
    #[TestDox('Marca e desmarca: repetir a ação ativa a desfaz, e marca vazia não fica guardada')]
    public function testAlternar(): void
    {
        $mapa = MarcasDasPessoas::alternar([], 7, MarcasDasPessoas::ANALISADO);
        self::assertSame(['7' => ['status' => 'analisado', 'feedback' => null]], $mapa);

        $mapa = MarcasDasPessoas::alternar($mapa, 7, MarcasDasPessoas::PARCIAL);
        self::assertSame(['7' => ['status' => 'analisado', 'feedback' => 'parcial']], $mapa);

        $mapa = MarcasDasPessoas::alternar($mapa, 7, MarcasDasPessoas::PARCIAL);
        $mapa = MarcasDasPessoas::alternar($mapa, 7, MarcasDasPessoas::ANALISADO);
        self::assertSame([], $mapa);
    }

    #[TestDox('Ação desconhecida e pessoa inválida são recusadas')]
    public function testRecusaInvalidos(): void
    {
        $this->expectException(PreferenciaInvalidaException::class);
        MarcasDasPessoas::alternar([], 7, 'apagar');
    }

    #[TestDox('Pessoa com id zero ou negativo é recusada')]
    public function testRecusaPessoaInvalida(): void
    {
        $this->expectException(PreferenciaInvalidaException::class);
        MarcasDasPessoas::alternar([], 0, MarcasDasPessoas::ANALISADO);
    }

    #[TestDox('Lido do banco, lixo vira mapa vazio e não quebra a tela; a chave fica fora do catálogo do menu')]
    public function testLerOuVazio(): void
    {
        self::assertSame([], MarcasDasPessoas::lerOuVazio('texto'));
        self::assertSame([], MarcasDasPessoas::lerOuVazio(['abc' => ['status' => 'analisado']]));
        self::assertSame([], MarcasDasPessoas::lerOuVazio(['5' => ['status' => 'qualquer']]));
        self::assertSame(['5' => ['status' => 'analisado', 'feedback' => 'sim']], MarcasDasPessoas::lerOuVazio(['5' => ['status' => 'analisado', 'feedback' => 'sim']]));
        self::assertNotContains(MarcasDasPessoas::CHAVE, CatalogoDePreferenciasDoDashboard::chaves());
    }
}
