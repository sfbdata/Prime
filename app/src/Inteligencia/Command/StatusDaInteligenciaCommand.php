<?php

declare(strict_types=1);

namespace App\Inteligencia\Command;

use App\Inteligencia\Enum\StatusDaAnalise;
use App\Inteligencia\Repository\AnaliseDeInteligenciaRepository;
use App\Inteligencia\Repository\ConfiguracaoDeInteligenciaRepository;
use App\Inteligencia\Service\ProvedorDeLinguagem;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Runbook do operador: qual provedor está ligado, se a flag da plataforma está ativa, quantos
 * escritórios ligaram a IA e as análises por escritório e status (pendentes/falhas são o que
 * interessa quando o worker parou). Lista TODOS os tenants de propósito — é visão de plataforma.
 */
#[AsCommand(
    name: 'app:inteligencia:status',
    description: 'Mostra o provedor da BlueJus IA, a flag da plataforma e as análises por escritório.',
)]
final class StatusDaInteligenciaCommand extends Command
{
    public function __construct(
        private readonly ProvedorDeLinguagem $provedor,
        private readonly AnaliseDeInteligenciaRepository $analises,
        private readonly ConfiguracaoDeInteligenciaRepository $configuracoes,
        private readonly bool $iaHabilitada,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('BlueJus IA — status');

        $io->definitionList(
            ['Provedor' => sprintf('%s (%s)', $this->provedor->nome(), $this->provedor->estaConfigurado() ? 'configurado' : 'não configurado')],
            ['Flag da plataforma (IA_HABILITADA)' => $this->iaHabilitada ? 'ligada' : 'desligada'],
            ['Escritórios com a IA ligada' => (string) count($this->configuracoes->listarHabilitadas())],
        );

        $porTenant = [];
        foreach ($this->analises->contarPorTenantEStatus() as $linha) {
            $id = $linha['tenantId'];
            $porTenant[$id] ??= ['id' => $id, 'nome' => $linha['tenantNome']]
                + array_fill_keys(array_map(static fn (StatusDaAnalise $s): string => $s->value, StatusDaAnalise::cases()), 0);
            $porTenant[$id][$linha['status']] = $linha['total'];
        }

        if ($porTenant === []) {
            $io->text('Nenhuma análise registrada.');

            return Command::SUCCESS;
        }

        $io->table(
            ['Tenant', 'Escritório', 'Pendentes', 'Processando', 'Concluídas', 'Falhas', 'Indisponíveis'],
            array_map(static fn (array $t): array => [
                (string) $t['id'],
                (string) $t['nome'],
                (string) $t[StatusDaAnalise::Pendente->value],
                (string) $t[StatusDaAnalise::Processando->value],
                (string) $t[StatusDaAnalise::Concluida->value],
                (string) $t[StatusDaAnalise::Falhou->value],
                (string) $t[StatusDaAnalise::Indisponivel->value],
            ], array_values($porTenant)),
        );

        return Command::SUCCESS;
    }
}
