<?php

declare(strict_types=1);

namespace App\Cobranca\Service\Importacao;

use App\Cobranca\Enum\StatusAcordo;

/**
 * Uma troca de status decidida pela linha `Situação:` da planilha de acordos detalhados — spec
 * `cobranca-importar-acordos-situacao.md`.
 *
 * Existe como objeto, e não como par de variáveis soltas, porque **a prévia e a confirmação têm de
 * decidir a partir do MESMO valor**. Depois de a confirmação escrever, `$acordo->getStatus()` já é o
 * status novo, e uma guarda que o consultasse de novo daria resposta diferente da prévia — que é
 * exatamente como prévia e confirmação passam a processar conjuntos de abas diferentes (§6). Quem
 * responde "o acordo está vigente?" daqui para a frente é o `$novo` guardado aqui.
 */
final class SobrescritaDeSituacao
{
    public function __construct(
        public readonly int $numero,
        public readonly string $situacaoDaPlanilha,
        public readonly StatusAcordo $anterior,
        public readonly StatusAcordo $novo,
    ) {
    }

    /**
     * Não vigente → vigente. As obrigações originais voltam a "substituída" e SAEM do exigível: é a
     * direção que precisa da medição do `ImpactoDaReativacaoDeAcordo` antes de qualquer escrita.
     */
    public function reativa(): bool
    {
        return !$this->anterior->ehVigente() && $this->novo->ehVigente();
    }

    /**
     * Vigente → não vigente. As originais voltam ao exigível: exige o descongelamento do §D5, senão
     * elas voltam ao saldo com os juros parados.
     */
    public function desativa(): bool
    {
        return $this->anterior->ehVigente() && !$this->novo->ehVigente();
    }

    public function descricao(): string
    {
        return sprintf(
            'Acordo %d: a contábil diz "%s" — status do sistema alterado de %s para %s (o importe sobrescreve o sistema).',
            $this->numero,
            $this->situacaoDaPlanilha,
            $this->anterior->value,
            $this->novo->value,
        );
    }
}
