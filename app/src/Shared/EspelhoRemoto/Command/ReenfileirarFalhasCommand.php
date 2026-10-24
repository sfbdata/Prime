<?php

declare(strict_types=1);

namespace App\Shared\EspelhoRemoto\Command;

use App\Shared\Armazenamento\CategoriaDeArquivo;
use App\Shared\EspelhoRemoto\Outbox\OutboxDeReplicacao;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Devolve à fila os envios do espelho que ficaram em `falhou` (o alerta da reconciliação os conta até
 * alguém agir): voltam a `pendente`, com tentativas zeradas e próxima tentativa agora. A retenção nunca
 * apaga `falhou`; este é o caminho de saída. A saída é só contagem por categoria.
 */
#[AsCommand(
    name: 'armazenamento:reenfileirar-falhas',
    description: 'Volta para a fila (pendente, tentativas zeradas) os envios do espelho R2 que estão em "falhou".',
)]
final class ReenfileirarFalhasCommand extends Command
{
    public function __construct(private readonly OutboxDeReplicacao $outbox)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('categoria', null, InputOption::VALUE_REQUIRED, 'Só as falhas desta categoria.')
            ->addOption('simular', null, InputOption::VALUE_NONE, 'Só conta o que voltaria, sem mudar nada.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io        = new SymfonyStyle($input, $output);
        $opcao     = $input->getOption('categoria');
        $categoria = $opcao === null ? null : CategoriaDeArquivo::tryFrom((string) $opcao);
        if ($opcao !== null && $categoria === null) {
            $io->error('--categoria precisa ser uma categoria de arquivo (por exemplo foto_perfil).');

            return Command::INVALID;
        }

        $falhas = $this->outbox->contarFalhasPorCategoria();
        if ($categoria !== null) {
            $falhas = array_intersect_key($falhas, [$categoria->value => true]);
        }
        $io->table(['categoria', 'envios em falhou'], $falhas === [] ? [['(nenhuma)', 0]] : array_map(null, array_keys($falhas), array_values($falhas)));

        if ($input->getOption('simular')) {
            $io->note(sprintf('Simulação: %d envio(s) voltariam para a fila; nada mudou.', array_sum($falhas)));

            return Command::SUCCESS;
        }

        $io->success(sprintf('%d envio(s) de volta para a fila.', $this->outbox->reenfileirarFalhas($categoria)));

        return Command::SUCCESS;
    }
}
