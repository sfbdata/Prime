<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\UseCase;

use App\Entity\Auth\User;
use App\Entity\Permission\AccessRequest;
use App\Entity\Tenant\Tenant;
use App\Pasta\Repository\PastaRepository;
use App\Repository\UserTenantRepository;
use App\Service\PermissionChecker;

/**
 * A busca da janela "Vincular à pasta de Expediente" (spec assinatura-documentos-etapa-3, E3-10; desenho 17, linha
 * 277): número, cliente e ação, sem acento nem caixa, até 60 pastas; termo vazio lista as mais recentes. Só a pasta do
 * escritório, fora da lápide, que a pessoa EDITA: o cadeado vai na consulta ({@see PastaRepository::buscarParaVinculoEmCampos()})
 * e a permissão de editar pasta (do perfil ou item a item) é conferida aqui, em lote. Quem não edita pasta nenhuma
 * recebe a lista vazia, sem número nem nome de cliente.
 */
final readonly class BuscarPastasParaVincularUseCase
{
    public const LIMITE = 60;
    private const TERMO_MAXIMO = 100;

    public function __construct(
        private PastaRepository $pastas,
        private PermissionChecker $permissoes,
        private UserTenantRepository $vinculos,
    ) {
    }

    /** @return array{pastas: list<array{id: int, nup: string, cliente: string, acao: string}>} */
    public function executar(User $quem, Tenant $tenant, string $termo): array
    {
        $achadas = $this->pastas->buscarParaVinculoEmCampos(mb_substr(trim($termo), 0, self::TERMO_MAXIMO), $tenant, $quem, self::LIMITE);
        if ($achadas === [] || $this->permissoes->hasPermission($quem, $tenant, 'resources.pasta.edit')) {
            return ['pastas' => $achadas];
        }

        // Sem a permissão do perfil: só as pastas liberadas item a item (o cadeado já saiu na consulta).
        $vinculo = $this->vinculos->findAtivoPorUserETenant($quem, $tenant);
        $liberadas = array_flip($this->permissoes->idsPermitidosComVinculo($quem, $vinculo, AccessRequest::RESOURCE_PASTA, array_column($achadas, 'id'), AccessRequest::ACTION_EDIT));

        return ['pastas' => array_values(array_filter($achadas, static fn (array $p): bool => isset($liberadas[$p['id']])))];
    }
}
