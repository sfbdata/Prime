<?php

declare(strict_types=1);

namespace App\Auth\UseCase;

use App\Auth\DTO\ResultadoVerificacaoOab;
use App\Auth\Enum\StatusOab;
use App\Auth\Service\ValidadorOab;
use App\Entity\Auth\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Roda a verificação de OAB sobre um User já existente e grava o status resultante.
 * Base compartilhada pelo botão "verificar" do perfil e pelo "re-verificar" do admin.
 *
 * Enquanto o backend está dormente (sem chave SOAP), `verificar` sempre devolve NaoVerificada
 * (fail-open). Usuário sem número/UF de OAB — ex.: dono grandfathered confirmado sem número —
 * devolve null e não altera nada (não há o que verificar).
 *
 * Regra do `verificadaEm`: só marca quando houve um veredicto real (Confirmada/Divergente);
 * NaoVerificada = nada foi verificado → limpa o timestamp.
 */
final class VerificarOabUseCase
{
    public function __construct(
        private readonly ValidadorOab $validadorOab,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function executar(User $user): ?ResultadoVerificacaoOab
    {
        $numero = $user->getOabNumero();
        $uf     = $user->getOabUf();

        if ($numero === null || $numero === '' || $uf === null || $uf === '') {
            return null;
        }

        $resultado = $this->validadorOab->verificar($numero, $uf, (string) $user->getFullName());

        $user->setOabStatus($resultado->status);
        $user->setOabNomeOficial($resultado->nomeOficial);
        $user->setOabVerificadaEm(
            $resultado->status === StatusOab::NaoVerificada ? null : new \DateTimeImmutable(),
        );

        $this->em->flush();

        return $resultado;
    }
}
