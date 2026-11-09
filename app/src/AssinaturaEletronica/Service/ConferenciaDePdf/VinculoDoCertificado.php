<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Service\ConferenciaDePdf;

/**
 * Se o certificado guardado no CMS é o que o signatário assinou (M24): o atributo assinado signingCertificateV2 (ou o
 * signingCertificate v1, com SHA-1) traz o hash do certificado, e o motor compara com o hash do certificado usado.
 * Mesmo vinculado, titular, CPF, CNPJ e ICP-Brasil são só declarados pelo certificado: a cadeia até a AC raiz é da
 * etapa 6 (M25).
 */
enum VinculoDoCertificado: string
{
    /** O hash do atributo é o do certificado, e a assinatura sobre os atributos confere. */
    case Vinculado = 'vinculado';
    /** Sem o atributo (ou sem atributos assinados): o certificado pode ter sido trocado depois da assinatura. */
    case NaoVinculado = 'nao_vinculado';
    /** O hash do atributo não é o do certificado: a assinatura fica `alterada`. */
    case Diferente = 'diferente';
    /** Não deu para conferir: hash fora da lista, chave que não confere ou sem certificado. */
    case NaoConferido = 'nao_conferido';

    public function motivo(): string
    {
        return match ($this) {
            self::Vinculado => 'O certificado é o que o signatário assinou',
            self::NaoVinculado => 'A assinatura não traz o vínculo com o certificado: titular e documento não estão vinculados a ela',
            self::Diferente => 'O certificado não é o que o signatário assinou',
            self::NaoConferido => 'Não foi possível conferir o vínculo do certificado com a assinatura',
        };
    }
}
