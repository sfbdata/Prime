<?php

declare(strict_types=1);

namespace App\Cobranca\Service\Importacao;

use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\Obrigacao;

/**
 * O que UMA execução do importador de acordos já criou ou mutou. É o equivalente do `PessoaEmImportacao`
 * do importador de cadastro, e existe pelo mesmo motivo: **no dry-run o banco nunca muda**, então sem
 * este registro cada linha decide como se fosse a primeira, e a projeção passa a prometer coisa diferente
 * do que a confirmação faz — justamente nos dois números que a spec manda conferir antes de gravar
 * (§1: o principal que SAI e o valor que ENTRA).
 *
 * ## Por que TRÊS índices, e não um
 *
 * O erro que custou três rodadas de revisão foi supor que a chave do acumulador bastava. O código alcança
 * a mesma linha do banco por caminhos diferentes, e cada caminho precisa reencontrar o registro:
 *
 * 1. **`trio` (caso, NN, competência)** — a chave canônica de casamento. Cobre a mesma dívida revisitada
 *    em outra aba do mesmo caso (dois acordos da mesma unidade compartilham o `CasoCobranca`).
 * 2. **`obr:<id>`** — a linha REAL que a busca devolveu. Necessário porque o fallback do legado casa uma
 *    obrigação sem competência com QUALQUER competência da planilha: `X|01/2026` e `X|02/2026` são dois
 *    trios distintos que resolvem para a MESMA obrigação. Sem este índice, a prévia somaria o principal
 *    dela duas vezes. É o caminho que produção terá (~30 linhas sem competência) e que o replay do dev
 *    nunca exercitou — lá não existe uma obrigação com competência nula sequer.
 * 3. **`nn:<caso>|<NN>`** — só para a guarda de ambiguidade da parcela, que pergunta "existe alguma
 *    obrigação com este NN neste caso?" sem olhar competência. Sem ele, a aba 1 criaria `X|C1` e a aba 2
 *    criaria `X|C2` na prévia, enquanto a confirmação recusaria a segunda por ambiguidade.
 *
 * ⚠️ O índice 3 **não** serve para decidir "já toquei nesta dívida": `X|C1` e `X|C2` são dívidas
 * diferentes, e tratá-las como a mesma reintroduziria o casamento por NN sozinho que a frente inteira
 * existe para eliminar. Cada índice responde a UMA pergunta; misturá-los é o erro seguinte.
 */
final class ObrigacoesTocadasNaImportacao
{
    /** @var array<string, string> chave → o que aconteceu com ela (`parcela`, `parcela-vinculada`, `conta`, `marcada`) */
    private array $porChave = [];

    /** Obrigação que esta execução CRIA (não existe id no dry-run — só os índices por chave lógica). */
    public function registrarCriada(CasoCobranca $caso, string $nn, string $competencia, string $tipo): void
    {
        $this->porChave[self::trio($caso, $nn, $competencia)] = $tipo;
        $this->porChave[self::porNn($caso, $nn)] = $tipo;
    }

    /** Obrigação que JÁ EXISTIA e que esta execução mutou — indexada também pela linha real. */
    public function registrarMutada(Obrigacao $obrigacao, CasoCobranca $caso, string $nn, string $competencia, string $tipo): void
    {
        $this->registrarCriada($caso, $nn, $competencia, $tipo);

        $id = $obrigacao->getId();
        if ($id !== null) {
            $this->porChave['obr:' . $id] = $tipo;
        }
    }

    /** Esta execução já tocou nesta dívida exata? Devolve o que fez com ela, ou null. */
    public function tipoDoTrio(CasoCobranca $caso, string $nn, string $competencia): ?string
    {
        return $this->porChave[self::trio($caso, $nn, $competencia)] ?? null;
    }

    /**
     * Esta execução já tocou nesta LINHA? Pergunta indispensável quando a busca casou pelo fallback do
     * legado: o trio muda, a obrigação é a mesma.
     */
    public function tipoDaObrigacao(Obrigacao $obrigacao): ?string
    {
        $id = $obrigacao->getId();

        return $id === null ? null : ($this->porChave['obr:' . $id] ?? null);
    }

    /**
     * Esta execução já criou/tocou alguma obrigação com este NN neste caso, em QUALQUER competência?
     * Responde só à guarda de ambiguidade da parcela — nunca à decisão de "é a mesma dívida".
     */
    public function temAlgumaComNn(CasoCobranca $caso, string $nn): bool
    {
        return isset($this->porChave[self::porNn($caso, $nn)]);
    }

    private static function trio(CasoCobranca $caso, string $nn, string $competencia): string
    {
        return $caso->getId() . '|' . $nn . '|' . $competencia;
    }

    private static function porNn(CasoCobranca $caso, string $nn): string
    {
        return 'nn:' . $caso->getId() . '|' . $nn;
    }
}
