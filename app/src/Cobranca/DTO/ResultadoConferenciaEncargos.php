<?php

declare(strict_types=1);

namespace App\Cobranca\DTO;

/**
 * O encargo GRAVADO no banco × o que a nossa fórmula produz na data do próprio snapshot
 * (SPEC docs/specs/cobranca-espelho-da-contabilidade.md §17).
 *
 * É a régua que faltava: nem a conferência (que compara `valor_original`) nem a calibração (que
 * compara a fórmula contra as colunas da planilha) leem o que está escrito em
 * `cobranca_obrigacao.juros/multa/correcao/honorarios`. Sem ela, a Fase 1 não consegue provar o
 * próprio conserto — rodar a calibração antes e depois de matar a dupla contagem dá o mesmo número.
 *
 * 🔑 **São DUAS dimensões de contagem independentes, e confundi-las já produziu relatório mentiroso.**
 *
 * 1. **Classificação** (o que a fórmula diz) — não depende de lote nenhum, roda em toda obrigação:
 *    `coerentes + comDuplaContagem + divergentes == universo`
 * 2. **Cobertura da assinatura** (contra o que deu para ler) — depende de haver o lote que escreveu:
 *    `assinaturaAvaliada + semParNoRelatorio + injulgaveis == universo`
 *
 * As duas dimensões acima valem **quatro** identidades no total, porque a lista da reconciliação tem as
 * suas (contagem e dinheiro) — todas verificadas por {@see self::baldesFecham()}. Num instrumento cuja
 * lista vira reescrita de dinheiro, a conta tem de fechar exata — e falhar alto quando não fecha.
 */
