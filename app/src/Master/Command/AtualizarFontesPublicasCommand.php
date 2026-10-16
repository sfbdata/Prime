<?php

declare(strict_types=1);

namespace App\Master\Command;

use App\Master\UseCase\AtualizarFontesPublicasUseCase;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Atualização das fontes do Painel do Master (opcional em cron; o mapa também se atualiza sozinho): retrato do Quadro da Advocacia (a OAB atualiza às 00:01) e da
 * população das UFs (IBGE). Fonte que falhar mantém o retrato anterior; o comando termina com
 * falha para o cron registrar.
 */
#[AsCommand(
    name: 'app:master:atualizar-fontes-publicas',
    description: 'Atualiza o Quadro da Advocacia (OAB) e a população das UFs (IBGE) do mapa do Painel do Master.',
)]
final class AtualizarFontesPublicasCommand extends Command
{
    public function __construct(
        private readonly AtualizarFontesPublicasUseCase $useCase,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $resultado = $this->useCase->executar();
        if ($resultado['emAndamento']) {
            $io->writeln('Outra atualização já está em andamento; nada feito.');

            return Command::SUCCESS;
        }

        $io->writeln('IBGE (população das UFs): ' . ($resultado['ibge'] ? 'atualizado' : 'indisponível, mantido o retrato anterior'));
        $io->writeln('OAB (Quadro da Advocacia): ' . ($resultado['oab'] ? 'atualizado' : 'indisponível, mantido o retrato anterior'));

        return $resultado['ibge'] && $resultado['oab'] ? Command::SUCCESS : Command::FAILURE;
    }
}
