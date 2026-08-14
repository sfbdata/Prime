<?php

declare(strict_types=1);

namespace App\Cobranca\Service\Espelho;

use App\Cobranca\Enum\TipoRelatorioContabil;

/**
 * Entrega o leitor certo para cada um dos quatro relatórios da contabilidade
 * (SPEC docs/specs/cobranca-espelho-quatro-relatorios.md §4.1).
 *
 * Os quatro são injetados por tipo concreto, não por iterador de tag: são exatamente quatro, são
 * conhecidos em tempo de escrita, e a lista fechada é o que faz `paraTipo()` **nunca** devolver
 * `null`. Um registro aberto por tag deixaria a ausência de um leitor virar erro em tempo de execução,
 * dentro de um comando que roda em produção — que é o pior lugar para descobrir isso.
 */
final class LeitoresDoEspelho
{
    public function __construct(
        private readonly LeitorEspelhoRelatorio $inadimplencia,
        private readonly LeitorEspelhoAcordos $acordos,
        private readonly LeitorEspelhoReceitas $receitas,
        private readonly LeitorEspelhoCadastro $cadastro,
    ) {
    }

    public function paraTipo(TipoRelatorioContabil $tipo): LeitorDeEspelho
    {
        return match ($tipo) {
            TipoRelatorioContabil::Inadimplencia => $this->inadimplencia,
            TipoRelatorioContabil::Acordos => $this->acordos,
            TipoRelatorioContabil::Receitas => $this->receitas,
            TipoRelatorioContabil::Cadastro => $this->cadastro,
        };
    }

    /**
     * Todos os quatro, na ordem do enum — usado pela declaração de cobertura, que precisa saber o
     * universo do que PODIA ter sido lido, não só o que foi.
     *
     * @return array<string, LeitorDeEspelho>
     */
    public function todos(): array
    {
        $todos = [];

        foreach (TipoRelatorioContabil::cases() as $tipo) {
            $todos[$tipo->value] = $this->paraTipo($tipo);
        }

        return $todos;
    }
}
