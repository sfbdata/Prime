<?php

declare(strict_types=1);

namespace App\Tarefa\UseCase;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Profile\Service\TratamentoDeAdvogado;
use App\Tarefa\DTO\AvisadoNaEsteiraOutput;
use App\Repository\UserTenantRepository;
use App\Tarefa\DTO\CaixaNaEsteiraOutput;
use App\Tarefa\DTO\EsteiraDaPastaOutput;
use App\Tarefa\DTO\MembroDaEquipeOutput;
use App\Tarefa\Entity\CaixaDaEsteira;
use App\Tarefa\Enum\EtapaDaPasta;
use App\Tarefa\Repository\CaixaDaEsteiraRepository;
use App\Tarefa\Repository\TarefaRepository;
use App\Tarefa\Service\QuemConfiguraACaixa;

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
 * O avisado aparece como "Primeiro Último"; quem tem OAB e gênero declarado ganha o "Dr./Dra." do `trat()` do
 * desenho (spec genero-no-cadastro, regra 4), sem adivinhar o gênero pelo nome como o protótipo.
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

        $caixas = array_map(
            fn (EtapaDaPasta $etapa): CaixaNaEsteiraOutput => $this->caixa($etapa, $contagens, $configuradas[$etapa->value] ?? null, $gargalo),
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

    /** @param array<string, array{total: int, atrasadas: int}> $contagens */
    private function caixa(EtapaDaPasta $etapa, array $contagens, ?CaixaDaEsteira $configurada, ?EtapaDaPasta $gargalo): CaixaNaEsteiraOutput
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
                static fn (User $u): AvisadoNaEsteiraOutput => new AvisadoNaEsteiraOutput(
                    self::nomeCurto((string) $u->getFullName()),
                    TratamentoDeAdvogado::de($u),
                ),
                $avisados,
            )),
            idsDosAvisados: array_values(array_map(static fn (User $u): int => (int) $u->getId(), $avisados)),
        );
    }

    /** "Primeiro Último" com as iniciais maiúsculas (spec C7); o título, se houver, a tela põe na frente. */
    private static function nomeCurto(string $nome): string
    {
        $partes = preg_split('/\s+/u', trim($nome), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($partes === []) {
            return '';
        }
        $maiuscula = static fn (string $p): string => mb_strtoupper(mb_substr($p, 0, 1)) . mb_strtolower(mb_substr($p, 1));

        return $maiuscula($partes[0]) . (count($partes) > 1 ? ' ' . $maiuscula($partes[count($partes) - 1]) : '');
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
