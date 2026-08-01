<?php

declare(strict_types=1);

namespace App\Cobranca\Service;

use App\Cobranca\Entity\Acordo;
use App\Cobranca\Entity\Obrigacao;
use App\Cobranca\Repository\ObrigacaoRepository;
use App\Entity\Tenant\Tenant;

/**
 * Faz as obrigações ORIGINAIS voltarem a CRESCER quando um acordo é desfeito — rompido ou cancelado
 * (spec `cobranca-cancelar-acordo.md` §D5).
 *
 * Existe como serviço, e não como método privado repetido nos dois UseCases, porque a regra é a MESMA
 * nos dois e é caminho de dinheiro: duas cópias de uma decisão voltam a divergir (foi exatamente esse
 * o defeito da prévia × confirmação dos importadores). Um método só é a única garantia estrutural.
 *
 * O que "voltar como estava" significa, nas palavras do dono: *"as obrigações devem voltar como
 * estavam antes de criar acordo, sem congelar nada"*.
 *
 * ⚠️ O que este serviço deliberadamente NÃO faz: apagar o vínculo `acordoSubstituto`. Ver `restaurar()`.
 */
final class RestauradorObrigacoesOriginais
{
    public function __construct(
        private readonly ObrigacaoRepository $obrigacaoRepository,
    ) {
    }

    /**
     * Descongela as originais que o acordo desfeito havia substituído. Persiste SEM flush — quem fecha
     * a transação é o evento de histórico do UseCase chamador.
     *
     * **O vínculo `acordoSubstituto` é PRESERVADO de propósito**, e isto é o oposto do que parece
     * intuitivo. Três razões, em ordem de importância:
     *
     * 1. **A original já volta ao saldo sem apagar nada.** `doCasoExigiveis` inclui a substituída cujo
     *    acordo NÃO é vigente (`asub.status IN (rompido, cancelado)`) — a restauração do saldo é
     *    derivada do status do acordo, nunca imperativa (invariável 20).
     * 2. **A tela também já a trata como dívida normal.** `ObrigacaoOutput::substituidaPorAcordo` é
     *    vigente-aware: com o acordo desfeito ela é `false`, some o rótulo e voltam os botões.
     * 3. **É a única memória de quais originais aquele acordo substituiu.** Apagado o vínculo, essa
     *    informação não existe em lugar nenhum: se um dia o acordo voltar a valer — por reativação, por
     *    correção manual, por um acordo novo sobre as mesmas dívidas —, o sistema não teria como tirar
     *    as originais do saldo, e as duas dívidas passariam a somar juntas. Guardar custa um `int`.
     *
     * (Não existe mecanismo de reativação hoje, por decisão do dono em 01/08: *"se tiver acordo de novo
     * das mesmas dívidas, então é para criar novo normalmente"*. O vínculo é preservado mesmo assim —
     * jogar fora a informação seria a escolha irreversível, e a régua do dono é que ação de dinheiro
     * não pode ser irreversível.)
     *
     * INV-C2: a LIQUIDADA é pulada. Ali o congelamento é da QUITAÇÃO — `liquidar()` é o único ponto do
     * código que congela, e quem o desfaz é `reabrir()`. Descongelar aqui poria juros a correr sobre
     * dívida já paga.
     *
     * INV-C3: descongelar BASTA para restaurar. Não é preciso desfazer a materialização que
     * `CriarAcordoUseCase` fez na data do acordo — descongelada, a próxima leitura hidrata do zero
     * (vencimento → hoje × taxa) e sobrescreve o snapshot.
     *
     * @param Obrigacao[] $substituidas obrigações já materializadas em array pelo chamador
     *
     * @return int quantas foram descongeladas (para o registro no histórico)
     */
    public function restaurar(array $substituidas): int
    {
        $descongeladas = 0;

        foreach ($substituidas as $substituida) {
            if ($substituida->estaLiquidada()) {
                continue;
            }

            $substituida->descongelarEncargos();
            $this->obrigacaoRepository->salvar($substituida);
            ++$descongeladas;
        }

        return $descongeladas;
    }

    /**
     * As originais que o acordo substituiu, por QUERY — nunca pela coleção inversa
     * `Acordo::getObrigacoesSubstituidas()`.
     *
     * 🔑 A coleção inversa nasce VAZIA quando o acordo foi criado na mesma unidade de trabalho
     * (`CriarAcordoUseCase` só escreve o lado dono). O laço de `restaurar()` então não descongelaria
     * nada, **em silêncio** — e o defeito passaria despercebido justamente no teste, porque em produção
     * o cancelamento é outro request e a coleção carrega do banco. A query dá a mesma resposta nos dois.
     *
     * @return Obrigacao[]
     */
    public function substituidasDe(Acordo $acordo, Tenant $tenant): array
    {
        return $this->obrigacaoRepository->substituidasPorAcordo($acordo, $tenant);
    }
}
