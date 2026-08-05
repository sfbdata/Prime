<?php

declare(strict_types=1);

namespace App\Cobranca\Service\Importacao;

/**
 * Veredito da conferência da linha `Filtros:` do rodapé (spec
 * `docs/specs/cobranca-validador-rodape-filtros.md` §3.1).
 *
 * Carrega junto os campos lidos e a linha crua porque a mensagem de erro precisa mostrar ao operador
 * O QUE veio no arquivo — dizer só "recorte errado" obriga a abrir o .xlsx à mão para descobrir o quê,
 * que é exatamente o trabalho que este item existe para poupar.
 */
final class ResultadoRodape
{
    /**
     * @param list<string>          $motivos uma frase por expectativa violada; vazio quando aceito
     * @param array<string, string> $campos  o que o rodapé trazia, já separado em chave => valor
     * @param list<string>          $orfaos  pedaços sem `chave:` (ver §2.2.2 da spec)
     */
    public function __construct(
        public readonly bool $aceito,
        public readonly array $motivos,
        public readonly ?string $linha = null,
        public readonly array $campos = [],
        public readonly array $orfaos = [],
    ) {
    }
}
