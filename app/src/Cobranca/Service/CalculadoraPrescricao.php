<?php

declare(strict_types=1);

namespace App\Cobranca\Service;

use App\Cobranca\DTO\ObrigacaoOutput;
use App\Cobranca\DTO\PrescricaoOutput;

/**
 * Contagem de dias até o limite de ajuizamento (2026-07-27), exibida no cabeçalho do objeto.
 *
 * Regra, inteira: pega a obrigação EM ABERTO com o vencimento mais antigo, soma `PRAZO_PADRAO_ANOS`
 * e conta quantos dias faltam. Só isso.
 *
 * O que ela DELIBERADAMENTE não faz — e por quê:
 *
 * - **Não conhece interrupção nem suspensão.** Protesto, citação, confissão de dívida e reconhecimento
 *   reiniciam ou suspendem o prazo, e nada disso está modelado no sistema. Uma calculadora que fingisse
 *   conhecê-los daria número errado com cara de certo.
 * - **Não distingue a natureza da dívida.** O padrão de 5 anos vem do prazo de cobrança de dívida
 *   líquida constante de instrumento (CC art. 206 §5º I), que é o caso de cota condominial — o uso real
 *   deste módulo. Carteira de outra natureza precisaria de outro prazo; por isso a constante está
 *   isolada, pronta para virar configuração da carteira quando alguém precisar.
 * - **Não usa obrigação quitada nem substituída por acordo vigente.** Elas não estão em aberto: não há
 *   o que ajuizar.
 *
 * Sem obrigação em aberto, devolve `null` e a caixa nem aparece na tela.
 *
 * O relógio vem SEMPRE de fora (o mesmo `EncargosVivos::agora()` que rege o resto da página). Nada de
 * `new \DateTimeImmutable()` aqui dentro: a data do "vencido" e a do prazo não podem divergir, e sem o
 * relógio injetado o teste não conseguiria fixar a faixa de severidade.
 */
final class CalculadoraPrescricao
{
    /**
     * Prazo padrão aplicado ao vencimento da competência mais antiga. Constante única: mudar aqui muda
     * a tela inteira e os testes juntos. Ver a nota da classe sobre por que 5.
     */
    public const PRAZO_PADRAO_ANOS = 5;

    /** Faltando isto ou menos, a caixa fica crítica (vermelha). */
    public const DIAS_CRITICO = 90;

    /** Faltando isto ou menos, a caixa entra em atenção (âmbar). */
    public const DIAS_ATENCAO = 180;

    /**
     * @param list<ObrigacaoOutput> $obrigacoesEmAberto obrigações vivas e não quitadas do caso — o
     *                                                  MESMO conjunto que a aba Dívida lista, já
     *                                                  filtrado por quem chama
     */
    public function calcular(array $obrigacoesEmAberto, \DateTimeImmutable $agora): ?PrescricaoOutput
    {
        $maisAntiga = null;
        foreach ($obrigacoesEmAberto as $obrigacao) {
            if ($maisAntiga === null || $obrigacao->vencimentoOriginal < $maisAntiga->vencimentoOriginal) {
                $maisAntiga = $obrigacao;
            }
        }

        if ($maisAntiga === null) {
            return null;
        }

        $prazoLimite = $maisAntiga->vencimentoOriginal->add(new \DateInterval('P' . self::PRAZO_PADRAO_ANOS . 'Y'));

        // Diferença em DIAS DE CALENDÁRIO, não em segundos: um prazo que vence hoje às 23h não pode
        // aparecer como "0 dias" de manhã e "-1" à noite. Zerar a hora dos dois lados torna a contagem
        // estável ao longo do dia — e é assim que uma pessoa conta prazo.
        $inicio = $agora->setTime(0, 0);
        $fim = $prazoLimite->setTime(0, 0);
        $diasRestantes = (int) $inicio->diff($fim)->format('%r%a');

        return new PrescricaoOutput(
            obrigacaoId: $maisAntiga->id,
            obrigacaoDescricao: $maisAntiga->descricao,
            vencimentoMaisAntigo: $maisAntiga->vencimentoOriginal,
            prazoLimite: $prazoLimite,
            diasRestantes: $diasRestantes,
            severidade: $this->severidade($diasRestantes),
            prazoAnos: self::PRAZO_PADRAO_ANOS,
        );
    }

    private function severidade(int $diasRestantes): string
    {
        if ($diasRestantes < 0) {
            return PrescricaoOutput::SEVERIDADE_ESGOTADA;
        }

        if ($diasRestantes <= self::DIAS_CRITICO) {
            return PrescricaoOutput::SEVERIDADE_CRITICA;
        }

        if ($diasRestantes <= self::DIAS_ATENCAO) {
            return PrescricaoOutput::SEVERIDADE_ATENCAO;
        }

        return PrescricaoOutput::SEVERIDADE_INFORMATIVA;
    }
}
