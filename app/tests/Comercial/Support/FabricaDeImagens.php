<?php

declare(strict_types=1);

namespace App\Tests\Comercial\Support;

/** Imagens pequenas feitas pelo GD para os testes da imagem da campanha (F3i). */
final class FabricaDeImagens
{
    public static function jpeg(int $largura, int $altura): string
    {
        $imagem = self::tela($largura, $altura);
        ob_start();
        imagejpeg($imagem, null, 90);

        return (string) ob_get_clean();
    }

    /** Um JPEG com um segmento APP1 "Exif" logo depois do SOI, com o marcador dentro (o que a regravação tem de tirar). */
    public static function jpegComExif(int $largura, int $altura, string $marcador): string
    {
        $jpeg = self::jpeg($largura, $altura);
        $carga = "Exif\0\0" . 'MM' . $marcador;
        $segmento = "\xFF\xE1" . pack('n', \strlen($carga) + 2) . $carga;

        return substr($jpeg, 0, 2) . $segmento . substr($jpeg, 2);
    }

    public static function png(int $largura, int $altura): string
    {
        $imagem = self::tela($largura, $altura);
        ob_start();
        imagepng($imagem);

        return (string) ob_get_clean();
    }

    public static function gif(int $largura, int $altura): string
    {
        $imagem = self::tela($largura, $altura);
        ob_start();
        imagegif($imagem);

        return (string) ob_get_clean();
    }

    private static function tela(int $largura, int $altura): \GdImage
    {
        $imagem = imagecreatetruecolor($largura, $altura);
        \assert($imagem instanceof \GdImage);
        imagefill($imagem, 0, 0, (int) imagecolorallocate($imagem, 10, 111, 160));

        return $imagem;
    }
}
