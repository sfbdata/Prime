<?php

declare(strict_types=1);

namespace App\Tests\Comercial\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * As rotas dos e-mails do Comercial têm extensão de imagem (/e/a/<token>.gif, /e/i/<token>.jpg). No nginx de produção,
 * o regex de binários responde 404 a arquivo que não existe em disco; o prefixo `^~ /e/` manda essas rotas ao PHP antes
 * dele. O nginx de dev não tem o regex, então a suíte passaria sem esta guarda.
 */
#[CoversNothing]
final class NginxDasRotasDoEmailTest extends TestCase
{
    #[TestDox('O nginx de produção manda /e/ ao front controller antes do regex de imagens')]
    public function testPrefixoDoEmailAntesDoRegexDeImagens(): void
    {
        $conf = (string) file_get_contents(__DIR__ . '/../../../../nginx/conf.d/nginx.prod.conf');
        $prefixo = strpos($conf, 'location ^~ /e/ {');
        $regex = strpos($conf, 'location ~* \.(jpg|jpeg|png|gif');

        self::assertNotFalse($prefixo, 'falta o location ^~ /e/ no nginx de produção');
        self::assertNotFalse($regex);
        self::assertLessThan($regex, $prefixo);
        self::assertMatchesRegularExpression('#location \^~ /e/ \{\s*try_files \$uri /index\.php\$is_args\$args;\s*\}#', $conf);
    }
}
