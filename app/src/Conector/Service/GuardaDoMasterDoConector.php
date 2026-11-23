<?php

declare(strict_types=1);

namespace App\Conector\Service;

use App\Entity\Auth\User;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Segunda camada dos UseCases do conector de I.A. na aba "Configurações da I.A." do Master: o controller já exige
 * `ROLE_SUPER_ADMIN`, mas um UseCase de plataforma não confia que todo chamador lembrou disso (molde:
 * {@see \App\Inteligencia\Service\GuardaDoMasterDaIa}). Também é a guarda das ferramentas do Painel do Master, no
 * `executar` e no `reconferir` (`docs/specs/mcp-remoto-oauth.md`, 6.9, decisão 39).
 */
final class GuardaDoMasterDoConector
{
    public static function exigir(User $user): void
    {
        if (!self::ehMaster($user)) {
            throw new AccessDeniedException('Só o Master vê o conector de I.A. dos escritórios.');
        }
    }

    /**
     * O papel da pessoa, a mesma regra de {@see \App\Master\Service\RegraDosMasters::ehMaster}. Não passa pelo
     * {@see ModoDoConector}, de propósito: as ferramentas do Painel do Master (6.9) perguntam se a pessoa do token é
     * Master; o teto da 6.3 continua tirando o passe do Master de todo o resto.
     */
    public static function ehMaster(User $user): bool
    {
        return \in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true);
    }
}
