<?php

declare(strict_types=1);

namespace App\Tenant\Service;

use App\Shared\Service\OrcamentoDeMemoriaDeImagem;
use App\Tenant\DTO\LogoPreparada;
use App\Tenant\Exception\DadosDoEscritorioInvalidos;

/**
 * Confere e regrava a logo do escritório. O que vai ao armazenamento é só o que o GD desenhou, nunca os bytes enviados
 * (some o EXIF e qualquer coisa escondida no arquivo). Na ordem: no máximo 2 MB; o tipo pelo CONTEÚDO (`finfo`), só
 * PNG, JPEG, WEBP ou GIF, e o `getimagesizefromstring` concorda; a matriz do GD cabe na memória; redução para no
 * máximo 320 px no maior lado (nunca amplia). JPEG sai JPEG; PNG, GIF e WEBP saem PNG (com a transparência).
 */
final class PreparadorDeLogoDoEscritorio
{
    public const LIMITE_EM_BYTES = 2 * 1024 * 1024;
    public const LADO_MAXIMO = 320;
    public const LADO_MAXIMO_ENVIADO = 6000;
    public const GRANDE_DEMAIS = 'A logo passa de 2 MB.';
    public const TIPO_RECUSADO = 'Envie uma imagem PNG, JPG, WEBP ou GIF.';
    public const SEM_MEMORIA = 'A imagem é grande demais para processar. Envie uma menor.';
    private const TIPOS = [
        'image/png' => \IMAGETYPE_PNG,
        'image/jpeg' => \IMAGETYPE_JPEG,
        'image/webp' => \IMAGETYPE_WEBP,
        'image/gif' => \IMAGETYPE_GIF,
    ];

    public function preparar(string $bytes): LogoPreparada
    {
        if (\strlen($bytes) > self::LIMITE_EM_BYTES) {
            throw new DadosDoEscritorioInvalidos(self::GRANDE_DEMAIS, 'logo');
        }
        $mime = strtolower((string) (new \finfo(\FILEINFO_MIME_TYPE))->buffer($bytes));
        $info = $bytes === '' ? false : @getimagesizefromstring($bytes);
        if (!isset(self::TIPOS[$mime]) || $info === false || $info[2] !== self::TIPOS[$mime]) {
            throw new DadosDoEscritorioInvalidos(self::TIPO_RECUSADO, 'logo');
        }
        [$largura, $altura] = [(int) $info[0], (int) $info[1]];
        if ($largura < 1 || $altura < 1 || $largura > self::LADO_MAXIMO_ENVIADO || $altura > self::LADO_MAXIMO_ENVIADO) {
            throw new DadosDoEscritorioInvalidos(self::TIPO_RECUSADO, 'logo');
        }
        if (!OrcamentoDeMemoriaDeImagem::cabe($largura, $altura)) {
            throw new DadosDoEscritorioInvalidos(self::SEM_MEMORIA, 'logo');
        }
        $origem = @imagecreatefromstring($bytes);
        if ($origem === false) {
            throw new DadosDoEscritorioInvalidos(self::TIPO_RECUSADO, 'logo');
        }

        $jpeg = $mime === 'image/jpeg';
        $destino = $this->reduzida($origem, $largura, $altura, $jpeg);
        [$novaLargura, $novaAltura] = [imagesx($destino), imagesy($destino)];

        ob_start();
        $escreveu = $jpeg ? imagejpeg($destino, null, 88) : imagepng($destino, null, 9);
        $saida = (string) ob_get_clean();
        if (!$escreveu || $saida === '') {
            throw new DadosDoEscritorioInvalidos(self::TIPO_RECUSADO, 'logo');
        }

        return new LogoPreparada($saida, $jpeg ? 'image/jpeg' : 'image/png', $jpeg ? 'jpg' : 'png', $novaLargura, $novaAltura);
    }

    private function reduzida(\GdImage $origem, int $largura, int $altura, bool $jpeg): \GdImage
    {
        $fator = min(1.0, self::LADO_MAXIMO / max($largura, $altura));
        $novaLargura = max(1, (int) round($largura * $fator));
        $novaAltura = max(1, (int) round($altura * $fator));
        $destino = imagecreatetruecolor($novaLargura, $novaAltura);
        if (!$jpeg) {
            imagealphablending($destino, false);
            imagesavealpha($destino, true);
            imagefill($destino, 0, 0, (int) imagecolorallocatealpha($destino, 0, 0, 0, 127));
        }
        imagecopyresampled($destino, $origem, 0, 0, 0, 0, $novaLargura, $novaAltura, $largura, $altura);

        return $destino;
    }
}
