<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Pdf;

/**
 * Os textos fixos que o `pdf()` do desenho imprime na folha (dc:409-412). Ficam aqui nesta etapa; o bloco 1b liga
 * às traduções. O formato da data e hora é o `toLocaleString('pt-BR')` do desenho.
 */
final class TextosDaFolha
{
    public const RASCUNHO = 'RASCUNHO';
    public const ROTULO_DO_RODAPE = 'CÓDIGO DE VALIDAÇÃO · PÁGINA %d DE %d';
    public const PREFIXO_DO_HASH = 'SHA-256 ';
    public const RECUO_DO_HASH = '        ';
    public const CONFIRA = 'Confira em bluejus.com.br/assinar/validar ou leia o QR Code.';
    public const EMITIDO_EM = 'Emitido em %s. Vale somente com registro correspondente no BlueJus.';
    public const ASSINADO_EM = 'Assinado em %s';
    public const ASSINATURA = 'Assinatura';
    public const PENDENTE = 'PENDENTE';
    public const MARCA_DAGUA = 'BLUEJUS · %s · ';
    public const MICROTEXTO = 'BLUEJUS ASSINATURA ELETRONICA DOCUMENTO AUTENTICO %s ';
    public const FAIXA = 'BLUEJUS ASSINA';
    public const ANEL = 'BLUEJUS AUTENTICO %s ';
    public const ANEL_INTERNO = 'BLUEJUS · ';
    public const MARCA_AZUL = 'Blue';
    public const MARCA_CLARA = 'Jus';

    private function __construct()
    {
    }

    public static function dataHora(\DateTimeImmutable $quando): string
    {
        return $quando->format('d/m/Y, H:i:s');
    }
}
