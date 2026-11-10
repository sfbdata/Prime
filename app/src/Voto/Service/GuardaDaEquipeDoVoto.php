<?php

declare(strict_types=1);

namespace App\Voto\Service;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Quem da equipe logada entra na gestão da assembleia de condomínio (spec docs/specs/voto-assembleia-condominio.md,
 * seção 5): a MESMA regra de `VotoController::assertAcesso()`, copiada aqui para os controllers novos da assembleia
 * sem refatorar o VotoController. Usuário logado, escritório na sessão, `canAccessModule('voto')` e o escritório
 * liberado pelo Master ({@see LiberacaoDoVotoNoEscritorio}, em que o Master passa sempre).
 *
 * A chave `voto` do catálogo do Master já barra a rota antes (prefixo `voto_`), como no VotoController.
 */
final class GuardaDaEquipeDoVoto
{
    private const MODULO = 'voto';

    public function __construct(
        private readonly Security $security,
        private readonly TenantContext $tenantContext,
        private readonly PermissionChecker $permissionChecker,
        private readonly LiberacaoDoVotoNoEscritorio $liberacao,
        private readonly TranslatorInterface $tradutor,
    ) {
    }

    /** O escritório da sessão, se a pessoa pode gerir o Voto nele; senão AccessDeniedException (403). */
    public function exigir(): Tenant
    {
        $usuario = $this->security->getUser();
        $tenant = $this->tenantContext->getCurrentTenant();
        if (!$usuario instanceof User || $tenant === null || !$this->permissionChecker->canAccessModule($usuario, $tenant, self::MODULO)) {
            throw new AccessDeniedException();
        }
        if (!$this->liberacao->liberadoParaOUsuarioAtual()) {
            throw new AccessDeniedException($this->tradutor->trans('voto.recusa.nao_liberado'));
        }

        return $tenant;
    }

    /** Nome de quem opera, para "aberta por" e "encerrada por" (ata). Só depois de {@see exigir()}. */
    public function nomeDeQuemOpera(): string
    {
        $usuario = $this->security->getUser();
        $nome = $usuario instanceof User ? trim((string) ($usuario->getFullName() ?? $usuario->getEmail() ?? '')) : '';

        return mb_substr($nome !== '' ? $nome : 'Equipe', 0, 180);
    }
}
