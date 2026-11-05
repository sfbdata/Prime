<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Auditoria 4.01: no nginx de dev, /_profiler e /_wdt (que mostram segredos do .env, cookies e o corpo do POST de
 * login) só respondem a este computador. A porta 8080 continua aberta na rede por decisão do dono. A conferência ao
 * vivo (403 de outro IP, inclusive por /index.php/_profiler) está descrita em mudancas/dev-profiler-local.md.
 */
#[CoversNothing]
final class NginxDevProfilerSoLocalTest extends TestCase
{
    private function bloco(): string
    {
        $conf = (string) file_get_contents(__DIR__ . '/../../../../nginx.conf');
        $inicio = strpos($conf, 'location ~ ^(/index\.php)?/(_profiler|_wdt)(/|$) {');
        self::assertNotFalse($inicio, 'falta a location do profiler no nginx de dev (cobrindo também /index.php/_profiler)');
        $fim = strpos($conf, '}', $inicio);
        self::assertNotFalse($fim);

        return substr($conf, $inicio, $fim - $inicio);
    }

    #[TestDox('/_profiler e /_wdt aceitam só 127.0.0.1 e o gateway do Docker; o resto recebe deny')]
    public function testSoEsteComputador(): void
    {
        $bloco = $this->bloco();
        preg_match_all('/^\s*allow\s+([^;]+);/m', $bloco, $allows);
        self::assertSame(['127.0.0.1', '172.18.0.1'], $allows[1]);
        self::assertMatchesRegularExpression('/^\s*deny all;/m', $bloco);
    }

    #[TestDox('o allow/deny vale porque a location usa try_files, não rewrite (rewrite pularia o controle de acesso)')]
    public function testTryFilesNaoRewrite(): void
    {
        $bloco = $this->bloco();
        self::assertStringContainsString('try_files $uri /index.php$is_args$args;', $bloco);
        self::assertStringNotContainsString('rewrite', $bloco);
    }

    #[TestDox('a location do profiler vem antes da do PHP (regex: vale a primeira que casa)')]
    public function testAntesDaLocationDoPhp(): void
    {
        $conf = (string) file_get_contents(__DIR__ . '/../../../../nginx.conf');
        self::assertLessThan(strpos($conf, 'location ~ \.php$'), strpos($conf, '(_profiler|_wdt)'));
    }
}
