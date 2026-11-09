<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Service;

use App\AssinaturaEletronica\DTO\SelfieRegravada;
use App\AssinaturaEletronica\Exception\AcaoRecusada;
use App\Shared\Service\OrcamentoDeMemoriaDeImagem;

/**
 * A foto que chega no "Assinar" Reforçado (contrato da etapa 7, decisão 6 e seção 6), na ordem:
 *
 *   1. data URL `data:image/...;base64,` de até 4 MB (o tamanho é conferido no base64, antes de decodificar);
 *   2. o tipo pelo CONTEÚDO (`finfo`), só JPEG ou PNG, e o `getimagesizefromstring` tem de concordar com ele (o
 *      prefixo da data URL não conta);
 *   3. de 240 a 4.096 px em cada lado, até 12,5 megapixels, e as matrizes do GD (a foto e a regravada) cabendo na
 *      memória (decodificar sem caber é Fatal error);
 *   4. regravada em JPEG qualidade 85 pelo GD, sobre fundo branco (o transparente do PNG não vira preto) e com o lado
 *      maior até 1.600 px: sai o EXIF (com o GPS da câmera) e qualquer byte escondido no arquivo.
 */
final class RegravacaoDaSelfie
{
    public const MAXIMO_EM_BYTES = 4 * 1024 * 1024;
    public const LADO_MINIMO = 240;
    public const LADO_MAXIMO = 4096;
    public const PIXELS_MAXIMO = 12_500_000;
    public const LADO_GRAVADO = 1600;
    public const QUALIDADE = 85;
    private const TIPOS = ['image/jpeg' => \IMAGETYPE_JPEG, 'image/png' => \IMAGETYPE_PNG];

    private function __construct()
    {
    }

    /** @throws AcaoRecusada */
    public static function regravar(string $dataUrl): SelfieRegravada
    {
        $bytes = self::decodificar($dataUrl);
        $mime = strtolower((string) (new \finfo(\FILEINFO_MIME_TYPE))->buffer($bytes));
        $info = @getimagesizefromstring($bytes);
        if (!isset(self::TIPOS[$mime]) || $info === false || $info[2] !== self::TIPOS[$mime]) {
            throw new AcaoRecusada('assinatura_eletronica.erro.selfie_invalida');
        }

        [$largura, $altura] = [(int) $info[0], (int) $info[1]];
        [$novaLargura, $novaAltura] = self::medidasGravadas($largura, $altura);
        if (!OrcamentoDeMemoriaDeImagem::cabe($largura * $altura + $novaLargura * $novaAltura, 1)) {
            throw new AcaoRecusada('assinatura_eletronica.erro.selfie_tamanho');
        }
        $jpeg = self::jpeg($bytes, $largura, $altura, $novaLargura, $novaAltura);

        return new SelfieRegravada($jpeg, hash('sha256', $jpeg), $novaLargura, $novaAltura);
    }

    /** @throws AcaoRecusada */
    private static function decodificar(string $dataUrl): string
    {
        if (preg_match('#^data:image/[a-z0-9.+-]{1,40};base64,#i', $dataUrl, $prefixo) !== 1) {
            throw new AcaoRecusada('assinatura_eletronica.erro.selfie_invalida');
        }
        // Base64 tem 4 caracteres para cada 3 bytes: corta antes de decodificar o que já passa do teto.
        if (\strlen($dataUrl) - \strlen($prefixo[0]) > 4 * (int) ceil(self::MAXIMO_EM_BYTES / 3)) {
            throw new AcaoRecusada('assinatura_eletronica.erro.selfie_grande');
        }

        $bytes = base64_decode(substr($dataUrl, \strlen($prefixo[0])), true);
        if ($bytes === false || $bytes === '') {
            throw new AcaoRecusada('assinatura_eletronica.erro.selfie_invalida');
        }
        if (\strlen($bytes) > self::MAXIMO_EM_BYTES) {
            throw new AcaoRecusada('assinatura_eletronica.erro.selfie_grande');
        }

        return $bytes;
    }

    /**
     * As medidas da regravada (só reduz, mantendo a proporção), depois de conferir as da foto.
     *
     * @return array{int, int}
     *
     * @throws AcaoRecusada
     */
    private static function medidasGravadas(int $largura, int $altura): array
    {
        if (min($largura, $altura) < self::LADO_MINIMO || max($largura, $altura) > self::LADO_MAXIMO || $largura * $altura > self::PIXELS_MAXIMO) {
            throw new AcaoRecusada('assinatura_eletronica.erro.selfie_tamanho');
        }
        $escala = min(1, self::LADO_GRAVADO / max($largura, $altura));

        return [max(1, (int) round($largura * $escala)), max(1, (int) round($altura * $escala))];
    }

    /** @throws AcaoRecusada */
    private static function jpeg(string $bytes, int $largura, int $altura, int $novaLargura, int $novaAltura): string
    {
        $foto = @imagecreatefromstring($bytes);
        if ($foto === false) {
            throw new AcaoRecusada('assinatura_eletronica.erro.selfie_invalida');
        }
        $regravada = imagecreatetruecolor($novaLargura, $novaAltura);
        imagefill($regravada, 0, 0, (int) imagecolorallocate($regravada, 255, 255, 255));
        imagecopyresampled($regravada, $foto, 0, 0, 0, 0, $novaLargura, $novaAltura, $largura, $altura);
        unset($foto);

        ob_start();
        $escreveu = imagejpeg($regravada, null, self::QUALIDADE);
        $jpeg = (string) ob_get_clean();
        if (!$escreveu || $jpeg === '') {
            throw new AcaoRecusada('assinatura_eletronica.erro.selfie_invalida');
        }

        return $jpeg;
    }
}