final readonly class ResultadoConferenciaEncargos
{
    /**
     * `$duplicadoPorCampo` existe porque um escalar único escondia coisas de naturezas diferentes:
     * multa duplicada entra no saldo cobrado, honorário **não** entra (`Obrigacao::valorExigivel()`
     * soma principal + juros + multa + correção). Levar um total à contabilidade sem separar os dois
     * seria apresentar como "saldo cobrado duas vezes" algo que em parte não é saldo.
     *
     * `$injulgaveis` NÃO é um balde de defeito — é a contagem do que esta régua **não sabe julgar**:
     * obrigação cujo snapshot não corresponde a nenhum lote carregado (INV-CE6). Ela continua
     * classificada como coerente ou divergente (isso não precisa de lote); o que fica suspenso é só a
     * acusação de dupla contagem. Precisa aparecer no relatório porque silenciá-la faria "0 dupla
     * contagem" significar duas coisas incompatíveis — "conferi e está limpo" e "não tinha contra o
     * que conferir".
     *
     * 🔑 **INV-CE8 — `$duplicadas` é a LISTA DA RECONCILIAÇÃO, e `$piores` NÃO é.**
     *
     * São perguntas diferentes e a confusão entre as duas já foi para a documentação: `$piores`
     * responde *"onde o gravado mais se afastou da nossa fórmula"*, está **cortado em 20** e ordenado
     * por essa diferença; `$duplicadas` responde *"quais dívidas têm dinheiro contado duas vezes"*,
     * é **completa** e ordenada pelo duplicado.
     *
     * Medido pela revisão no dev: o 20º item de `$piores` já estava em R$ 91,72, enquanto o duplicado
     * típico é da ordem de R$ 38 por dívida — ou seja, usar `$piores` como fonte da reconciliação
     * deixaria de fora justamente as dívidas a corrigir.
     *
     * Cada linha carrega **o lote que foi usado** (id + emissão): sem isso, um erro de reconciliação
     * não teria como ser achado nem desfeito (decisão do dono, §17.8 da spec).
     *
     * @param array<string, int>                                                       $duplicadoPorCampo campo => centavos
     * @param list<array{unidade: string, referencia: ?string, campo: string, gravado: int,
     *                   pelaFormula: int, diferenca: int, duplicado: int, ehParcelaDeAcordo: bool}> $piores
     * @param list<array{obrigacaoId: ?int, unidade: string, referencia: ?string, competencia: ?string,
     *                   loteId: ?int, loteEmitidoEm: ?\DateTimeImmutable,
     *                   duplicadoPorCampo: array<string, int>, duplicadoNoSaldo: int,
     *                   duplicadoForaDoSaldo: int, corrigidoPorCampo: array<string, int>}> $duplicadas
     */
    public function __construct(
        public string $carteira,
        public ?\DateTimeImmutable $dadosAte,
        public int $universo,
        public int $assinaturaAvaliada,
        public int $semParNoRelatorio,
        public int $coerentes,
        public int $comDuplaContagem,
        public int $divergentes,
        public int $injulgaveis,
        public int $diferencaEmCentavos,
        public int $duplicadoEmCentavos,
        public array $duplicadoPorCampo,
        public array $piores,
        public array $duplicadas,
    ) {
    }

    /** O que a reconciliação tiraria do SALDO do devedor (juros + multa + correção). */
    public function duplicadoNoSaldoEmCentavos(): int
    {
        return array_sum(array_column($this->duplicadas, 'duplicadoNoSaldo'));
    }

    /** O que ela tiraria FORA do saldo (honorário) — não move a conta de ninguém. */
    public function duplicadoForaDoSaldoEmCentavos(): int
    {
        return array_sum(array_column($this->duplicadas, 'duplicadoForaDoSaldo'));
    }

    /**
     * As QUATRO identidades do resultado. Falso aqui significa que a régua perdeu dívida ou dinheiro
     * pelo caminho, e o comando **falha alto** em vez de imprimir número que não soma.
     *
     * As duas últimas entraram por achado de revisão: a manchete imprime `duplicadoEmCentavos`
     * (acumulado no laço) e o rodapé da lista imprime os dois somatórios por **outro caminho**
     * (`array_column` sobre `$duplicadas`). Hoje batem por construção — e é exatamente esse "por
     * construção" que uma refatoração quebra em silêncio.
     */
    public function baldesFecham(): bool
    {
        return $this->coerentes + $this->comDuplaContagem + $this->divergentes === $this->universo
            && $this->assinaturaAvaliada + $this->semParNoRelatorio + $this->injulgaveis === $this->universo
            && count($this->duplicadas) === $this->comDuplaContagem
            && $this->duplicadoNoSaldoEmCentavos() + $this->duplicadoForaDoSaldoEmCentavos()
                === $this->duplicadoEmCentavos;
    }

    /** Sobre o UNIVERSO, não sobre o que deu para conferir — senão 100% pode significar 2 de 530. */
    public function percentualCoerente(): float
    {
        return $this->universo === 0 ? 0.0 : ($this->coerentes / $this->universo) * 100;
    }

    /** Quanto do universo teve a assinatura efetivamente lida contra as colunas do lote que o escreveu. */
    public function percentualCoberto(): float
    {
        return $this->universo === 0 ? 0.0 : ($this->assinaturaAvaliada / $this->universo) * 100;
    }

    /**
     * O veredito, e a ordem dos estados é decisão, não acaso.
     *
     * `dupla contagem` vence tudo mesmo com um único caso: divergência é ruído esperado (snapshot
     * velho, arredondamento), enquanto a assinatura é dinheiro contado duas vezes e não tem versão
     * pequena aceitável.
     *
     * 🔑 **`coerente` exige cobertura TOTAL**, e essa guarda é a correção de um defeito medido: com o
     * veredito cego para os injulgáveis, a régua imprimia a caixa verde *"Todo encargo gravado é um
     * número que a nossa fórmula produz"* numa carteira com **2 dívidas conferidas e 528 jamais
     * examinadas**. Um instrumento que diz "está tudo certo" sobre 0,4% do universo é pior que
     * instrumento nenhum, porque o silêncio dele é lido como prova.
     */
    public function veredito(): string
    {
        if ($this->universo === 0) {
            return 'sem dado';
        }

        if ($this->comDuplaContagem > 0) {
            return 'dupla contagem';
        }

        if ($this->divergentes > 0) {
            return 'divergente';
        }

        return $this->injulgaveis === 0 ? 'coerente' : 'cobertura incompleta';
    }
}
