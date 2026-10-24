<?php

declare(strict_types=1);

namespace App\Shared\EspelhoRemoto\Command;

use App\Shared\Armazenamento\CategoriaDeArquivo;
use App\Shared\EspelhoRemoto\Acervo\CarregadorDoAcervo;
use App\Shared\EspelhoRemoto\PoliticaDeEspelhamento;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Carga do acervo existente de UMA categoria na outbox (P2.6). Procedimento: ligar a categoria em
 * `ARMAZENAMENTO_ESPELHO_CATEGORIAS`, rodar este comando e esperar a fila esvaziar (o worker agendado
 * leva 50 por minuto). Recusa categoria desligada. Idempotente; a saída é só contagem.
 */
#[AsCommand(
    name: 'armazenamento:carregar-acervo',
    description: 'Enfileira no espelho R2 os arquivos já existentes de uma categoria ligada (só o que ainda não foi enviado nem está na fila).',
)]
final class CarregarAcervoCommand extends Command
{
    public function __construct(
        private readonly CarregadorDoAcervo $carregador,
        private readonly PoliticaDeEspelhamento $politica,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('categoria', null, InputOption::VALUE_REQUIRED, 'A categoria a carregar (precisa estar ligada na política).')
            ->addOption('limite', null, InputOption::VALUE_REQUIRED, 'Para depois de enfileirar N arquivos.')
            ->addOption('simular', null, InputOption::VALUE_NONE, 'Só conta o que seria enfileirado, sem gravar na outbox.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io        = new SymfonyStyle($input, $output);
        $categoria = CategoriaDeArquivo::tryFrom((string) $input->getOption('categoria'));
        if ($categoria === null) {
            $io->error('--categoria precisa ser uma categoria de arquivo (por exemplo foto_perfil).');

            return Command::INVALID;
        }

        $limite = $input->getOption('limite');
        if ($limite !== null && (!ctype_digit((string) $limite) || (int) $limite < 1)) {
            $io->error('--limite precisa ser um inteiro maior que zero.');

            return Command::INVALID;
        }

        if (!$this->politica->espelha($categoria)) {
            $io->error(sprintf('A categoria %s não está ligada em ARMAZENAMENTO_ESPELHO_CATEGORIAS: ligue antes de carregar o acervo.', $categoria->value));

            return Command::FAILURE;
        }

        $simular = (bool) $input->getOption('simular');
        $resumo  = $this->carregador->carregar($categoria, $limite === null ? null : (int) $limite, $simular);

        $io->table(['carga do acervo (' . $categoria->value . ')', 'quantidade'], array_map(null, array_keys($resumo->comoLista()), array_values($resumo->comoLista())));
        if ($simular) {
            $io->note('Simulação: nada foi gravado na outbox.');
        }

        return Command::SUCCESS;
    }
}
