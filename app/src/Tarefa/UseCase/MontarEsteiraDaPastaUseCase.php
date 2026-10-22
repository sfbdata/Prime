<?php

declare(strict_types=1);

namespace App\Tarefa\UseCase;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Repository\UserTenantRepository;
use App\Tarefa\DTO\CaixaNaEsteiraOutput;
use App\Tarefa\DTO\EsteiraDaPastaOutput;
use App\Tarefa\DTO\MembroDaEquipeOutput;
use App\Tarefa\Entity\CaixaDaEsteira;
use App\Tarefa\Enum\EtapaDaPasta;
use App\Tarefa\Repository\CaixaDaEsteiraRepository;
use App\Tarefa\Repository\TarefaRepository;
use App\Tarefa\Service\QuemConfiguraACaixa;
use App\Tarefa\Service\TratamentoDoAvisado;

/**
 * Monta a esteira da pasta do topo de Minhas Metas (desenho 04 de 22/10, dc L61 e L163-167).
 *
 * **Quem:** qualquer colaborador com acesso a Metas. A esteira é GERAL DO ESCRITÓRIO: todos veem as mesmas
 * caixas e os mesmos números, de toda a equipe. Só os números: nenhuma meta de outro aparece aqui.
 *
 * **O quê:** as 8 caixas na ordem, cada uma com as metas abertas (em revisão conta), as atrasadas, o selo
 * GARGALO e o que o escritório mudou nela (nome da caixa, nome da etapa, avisados).
 *
 * **Regras:** GARGALO é a caixa com MAIS atrasadas, só se houver alguma; no empate, a primeira da esteira
 * (dc L165). Meta de pasta oculta pelo cadeado para quem olha não entra nos números (spec pasta-cadeado D3).
 * A equipe para o @ do modal só vem para quem pode configurar caixa (D2): os outros nem recebem a lista.
 * O avisado aparece como no desenho (dc L61, `trat()`): "Primeiro Último", com "Dr." ou "Dra." para advogado
 * (função de advogado ou sócio, ou OAB). A função vem do vínculo, lido uma vez e só se alguma caixa tem avisado.
 *
 * **Erros:** não lança; é leitura.
 */
final class MontarEsteiraDaPastaUseCase
{
    public function __construct(
        private readonly TarefaRepository $tarefas,
        private readonly CaixaDaEsteiraRepository $caixas,
        private readonly UserTenantRepository $vinculos,
        private readonly QuemConfiguraACaixa $quemConfigura,
    ) {
    }

    /** @param list<int> $pastasOcultas */
    public function executar(User $usuario, Tenant $escritorio, \DateTimeImmutable $hoje, array $pastasOcultas = []): EsteiraDaPastaOutput
    {
        $contagens     = $this->tarefas->contarEsteira($escritorio, $hoje, $pastasOcultas);
        $configuradas  = $this->caixas->configuradasDoEscritorio($escritorio);
        $gargalo       = $this->gargalo($contagens);
        $podeConfigurar = $this->quemConfigura->pode($usuario, $escritorio);

        $funcoes = $this->funcoesDosAvisados($escritorio, $configuradas);
        $caixas = array_map(
            fn (EtapaDaPasta $etapa): CaixaNaEsteiraOutput => $this->caixa($etapa, $contagens, $configuradas[$etapa->value] ?? null, $gargalo, $funcoes),
            EtapaDaPasta::cases(),
        );

        return new EsteiraDaPastaOutput(
            caixas: $caixas,
            podeConfigurar: $podeConfigurar,
            membros: $podeConfigurar ? $this->membros($escritorio) : [],
        );
    }

    /** @param array<string, array{total: int, atrasadas: int}> $contagens */
    private function gargalo(array $contagens): ?EtapaDaPasta
    {
        $gargalo = null;
        $maior   = 0;
        foreach (EtapaDaPasta::cases() as $etapa) {
            $atrasadas = $contagens[$etapa->value]['atrasadas'] ?? 0;
            if ($atrasadas > $maior) {
                $gargalo = $etapa;
                $maior   = $atrasadas;
            }
        }

        return $gargalo;
    }

    /**
     * A função de cada pessoa da equipe, por id; vazio (e sem consulta) quando nenhuma caixa tem avisado.
     *
     * @param array<string, CaixaDaEsteira> $configuradas
     *
     * @return array<int, ?string>
     */
    private function funcoesDosAvisados(Tenant $escritorio, array $configuradas): array
    {
        $temAvisado = array_filter($configuradas, static fn (CaixaDaEsteira $c): bool => !$c->getAvisados()->isEmpty());
        if ($temAvisado === []) {
            return [];
        }
        $funcoes = [];
        foreach ($this->vinculos->findAtivosComCargoELotacaoPorTenant($escritorio) as $vinculo) {
            $funcoes[(int) $vinculo->getUser()->getId()] = $vinculo->getCargo()?->getNome();
        }

        return $funcoes;
    }

    /**
     * @param array<string, array{total: int, atrasadas: int}> $contagens
     * @param array<int, ?string>                              $funcoes
     */
    private function caixa(EtapaDaPasta $etapa, array $contagens, ?CaixaDaEsteira $configurada, ?EtapaDaPasta $gargalo, array $funcoes): CaixaNaEsteiraOutput
    {
        $avisados = $configurada === null ? [] : $configurada->getAvisados()->toArray();

        return new CaixaNaEsteiraOutput(
            etapa: $etapa,
            total: $contagens[$etapa->value]['total'] ?? 0,
            atrasadas: $contagens[$etapa->value]['atrasadas'] ?? 0,
            gargalo: $gargalo === $etapa,
            nomeDaCaixa: $configurada?->getNomeDaCaixa(),
            nomeDaEtapa: $configurada?->getNomeDaEtapa(),
            avisados: array_values(array_map(
                static fn (User $u): string => TratamentoDoAvisado::de((string) $u->getFullName(), $funcoes[(int) $u->getId()] ?? null, (string) $u->getOabNumero() !== ''),
                $avisados,
            )),
            idsDosAvisados: array_values(array_map(static fn (User $u): int => (int) $u->getId(), $avisados)),
        );
    }

    /** @return list<MembroDaEquipeOutput> */
    private function membros(Tenant $escritorio): array
    {
        return array_values(array_map(
            static fn (UserTenant $vinculo): MembroDaEquipeOutput => new MembroDaEquipeOutput(
                (int) $vinculo->getUser()->getId(),
                (string) $vinculo->getUser()->getFullName(),
                $vinculo->getCargo()?->getNome(),
            ),
            $this->vinculos->findAtivosComCargoELotacaoPorTenant($escritorio),
        ));
    }
}
