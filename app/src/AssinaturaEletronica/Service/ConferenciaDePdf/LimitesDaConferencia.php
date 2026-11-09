<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Service\ConferenciaDePdf;

/**
 * Tetos do motor de conferência (contrato da etapa 5, seção 5). O PDF vem de fora: cada leitura tem um limite, e passar
 * dele vira resultado com motivo, nunca exceção.
 */
final class LimitesDaConferencia
{
    /** O dobro do upload de PDF da assinatura (10 MB), para caber as versões assinadas que voltam. */
    public const PDF_MAXIMO_EM_BYTES = 20 * 1024 * 1024;

    /** Ocorrências do texto `/ByteRange`, repetidas ou não. */
    public const DECLARACOES_DE_FAIXA_MAXIMAS = 100;

    /** Faixas diferentes, cada uma é uma assinatura conferida. */
    public const ASSINATURAS_MAXIMAS = 30;

    /** Número da faixa com mais dígitos que isto é absurdo (o PDF tem no máximo 20 MB). */
    public const DIGITOS_MAXIMOS_DA_FAIXA = 12;

    /** Trecho fora da faixa (o `<hex>` do /Contents): 2 MB de hexadecimal, 1 MB de CMS. */
    public const TRECHO_DA_ASSINATURA_MAXIMO_EM_BYTES = 2 * 1024 * 1024;

    public const PROFUNDIDADE_MAXIMA_DER = 32;

    /** Elementos de uma estrutura DER lida de uma vez (o CMS inteiro ou o valor de uma extensão). */
    public const ELEMENTOS_MAXIMOS_DER = 50_000;

    /** Bytes do campo de tamanho no formato longo: 4 dá tamanhos até 4 GB. */
    public const BYTES_DE_TAMANHO_MAXIMOS_DER = 4;

    public const CERTIFICADOS_MAXIMOS = 30;

    /** Cabeçalhos `N G obj` vistos, fluxos /ObjStm percorridos e o /N de cada um. */
    public const OBJETOS_MAXIMOS = 10_000;

    /** Saída do inflate (defesa contra bomba de compressão). */
    public const INFLADO_MAXIMO_EM_BYTES = 10 * 1024 * 1024;

    public const CARACTERES_MAXIMOS_DE_NOME = 256;

    private function __construct()
    {
    }
}
