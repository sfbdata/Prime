<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Pdf;

use App\AssinaturaEletronica\Pdf\Diagramacao\ColunasDaFolha;
use App\AssinaturaEletronica\Pdf\Diagramacao\TextoComNegrito;

/**
 * O alto de cada página das duas colunas (dc:415): texto lateral girado -90 graus em (92, H/2 - 20), de 40 descendo
 * até caber em 1180 (mínimo 18); logo que cabe em 180 x 80 à esquerda; número (`n`) 21 à direita em 72; título (`t`)
 * negrito 17,5 descendo de 0,5 até caber (mínimo 12), centrado entre a logo e a margem, em 112.
 */
final class CabecalhoDeDuasColunas
{
    public const LATERAL_MAIOR = 40.0;
    public const LATERAL_MENOR = 18.0;
    public const LATERAL_CABE_EM = 1180.0;
    public const TITULO_MAIOR = 17.5;
    public const TITULO_MENOR = 12.0;
    private const CINZA = '#44546a';

    public function desenhar(Tela $tela, RecursosDaFolha $recursos): void
    {
        $folha = $recursos->folha;
        $this->lateral($tela, TextoComNegrito::semMarcas((string) $folha->lateral));

        $larguraDaLogo = 0.0;
        $logo = $recursos->logo();
        if ($logo !== null) {
            $k = min(180 / imagesx($logo), 80 / imagesy($logo));
            $larguraDaLogo = imagesx($logo) * $k;
            $tela->imagem($logo, ColunasDaFolha::XL, 92 - imagesy($logo) * $k / 2, $larguraDaLogo, imagesy($logo) * $k);
        }

        $numero = self::primeiro($folha->blocos, 'n');
        if ($numero !== null) {
            $tela->texto($numero, ColunasDaFolha::XR, 72, Fonte::Sans, 21, Cor::hex(self::CINZA), Alinhamento::Direita);
        }

        $titulo = self::primeiro($folha->blocos, 't');
        if ($titulo !== null) {
            $this->titulo($tela, $titulo, ColunasDaFolha::XL + $larguraDaLogo + 16);
        }
    }

    /** `while (measureText(lat).width > 1180 && fz > 18) fz--`, a partir de 40 (dc:415). */
    public static function tamanhoDaLateral(string $texto): float
    {
        $tamanho = self::LATERAL_MAIOR;
        while (MedidorDeTexto::largura($texto, Fonte::Sans, $tamanho) > self::LATERAL_CABE_EM && $tamanho > self::LATERAL_MENOR) {
            --$tamanho;
        }

        return $tamanho;
    }

    /** Negrito de 17,5 descendo de 0,5 em 0,5 até caber em $espaco, no mínimo 12 (dc:415). */
    public static function tamanhoDoTitulo(string $titulo, float $espaco): float
    {
        $tamanho = self::TITULO_MAIOR;
        while (MedidorDeTexto::largura($titulo, Fonte::SansNegrito, $tamanho) > $espaco && $tamanho > self::TITULO_MENOR) {
            $tamanho -= .5;
        }

        return $tamanho;
    }

    private function lateral(Tela $tela, string $texto): void
    {
        $tamanho = self::tamanhoDaLateral($texto);
        $tela->texto($texto, 92, Tela::H / 2 - 20, Fonte::Sans, $tamanho, Cor::hex(self::CINZA), Alinhamento::Centro, -M_PI / 2);
    }

    private function titulo(Tela $tela, string $titulo, float $x0): void
    {
        $tamanho = self::tamanhoDoTitulo($titulo, ColunasDaFolha::XR - $x0);
        $largura = $tela->largura($titulo, Fonte::SansNegrito, $tamanho);
        $tela->texto($titulo, $x0 + (ColunasDaFolha::XR - $x0 - $largura) / 2, 112, Fonte::SansNegrito, $tamanho, Cor::hex('#12242f'));
    }

    /** @param list<array<string, int|string>> $blocos */
    private static function primeiro(array $blocos, string $tipo): ?string
    {
        foreach ($blocos as $bloco) {
            if ($bloco['k'] === $tipo) {
                return TextoComNegrito::semMarcas((string) ($bloco['x'] ?? ''));
            }
        }

        return null;
    }
}
