<?php

declare(strict_types=1);

namespace App\Tarefa\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tarefa\TarefaMensagem;
use App\Entity\Tenant\Tenant;
use App\Repository\TarefaMensagemRepository;
use App\Tarefa\DTO\CertificarCumprimentoInput;
use App\Tarefa\Exception\CertidaoRecusadaException;
use App\Tarefa\Service\QuemCuidaDaMeta;

/**
 * Certificar o cumprimento de um despacho (desenho 04B de 22/10, L71 e L161; spec `minhas-metas-esteira-2210.md`,
 * C15). Responsável ou criador da meta escreve "Certifico que ..."; grava quem e quando, e o "Cumprir até" some da
 * linha do tempo. Uma certidão por despacho. O texto já chega validado pelo Input (início e limite de 1.000).
 */
final class CertificarCumprimentoUseCase
{
    public function __construct(
        private readonly TarefaMensagemRepository $mensagens,
        private readonly QuemCuidaDaMeta $quemCuida,
    ) {
    }

    public function executar(TarefaMensagem $despacho, User $quem, Tenant $escritorio, CertificarCumprimentoInput $input, \DateTimeImmutable $agora): void
    {
        $meta = $despacho->getTarefa();
        if ($meta === null || $despacho->getTenant()?->getId() !== $escritorio->getId()) {
            throw CertidaoRecusadaException::foraDoEscritorio();
        }
        if (!$this->quemCuida->ehResponsavelOuCriador($meta, $quem)) {
            throw CertidaoRecusadaException::semPermissao();
        }
        if ($despacho->temCertidao()) {
            throw CertidaoRecusadaException::jaCertificado();
        }

        $despacho->certificar($quem, trim($input->texto), $agora);
        $this->mensagens->confirmar();
    }
}
