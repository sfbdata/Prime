<?php

declare(strict_types=1);

namespace App\Tests\Conector\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * O nginx de produção deixa os metadados OAuth chegarem ao Symfony (spec mcp-remoto-oauth 3.3 e 12.13). O server 443
 * do bluejus.com.br termina com `location ~ /\. { deny all; }`, um regex que vence o `location /`: sem um prefixo
 * `^~ /.well-known/oauth-`, o `/.well-known/oauth-authorization-server` e o `/.well-known/oauth-protected-resource`
 * recebiam 403 do nginx. O nginx de dev não tem esse bloqueio, então a suíte funcional passaria sem esta guarda.
 */
#[CoversNothing]
final class NginxDoConectorTest extends TestCase
{
    private const LOCATION = 'location ^~ /.well-known/oauth- {';

    #[TestDox('o server bluejus.com.br manda /.well-known/oauth-* ao PHP, por prefixo ^~')]
    public function testLocationDosMetadados(): void
    {
        self::assertMatchesRegularExpression(
            '#location \^~ /\.well-known/oauth- {\s*try_files \$uri /index\.php\$is_args\$args;\s*}#',
            $this->serverDoBlueJus(),
        );
    }

    #[TestDox('a location dos metadados vem antes do bloqueio de arquivos ocultos, que continua lá')]
    public function testAntesDoBloqueioDeOcultos(): void
    {
        $server = $this->serverDoBlueJus();
        $metadados = strpos($server, self::LOCATION);
        $ocultos = strpos($server, 'location ~ /\. {');

        self::assertNotFalse($metadados);
        self::assertNotFalse($ocultos, 'o bloqueio de arquivos ocultos (.env, .git) não pode sair');
        self::assertLessThan($ocultos, $metadados);
        self::assertMatchesRegularExpression('#location ~ /\\\\\. {\s*deny all;\s*}#', $server);
    }

    #[TestDox('o prefixo é só o oauth-: o resto do /.well-known continua barrado no server 443')]
    public function testSoOPrefixoOauth(): void
    {
        $server = $this->serverDoBlueJus();

        self::assertSame(1, preg_match_all('#location[^{]*well-known#', $server), 'só a location dos metadados OAuth abre o /.well-known no 443');
    }

    /** O server 443 do bluejus.com.br (não o do www nem o do grupojusprime.tech). */
    private function serverDoBlueJus(): string
    {
        $conf = (string) file_get_contents(__DIR__ . '/../../../../nginx/conf.d/nginx.prod.conf');
        $inicio = strpos($conf, "listen 443 ssl;\n    server_name bluejus.com.br;");
        self::assertNotFalse($inicio, 'falta o server 443 do bluejus.com.br');
        $fim = strpos($conf, 'server_name grupojusprime.tech', $inicio);
        self::assertNotFalse($fim);

        return substr($conf, $inicio, $fim - $inicio);
    }
}
