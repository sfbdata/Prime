<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * O nginx de produção serve os 3 sites estáticos (sfbdata/sites-bluejus) no bluejus.com.br: o site institucional na
 * raiz e nas páginas dele, o blog e o gratis nos prefixos. O nginx de dev não tem nada disso, então a suíte passaria
 * sem esta guarda. Cada location estático usa `=`/`^~` (ou um regex sem extensão, que os de css/js/imagens não pegam)
 * e termina em `=404`, para arquivo ausente nunca cair no PHP; o POST de /contato vai ao Symfony.
 */
#[CoversNothing]
final class NginxDosSitesEstaticosTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function locations(): iterable
    {
        yield 'raiz' => ['location = / {\s*root /var/www/sites/site;\s*try_files /index.html =404;\s*}'];
        yield 'páginas do site' => ['location ~ \^/\(como-funciona\|duvidas\|funcionalidades\|gratuito\|para-escritorios\|seguranca\|sobre\)\$ {\s*root /var/www/sites/site;\s*try_files /\$1\.html =404;\s*}'];
        yield 'robots' => ['location = /robots\.txt {\s*root /var/www/sites/site;\s*try_files \$uri =404;\s*}'];
        yield 'sitemap' => ['location = /sitemap\.xml {\s*root /var/www/sites/site;\s*try_files \$uri =404;\s*}'];
        yield 'sitemap do site' => ['location = /sitemap-site\.xml {\s*root /var/www/sites/site;\s*try_files \$uri =404;\s*}'];
        yield '_astro' => ['location \^~ /_astro/ {\s*root /var/www/sites/site;\s*try_files \$uri =404;\s*}'];
        yield 'imagens' => ['location \^~ /imagens/ {\s*root /var/www/sites/site;\s*try_files \$uri =404;\s*}'];
        yield 'contato' => ['location = /contato {\s*if \(\$request_method = POST\) {\s*rewrite \^ /index\.php last;\s*}\s*root /var/www/sites/site;\s*try_files /contato\.html =404;\s*}'];
        yield 'blog' => ['location \^~ /blog {\s*root /var/www/sites;\s*try_files \$uri \$uri\.html \$uri/index\.html =404;\s*}'];
        yield 'gratis' => ['location \^~ /gratis {\s*root /var/www/sites;\s*try_files \$uri \$uri\.html \$uri/index\.html =404;\s*}'];
    }

    #[DataProvider('locations')]
    #[TestDox('O server bluejus.com.br serve o site estático: $_dataName')]
    public function testLocationDoSite(string $padrao): void
    {
        self::assertMatchesRegularExpression('#' . $padrao . '#', $this->serverDoBlueJus());
    }

    #[TestDox('O regex das páginas do site vem antes dos regex de css/js e de imagens (regex: o primeiro que casa vence)')]
    public function testOrdemDosRegex(): void
    {
        $server = $this->serverDoBlueJus();
        $paginas = strpos($server, 'location ~ ^/(como-funciona');

        self::assertNotFalse($paginas);
        self::assertLessThan(strpos($server, 'location ~* \.(css|js)$'), $paginas);
        self::assertLessThan(strpos($server, 'location ~* \.(jpg|jpeg|png|gif'), $paginas);
    }

    #[TestDox('Nenhum location dos sites usa add_header (apagaria os cabeçalhos de segurança herdados do server)')]
    public function testSemAddHeaderNosSites(): void
    {
        $server = $this->serverDoBlueJus();
        $inicio = (int) strpos($server, 'location = / {');
        $fim = (int) strpos($server, 'location ^~ /perfil/foto/');

        self::assertGreaterThan($inicio, $fim);
        self::assertStringNotContainsString('add_header', substr($server, $inicio, $fim - $inicio));
    }

    #[TestDox('O nginx de produção monta ./sites-publicados (só leitura) e a pasta fica fora do git')]
    public function testVolumeEGitignore(): void
    {
        $raiz = __DIR__ . '/../../../..';

        self::assertStringContainsString('- ./sites-publicados:/var/www/sites:ro', (string) file_get_contents($raiz . '/docker-compose.prod.yml'));
        self::assertMatchesRegularExpression('#^sites-publicados/$#m', (string) file_get_contents($raiz . '/.gitignore'));
    }

    /** O server 443 do bluejus.com.br (não o do www nem o do grupojusprime.tech). */
    private function serverDoBlueJus(): string
    {
        $conf = (string) file_get_contents(__DIR__ . '/../../../../nginx/conf.d/nginx.prod.conf');
        $inicio = strpos($conf, "listen 443 ssl;\n    server_name bluejus.com.br;");
        self::assertNotFalse($inicio, 'falta o server 443 do bluejus.com.br');
        $fim = strpos($conf, 'server_name grupojusprime.tech', $inicio);

        return substr($conf, $inicio, ($fim === false ? \strlen($conf) : $fim) - $inicio);
    }
}
