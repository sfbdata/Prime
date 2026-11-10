<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Unit;

use App\Ponto\DTO\DadosDoComprovante;
use App\Ponto\Service\ImagemDoComprovante\ImagemDoComprovante;
use App\Ponto\Service\ImagemDoComprovante\TelaDoComprovante;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * A imagem do comprovante (E15, P49; desenho 16 L550 a L594): JPEG 93 em 2480 x 3670, padrões tirados do próprio selo
 * e memória que cabe com folga no `memory_limit` de 128 MB da produção.
 */
#[Group('ponto')]
#[CoversClass(ImagemDoComprovante::class)]
#[CoversClass(TelaDoComprovante::class)]
final class ImagemDoComprovanteTest extends TestCase
{
    /** O que a geração pode somar ao que a requisição já usa: metade do limite de produção (128 MB). */
    private const TETO_DE_MEMORIA = 64 * 1024 * 1024;
    private const SELO = 'ab12cd34ab12cd34ab12cd34ab12cd34ab12cd34ab12cd34ab12cd34ab12cd34';

    #[TestDox('JPEG em alta (2480 x 3670), e a geração isolada fica abaixo de 64 MB de pico')]
    public function testJpegEmAltaComFolgaDeMemoria(): void
    {
        $gerador = $this->gerador();
        $dados = $this->dados();
        gc_collect_cycles();
        memory_reset_peak_usage();
        $antes = memory_get_usage(true);

        $jpeg = $gerador->gerar($dados, self::SELO, 'https://bluejus.test/ponto/validar/BJP-ABCD-EFGH-JK23', 'bluejus.test/ponto/validar');

        $pico = memory_get_peak_usage(true) - $antes;
        self::assertLessThan(self::TETO_DE_MEMORIA, $pico, sprintf('pico de %.1f MB', $pico / 1048576));
        $tamanho = getimagesizefromstring($jpeg);
        self::assertNotFalse($tamanho);
        self::assertSame([2480, 3670, IMAGETYPE_JPEG], [$tamanho[0], $tamanho[1], $tamanho[2]]);
    }

    #[TestDox('Os padrões saem do selo: o mesmo comprovante gera a mesma imagem; outro selo, outra imagem')]
    public function testPadroesDoSelo(): void
    {
        $gerador = $this->gerador();
        $dados = $this->dados();
        $gerar = fn (string $selo): string => $gerador->gerar($dados, $selo, 'https://bluejus.test/ponto/validar/X', 'bluejus.test/ponto/validar', 0.5);

        self::assertSame($gerar(self::SELO), $gerar(self::SELO));
        self::assertNotSame($gerar(self::SELO), $gerar(str_repeat('9f', 32)));
    }

    #[TestDox('O "Trabalho externo" do MÉTODO (desenho L584) muda a imagem; sem ele, a linha sai sem a marca')]
    public function testTrabalhoExternoMudaAImagem(): void
    {
        $gerador = $this->gerador();
        $gerar = fn (DadosDoComprovante $dados): string => $gerador->gerar($dados, self::SELO, 'https://bluejus.test/ponto/validar/X', 'bluejus.test/ponto/validar', 0.5);

        self::assertNotSame($gerar($this->dados()), $gerar($this->dados(trabalhoExterno: false)));
    }

    private function gerador(): ImagemDoComprovante
    {
        return new ImagemDoComprovante(dirname(__DIR__, 3) . '/vendor/dompdf/dompdf/lib/fonts');
    }

    /** A linha do MÉTODO mais longa por padrão: sem internet e trabalho externo. */
    private function dados(bool $trabalhoExterno = true): DadosDoComprovante
    {
        return new DadosDoComprovante(
            'BJP-ABCD-EFGH-JK23',
            1,
            7,
            'Maria da Silva Pereira',
            'Escritório Modelo',
            '12345678000190',
            new \DateTimeImmutable('2026-11-09T08:01:02-03:00'),
            'entrada',
            DadosDoComprovante::METODO_SISTEMA,
            '-23.55000, -46.63000 · precisão 12 m',
            true,
            $trabalhoExterno,
        );
    }
}
