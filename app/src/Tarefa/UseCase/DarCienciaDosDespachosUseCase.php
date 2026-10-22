<?php

declare(strict_types=1);

namespace App\Tarefa\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tenant\Tenant;
use App\Repository\TarefaMensagemRepository;
use App\Tarefa\Service\QuemCuidaDaMeta;

/**
 * Ciência automática (desenho 04B de 22/10, L117; spec `minhas-metas-esteira-2210.md`, C14).
 *
 * Abrir a meta marca "Ciente" em cada despacho de OUTRA pessoa que ainda não tem ciência, só quando quem abre é
 * responsável ou criador da meta: quem só olha (um administrador, por exemplo) não dá ciência. Vale a primeira
 * ciência; as seguintes não mudam nada. Devolve quantos despachos foram marcados.
 */
final class DarCienciaDosDespachosUseCase
{
    public function __construct(
        private readonly TarefaMensagemRepository $mensagens,
        private readonly QuemCuidaDaMeta $quemCuida,
    ) {
    }

    public function executar(Tarefa $meta, User $quemAbriu, Tenant $escritorio, \DateTimeImmutable $agora): int
    {
        if ($meta->getTenant()?->getId() !== $escritorio->getId() || !$this->quemCuida->ehResponsavelOuCriador($meta, $quemAbriu)) {
            return 0;
        }

        $marcados = 0;
        foreach ($meta->getMensagens() as $despacho) {
            $autor = $despacho->getUsuario();
            if ($despacho->temCiencia() || $autor === null || $autor->getId() === $quemAbriu->getId()) {
                continue;
            }
            $despacho->darCiencia($quemAbriu, $agora);
            ++$marcados;
        }

        if ($marcados > 0) {
            $this->mensagens->confirmar();
        }

        return $marcados;
    }
}
