<?php

declare(strict_types=1);

namespace App\Tests\Master\Unit;

use App\Master\Service\LocalizacaoDaPessoa;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(LocalizacaoDaPessoa::class)]
final class LocalizacaoDaPessoaTest extends TestCase
{
    private const ESTADOS = [1 => 'DF', 2 => null, 3 => 'go', 4 => 'Distrito Federal'];

    /**
     * @param list<array{tenantId: int, lotacao: string, primario: bool, ultimoAcesso: string}> $vinculos
     * @param array{uf: string, cidade: string, fonte: string}|null                           $esperado
     */
    #[DataProvider('casos')]
    #[TestDox('Localização: $_dataName')]
    public function testLocaliza(?string $oabUf, array $vinculos, ?array $esperado): void
    {
        self::assertSame($esperado, LocalizacaoDaPessoa::localizar(['oabUf' => $oabUf, 'vinculos' => $vinculos], self::ESTADOS));
    }

    #[TestDox('Lotação com HTML não sai com < > " \' ` e o nome é cortado em 80 caracteres')]
    public function testNomeDeLugarSeguro(): void
    {
        $local = LocalizacaoDaPessoa::localizar(['oabUf' => null, 'vinculos' => [
            ['tenantId' => 1, 'lotacao' => '<img src=x onerror=alert(1)>/SP', 'primario' => true, 'ultimoAcesso' => ''],
        ]], self::ESTADOS);

        self::assertSame('SP', $local['uf'] ?? null);
        self::assertDoesNotMatchRegularExpression('/[<>"\'`]/', $local['cidade']);
        self::assertSame('img src=x onerror=alert(1)', $local['cidade']);
        self::assertSame(80, mb_strlen(LocalizacaoDaPessoa::nomeDeLugar(str_repeat('á', 200))));
        self::assertSame('São Paulo', LocalizacaoDaPessoa::nomeDeLugar("  São\n\t \"Paulo\"  "));
    }

    /**
     * @return iterable<string, array{0: ?string, 1: list<array<string, mixed>>, 2: ?array<string, string>}>
     */
    public static function casos(): iterable
    {
        $v = static fn (int $t, string $lot, bool $prim = false, string $acesso = ''): array => ['tenantId' => $t, 'lotacao' => $lot, 'primario' => $prim, 'ultimoAcesso' => $acesso];

        yield 'lotação Cidade/UF vence a OAB (exemplo real: Maceió/AL com OAB DF)' => [
            'DF', [$v(1, 'Maceió/AL', true), $v(2, '', true)], ['uf' => 'AL', 'cidade' => 'Maceió', 'fonte' => 'lotação'],
        ];
        yield 'lotação com hífen ou travessão também vale' => [
            null, [$v(1, 'Goiânia - go')], ['uf' => 'GO', 'cidade' => 'Goiânia', 'fonte' => 'lotação'],
        ];
        yield 'vínculo primário escolhido antes do secundário' => [
            null, [$v(3, 'Santos/SP', false, '2026-10-15'), $v(1, 'Recife/PE', true, '2026-01-01')], ['uf' => 'PE', 'cidade' => 'Recife', 'fonte' => 'lotação'],
        ];
        yield 'sem lotação Cidade/UF: UF da OAB' => [
            'sp', [$v(1, 'Sede', true)], ['uf' => 'SP', 'cidade' => '', 'fonte' => 'OAB'],
        ];
        yield 'sem lotação e sem OAB: estado do escritório' => [
            null, [$v(3, '')], ['uf' => 'GO', 'cidade' => '', 'fonte' => 'escritório'],
        ];
        yield 'lotação com sigla que não é UF segue para a OAB' => [
            'RJ', [$v(1, 'Setor/RH')], ['uf' => 'RJ', 'cidade' => '', 'fonte' => 'OAB'],
        ];
        yield 'nada disso: sem localização' => [
            null, [$v(2, '')], null,
        ];
        yield 'estado do escritório por extenso não é sigla: sem localização' => [
            null, [$v(4, '')], null,
        ];
    }
}
