<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Service\ConferenciaDePdf;

/**
 * Uma assinatura do PDF, conferida (contrato da etapa 5, seção 3.2). Datas em UTC. Com faixa inválida só os números
 * da faixa vêm preenchidos (`tipo` vazio), como no protótipo.
 */
final readonly class AssinaturaConferida
{
    /**
     * @param list<int> $faixaDeBytes os quatro números do /ByteRange; número com mais de 12 dígitos vira -1
     */
    public function __construct(
        public int $ordem,
        public array $faixaDeBytes,
        public bool $faixaValida,
        /** `c + m`; -1 quando algum número da faixa é absurdo */
        public int $fim,
        public bool $cobreODocumento,
        public int $bytesDepois,
        public string $tipo,
        public ?string $algoritmoDoResumo,
        public ?bool $resumoConfere,
        public ?bool $chaveConfere,
        public ?string $titular,
        public ?string $cpf,
        public ?string $cnpj,
        public ?string $documentoMascarado,
        public ?string $emissor,
        public bool $icpBrasil,
        public ?string $numeroDeSerie,
        public ?\DateTimeImmutable $validoDesde,
        public ?\DateTimeImmutable $validoAte,
        public ?\DateTimeImmutable $horaDeclarada,
        public ?int $docMdp,
        public VereditoDaAssinatura $veredito,
        public string $motivo,
    ) {
    }

    public function carimboDoTempo(): bool
    {
        return $this->tipo === ConferenciaDePdf::TIPO_CARIMBO_DO_TEMPO;
    }
}
