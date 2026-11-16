<?php

declare(strict_types=1);

namespace App\Voto\Service;

use App\Voto\DTO\ResumoDoResultado;
use App\Voto\Exception\OperacaoInvalidaNaAssembleiaException;
use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Renderer\GDLibRenderer;
use BaconQrCode\Writer;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * PNG do resumo para divulgar nos grupos (WhatsApp): largura fixa de 1000 px, altura conforme o número de pautas.
 * Cores e fontes do cartaz do Voto ({@see CartazDaAssembleia}). Só resultado, participação e quórum por pauta, QR do
 * link público e código de conferência: nenhum dado pessoal.
 */
final class ImagemDoResumoDoResultado
{
    private const FONTES = '/usr/share/fonts/opentype/urw-base35/';
    private const LARGURA = 1000;
    private const MARGEM = 60;
    private const QR = 300;
    private const ALTURA_MAXIMA = 12000;

    public function __construct(private readonly TranslatorInterface $tradutor)
    {
    }

    public function gerar(ResumoDoResultado $resumo): string
    {
        $altura = $this->desenhar(null, $resumo);
        // 1000 x 12000 px em true color passa de 45 MB de memória: acima disso o resumo vai só em PDF.
        if ($altura > self::ALTURA_MAXIMA) {
            throw new OperacaoInvalidaNaAssembleiaException($this->tradutor->trans('voto.assembleia.gestor.relatorio.muitas_pautas'));
        }
        $imagem = imagecreatetruecolor(self::LARGURA, $altura);
        if ($imagem === false) {
            throw new \RuntimeException('Não foi possível gerar a imagem do resultado.');
        }
        try {
            imagefilledrectangle($imagem, 0, 0, self::LARGURA, $altura, 0xffffff);
            $this->desenhar($imagem, $resumo);

            return $this->png($imagem);
        } finally {
            imagedestroy($imagem);
        }
    }

    /** Percorre o desenho; sem imagem só mede. Devolve a altura total. */
    private function desenhar(?\GdImage $imagem, ResumoDoResultado $r): int
    {
        $util = self::LARGURA - 2 * self::MARGEM;
        if ($imagem !== null) {
            imagefilledrectangle($imagem, 0, 0, self::LARGURA, 150, 0x0a6fa0);
            $this->centro($imagem, mb_strtoupper($this->tradutor->trans('voto.assembleia.gestor.relatorio.imagem_titulo_pagina')), 56, true, 0xffffff, 98);
        }
        $y = 150 + 28;
        foreach ($this->quebrar($r->titulo, 38, true, $util) as $linha) {
            $y += 48;
            $this->texto($imagem, $linha, self::MARGEM, $y, 38, true, 0x0b3550);
        }
        $y += 24;
        foreach ($r->pautas as $pauta) {
            if ($imagem !== null) {
                imagefilledrectangle($imagem, self::MARGEM, $y, self::LARGURA - self::MARGEM, $y + 3, 0xd6e3ec);
            }
            $y += 24;
            foreach ($this->quebrar($pauta->titulo, 32, true, $util) as $linha) {
                $y += 42;
                $this->texto($imagem, $linha, self::MARGEM, $y, 32, true, 0x0b3550);
            }
            $y += 40;
            $this->texto($imagem, $this->tradutor->trans('voto.assembleia.gestor.relatorio.rotulo_participacao') . ': ' . $pauta->participacao, self::MARGEM, $y, 26, false, 0x34505f);
            $y += 38;
            $this->texto($imagem, $pauta->quorum, self::MARGEM, $y, 26, false, 0x34505f);
            $y += 20;
            foreach ($pauta->linhas as $i => $linha) {
                if ($imagem !== null && $i % 2 === 0) {
                    imagefilledrectangle($imagem, self::MARGEM, $y, self::LARGURA - self::MARGEM, $y + 44, 0xeaf1f6);
                }
                $this->texto($imagem, $this->caber($linha['rotulo'], 28, false, $util - 160), self::MARGEM + 14, $y + 31, 28, false, 0x12242f);
                $this->direita($imagem, (string) $linha['votos'], self::LARGURA - self::MARGEM - 14, $y + 31, 28, true, 0x12242f);
                $y += 44;
            }
            $y += 18;
            foreach ($this->quebrar($this->tradutor->trans('voto.assembleia.gestor.relatorio.rotulo_resultado') . ': ' . $pauta->resultado, 32, true, $util) as $linha) {
                $y += 44;
                $this->texto($imagem, $linha, self::MARGEM, $y, 32, true, 0x1e7b45);
            }
            $y += 36;
        }

        return $this->rodape($imagem, $r, $y);
    }

