<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\DTO;

use App\AssinaturaEletronica\Service\Cadeia\DecisaoDaCadeia;
use App\AssinaturaEletronica\Service\ConferenciaDePdf\ResultadoDaConferencia;
use App\AssinaturaEletronica\Service\ResultadoDoPdfAssinado;

/**
 * O PDF assinado que passou em tudo, fora da transação, e vai ser guardado como a versão `devolvida` (etapa 5, bloco
 * 5C): o motor, a regra da E6-6, o CPF do certificado (E5-3) e a cadeia confirmada pelo validador (E6-6).
 *
 * Leva a ordem da última versão lida ANTES do motor (nula se não havia nenhuma): com a linha travada, quem grava só
 * guarda se ela ainda é a última. Lida antes, ela nunca é mais nova que a base que o motor conferiu; se a última na
 * trava é ela, é também a base do motor, e o arquivo começa pela versão atual.
 */
final readonly class PdfAssinadoConfirmado
{
    private function __construct(
        public PdfAssinadoEnviado $arquivo,
        public ResultadoDaConferencia $motor,
        public ?int $ordemDaBase,
        public DecisaoDaCadeia $cadeia,
    ) {
    }

    /** @throws \LogicException conferência recusada ou cadeia não confirmada */
    public static function de(PdfAssinadoEnviado $arquivo, ResultadoDoPdfAssinado $conferencia, ?int $ordemDaBase, DecisaoDaCadeia $cadeia): self
    {
        if (!$conferencia->aceitou() || $conferencia->motor === null || !$cadeia->confirmou()) {
            throw new \LogicException('Só o PDF assinado aceito pelo motor e com a cadeia confirmada é guardado.');
        }

        return new self($arquivo, $conferencia->motor, $ordemDaBase, $cadeia);
    }

    /** Quantas assinaturas digitais o arquivo tem (o `n` do método no desenho, dc:387). */
    public function assinaturas(): int
    {
        return \count($this->motor->assinaturas);
    }
}
