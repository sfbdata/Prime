<?php

declare(strict_types=1);

namespace App\Ponto\Service\ImagemDoComprovante;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;

/**
 * O QR Code do pé do comprovante (desenho 16 L583) e da folha em PDF (L698): fundo branco com 4 de margem e os
 * módulos em azul-escuro, correção M, como o `qrcode(0, 'M')` do desenho. O endereço é o da validação no próprio
 * sistema (D20).
 */
final class QrNaTela
{
    private const AZUL_ESCURO = '#0b3550';

    public function __construct(
        private readonly TelaDoComprovante $tela,
    ) {
    }

    /** O QR com `lado` de módulos a partir de (x + 4, y + 4), sobre o quadrado branco de `lado + 8`. */
    public function desenhar(string $endereco, float $x, float $y, float $lado): void
    {
        $matriz = Encoder::encode($endereco, ErrorCorrectionLevel::M())->getMatrix();
        $modulos = $matriz->getWidth();
        $u = $lado / $modulos;
        $this->tela->retangulo($x, $y, $lado + 8, $lado + 8, $this->tela->cor('#ffffff'));
        $escuro = $this->tela->cor(self::AZUL_ESCURO);
        for ($linha = 0; $linha < $modulos; ++$linha) {
            for ($coluna = 0; $coluna < $modulos; ++$coluna) {
                if ($matriz->get($coluna, $linha) === 1) {
                    $this->tela->retangulo($x + 4 + $coluna * $u, $y + 4 + $linha * $u, $u + .25, $u + .25, $escuro);
                }
            }
        }
    }
}
