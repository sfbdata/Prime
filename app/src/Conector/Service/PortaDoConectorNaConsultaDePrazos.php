<?php

declare(strict_types=1);

namespace App\Conector\Service;

use App\ChatIa\Service\PortaDaConsultaDePrazos;
use App\Conector\Exception\AcessoDoConectorRecusado;
use App\Conector\Exception\ErroDaFerramenta;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Tarefa\Service\AcessoAoModuloTarefas;

/**
 * A porta do conector de I.A. na consulta de prazos e metas (`docs/specs/mcp-remoto-oauth.md`, 6.4, `ler_agenda` com
 * `minhas_metas`, e 14.i). No lugar do aceite da BlueJus I.A. (a {@see \App\ChatIa\Service\GuardaDaConsultaDePrazos} do
 * chat), vale o que cobre o conector:
 *
 *  - o módulo das metas ({@see AcessoAoModuloTarefas}, a mesma guarda da tela Minhas Metas);
 *  - a liberação do conector nos dois níveis ({@see LiberacaoDoConector}, decisão 14.a), que o portão já conferiu na
 *    requisição e que aqui se confere de novo, porque a permissão pode mudar no meio.
 *
 * Recusa com {@see ErroDaFerramenta::semPermissao()}, a mensagem fixa que vai ao app de I.A.: a recusa da liberação não
 * diz qual nível faltou (a {@see AcessoDoConectorRecusado::liberacao()} também não diz).
 *
 * Quem chama passa esta porta como parâmetro de
 * {@see \App\ChatIa\UseCase\ConsultarPrazosEMetasUseCase::executar()}; ninguém a recebe pela interface no construtor.
 * Pessoa e escritório chegam explícitos, da {@see \App\Conector\DTO\ChamadaDoConector}: nada de sessão.
 */
final class PortaDoConectorNaConsultaDePrazos implements PortaDaConsultaDePrazos
{
    public function __construct(
        private readonly AcessoAoModuloTarefas $moduloTarefas,
        private readonly LiberacaoDoConector $liberacao,
    ) {
    }

    /** @throws ErroDaFerramenta */
    public function exigir(User $eu, Tenant $tenant): void
    {
        if (!$this->moduloTarefas->pode($eu, $tenant)) {
            throw ErroDaFerramenta::semPermissao();
        }

        try {
            $this->liberacao->exigir($tenant);
        } catch (AcessoDoConectorRecusado) {
            throw ErroDaFerramenta::semPermissao();
        }
    }
}
