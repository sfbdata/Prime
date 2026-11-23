<?php

declare(strict_types=1);

namespace App\Backup\Coletor;

use App\Backup\Service\ZipDoBackup;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Um coletor por domínio: põe no ZIP do backup o que `$usuario` pode ver em `$tenant` (spec backup, seções 2 e 4).
 *
 * Roda no worker, SEM sessão: nada de `TenantContext`, `Security` nem `TenantFilter`. O escritório entra escrito na
 * mão em toda consulta, e a visibilidade vem só das variantes que recebem `User` e `Tenant`. A ordem de execução
 * sai da prioridade da tag (`#[AsTaggedItem(priority: N)]` na classe; maior roda antes).
 */
#[AutoconfigureTag(ColetorDoBackup::TAG)]
interface ColetorDoBackup
{
    public const TAG = 'app.coletor_do_backup';

    public function coletar(User $usuario, Tenant $tenant, ZipDoBackup $zip): void;
}
