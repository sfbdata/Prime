<?php

declare(strict_types=1);

namespace App\Tests\Arquitetura;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Yaml\Yaml;

/**
 * Trava a queda do build da v2.11.0 (21/10/2026): o `cache:warmup` do Dockerfile roda com um .env de mentira
 * (o `printf` da imagem), e uma `%env(MAILER_FROM)%` sem padrão derrubou o build porque o warmup do Twig instancia
 * a extensão que a usa. O CI não via: ele lê o .env do repositório.
 *
 * Regra: toda `%env(NOME)%` sem `default:` nos YAML de `config/` (fora de `when@dev` e `when@test`) está no `printf`
 * do Dockerfile. Estar só no `.env.prod.example` não basta: ele vale para o container no ar, não para o build (o
 * MAILER_FROM estava lá e o build caiu assim mesmo).
 */
#[CoversNothing]
final class EnvDoBuildArquiteturaTest extends TestCase
{
    #[TestDox('toda variável de ambiente sem padrão da config existe no .env do build (printf do Dockerfile)')]
    public function testVariavelSemPadraoExisteNoBuild(): void
    {
        $conhecidas = $this->doPrintfDoDockerfile(\dirname(__DIR__, 3) . '/Dockerfile');

        $faltando = [];
        foreach ($this->semPadraoNaConfig(\dirname(__DIR__, 2) . '/config') as $nome => $arquivo) {
            if (!\in_array($nome, $conhecidas, true)) {
                $faltando[] = $nome . ' (' . $arquivo . ')';
            }
        }

        self::assertSame([], $faltando, 'Acrescente ao printf do Dockerfile, com valor de mentira que passe na validação do serviço.');
    }

    /** @return list<string> */
    private function doPrintfDoDockerfile(string $arquivo): array
    {
        self::assertFileExists($arquivo);
        self::assertSame(1, preg_match('/^RUN printf "(APP_ENV=[^"]*)" > \.env$/m', (string) file_get_contents($arquivo), $m), 'printf do .env do build não encontrado no Dockerfile.');
        preg_match_all('/(?:^|\\\\n)([A-Z][A-Z0-9_]*)=/', $m[1], $nomes);

        return $nomes[1];
    }

    /** @return array<string, string> nome => arquivo onde aparece */
    private function semPadraoNaConfig(string $config): array
    {
        $achadas = [];
        foreach ((new Finder())->files()->in($config)->name('*.yaml') as $arquivo) {
            $dados = Yaml::parseFile($arquivo->getPathname(), Yaml::PARSE_CUSTOM_TAGS);
            if (\is_array($dados)) {
                unset($dados['when@dev'], $dados['when@test']);
            }
            // serialize, não json: pega também o que está dentro de tag (!tagged_iterator e afins).
            preg_match_all('/%env\(([^)%]+)\)%/', serialize($dados), $m);
            foreach ($m[1] as $expr) {
                $partes = explode(':', $expr);
                if (!\in_array('default', $partes, true)) {
                    $achadas[end($partes)] = $arquivo->getRelativePathname();
                }
            }
        }
        ksort($achadas);

        return $achadas;
    }
}
