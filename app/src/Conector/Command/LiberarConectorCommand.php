<?php

declare(strict_types=1);

namespace App\Conector\Command;

use App\Conector\UseCase\LiberarConectorNoEscritorioUseCase;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Liga o conector de I.A. num escritório, sem tela (`docs/specs/mcp-remoto-oauth.md`, 13.1 e 14.a): ligado e com o
 * aceite da versão vigente, só no escritório informado. O administrador do escritório faz o mesmo pela tela.
 *
 * `--master` é obrigatório: o e-mail de um Master com a conta ativa, que fica como autor do aceite.
 */
#[AsCommand(
    name: 'conector:liberar',
    description: 'Liga o conector de I.A. num escritório, com o aceite em nome de um Master.',
)]
final class LiberarConectorCommand extends Command
{
    public function __construct(
        private readonly LiberarConectorNoEscritorioUseCase $liberar,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('tenantId', InputArgument::REQUIRED, 'Id do escritório')
            ->addOption('master', null, InputOption::VALUE_REQUIRED, 'E-mail do Master que dá o aceite');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $tenantId = (string) $input->getArgument('tenantId');
        $master = $input->getOption('master');

        if (preg_match('/^[1-9]\d*$/', $tenantId) !== 1) {
            $io->error('Informe o id do escritório (número inteiro).');

            return Command::INVALID;
        }
        if (!\is_string($master) || trim($master) === '') {
            $io->error('Informe --master com o e-mail de um Master.');

            return Command::INVALID;
        }

        try {
            $this->liberar->executar((int) $tenantId, $master);
        } catch (\DomainException $recusa) {
            $io->error($recusa->getMessage());

            return Command::FAILURE;
        }

        $io->success(sprintf('Conector de I.A. liberado e ligado no escritório %s.', $tenantId));

        return Command::SUCCESS;
    }
}
