<?php

declare(strict_types=1);

namespace App\Tests\Arquitetura;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * **Código de produção só usa pacote que a produção instala.**
 *
 * A imagem de produção roda `composer install --no-dev`: o que está só em `packages-dev` do
 * `composer.lock` não existe lá. O `symfony/process` chegava ao projeto só por pacotes de dev
 * (paratest, php-cs-fixer, maker-bundle), então o `ExecucaoDoGhostscript` funcionava no dev e nos
 * testes e falhava em produção, caindo só no log (a compressão é de melhor esforço).
 *
 * O teste lê os nomes de classe de `src/` pelo tokenizer do PHP (comentário não conta) e recusa os
 * que pertencem a um namespace que só pacote de dev declara.
 */
#[CoversNothing]
final class DependenciasDeProducaoArquiteturaTest extends TestCase
{
    /**
     * Pastas de `src/` que só carregam em dev e test, e por quê.
     *
     * @var array<string, string>
     */
    private const PASTAS_SO_DE_DEV = [
        'src/DataFixtures/' => 'DoctrineFixturesBundle só em dev e test (config/bundles.php)',
        'src/Story/'        => 'ZenstruckFoundryBundle só em dev e test (config/bundles.php)',
    ];

    #[TestDox('src/ não usa classe de pacote que só existe em require-dev')]
    public function testSrcNaoUsaPacoteSoDeDev(): void
    {
        $raiz = \dirname(__DIR__, 2);
        $lock = json_decode((string) file_get_contents($raiz . '/composer.lock'), true, 512, \JSON_THROW_ON_ERROR);

        $deProducao = self::namespacesDe($lock['packages']);
        $soDeDev    = array_filter(
            self::namespacesDe($lock['packages-dev']),
            static function (string $ns) use ($deProducao): bool {
                foreach (array_keys($deProducao) as $prod) {
                    if (str_starts_with($ns, $prod) || str_starts_with($prod, $ns)) {
                        return false;
                    }
                }

                return true;
            },
            \ARRAY_FILTER_USE_KEY,
        );
        self::assertNotEmpty($soDeDev, 'O lock deveria ter pacotes só de dev (phpunit, por exemplo)');

        $violacoes = [];
        $arquivos  = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($raiz . '/src', \FilesystemIterator::SKIP_DOTS));
        foreach ($arquivos as $arquivo) {
            if ('php' !== $arquivo->getExtension()) {
                continue;
            }
            $relativo = substr($arquivo->getPathname(), \strlen($raiz) + 1);
            foreach (array_keys(self::PASTAS_SO_DE_DEV) as $pasta) {
                if (str_starts_with($relativo, $pasta)) {
                    continue 2;
                }
            }

            foreach (\PhpToken::tokenize((string) file_get_contents($arquivo->getPathname())) as $token) {
                if (!$token->is([\T_NAME_QUALIFIED, \T_NAME_FULLY_QUALIFIED])) {
                    continue;
                }
                $nome = ltrim($token->text, '\\') . '\\';
                foreach ($soDeDev as $ns => $pacote) {
                    if (str_starts_with($nome, $ns)) {
                        $violacoes[] = \sprintf('%s:%d usa %s (pacote %s, só em require-dev)', $relativo, $token->line, rtrim($nome, '\\'), $pacote);
                    }
                }
            }
        }

        self::assertSame([], $violacoes, "Mova o pacote para require no composer.json, ou tire o uso de src/:\n" . implode("\n", $violacoes));
    }

    /**
     * @param list<array<string, mixed>> $pacotes
     *
     * @return array<string, string> namespace => nome do pacote
     */
    private static function namespacesDe(array $pacotes): array
    {
        $namespaces = [];
        foreach ($pacotes as $pacote) {
            foreach (['psr-4', 'psr-0'] as $padrao) {
                foreach (array_keys($pacote['autoload'][$padrao] ?? []) as $ns) {
                    if ('' !== $ns) {
                        $namespaces[$ns] = $pacote['name'];
                    }
                }
            }
        }

        return $namespaces;
    }
}
