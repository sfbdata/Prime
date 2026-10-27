<?php

declare(strict_types=1);

namespace App\Inteligencia\Command;

use App\Inteligencia\UseCase\ExpirarConteudoDaInteligenciaUseCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Apaga o conteúdo das análises da BlueJus IA mais antigas que o prazo escolhido pelo Master (12 meses por padrão,
 * {@see ExpirarConteudoDaInteligenciaUseCase}).
 * Destrutivo: sem `--force` recusa. O agendador roda com `--force`.
 */
#[AsCommand(
    name: 'app:inteligencia:expirar-conteudo',
    description: 'Apaga o conteúdo (respostas e perguntas) das análises da BlueJus IA mais antigas que o prazo do Master (12 meses por padrão).',
)]
final class ExpirarConteudoDaInteligenciaCommand extends Command
{
    public function __construct(
        private readonly ExpirarConteudoDaInteligenciaUseCase $expirar,
        #[Autowire(service: 'monolog.logger.inteligencia')]
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('force', 'f', InputOption::VALUE_NONE, 'Executa (operação destrutiva; uso no agendador).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ((bool) $input->getOption('force') === false) {
            $io->error('Operação destrutiva: rode com --force.');

            return Command::FAILURE;
        }

        $resultado = $this->expirar->executar(new \DateTimeImmutable());

        $this->logger->info('Retenção da IA ({meses} meses): conteúdo de {analises} análise(s), {perguntas} pergunta(s) e {mensagens} mensagem(ns) do chat anteriores a {limite} apagado.', [
            'meses'     => $resultado['meses'],
            'analises'  => $resultado['analises'],
            'perguntas' => $resultado['perguntas'],
            'mensagens' => $resultado['mensagens'],
            'limite'    => $resultado['limite']->format('Y-m-d'),
        ]);
        $io->success(sprintf(
            'Conteúdo apagado (prazo de %d meses): %d análise(s), %d pergunta(s) e %d mensagem(ns) do chat anteriores a %s.',
            $resultado['meses'],
            $resultado['analises'],
            $resultado['perguntas'],
            $resultado['mensagens'],
            $resultado['limite']->format('d/m/Y'),
        ));

        return Command::SUCCESS;
    }
}
