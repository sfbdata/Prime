<?php

declare(strict_types=1);

namespace App\Comercial\Campanha;

use App\Comercial\DTO\ImagemPreparada;
use App\Comercial\Exception\CampanhaRecusada;
use App\Shared\Service\OrcamentoDeMemoriaDeImagem;

/**
 * Confere e regrava a imagem da campanha do modo simples (spec `comercial-e-emails.md` §12, F3i). O que vai ao disco é
 * só o que o GD desenhou, nunca os bytes enviados: some o EXIF (com a localização) e qualquer coisa escondida no
 * arquivo. Na ordem:
 *   1. no máximo 1 MB, contado nos bytes recebidos;
 *   2. o tipo pelo CONTEÚDO (`finfo`), só JPEG ou PNG, e o `getimagesizefromstring` tem de concordar com ele;
 *   3. no máximo 4000 px de cada lado, e a matriz do GD tem de caber na memória (decodificar sem caber é Fatal error);
 *   4. a regravação (JPEG com qualidade 0,85, como o desenho; PNG com a transparência) também não passa de 1 MB.
 *
 * O navegador já reduz a 1080 px e converte em JPEG (dc L659); estes limites valem para quem chega por fora da tela.
 */
final class PreparadorDeImagemDaCampanha
{
    public const LIMITE_EM_BYTES = 1024 * 1024;
    public const LADO_MAXIMO = 4000;
    public const GRANDE_DEMAIS = 'A imagem passa de 1 MB.';
    public const TIPO_RECUSADO = 'Envie uma imagem JPG ou PNG.';
    public const DIMENSAO_RECUSADA = 'A imagem passa de 4000 px de lado.';
    public const SEM_MEMORIA = 'A imagem é grande demais para processar. Envie uma menor.';
    private const QUALIDADE_JPEG = 85;
    private const NIVEL_PNG = 9;
    /** O tipo pelo conteúdo e o tipo que o `getimagesizefromstring` precisa enxergar nele. */
    private const TIPOS = ['image/jpeg' => \IMAGETYPE_JPEG, 'image/png' => \IMAGETYPE_PNG];

    public function preparar(string $bytes): ImagemPreparada
    {
        if (\strlen($bytes) > self::LIMITE_EM_BYTES) {
            throw new CampanhaRecusada(self::GRANDE_DEMAIS);
        }
        $mime = strtolower((string) (new \finfo(\FILEINFO_MIME_TYPE))->buffer($bytes));
        $info = $bytes === '' ? false : @getimagesizefromstring($bytes);
        if (!isset(self::TIPOS[$mime]) || $info === false || $info[2] !== self::TIPOS[$mime]) {
            throw new CampanhaRecusada(self::TIPO_RECUSADO);
        }
        [$largura, $altura] = [(int) $info[0], (int) $info[1]];
        if ($largura < 1 || $altura < 1 || $largura > self::LADO_MAXIMO || $altura > self::LADO_MAXIMO) {
            throw new CampanhaRecusada($largura < 1 || $altura < 1 ? self::TIPO_RECUSADO : self::DIMENSAO_RECUSADA);
        }
        if (!OrcamentoDeMemoriaDeImagem::cabe($largura, $altura)) {
            throw new CampanhaRecusada(self::SEM_MEMORIA);
        }
        $regravada = self::regravar($bytes, $mime);
        if (\strlen($regravada) > self::LIMITE_EM_BYTES) {
            throw new CampanhaRecusada(self::GRANDE_DEMAIS);
        }

        return new ImagemPreparada($regravada, $mime, $mime === 'image/png' ? 'png' : 'jpg', $largura, $altura);
    }

    private static function regravar(string $bytes, string $mime): string
    {
        $imagem = @imagecreatefromstring($bytes);
        if ($imagem === false) {
            throw new CampanhaRecusada(self::TIPO_RECUSADO);
        }
        if ($mime === 'image/png') {
            imagealphablending($imagem, false);
            imagesavealpha($imagem, true);
        }
        ob_start();
        $escreveu = $mime === 'image/png' ? imagepng($imagem, null, self::NIVEL_PNG) : imagejpeg($imagem, null, self::QUALIDADE_JPEG);
        $saida = (string) ob_get_clean();
        if (!$escreveu || $saida === '') {
            throw new CampanhaRecusada(self::TIPO_RECUSADO);
        }

        return $saida;
    }
}
