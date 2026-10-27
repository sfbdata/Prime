<?php

declare(strict_types=1);

namespace App\Cobranca\Command;

use App\Cobranca\Service\Espelho\GuardaDeLogComPii;
use App\Cobranca\UseCase\RegravarNomeDasPastasJudiciaisUseCase;
use App\Repository\TenantRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Regrava o `nome_cliente` das pastas judiciais para o padrão "só o cliente da carteira" (decisão do
 * dono, 2026-10-27). Pontual: roda uma vez por escritório depois do deploy. Simula por padrão.
 */
#[AsCommand(
    name: 'app:cobranca:regravar-nome-pasta-judicial',
    description: 'Regrava o nome das pastas judiciais para o cliente da carteira (SIMULA por padrão; grava só com --aplicar)',
)]
final class RegravarNomeDasPastasJudiciaisCommand extends Command implements LidaComDadoPessoal
{
    public const ERRO_DE_INVOCACAO = 64;

    public function __construct(
        private readonly GuardaDeLogComPii $guardaDeLog,
        private readonly RegravarNomeDasPastasJudiciaisUseCase $regravar,
        private readonly TenantRepository $tenants,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('aceito-log-com-pii', null, InputOption::VALUE_NONE, 'Roda mesmo com o log de SQL ligado. A saída conterá dado pessoal.')
            ->addOption('tenant-id', null, InputOption::VALUE_REQUIRED, 'ID do escritório')
            ->addOption('aplicar', null, InputOption::VALUE_NONE, '🔴 GRAVA no banco. Sem esta opção o comando apenas simula.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($this->guardaDeLog->bloqueia($io, (bool) $input->getOption('aceito-log-com-pii'), 'app:cobranca:regravar-nome-pasta-judicial')) {
            return GuardaDeLogComPii::LOG_COM_PII;
        }

        $tenant = $this->tenants->find((int) $input->getOption('tenant-id'));
        if ($tenant === null) {
            $io->error('Escritório (tenant) não encontrado.');

            return self::ERRO_DE_INVOCACAO;
        }

        $aplicar = (bool) $input->getOption('aplicar');
        $io->text($aplicar ? 'MODO --aplicar: GRAVA no banco.' : 'SIMULAÇÃO. Nada é gravado. Use --aplicar para valer.');

        $mudancas = $this->regravar->executar($tenant, $aplicar);

        if ($mudancas === []) {
            $io->success('Todas as pastas judiciais já estão no padrão. Nada a regravar.');

            return Command::SUCCESS;
        }

        $io->table(
            ['pasta', 'nome antes', 'nome depois'],
            array_map(static fn (array $m): array => ['#' . $m['pastaId'], (string) $m['antes'], $m['depois']], $mudancas),
        );
        $io->success(sprintf('%s %d pasta(s).', $aplicar ? 'Regravadas' : 'Seriam regravadas', count($mudancas)));

        return Command::SUCCESS;
    }
}
