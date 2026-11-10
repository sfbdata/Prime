<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Service\ConferenciaDePdf;

/** Resultado da conferência de um PDF (contrato da etapa 5, seção 3.1). */
final readonly class ResultadoDaConferencia
{
    /**
     * @param list<AssinaturaConferida> $assinaturas da mais antiga para a mais recente (ordem do fim da faixa)
     */
    public function __construct(
        public SituacaoDaConferencia $situacao,
        public string $motivo,
        public int $tamanho,
        public array $assinaturas,
        /** 0 sem DocMDP; 1, 2 ou 3: o P da primeira assinatura que tem DocMDP */
        public int $docMdp,
        public bool $alteradoDepoisDaCertificacao,
        public bool $cifrado,
        public bool $permiteCarimbo,
        public ConferenciaDoPrefixo $prefixo,
        public int $assinaturasNovas,
        public ConferenciaDoCpf $cpf,
    ) {
    }

    public function temAssinatura(): bool
    {
        return $this->assinaturas !== [];
    }

    public function ultimaAssinatura(): ?AssinaturaConferida
    {
        return $this->assinaturas === [] ? null : $this->assinaturas[\count($this->assinaturas) - 1];
    }
}
