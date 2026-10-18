<?php

declare(strict_types=1);

namespace App\Tests\Novidades\Unit;

use App\Novidades\Service\LeitorDeNovidades;
use App\Novidades\Twig\NovidadesExtension;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

#[CoversClass(NovidadesExtension::class)]
final class NovidadesExtensionTest extends TestCase
{
    #[TestDox('arquivo ilegível não derruba a página: o menu fica sem selo e o erro vai para o log')]
    public function testArquivoQuebradoNaoDerrubaAPagina(): void
    {
        $arquivo = tempnam(sys_get_temp_dir(), 'novidades');
        file_put_contents($arquivo, "versoes:\n- versao: \"quebrada\"\n");
        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $erros = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->erros[] = (string) $message;
            }
        };

        try {
            $extensao = new NovidadesExtension(new LeitorDeNovidades($arquivo), $logger);
            self::assertNull($extensao->maisRecente());
            self::assertNull($extensao->maisRecente());
            self::assertCount(1, $logger->erros, 'lê o arquivo uma vez por requisição');
        } finally {
            unlink($arquivo);
        }
    }
}
