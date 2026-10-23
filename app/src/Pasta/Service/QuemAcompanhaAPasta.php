<?php

declare(strict_types=1);

namespace App\Pasta\Service;

use App\Entity\Auth\User;
use App\Entity\Permission\AccessRequest;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\AbaDaPasta;
use App\Pasta\Entity\Pasta;
use App\Pasta\Repository\PastaAcompanhamentoDesligadoRepository;
use App\Service\PermissionChecker;

/**
 * Quem recebe o aviso de alteração de uma pasta acompanhada ("Acompanhar alterações" do menu ⋮,
 * desenho "02 - EXPEDIENTES - VS 18.10.26", D:4447 e D:4493-4499).
 *
 * O padrão é LIGADO: acompanha a pasta todo usuário do escritório dela que
 *   - é usuário ativo e tem vínculo ATIVO com o escritório (o critério de quem é mencionável);
 *   - NÃO desligou o acompanhamento desta pasta (linha em `pasta_acompanhamento_desligado`);
 *   - VÊ a pasta: `canAccessResource` de `view`, a mesma checagem da `pasta_show`, das menções e
 *     do "Avisar quando chegar" do Push; ela já aplica o cadeado como teto (Oculto não vê);
 *   - VÊ o Registro: a aba "Dados da pasta" não está oculta para ele
 *     ({@see VisibilidadeDasAbasDaPasta}), porque o aviso leva para uma mensagem dessa aba.
 *
 * Saem também os que `$naoAvisar` indica: o autor da alteração e quem já recebe um aviso mais
 * específico pela mesma ação (o dono do comentário respondido e os mencionados). Uma ação, um
 * aviso.
 *
 * PENDÊNCIA (decisão do coordenador, 23/10/2026): hoje só a MENSAGEM NOVA no Registro da pasta
 * conta como alteração ({@see AvisoDePastaAcompanhada}, chamado por `EnviarMensagemPastaUseCase`).
 * Ficam para frentes próprias: documento novo, meta criada ou concluída, troca de situação, troca
 * de responsável e movimentação do Push. Cada uma entra chamando o aviso com os seus `$naoAvisar`.
 */
final class QuemAcompanhaAPasta
{
    public function __construct(
        private readonly PastaAcompanhamentoDesligadoRepository $desligados,
        private readonly PermissionChecker $permissionChecker,
        private readonly VisibilidadeDasAbasDaPasta $visibilidadeDasAbas,
    ) {
    }

    /**
     * @param list<User|null> $naoAvisar
     *
     * @return list<User>
     */
    public function destinatarios(Pasta $pasta, Tenant $tenant, array $naoAvisar): array
    {
        $donoDaPasta = $pasta->getTenant();
        if ($pasta->getId() === null || $donoDaPasta === null || !self::mesmoTenant($donoDaPasta, $tenant)) {
            return [];
        }

        $fora = self::idsDe($naoAvisar);
        $destinatarios = [];
        foreach ($this->desligados->colaboradoresQueNaoDesligaram($pasta, $tenant) as $pessoa) {
            if (!isset($fora[(int) $pessoa->getId()]) && $this->veORegistro($pessoa, $pasta, $tenant)) {
                $destinatarios[] = $pessoa;
            }
        }

        return $destinatarios;
    }

    private function veORegistro(User $pessoa, Pasta $pasta, Tenant $tenant): bool
    {
        return $this->permissionChecker->canAccessResource($pessoa, $tenant, AccessRequest::RESOURCE_PASTA, (int) $pasta->getId(), AccessRequest::ACTION_VIEW)
            && !$this->visibilidadeDasAbas->ocultaPara($pasta, AbaDaPasta::Dados, $pessoa);
    }

    /**
     * @param list<User|null> $pessoas
     *
     * @return array<int, true>
     */
    private static function idsDe(array $pessoas): array
    {
        $ids = [];
        foreach ($pessoas as $pessoa) {
            if ($pessoa !== null && $pessoa->getId() !== null) {
                $ids[$pessoa->getId()] = true;
            }
        }

        return $ids;
    }

    private static function mesmoTenant(Tenant $a, Tenant $b): bool
    {
        return $a === $b || ($a->getId() !== null && $a->getId() === $b->getId());
    }
}