    private function rodape(?\GdImage $imagem, ResumoDoResultado $r, int $y): int
    {
        $util = self::LARGURA - 2 * self::MARGEM;
        if ($imagem !== null) {
            imagefilledrectangle($imagem, self::MARGEM, $y, self::LARGURA - self::MARGEM, $y + 3, 0xd6e3ec);
        }
        $y += 36;
        if ($imagem !== null) {
            $bytes = (new Writer(new GDLibRenderer(self::QR, 4)))->writeString($r->link, 'UTF-8', ErrorCorrectionLevel::M());
            $qr = imagecreatefromstring($bytes);
            if ($qr === false) {
                throw new \RuntimeException('Não foi possível gerar o QR Code.');
            }
            imagecopy($imagem, $qr, self::MARGEM, $y, 0, 0, self::QR, self::QR);
            imagedestroy($qr);
        }
        $x = self::MARGEM + self::QR + 36;
        $largura = self::LARGURA - self::MARGEM - $x;
        $ty = $y + 20;
        foreach ($this->quebrar($this->tradutor->trans('voto.assembleia.gestor.relatorio.imagem_qr'), 28, true, $largura) as $linha) {
            $ty += 38;
            $this->texto($imagem, $linha, $x, $ty, 28, true, 0x0b3550);
        }
        $ty += 52;
        $this->texto($imagem, $this->tradutor->trans('voto.assembleia.gestor.relatorio.codigo_conferencia', ['codigo' => $r->codigoDeConferencia]), $x, $ty, 30, true, 0x0b3550);
        foreach ($this->quebrar($r->link, 22, false, $largura) as $linha) {
            $ty += 36;
            $this->texto($imagem, $linha, $x, $ty, 22, false, 0x34505f);
        }
        $y += self::QR + 34;
        foreach ($this->quebrar($this->tradutor->trans('voto.assembleia.gestor.relatorio.nota'), 22, false, $util) as $linha) {
            $y += 32;
            $this->texto($imagem, $linha, self::MARGEM, $y, 22, false, 0x8a6200);
        }

        return $y + 40;
    }

    /** @return list<string> */
    private function quebrar(string $texto, float $pixels, bool $negrito, int $largura): array
    {
        $linhas = [];
        $atual = '';
        foreach (preg_split('/\s+/u', trim($texto)) ?: [] as $palavra) {
            $teste = $atual === '' ? $palavra : $atual . ' ' . $palavra;
            if ($atual !== '' && $this->largura($teste, $pixels, $negrito) > $largura) {
                $linhas[] = $atual;
                $atual = $this->caber($palavra, $pixels, $negrito, $largura);
                continue;
            }
            $atual = $this->caber($teste, $pixels, $negrito, $largura);
        }

        return [...$linhas, $atual];
    }

    /** Corta com reticências o que passar da largura (uma palavra sem espaço, por exemplo). */
    private function caber(string $texto, float $pixels, bool $negrito, int $largura): string
    {
        if ($this->largura($texto, $pixels, $negrito) <= $largura) {
            return $texto;
        }
        $curto = $texto;
        while (mb_strlen($curto) > 1 && $this->largura($curto . '...', $pixels, $negrito) > $largura) {
            $curto = mb_substr($curto, 0, -1);
        }

        return $curto . '...';
    }

    private function largura(string $texto, float $pixels, bool $negrito): int
    {
        $caixa = imagettfbbox($pixels * .75, 0, $this->fonte($negrito), $texto);
        if ($caixa === false) {
            throw new \RuntimeException('Não foi possível medir o texto da imagem.');
        }

        return $caixa[2] - $caixa[0];
    }

    private function fonte(bool $negrito): string
    {
        return self::FONTES . ($negrito ? 'NimbusSans-Bold.otf' : 'NimbusSans-Regular.otf');
    }

    private function texto(?\GdImage $imagem, string $texto, int $x, int $y, float $pixels, bool $negrito, int $cor): void
    {
        if ($imagem !== null) {
            imagettftext($imagem, $pixels * .75, 0, $x, $y, $cor, $this->fonte($negrito), $texto);
        }
    }

    private function direita(?\GdImage $imagem, string $texto, int $xDireito, int $y, float $pixels, bool $negrito, int $cor): void
    {
        $this->texto($imagem, $texto, $xDireito - $this->largura($texto, $pixels, $negrito), $y, $pixels, $negrito, $cor);
    }

    private function centro(\GdImage $imagem, string $texto, float $pixels, bool $negrito, int $cor, int $y): void
    {
        $this->texto($imagem, $texto, (int) ((self::LARGURA - $this->largura($texto, $pixels, $negrito)) / 2), $y, $pixels, $negrito, $cor);
    }

    private function png(\GdImage $imagem): string
    {
        ob_start();
        try {
            if (!imagepng($imagem)) {
                throw new \RuntimeException('Não foi possível gerar a imagem do resultado.');
            }

            return (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
    }
}
