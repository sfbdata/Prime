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
 * processo não estava vinculado, é no-op. Se o desvinculado era o principal, só é promovido o
 * primeiro vínculo SEM tipo de apenso (vínculo antigo); apensado com tipo nunca vira principal, então a
 * pasta com principal e apensados fica sem principal e com os apensados intactos (decisão do P4f,
 * spec §2: embargos não é a ação principal).
 *
 * Apensado (P4f): desvincular um apensado não mexe no principal e escreve o cartão do sistema
 * "apensado X desvinculado." na timeline de Dados (`SYS.proc`, N:6711), em nome de quem desvinculou.
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
        $eraApensado = $pasta->ehApensado($processo);

        $pasta->desvincularProcesso($processo);
        if ($eraApensado && $usuario !== null) {
            $this->registro?->registrar($pasta, $usuario, 'proc', 'apensado ' . $this->numero->numeroCnj($processo->getNumeroProcesso()) . ' desvinculado.');
        }
        $this->em->flush();
    }
}
