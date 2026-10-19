<?php

declare(strict_types=1);

namespace App\Master\Service;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;

/**
 * Quem é Master e quem é o Master primário (spec docs/specs/perfis-1.2-masters.md, M1 a M4).
 *
 *  - Master = `ROLE_SUPER_ADMIN`: os poderes do sistema (bypass total no PermissionChecker).
 *  - Master primário = Master que também tem `ROLE_MASTER_PRIMARIO`. Esse papel só MARCA o
 *    primário (um só, o criador do BlueJus); sozinho não dá acesso a nada. Só o primário promove e
 *    remove Masters secundários, e ele nunca é removido pela tela (a troca é do app:master:definir).
 *
 * Conta do Master (spec docs/specs/perfis-1.2-contas.md C5, Q2 e Q4): o escritório cujo criador
 * (`Tenant.criadoPor`) é Master. O Master não tem bypass de conta suspensa (o TenantContext e o
 * TenantContextValidatorListener barram também o ROLE_SUPER_ADMIN): suspender ou excluir a conta dele
 * o trancaria fora do próprio escritório. As duas recusas moram aqui, num lugar só, para a decisão de
 * produto poder mudar sem caçar `if` pelo sistema.
 *
 * Os nomes dos papéis moram aqui; quem precisar deles (comandos, consultas, UseCases) usa estas
 * constantes. A migration Version20261019120000 repete os textos de propósito: migration não
 * depende de código da aplicação.
 */
final class RegraDosMasters
{
    public const PAPEL_MASTER = 'ROLE_SUPER_ADMIN';
    public const PAPEL_MASTER_PRIMARIO = 'ROLE_MASTER_PRIMARIO';

    public const RECUSA_SUSPENDER_CONTA_DO_MASTER = 'Esta conta foi criada por um Master. Suspendê-la tiraria dele o acesso ao próprio escritório.';
    public const RECUSA_EXCLUIR_CONTA_DO_MASTER = 'Esta conta foi criada por um Master e não pode ser excluída.';

    public function ehMaster(User $user): bool
    {
        return in_array(self::PAPEL_MASTER, $user->getRoles(), true);
    }

    /** Primário só conta se também for Master: o papel de primário sozinho não vale nada. */
    public function ehMasterPrimario(User $user): bool
    {
        return $this->ehMaster($user) && in_array(self::PAPEL_MASTER_PRIMARIO, $user->getRoles(), true);
    }

    /** O escritório foi criado por um Master (`Tenant.criadoPor` com ROLE_SUPER_ADMIN). */
    public function ehContaDoMaster(Tenant $conta): bool
    {
        $criador = $conta->getCriadoPor();

        return $criador !== null && $this->ehMaster($criador);
    }

    /**
     * Mensagem de recusa ao SUSPENDER a conta, ou nulo quando pode. Decisão de produto Q2 (19/10):
     * a conta do Master não é suspensa, porque ele não tem bypass de conta suspensa.
     */
    public function recusaParaSuspender(Tenant $conta): ?string
    {
        return $this->ehContaDoMaster($conta) ? self::RECUSA_SUSPENDER_CONTA_DO_MASTER : null;
    }

    /**
     * Mensagem de recusa ao EXCLUIR a conta, ou nulo quando pode (C5 e Q4): vale para toda rota de
     * exclusão, também as antigas `escritorio_excluir` e `app_tenant_delete`.
     */
    public function recusaParaExcluir(Tenant $conta): ?string
    {
        return $this->ehContaDoMaster($conta) ? self::RECUSA_EXCLUIR_CONTA_DO_MASTER : null;
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
