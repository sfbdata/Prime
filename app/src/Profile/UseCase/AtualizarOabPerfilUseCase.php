<?php

declare(strict_types=1);

namespace App\Profile\UseCase;

use App\Auth\Service\ValidadorOab;
use App\Auth\UseCase\VerificarOabUseCase;
use App\Entity\Auth\User;
use App\Profile\DTO\OabPerfilInput;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Informar/editar/limpar a OAB do próprio usuário no perfil.
 *
 *  - Limpou (número e UF vazios) → zera número/UF/status/nome oficial/verificadaEm.
 *  - Informou/editou → valida o formato (ValidadorOab), grava número/UF e, **se o valor mudou**,
 *    recalcula o status via VerificarOabUseCase (dormente → NaoVerificada). Reenviar o mesmo
 *    valor não mexe no status (não perde uma confirmação à toa).
 *
 * UF é normalizada para maiúscula e ambos os campos têm trim antes da validação.
 */
final class AtualizarOabPerfilUseCase
{
    public function __construct(
        private readonly ValidadorOab $validadorOab,
        private readonly VerificarOabUseCase $verificarOab,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function executar(User $user, OabPerfilInput $input): void
    {
        $numero = trim((string) ($input->oabNumero ?? ''));
        $uf     = strtoupper(trim((string) ($input->oabUf ?? '')));

        if ($numero === '' && $uf === '') {
            $user->setOabNumero(null);
            $user->setOabUf(null);
            $user->setOabStatus(null);
            $user->setOabNomeOficial(null);
            $user->setOabVerificadaEm(null);
            $this->em->flush();

            return;
        }

        $this->validadorOab->validarFormato($numero, $uf);

        $mudou = $numero !== ($user->getOabNumero() ?? '') || $uf !== ($user->getOabUf() ?? '');

        $user->setOabNumero($numero);
        $user->setOabUf($uf);

        if ($mudou === false) {
            $this->em->flush();

            return;
        }

        // Mudou → recalcula o status (dormente → NaoVerificada). VerificarOabUseCase dá o flush.
        $this->verificarOab->executar($user);
    }
}
