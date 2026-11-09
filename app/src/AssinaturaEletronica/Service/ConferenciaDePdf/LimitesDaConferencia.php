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

    /**
     * Trecho fora da faixa (o `<hex>` do /Contents): 16 MB de hexadecimal, 8 MB de CMS. Cabe um /Contents com LCRs de
     * validação de longo prazo dentro do PDF de 20 MB (M23).
     */
    public const TRECHO_DA_ASSINATURA_MAXIMO_EM_BYTES = 16 * 1024 * 1024;

    public const PROFUNDIDADE_MAXIMA_DER = 32;

    /**
     * Elementos de uma estrutura DER lida de uma vez (o CMS inteiro ou o valor de uma extensão), sem contar o que há
     * dentro dos containers que o motor não lê (M23).
     */
    public const ELEMENTOS_MAXIMOS_DER = 50_000;

    /** Bytes do campo de tamanho no formato longo: 4 dá tamanhos até 4 GB. */
    public const BYTES_DE_TAMANHO_MAXIMOS_DER = 4;

    public const CERTIFICADOS_MAXIMOS = 30;

    /** Cabeçalhos `N G obj` vistos, fluxos /ObjStm percorridos e o /N de cada um. */
    public const OBJETOS_MAXIMOS = 10_000;

    /** Saída do inflate (defesa contra bomba de compressão). */
    public const INFLADO_MAXIMO_EM_BYTES = 10 * 1024 * 1024;

    /**
     * Trabalho somado de uma leitura de objeto: bytes copiados (janelas do dicionário, dados do fluxo) mais bytes
     * inflados. Cinco vezes o teto do PDF: cabe um PDF real e para o arquivo feito para gastar CPU (M22).
     */
    public const TRABALHO_MAXIMO_EM_BYTES = 100 * 1024 * 1024;

    public const CARACTERES_MAXIMOS_DE_NOME = 256;

    /** Cabeçalhos `N G obj` e palavras `endobj` e `endstream` no índice do arquivo inteiro (análise do acréscimo, M27). */
    public const CABECALHOS_MAXIMOS_DO_ARQUIVO = 200_000;

    /** Valores lidos nos dicionários de uma conferência (análise do acréscimo, M27). */
    public const ELEMENTOS_MAXIMOS_DA_SINTAXE = 1_000_000;

    private function __construct()
    {
    }
}
