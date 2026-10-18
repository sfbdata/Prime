<?php

declare(strict_types=1);

namespace App\Tests\Novidades\Unit;

use App\Novidades\DTO\VersaoComNovidades;
use App\Novidades\Service\LeitorDeNovidades;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(LeitorDeNovidades::class)]
#[CoversClass(VersaoComNovidades::class)]
final class LeitorDeNovidadesTest extends TestCase
{
    private string $arquivo;

    protected function setUp(): void
    {
        $this->arquivo = tempnam(sys_get_temp_dir(), 'novidades') . '.yaml';
    }

    protected function tearDown(): void
    {
        @unlink($this->arquivo);
    }

    #[TestDox('lê as versões na ordem do arquivo, com data e itens')]
    public function testLeVersoes(): void
    {
        file_put_contents($this->arquivo, <<<YAML
            versoes:
            - versao: "2.1.0"
              data: "2026-10-20"
              itens:
                - "Agora há Y."
            - versao: "2.0.0"
              data: "2026-10-17"
              itens:
                - "Primeira."
                - "Segunda."
            YAML);

        $versoes = (new LeitorDeNovidades($this->arquivo))->versoes();

        self::assertCount(2, $versoes);
        self::assertSame('2.1.0', $versoes[0]->versao);
        self::assertSame('20/10/2026', $versoes[0]->data->format('d/m/Y'));
        self::assertSame(['Agora há Y.'], $versoes[0]->itens);
        self::assertSame(['Primeira.', 'Segunda.'], $versoes[1]->itens);
    }

    #[TestDox('a mais recente é a primeira; sem arquivo, não há novidade')]
    public function testMaisRecente(): void
    {
        file_put_contents($this->arquivo, "versoes:\n- versao: \"2.0.0\"\n  data: \"2026-10-17\"\n  itens: [\"x\"]\n");
        self::assertSame('2.0.0', (new LeitorDeNovidades($this->arquivo))->maisRecente()?->versao);

        self::assertNull((new LeitorDeNovidades('/nao/existe.yaml'))->maisRecente());
        self::assertSame([], (new LeitorDeNovidades('/nao/existe.yaml'))->versoes());
    }

    #[TestDox('entrada fora do formato derruba a leitura (o teste do arquivo real pega no PR)')]
    public function testFormatoInvalido(): void
    {
        file_put_contents($this->arquivo, "versoes:\n- versao: \"v2\"\n  data: \"ontem\"\n  itens: []\n");

        $this->expectException(\UnexpectedValueException::class);
        (new LeitorDeNovidades($this->arquivo))->versoes();
    }
}
