<?php

declare(strict_types=1);

namespace App\Cobranca\Service\Importacao;

/**
 * Veredito da conferência da linha `Filtros:` do rodapé (spec
 * `docs/specs/cobranca-validador-rodape-filtros.md` §3.1).
 *
 * Carrega a linha crua junto porque a mensagem de erro precisa mostrar ao operador O QUE veio no
 * arquivo — dizer só "recorte errado" o obrigaria a abrir o `.xlsx` à mão para descobrir o quê, que é
 * exatamente o trabalho que este item existe para poupar.
 *
 * ⚠️ Não guarda os campos separados nem os órfãos: a 1ª revisão mostrou que eram payload morto
 * (nenhum consumidor), e campo sem consumidor envelhece mentindo. Se um dia a mensagem precisar
 * deles, eles voltam junto com o consumidor.
 */
final class ResultadoRodape
{
    /**
     * @param list<string> $motivos uma frase por expectativa violada; vazio quando aceito
     */
    public function __construct(
        public readonly bool $aceito,
        public readonly array $motivos,
        public readonly ?string $linha = null,
    ) {
    }
}
