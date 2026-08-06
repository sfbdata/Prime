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

    /**
     * @var array<string, array{acordo: ?int, valor: ?int, obrigacao: ?Obrigacao}> chave → de qual acordo
     *                                                                            (número EXTERNO) a
     *                                                                            parcela nasceu, com que
     *                                                                            valor, e a linha real
     *                                                                            quando ela existe
     */
    private array $procedencia = [];

    /**
     * Obrigação que esta execução CRIA (não existe id no dry-run — só os índices por chave lógica).
     *
     * `$acordoNumeroExterno` e `$valorCentavos` são a memória de que a porta B da INV-I precisa: quando
     * a conta original de uma aba seguinte disser "sou parcela do acordo N", é aqui que se confere se
     * foi mesmo o acordo N que a criou nesta execução. `$obrigacao` só existe na confirmação — no
     * dry-run nada é gravado, e é justamente por isso que o VALOR é guardado à parte: prévia e
     * confirmação têm de somar o mesmo principal, e só o valor da planilha existe nos dois modos.
     */
    public function registrarCriada(
        CasoCobranca $caso,
        string $nn,
        string $competencia,
        string $tipo,
        ?int $acordoNumeroExterno = null,
        ?int $valorCentavos = null,
        ?Obrigacao $obrigacao = null,
    ): void {
        $this->porChave[self::trio($caso, $nn, $competencia)] = $tipo;
        $this->porChave[self::porNn($caso, $nn)] = $tipo;

        $this->procedencia[self::trio($caso, $nn, $competencia)] = [
            'acordo' => $acordoNumeroExterno,
            'valor' => $valorCentavos,
            'obrigacao' => $obrigacao,
        ];
    }

    /** Obrigação que JÁ EXISTIA e que esta execução mutou — indexada também pela linha real. */
    public function registrarMutada(
        Obrigacao $obrigacao,
        CasoCobranca $caso,
        string $nn,
        string $competencia,
        string $tipo,
        ?int $acordoNumeroExterno = null,
    ): void {
        $this->registrarCriada($caso, $nn, $competencia, $tipo, $acordoNumeroExterno, $obrigacao->getValorOriginal(), $obrigacao);

        $id = $obrigacao->getId();
        if ($id !== null) {
            $this->porChave['obr:' . $id] = $tipo;
            $this->procedencia['obr:' . $id] = [
                'acordo' => $acordoNumeroExterno,
                'valor' => $obrigacao->getValorOriginal(),
                'obrigacao' => $obrigacao,
            ];
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
     * De qual acordo (número EXTERNO) esta execução fez essa dívida ser parcela, com que valor e — só na
     * confirmação — qual é a linha real. Devolve `null` quando esta execução não tocou nela.
     *
     * @return array{acordo: ?int, valor: ?int, obrigacao: ?Obrigacao}|null
     */
    public function procedenciaDoTrio(CasoCobranca $caso, string $nn, string $competencia): ?array
    {
        return $this->procedencia[self::trio($caso, $nn, $competencia)] ?? null;
    }

    /**
     * Idem, pela LINHA real — o caminho do fallback do legado, em que o trio da conta original difere do
     * trio com que a parcela foi registrada mas a obrigação é a mesma.
     *
     * @return array{acordo: ?int, valor: ?int, obrigacao: ?Obrigacao}|null
     */
    public function procedenciaDaObrigacao(Obrigacao $obrigacao): ?array
    {
        $id = $obrigacao->getId();

        return $id === null ? null : ($this->procedencia['obr:' . $id] ?? null);
    }

    /**
     * Esta execução marcou como substituída alguma parcela de um acordo? Devolve os números EXTERNOS
     * desses acordos.
     *
     * Existe para a guarda de desativação (`motivoParaNaoDesativar`): ela descobre parcelas renegociadas
     * por QUERY ao banco, e a query não enxerga o que esta mesma execução acabou de marcar quando a aba
     * do acordo VELHO é processada antes da aba do sucessor. Sem isto, a ordem das abas no arquivo
     * decidiria se um acordo pode ou não ser desativado.
     *
     * @return list<int>
     */
    public function acordosComParcelaRenegociadaNaExecucao(): array
    {
        $numeros = [];
        foreach ($this->procedencia as $chave => $dados) {
            if (($this->porChave[$chave] ?? null) !== 'marcada') {
                continue;
            }
            if ($dados['acordo'] !== null) {
                $numeros[$dados['acordo']] = true;
            }
        }

        return array_map('intval', array_keys($numeros));
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
