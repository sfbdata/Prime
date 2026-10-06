<?php

declare(strict_types=1);

namespace App\Inteligencia\Twig;

use App\Entity\Auth\User;
use App\Inteligencia\Enum\Agente;
use App\Inteligencia\Enum\Disponibilidade;
use App\Inteligencia\Service\DisponibilidadeDeInteligencia;
use App\Service\Tenant\TenantContext;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `ia_disponibilidade()` — para a tela dizer o motivo certo no lugar do botão. Devolve o enum:
 * no Twig, `ia.estaDisponivel`, `ia.mensagem` e `ia.value` ('disponivel', 'sem_permissao', …).
 * Sem usuário/tenant na sessão responde `SemPermissao` (nenhuma página de pasta chega aqui assim).
 *
 * `ia_agentes()` — os sete agentes da pasta (enum `Agente`), para o drawer do cabeçalho
 * desenhar a lista sem depender do controller da pasta (legado, fora desta frente).
 */
final class InteligenciaExtension extends AbstractExtension
{
    public function __construct(
        private readonly DisponibilidadeDeInteligencia $disponibilidade,
        private readonly Security $security,
        private readonly TenantContext $tenantContext,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('ia_disponibilidade', $this->iaDisponibilidade(...)),
            new TwigFunction('ia_agentes', $this->iaAgentes(...)),
        ];
    }

    /** @return list<Agente> */
    public function iaAgentes(): array
    {
        return Agente::cases();
    }

    public function iaDisponibilidade(): Disponibilidade
    {
        $user = $this->security->getUser();
        $tenant = $this->tenantContext->getCurrentTenant();

        if (!$user instanceof User || $tenant === null) {
            return Disponibilidade::SemPermissao;
        }

        return $this->disponibilidade->para($user, $tenant);
    }
}
