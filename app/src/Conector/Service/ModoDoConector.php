<?php

declare(strict_types=1);

namespace App\Conector\Service;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Teto do conector de I.A. (spec mcp-remoto-oauth 6.3, item 2): pelo conector, a pessoa do token age como qualquer
 * colega, sem as funções de administrador, mesmo sendo Master ou primário (decisão 14.b).
 *
 * Ligado só pelo PortaoDoConector, com o vínculo do token. Enquanto ligado, e SÓ quando a pergunta é sobre essa pessoa
 * ({@see valePara()}):
 *  - `PermissionChecker`: o passe do Master (`isGlobalSuperAdmin`) e o do perfil de sistema (`TenantRole::isSystem()`)
 *    respondem falso em todos os métodos públicos; `canAdminister`, `hasPermission` com `admin.*` e
 *    `isAdministradorDoEscritorio` respondem falso;
 *  - `RegraDosPrimarios::ehMaster` e `ehPrimario` respondem falso.
 * Pergunta sobre outra pessoa (o colega que recebe um aviso, por exemplo) segue o comportamento normal.
 *
 * Sem o passe, o perfil de sistema tem toda permissão que não começa com `admin.` ({@see perfilDeSistemaTem()}), e passa
 * pelo cadeado da pasta e pelas abas ocultas como qualquer colega (6.3, item 3; revisão de 16/11/2026, achado 5).
 *
 * Serviço por requisição: zera no `reset()` (tag `kernel.reset`, entre requisições e entre mensagens do worker), e a
 * requisição seguinte do navegador, no mesmo processo, volta a ter os passes (`ModoDoConectorNoNavegadorTest`).
 */
final class ModoDoConector implements ResetInterface
{
    private ?int $userId = null;

    /** Liga o modo para a pessoa do vínculo. Só o PortaoDoConector chama. */
    public function ligar(UserTenant $vinculo): void
    {
        $userId = $vinculo->getUser()->getId();
        if ($userId === null) {
            throw new \LogicException('O modo do conector exige a pessoa já gravada.');
        }

        $this->userId = $userId;
    }

    public function estaLigado(): bool
    {
        return $this->userId !== null;
    }

    /** O modo vale para esta pessoa: está ligado e a pergunta é sobre a pessoa do token. */
    public function valePara(User $user): bool
    {
        return $this->userId !== null && $user->getId() === $this->userId;
    }

    /** Sem o passe, o perfil de sistema tem esta permissão? Toda permissão, menos as `admin.*`. */
    public function perfilDeSistemaTem(string $codigo): bool
    {
        return !str_starts_with($codigo, 'admin.');
    }

    public function reset(): void
    {
        $this->userId = null;
    }
}
