<?php

declare(strict_types=1);

namespace App\Master\Service;

use App\Entity\Auth\User;

/**
 * Quem é Master e quem é o Master primário (spec docs/specs/perfis-1.2-masters.md, M1 a M4).
 *
 *  - Master = `ROLE_SUPER_ADMIN`: os poderes do sistema (bypass total no PermissionChecker).
 *  - Master primário = Master que também tem `ROLE_MASTER_PRIMARIO`. Esse papel só MARCA o
 *    primário (um só, o criador do BlueJus); sozinho não dá acesso a nada. Só o primário promove e
 *    remove Masters secundários, e ele nunca é removido pela tela (a troca é do app:master:definir).
 *
 * Os nomes dos papéis moram aqui; quem precisar deles (comandos, consultas, UseCases) usa estas
 * constantes. A migration Version20261019120000 repete os textos de propósito: migration não
 * depende de código da aplicação.
 */
final class RegraDosMasters
{
    public const PAPEL_MASTER = 'ROLE_SUPER_ADMIN';
    public const PAPEL_MASTER_PRIMARIO = 'ROLE_MASTER_PRIMARIO';

    public function ehMaster(User $user): bool
    {
        return in_array(self::PAPEL_MASTER, $user->getRoles(), true);
    }

    /** Primário só conta se também for Master: o papel de primário sozinho não vale nada. */
    public function ehMasterPrimario(User $user): bool
    {
        return $this->ehMaster($user) && in_array(self::PAPEL_MASTER_PRIMARIO, $user->getRoles(), true);
    }

    /**
     * Papéis como gravados na coluna, sem o ROLE_USER que o getRoles() acrescenta sempre.
     *
     * @return list<string>
     */
    public static function papeisGravados(User $user): array
    {
        return array_values(array_filter(
            $user->getRoles(),
            static fn (string $papel): bool => $papel !== 'ROLE_USER',
        ));
    }

    /** Dá os papéis ao usuário, sem repetir os que ele já tem e sem mexer nos demais. */
    public static function darPapeis(User $user, string ...$papeis): void
    {
        $user->setRoles(array_values(array_unique([...self::papeisGravados($user), ...$papeis])));
    }

    /** Tira os papéis do usuário, sem mexer nos demais. */
    public static function tirarPapeis(User $user, string ...$papeis): void
    {
        $user->setRoles(array_values(array_filter(
            self::papeisGravados($user),
            static fn (string $papel): bool => !in_array($papel, $papeis, true),
        )));
    }
}
