<?php

declare(strict_types=1);

namespace App\Ponto\DTO;

use App\Ponto\Entity\AceiteDaFolhaDoPonto;
use App\Ponto\Enum\SituacaoDoAceiteDaFolha;
use App\Ponto\Exception\AssinaturaDaFolhaRecusadaException;

/**
 * O aceite da folha de uma competência, para a barra da folha e para quem acabou de assinar (spec ponto-desenho-0811,
 * E16, P22; decisões 1, 3 e 4): o aceite que vale (o de maior número), se a folha mudou depois dele e se a pessoa pode
 * assinar agora. Sem aceite, os campos do aceite são nulos.
 *
 * `impedimento` diz por que não pode assinar (um motivo de {@see AssinaturaDaFolhaRecusadaException}), ou null.
 */
final readonly class AceiteDaFolhaOutput
{
    public const TEXTO_ALTERADA = 'Alterada depois do aceite';

    public function __construct(
        public int $ano,
        public int $mes,
        public ?SituacaoDoAceiteDaFolha $situacao,
        public ?\DateTimeImmutable $aceitoEm,
        public ?string $ressalva,
        public ?int $numero,
        public ?string $codigoDaFolhaVista,
        public bool $alterada,
        public bool $podeAssinar,
        public ?string $impedimento,
    ) {
    }

    public static function daCompetencia(int $ano, int $mes, ?AceiteDaFolhaDoPonto $vigente, bool $alterada, ?string $impedimento): self
    {
        return new self(
            $ano,
            $mes,
            $vigente?->getSituacao(),
            $vigente?->getAceitoEm(),
            $vigente?->getRessalva(),
            $vigente?->getNumero(),
            $vigente?->getFolhaVista()->getCodigo(),
            $vigente !== null && $alterada,
            $impedimento === null,
            $impedimento,
        );
    }

    /** O aceite que acabou de ser gravado: vale, não está alterado e não dá para assinar de novo. */
    public static function doAceiteGravado(AceiteDaFolhaDoPonto $aceite): self
    {
        return self::daCompetencia($aceite->getAno(), $aceite->getMes(), $aceite, false, AssinaturaDaFolhaRecusadaException::JA_ASSINADA);
    }

    public function temAceite(): bool
    {
        return $this->situacao !== null;
    }

    /** O aceite vai carimbado no PDF: há aceite e a folha não mudou depois dele (decisão 3). */
    public function carimbaAFolha(): bool
    {
        return $this->temAceite() && !$this->alterada;
    }

    /**
     * O texto do selo da barra: "Assinada em dd/mm/aaaa" ou "Ressalva em dd/mm/aaaa" (desenho L816), "Alterada depois
     * do aceite" no lugar deles quando a folha mudou (decisão 4), ou null sem aceite.
     */
    public function textoDoSelo(): ?string
    {
        if ($this->situacao === null || $this->aceitoEm === null) {
            return null;
        }
        if ($this->alterada) {
            return self::TEXTO_ALTERADA;
        }
        $prefixo = $this->situacao === SituacaoDoAceiteDaFolha::Concordo ? 'Assinada em ' : 'Ressalva em ';

        return $prefixo . $this->aceitoEm->format('d/m/Y');
    }
}
