<?php

declare(strict_types=1);

namespace App\Cobranca\DTO;

use App\Cobranca\Entity\Acordo;
use App\Cobranca\Enum\StatusAcordo;

/**
 * Leitura de um Acordo do Caso (Etapa 8). Estado com badge (`badgeClass()` do enum); contagens de
 * obrigações substituídas × parcelas para o Twig resumir sem iterar coleções. A partir do Ajuste 10,
 * o acordo VIGENTE não usa este DTO isolado na tela — vira `GrupoAcordoObrigacoesOutput` dentro da
 * seção "Dívida em aberto"; este `AcordoOutput` continua alimentando a lista completa (`caso.acordos`)
 * e aparece direto na seção "Acordos encerrados" (rompidos/cancelados, ou vigente sem parcela viva).
 */
final class AcordoOutput
{
    public function __construct(
        public readonly int $id,
        public readonly \DateTimeImmutable $dataAcordo,
        public readonly string $statusLabel,
        public readonly string $statusBadgeClass,
        public readonly bool $vigente,
        public readonly bool $ativo,
        public readonly ?string $motivoRompimento,
        public readonly ?string $motivoCancelamento,
        public readonly int $qtdObrigacoesSubstituidas,
        public readonly int $qtdParcelas,
        /**
         * Id do acordo que ASSUMIU este — derivado, nunca gravado (spec
         * `cobranca-acordo-assume-parcelas-do-anterior.md`). Nulo no acordo comum e também no acordo
         * apenas PARCIALMENTE renegociado, que continua vigente e com parcelas a receber.
         *
         * Quem calcula é o `MontarDetalheCasoUseCase::sucessorPorAcordo`, a partir das obrigações do
         * caso: só ele tem a lista completa, e a coleção inversa do acordo não serve (nasce vazia na
         * mesma unidade de trabalho).
         *
         * ⚠️ **Nulo também quando há MAIS DE UM sucessor** — aí `qtdSucessores` é que responde. Medido
         * no dado real: 8 acordos tiveram as parcelas divididas entre vários acordos novos (4 recebem o
         * selo), e no acervo inteiro um deles chega a 22. Exibir um só seria escolher pela ordem da
         * query e afirmar, para as demais parcelas, uma coisa falsa.
         */
        public readonly ?int $substituidoPeloAcordoId = null,
        /** Quantos acordos distintos assumiram parcelas deste. 0 = não foi substituído. */
        public readonly int $qtdSucessores = 0,
    ) {
    }

    public static function fromEntity(Acordo $a, ?int $substituidoPeloAcordoId = null, int $qtdSucessores = 0): self
    {
        return new self(
            id: $a->getId() ?? 0,
            dataAcordo: $a->getDataAcordo(),
            statusLabel: $a->getStatus()->label(),
            statusBadgeClass: $a->getStatus()->badgeClass(),
            vigente: $a->getStatus()->ehVigente(),
            ativo: $a->getStatus() === StatusAcordo::Ativo,
            motivoRompimento: $a->getMotivoRompimento(),
            motivoCancelamento: $a->getMotivoCancelamento(),
            qtdObrigacoesSubstituidas: $a->getObrigacoesSubstituidas()->count(),
            qtdParcelas: $a->getParcelas()->count(),
            substituidoPeloAcordoId: $substituidoPeloAcordoId,
            qtdSucessores: $qtdSucessores,
        );
    }
}
