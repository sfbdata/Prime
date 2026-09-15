<?php

declare(strict_types=1);

namespace App\Ponto\Validacao;

use Symfony\Component\Validator\Constraints\File;

/**
 * Regra única do anexo de justificativa de ponto — tipos e tamanho.
 *
 * Existe porque criação e edição divergiram: `JustificativaPontoType` validava com `Assert\File`
 * (10 MB, PDF/JPEG/PNG), enquanto a edição lia `$request->files->get('anexo')` cru e mandava direto
 * para o storage, sem limite de tamanho nem de tipo. Qualquer arquivo entrava pela porta da edição.
 *
 * Com a regra num lugar só, as duas portas não têm como divergir de novo.
 */
final class RestricoesAnexoJustificativa
{
    /**
     * O limite é declarado APENAS nesta forma. Uma constante em bytes conviveria mal com ela: o
     * `File` do Symfony trata o sufixo `M` como 1000*1000 (10.000.000), não 1024*1024
     * (10.485.760) — duas "mesmas" regras com 485.760 bytes de diferença. Quem precisar do valor
     * numérico deve derivá-lo daqui, não redeclará-lo.
     */
    public const MAX_LEGIVEL = '10M';

    /** @var string[] */
    public const MIMES_PERMITIDOS = ['application/pdf', 'image/jpeg', 'image/png'];

    public const MENSAGEM_TAMANHO = 'O arquivo não pode exceder 10 MB.';

    public const MENSAGEM_TIPO = 'Somente PDF, JPEG ou PNG são aceitos.';

    public static function constraint(): File
    {
        return new File(
            maxSize: self::MAX_LEGIVEL,
            mimeTypes: self::MIMES_PERMITIDOS,
            maxSizeMessage: self::MENSAGEM_TAMANHO,
            mimeTypesMessage: self::MENSAGEM_TIPO,
        );
    }
}
