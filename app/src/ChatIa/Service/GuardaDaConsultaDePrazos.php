<?php

declare(strict_types=1);

namespace App\ChatIa\Service;

use App\ChatIa\Exception\SemAcessoAsMetasNoChatException;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\DTO\FerramentaDeLinguagem;
use App\Inteligencia\Repository\ConfiguracaoDeInteligenciaRepository;
use App\Tarefa\Service\AcessoAoModuloTarefas;

/**
 * Quem pode consultar prazos e metas pelo chat, nos DOIS lados (como {@see GuardaDasPropostas}): o pedido só leva a ferramenta
 * para quem pode ({@see self::ferramentasPara()}), e a consulta reconfere ao rodar ({@see self::exigir()}), porque a permissão
 * pode mudar no meio.
 *
 * Pode quem passa na guarda da tela Minhas Metas (módulo `tarefas`, {@see AcessoAoModuloTarefas}) num escritório que deu o
 * aceite de envio de dados à I.A (o mesmo do contexto de metas). As guardas do próprio chat (vínculo, módulo da I.A,
 * disponibilidade, teto) vêm antes, no envio.
 */
final class GuardaDaConsultaDePrazos
{
    public function __construct(
        private readonly AcessoAoModuloTarefas $moduloTarefas,
        private readonly ConfiguracaoDeInteligenciaRepository $configuracoes,
    ) {
    }

    /** @return list<FerramentaDeLinguagem> */
    public function ferramentasPara(User $eu, Tenant $tenant): array
    {
        return $this->podeConsultar($eu, $tenant) ? [FerramentasDaConsulta::prazosEMetas()] : [];
    }

    public function podeConsultar(User $eu, Tenant $tenant): bool
    {
        return $this->moduloTarefas->pode($eu, $tenant)
            && ($this->configuracoes->findDoTenant($tenant)?->aceiteDeEnvioDado() ?? false);
    }

    /** @throws SemAcessoAsMetasNoChatException */
    public function exigir(User $eu, Tenant $tenant): void
    {
        if (!$this->podeConsultar($eu, $tenant)) {
            throw new SemAcessoAsMetasNoChatException();
        }
    }
}
