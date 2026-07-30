<?php

declare(strict_types=1);

namespace App\Cobranca\Service\Importacao;

/**
 * Uma linha da seção "Relação das contas originais" de uma aba de acordo: a dívida que EXISTIA antes e
 * que o acordo substituiu. Value object fonte-agnóstico (spec `cobranca-importar-acordos-detalhados.md`
 * §2/§3.2).
 *
 * A chave de casamento com a `Obrigacao` do sistema é **NN + competência**, nunca o NN sozinho — por
 * isso os dois campos são obrigatórios aqui. Casar só pelo NN marcaria como substituídas 3 dívidas de
 * 2022 da carteira TOP LIFE I por causa de acordos de 2026 da TOP LIFE 2, apagando R$ 435,00 de
 * cobrança legítima (spec §1, `cobranca-importar-chave-competencia.md`).
 */
final class ContaOriginalImportavel
{
    public function __construct(
        public readonly string $nn,
        public readonly string $classe,
        public readonly string $competencia,
        public readonly \DateTimeImmutable $vencimento,
        public readonly int $valorCentavos,
    ) {
    }

    /**
     * Descrição da Obrigação reconstruída (§3.2.1), no MESMO formato que o importador de inadimplência
     * gera (`BoletoImportavel::descricao()`) — uma conta reconstruída não pode parecer de outra espécie
     * na tela. A marcação de procedência é acrescentada pelo UseCase, que é quem conhece a emissão.
     */
    public function descricao(): string
    {
        return trim("{$this->classe} — competência {$this->competencia}", ' —');
    }
}
