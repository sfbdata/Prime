<?php

declare(strict_types=1);

namespace App\Profile\Service;

use App\Entity\Auth\User;
use App\Entity\Notificacao;
use App\Profile\Preferencia\CatalogoDePreferenciasDoPerfil;
use App\Profile\UseCase\ObterPreferenciasDoPerfilUseCase;
use App\Service\Tenant\TenantContext;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Preferência "Notificações no sistema" do Meu Perfil (desenho 07, prefT 'notif'; spec
 * docs/specs/meu-perfil-inventario-2110.md). Desligada, o sininho fica em silêncio para a pessoa, no escritório da
 * sessão: sem contador, sem a lista do sino e sem o salto e o som do lançador da Central. A notificação continua
 * gravada e aparece em "Ver todas"; religar mostra tudo de novo.
 *
 * Exceção (decisão do coordenador, 21/10/2026): o que pede ação para não perder acesso ou prazo legal nunca entra no
 * silêncio ({@see self::SEMPRE_AVISA}).
 */
final class SilencioDoSininho implements ResetInterface
{
    /**
     * - pedido de exclusão de conta: a conta fica suspensa a pedido até alguém decidir;
     * - publicação do DJEN e movimento na pasta ("Avisar quando chegar"): abrem prazo processual;
     * - pedido de acesso a pasta trancada: alguém fica bloqueado até a decisão.
     */
    public const SEMPRE_AVISA = [
        Notificacao::TIPO_CONTA_PEDIDO_EXCLUSAO,
        Notificacao::TIPO_DJEN_PUBLICACAO,
        Notificacao::TIPO_PUSH_PUBLICACAO_PASTA,
        Notificacao::TIPO_PASTA_PEDIDO_ACESSO,
    ];

    /** @var array<string, bool> */
    private array $memoria = [];

    public function __construct(
        private readonly ObterPreferenciasDoPerfilUseCase $preferencias,
        private readonly TenantContext $tenantContext,
    ) {
    }

    public function silenciado(User $usuario): bool
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if ($tenant === null) {
            return false;
        }
        $chave = $usuario->getId() . ':' . $tenant->getId();
        $this->memoria[$chave] ??= !$this->preferencias->executar($tenant, $usuario)[CatalogoDePreferenciasDoPerfil::NOTIFICACOES];

        return $this->memoria[$chave];
    }

    /**
     * Os tipos que ainda contam e aparecem no sino; nulo = todos (preferência ligada).
     *
     * @return list<string>|null
     */
    public function tiposQueContam(User $usuario): ?array
    {
        return $this->silenciado($usuario) ? self::SEMPRE_AVISA : null;
    }

    public function reset(): void
    {
        $this->memoria = [];
    }
}
