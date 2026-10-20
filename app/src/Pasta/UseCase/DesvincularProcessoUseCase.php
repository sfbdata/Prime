<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Auth\User;
use App\Pasta\Entity\Pasta;
use App\Pasta\Service\RegistroDoSistemaNaPasta;
use App\Processo\Entity\Processo;
use App\Twig\NumeroProcessoExtension;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Desvincula um processo específico de uma pasta.
 *
 * Quem: usuário com permissão de edição na pasta. O quê: remover um processo da pasta — o
 * registro do Processo permanece (pode estar vinculado a outras pastas). Idempotente: se o
 * processo não estava vinculado, é no-op. Se o desvinculado era o principal e ainda restam
 * processos, o primeiro remanescente é promovido a principal.
 *
 * Registro (P5d, desenho `autoReg('proc', 'processo N desvinculado da pasta.')`): o cartão do sistema na
 * timeline, em nome de quem desvinculou; auditado pelo AuditLogSubscriber (PastaMensagem).
 */
final class DesvincularProcessoUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ?RegistroDoSistemaNaPasta $registro = null,
        private readonly NumeroProcessoExtension $numero = new NumeroProcessoExtension(),
    ) {
    }

    public function executar(Pasta $pasta, Processo $processo, ?User $usuario = null): void
    {
        $estava = $pasta->temProcesso($processo);
        $pasta->desvincularProcesso($processo);
        if ($estava && $usuario !== null) {
            $this->registro?->registrar($pasta, $usuario, 'proc', 'processo ' . $this->numero->numeroCnj($processo->getNumeroProcesso()) . ' desvinculado da pasta.');
        }
        $this->em->flush();
    }
}
