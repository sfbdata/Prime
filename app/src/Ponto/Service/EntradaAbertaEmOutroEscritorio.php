<?php

declare(strict_types=1);

namespace App\Ponto\Service;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\Repository\RegistroPontoRepository;
use App\Repository\UserTenantRepository;
use App\Service\PermissionChecker;

/**
 * Trava da entrada (spec `docs/specs/ponto-por-escritorio.md` §2.2, risco ALTO): quem está com o ponto aberto HOJE
 * num escritório Y não bate a entrada no escritório X. "Aberto" é a última batida do dia em Y até o `agora` não ser
 * saída, havendo entrada (reentrada e repouso sem retorno contam). Só os Y em que a pessoa tem vínculo ativo, com o
 * escritório ativo e o módulo de ponto (P-C); mais de um, o primeiro em ordem alfabética (P-E).
 *
 * Só a saída batida em Y, ou a aprovação do esquecimento pelo gestor de Y (que vira batida de saída), solta a trava:
 * o pedido de esquecimento ainda pendente não solta (P-A, decisão do dono de 19/10).
 *
 * Só a própria pessoa vê a resposta, e dela só sai o nome do escritório.
 */
final class EntradaAbertaEmOutroEscritorio
{
    /** A frase do dono. */
    public const MENSAGEM_TRAVA = 'Você está com o ponto aberto em %s. Registre a saída lá antes.';

    public function __construct(
        private readonly RegistroPontoRepository $registros,
        private readonly UserTenantRepository $vinculos,
        private readonly PermissionChecker $permissoes,
    ) {
    }

    /** A recusa da entrada em `$atual`, ou null quando ela pode seguir. */
    public function mensagem(User $user, Tenant $atual, \DateTimeImmutable $agora): ?string
    {
        $nome = $this->onde($user, $atual, $agora);
        if ($nome === null) {
            return null;
        }

        return sprintf(self::MENSAGEM_TRAVA, $nome);
    }

    /** O nome do escritório em que o ponto está aberto hoje, ou null. */
    public function onde(User $user, Tenant $atual, \DateTimeImmutable $agora): ?string
    {
        $escritorios = $this->outrosEscritoriosComPonto($user, $atual);
        if ($escritorios === []) {
            return null;   // sem vínculo ativo em outro escritório: nem consulta as batidas (M4, N5)
        }

        $abertos = $this->registros->pontoAbertoHojeEmOutrosEscritorios(
            $user,
            $atual,
            $escritorios,
            $this->inicioDaJanela($agora),
            $agora,
        );

        return $abertos[0]['nome'] ?? null;   // já em ordem alfabética (P-E)
    }

    /**
     * A janela da trava: o mesmo dia (variante fixada pelo dono). "Qualquer entrada aberta" seria trocar este início.
     */
    private function inicioDaJanela(\DateTimeImmutable $agora): \DateTimeImmutable
    {
        return $agora->setTime(0, 0, 0);
    }

    /**
     * Os outros escritórios ativos com vínculo ativo em que a pessoa tem o módulo de ponto (P-C). Sem o módulo ela
     * não bateria a saída lá. Para o super admin o módulo é sempre verdadeiro: com vínculo em Y, Y conta (N6).
     *
     * @return list<int>
     */
    private function outrosEscritoriosComPonto(User $user, Tenant $atual): array
    {
        $escritorios = [];
        foreach ($this->vinculos->findActiveByUser($user) as $vinculo) {
            $tenant = $vinculo->getTenant();
            if ($tenant->getId() !== $atual->getId() && $this->permissoes->canAccessModule($user, $tenant, 'ponto')) {
                $escritorios[] = (int) $tenant->getId();
            }
        }

        return $escritorios;
    }
}
