<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Pdf;

/**
 * Como cada página vira JPEG no {@see EscritorDePdf}. O desenho é o padrão: a página inteira da {@see Tela}
 * (2480 x 3507 px) em JPEG 90, de 1,2 a 2,2 MB por página. O motor e o validador param em 20 MB, então o perfil leve
 * existe só para o gerado que passar disso: a mesma página reduzida à metade (150 dpi) e em JPEG 70, de 260 a 400 KB
 * por página. O tamanho da página no PDF é o mesmo nos dois; só a imagem tem menos pixels.
 */
enum PerfilDoPdf: string
{
    case Desenho = 'desenho';
    case Leve = 'leve';

    /** A qualidade da JPEG de cada página, de 0 a 100. */
    public function qualidade(): int
    {
        return match ($this) {
            self::Desenho => EscritorDePdf::QUALIDADE,
            self::Leve => 70,
        };
    }

    /** Quanto a largura e a altura da página desenhada são multiplicadas antes de virar JPEG. */
    public function reducao(): float
    {
        return match ($this) {
            self::Desenho => 1.0,
            self::Leve => 0.5,
        };
    }
}
