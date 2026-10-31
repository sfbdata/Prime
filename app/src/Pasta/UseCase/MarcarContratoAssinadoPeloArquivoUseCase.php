<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;

/**
 * Contrato assinado digitalmente enviado em CONTRATOS passa a pasta para "Assinado"
 * (desenho 02 de 31.10.26, `finUpload`: assinado E nome com "contrato" marca o contrato).
 *
 * Quem dispara: o envio de arquivo da caixa CONTRATOS da aba Financeiro, logo depois de gravar.
 * A detecção da assinatura (`.p7s`/`.p7m` ou PDF assinado) é do upload (`DetectorDeAssinaturaDigital`);
 * aqui só se decide se o arquivo vale como "o contrato assinado". Recibo ou guia assinados não valem.
 *
 * Só marca: nunca reabre. Quem já está Assinado não muda (nenhum cartão novo na linha do tempo).
 * A marcação em si é a do selo (`AlterarSituacaoContratoUseCase`): cartão e andamento acompanham.
 */
final class MarcarContratoAssinadoPeloArquivoUseCase
{
    public function __construct(
        private readonly AlterarSituacaoContratoUseCase $alterarSituacao,
    ) {}

    /** Devolve a situação do contrato da pasta depois do envio (`PENDENTE` ou `REGULAR`). */
    public function executar(Pasta $pasta, PastaDocumento $documento, User $usuario, ?Tenant $tenant): string
    {
        if ($this->valeComoContratoAssinado($pasta, $documento) && $pasta->getSituacaoContrato() !== 'REGULAR') {
            $this->alterarSituacao->executar($pasta, 'REGULAR', $usuario, $tenant);
        }

        return $pasta->getSituacaoContrato();
    }

    private function valeComoContratoAssinado(Pasta $pasta, PastaDocumento $documento): bool
    {
        return $documento->getPasta() === $pasta
            && $documento->getCategoria() === PastaDocumento::CATEGORIA_CONTRATO
            && $documento->estaAssinado()
            && preg_match('/contrato/i', (string) $documento->getNomeOriginal()) === 1;
    }
}
