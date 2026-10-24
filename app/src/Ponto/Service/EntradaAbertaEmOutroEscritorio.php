<?php

declare(strict_types=1);

namespace App\Ponto\Service;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\Repository\RegistroPontoRepository;
use App\Repository\UserTenantRepository;
use App\Service\PermissionChecker;

/**
 * Aviso de pendência na entrada (spec `docs/specs/ponto-esquecimento-nao-trava.md` R2, risco ALTO): quem está com o
 * ponto aberto HOJE num escritório Y bate a entrada no escritório X normalmente, e a resposta da batida traz o aviso de
 * que ficou um registro em aberto em Y. Esquecimento nunca trava (decisão do dono, 23/10); até então era uma recusa
 * (`ponto-por-escritorio.md` §2.2, P-A). "Aberto" é a última batida do dia em Y até o `agora` não ser saída, havendo
 * entrada (reentrada e repouso sem retorno contam). Só os Y em que a pessoa tem vínculo ativo, com o escritório ativo e
 * o módulo de ponto (P-C); mais de um, o primeiro em ordem alfabética (P-E).
 *
 * O aviso leva o nome de Y porque só a própria pessoa o vê, na resposta da batida dela. Ninguém mais (admin, gestor
 * ou aprovador de X) recebe esse nome.
 */
final class EntradaAbertaEmOutroEscritorio
{
    /** O rótulo do link do aviso: o pedido de esquecimento se faz no escritório onde ficou o registro em aberto. */
    public const ROTULO_TROCAR = 'Trocar de escritório';

    /** A frase da spec (R2): o escritório e o dia (dd/mm). */
    public const AVISO_PENDENCIA = 'Ficou um registro em aberto em %s no dia %s. Envie o registro de esquecimento por lá.';

    public function __construct(
        private readonly RegistroPontoRepository $registros,
        private readonly UserTenantRepository $vinculos,
        private readonly PermissionChecker $permissoes,
    ) {
    }

    /** O aviso para a entrada em `$atual`, ou null quando não há ponto aberto em outro escritório. Nunca recusa. */
    public function aviso(User $user, Tenant $atual, \DateTimeImmutable $agora): ?string
    {
        $nome = $this->onde($user, $atual, $agora);
        if ($nome === null) {
            return null;
        }

        return sprintf(self::AVISO_PENDENCIA, $nome, $agora->format('d/m'));
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
     * A janela do aviso: o mesmo dia (variante fixada pelo dono). "Qualquer entrada aberta" seria trocar este início.
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
