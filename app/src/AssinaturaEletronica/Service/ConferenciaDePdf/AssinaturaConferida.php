<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Service\ConferenciaDePdf;

/**
 * Uma assinatura do PDF, conferida (contrato da etapa 5, seção 3.2). Datas em UTC. Com faixa inválida só os números
 * da faixa vêm preenchidos (`tipo` vazio), como no protótipo.
 *
 * Titular, CPF, CNPJ, emissor e ICP-Brasil são DECLARADOS pelo certificado: a cadeia até a AC raiz não é conferida
 * antes da etapa 6 (pyHanko), e qualquer um faz um certificado com o nome que quiser (M25). `vinculoDoCertificado`
 * diz se o certificado é o que o signatário assinou ou se pode ter sido trocado depois (M24).
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
        /** CN do titular, declarado pelo certificado */
        public ?string $titular,
        /** declarado pelo certificado */
        public ?string $cpf,
        /** declarado pelo certificado */
        public ?string $cnpj,
        public ?string $documentoMascarado,
        /** CN do emissor, declarado pelo certificado */
        public ?string $emissor,
        /** a organização do emissor contém "ICP-Brasil"; só o texto, sem cadeia conferida (M25) */
        public bool $emissorDeclaraIcpBrasil,
        public ?string $numeroDeSerie,
        public ?\DateTimeImmutable $validoDesde,
        public ?\DateTimeImmutable $validoAte,
        public ?\DateTimeImmutable $horaDeclarada,
        public ?int $docMdp,
        public VereditoDaAssinatura $veredito,
        public string $motivo,
        public VinculoDoCertificado $vinculoDoCertificado = VinculoDoCertificado::NaoConferido,
    ) {
    }

    public function carimboDoTempo(): bool
    {
        return $this->tipo === ConferenciaDePdf::TIPO_CARIMBO_DO_TEMPO;
    }
}
