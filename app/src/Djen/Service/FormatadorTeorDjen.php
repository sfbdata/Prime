<?php

declare(strict_types=1);

namespace App\Djen\Service;

use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;

/**
 * Deixa o teor da publicação legível e SEGURO para exibir.
 *
 * Dois cenários (o DJEN entrega de formas diferentes por tribunal):
 *  - Teor em HTML (tabelas de partes, negrito, seções): é sanitizado (mantém a formatação de
 *    documento, remove script/style/handlers) — ver sanitizador `djen` em html_sanitizer.yaml.
 *  - Teor em texto corrido (bloco único, sem quebras — comum em TJs): recebe quebras heurísticas
 *    para separar cabeçalho/rótulos e restaurar linhas achatadas em espaços duplos. O texto é
 *    escapado antes de qualquer coisa, então a saída é HTML seguro (só com <br>).
 *
 * A saída SEMPRE é HTML seguro para renderizar com `|raw`.
 */
final class FormatadorTeorDjen
{
    /** Rótulos/cabeçalhos comuns de documentos judiciais — ganham quebra de linha antes. */
    private const ROTULOS = 'Número do processo|Classe judicial|Assunto|Órgão|AUTOR|AUTORA|RÉU|REU|'
        . 'RECLAMANTE|RECLAMADO|RECLAMADA|REQUERENTE|REQUERIDO|REQUERIDA|EXEQUENTE|EXECUTADO|EXECUTADA|'
        . 'EMBARGANTE|EMBARGADO|APELANTE|APELADO|AGRAVANTE|AGRAVADO|IMPETRANTE|IMPETRADO|INTIMADO|INTIMADA|'
        . 'ADVOGADO|ADVOGADA|SENTENÇA|DECISÃO|DESPACHO|DISPOSITIVO|RELATÓRIO|FUNDAMENTAÇÃO|CONCLUSÃO|'
        . 'ATO ORDINATÓRIO|VISTOS|FUNDAMENTO E DECIDO|É o relatório|Ante o exposto|Isso posto|'
        . 'Diante do exposto|Posto isso|Publique-se|Intime-se|Intimem-se|Cumpra-se|DEFIRO|INDEFIRO|HOMOLOGO';

    public function __construct(
        private readonly HtmlSanitizerInterface $djenSanitizer,
    ) {
    }

    public function formatar(?string $textoBruto): ?string
    {
        if ($textoBruto === null) {
            return null;
        }

        $texto = trim($textoBruto);
        if ($texto === '') {
            return null;
        }

        // Tem marcação HTML de formatação? Então mantém a estrutura do documento (sanitizada).
        if (preg_match('/<(br|p|div|section|article|table|tr|td|th|li|ul|ol|h[1-6]|b|strong|em|i|span|font)\b/i', $texto) === 1) {
            return $this->djenSanitizer->sanitize($texto);
        }

        return $this->prettificarTextoCorrido($texto);
    }

    private function prettificarTextoCorrido(string $texto): string
    {
        // Escapa primeiro: a saída é segura; rótulos e espaços sobrevivem ao escape.
        $texto = htmlspecialchars($texto, \ENT_QUOTES | \ENT_HTML5, 'UTF-8');

        // Runs de 2+ espaços costumam ser quebras de linha achatadas na origem.
        $texto = preg_replace('/ {2,}/', "\n", $texto) ?? $texto;

        // Quebra antes de rótulos/cabeçalhos conhecidos.
        $texto = preg_replace('/[ \t]*(?=(?:' . self::ROTULOS . ')\b)/u', "\n", $texto) ?? $texto;

        // Colapsa quebras excessivas.
        $texto = preg_replace('/\n{3,}/', "\n\n", $texto) ?? $texto;

        return str_replace("\n", '<br>', trim($texto));
    }
}
