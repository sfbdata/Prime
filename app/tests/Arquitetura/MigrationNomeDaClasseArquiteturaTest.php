<?php

declare(strict_types=1);

namespace App\Tests\Arquitetura;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;

/**
 * A classe de cada migration tem o nome do arquivo. O Doctrine registra a versão pelo nome da CLASSE: uma
 * migration renomeada só no arquivo (20/10/2026, Version20261019235600 com a classe antiga) entra na fila com
 * a versão velha, fora da ordem combinada, e o `scripts/migrar-dev.sh <Version...>` a recusa.
 */
#[CoversNothing]
final class MigrationNomeDaClasseArquiteturaTest extends TestCase
{
    private const MIGRATIONS = __DIR__ . '/../../migrations';

    #[TestDox('Toda migration declara a classe com o mesmo nome do arquivo, no namespace DoctrineMigrations')]
    public function testClasseIgualAoArquivo(): void
    {
        $divergentes = [];

        foreach ((new Finder())->files()->in(self::MIGRATIONS)->name('Version*.php') as $arquivo) {
            $esperado = $arquivo->getBasename('.php');
            $codigo = $arquivo->getContents();
            $temNamespace = preg_match('/^namespace DoctrineMigrations;$/m', $codigo) === 1;
            $temClasse = preg_match('/^(?:final\s+)?class\s+(\w+)\s+extends\s+AbstractMigration\b/m', $codigo, $m) === 1;
            if (!$temNamespace || !$temClasse || $m[1] !== $esperado) {
                $divergentes[] = sprintf('%s: classe %s', $arquivo->getFilename(), $temClasse ? $m[1] : '(não achada)');
            }
        }

        self::assertSame([], $divergentes, 'Renomeou a migration? Troque também o nome da classe (e o registro no banco da frente).');
    }
}
