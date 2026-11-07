<?php

declare(strict_types=1);

namespace App\ChatIa\UseCase;

use App\ChatIa\DTO\ConsultaDePrazosInput;
use App\ChatIa\DTO\MetaDoPrazoOutput;
use App\ChatIa\DTO\PrazosEMetasOutput;
use App\ChatIa\Enum\PeriodoDaConsulta;
use App\ChatIa\Exception\SemAcessoAsMetasNoChatException;
use App\ChatIa\Service\GuardaDaConsultaDePrazos;
use App\ChatIa\Service\ListaDasMinhasMetas;
use App\Entity\Auth\User;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tenant\Tenant;
use App\Processo\Repository\ProcessoRepository;
use App\Shared\Prazo\RegraDoPrazo;
use App\Tarefa\Enum\AbaMetas;
use Psr\Clock\ClockInterface;

/**
 * A I.A do chat consulta os prazos e metas da PRÓPRIA pessoa (spec `docs/specs/chat-ia-prazos-e-metas.md`). Só leitura: nada
 * aqui grava, altera ou conclui meta.
 *
 * **Quem:** a pessoa logada, quando o modelo pede a ferramenta `consultar_prazos_e_metas` numa resposta a ela. O modelo só
 * escolhe o período e o papel (a aba); pessoa e escritório são SEMPRE os da sessão: não há como pedir o prazo de um colega.
 *
 * **O quê:** as metas que a tela Minhas Metas mostraria para ela na aba e no filtro de prazo equivalentes
 * ({@see ListaDasMinhasMetas}, a mesma consulta da tela, com cadeado e "só acompanhar não basta"), e daí só TIRA:
 * - "hoje" recorta o "Vencem em 7 dias" no dia;
 * - meta de outro escritório sai (guarda explícita, também sem o `TenantFilter`);
 * - meta de pasta com processo em segredo de justiça sai (regra 4 da Inteligencia); a saída diz só quantas;
 * - mostra até {@see self::MAXIMO_DE_METAS}, pelo prazo (a mais antiga primeiro, como a tela); o total vai junto.
 *
 * **Erros:** sem o módulo Tarefas ou sem o aceite do escritório, {@see SemAcessoAsMetasNoChatException} (quem chama põe uma
 * linha fixa no prompt, sem dado).
 */
final class ConsultarPrazosEMetasUseCase
{
    public const MAXIMO_DE_METAS = 20;
    private const MAXIMO_DE_NOMES = 3;

    public function __construct(
        private readonly GuardaDaConsultaDePrazos $guarda,
        private readonly ListaDasMinhasMetas $lista,
        private readonly ProcessoRepository $processos,
        private readonly ClockInterface $relogio,
    ) {
    }

    /** @throws SemAcessoAsMetasNoChatException */
    public function executar(User $eu, Tenant $tenant, ConsultaDePrazosInput $input): PrazosEMetasOutput
    {
        $this->guarda->exigir($eu, $tenant);
        $hoje = \DateTimeImmutable::createFromInterface($this->relogio->now())->setTime(0, 0);

        $daTela = $this->lista->daTela($eu, $tenant, $input->papel, $input->periodo->filtroDaTela());
        $doPeriodo = $this->doPeriodoEDoEscritorio($daTela, $tenant, $input->periodo, $hoje);
        $liberadas = $this->semSegredoDeJustica($doPeriodo, $tenant);
        $mostradas = \array_slice(self::pelaOrdemDoPrazo($liberadas), 0, self::MAXIMO_DE_METAS);
        $this->lista->carregarCartoes(array_map(static fn (Tarefa $m): int => (int) $m->getId(), $mostradas), $tenant);

        return new PrazosEMetasOutput(
            periodo: $input->periodo,
            papel: $input->papel,
            hoje: $hoje,
            total: \count($liberadas),
            omitidasPorSegredo: \count($doPeriodo) - \count($liberadas),
            metas: array_map(fn (Tarefa $m): MetaDoPrazoOutput => $this->saida($m, $input->papel, $hoje), $mostradas),
        );
    }

    /**
     * Só as do escritório da sessão e com prazo; no período "hoje", só as que vencem hoje (a tela já recortou atrasadas e os 7 dias).
     *
     * @param list<Tarefa> $metas
     *
     * @return list<Tarefa>
     */
    private function doPeriodoEDoEscritorio(array $metas, Tenant $tenant, PeriodoDaConsulta $periodo, \DateTimeImmutable $hoje): array
    {
        return array_values(array_filter($metas, static function (Tarefa $meta) use ($tenant, $periodo, $hoje): bool {
            $prazo = $meta->getPrazo();
            $doEscritorio = $meta->getTenant() === $tenant
                || ($tenant->getId() !== null && $meta->getTenant()?->getId() === $tenant->getId());
            if (!$doEscritorio || $prazo === null) {
                return false;
            }

            return $periodo !== PeriodoDaConsulta::Hoje || RegraDoPrazo::diasAte($prazo, $hoje) === 0;
        }));
    }

    /**
     * Tira as metas de pasta com algum processo em segredo de justiça (a regra da entidade, lida em lote).
     *
     * @param list<Tarefa> $metas
     *
     * @return list<Tarefa>
     */
    private function semSegredoDeJustica(array $metas, Tenant $tenant): array
    {
        $pastas = array_values(array_unique(array_map(static fn (Tarefa $m): int => (int) $m->getPasta()->getId(), $metas)));
        $emSegredo = array_flip($this->processos->idsDasPastasEmSegredoDeJustica($pastas, $tenant));

        return array_values(array_filter($metas, static fn (Tarefa $m): bool => !isset($emSegredo[(int) $m->getPasta()->getId()])));
    }

    /**
     * @param list<Tarefa> $metas
     *
     * @return list<Tarefa>
     */
    private static function pelaOrdemDoPrazo(array $metas): array
    {
        usort($metas, static fn (Tarefa $a, Tarefa $b): int => [$a->getPrazo(), $a->getId()] <=> [$b->getPrazo(), $b->getId()]);

        return $metas;
    }

    private function saida(Tarefa $meta, AbaMetas $papel, \DateTimeImmutable $hoje): MetaDoPrazoOutput
    {
        $pasta = $meta->getPasta();
        $prazo = $meta->getPrazo() ?? $hoje;

        return new MetaDoPrazoOutput(
            numero: $meta->getNumero(),
            titulo: $meta->getTitulo(),
            pasta: (string) $pasta->getNup(),
            cliente: (string) $pasta->getNomeCliente(),
            prazo: $prazo,
            dias: RegraDoPrazo::diasAte($prazo, $hoje),
            prioridade: $meta->getPrioridade()->value,
            quem: $this->quem($meta, $papel),
        );
    }

    /**
     * O "quem" da coluna da tela ({@see AbaMetas::rotuloDaColunaQuem()}): na aba Sou responsável, quem delegou; nas outras, os
     * responsáveis (até três nomes).
     *
     * @return list<string>
     */
    private function quem(Tarefa $meta, AbaMetas $papel): array
    {
        $pessoas = $papel === AbaMetas::RESPONSAVEL ? [$meta->getCriadoPor()] : $meta->getResponsaveis()->toArray();
        $nomes = [];
        foreach ($pessoas as $pessoa) {
            $nome = trim((string) $pessoa?->getFullName());
            if ($nome !== '') {
                $nomes[] = $nome;
            }
        }

        return \array_slice($nomes, 0, self::MAXIMO_DE_NOMES);
    }
}
