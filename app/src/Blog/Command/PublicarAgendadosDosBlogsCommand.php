<?php

declare(strict_types=1);

namespace App\Blog\Command;

use App\Blog\UseCase\PublicarAgendadosDosBlogsUseCase;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `blog:publicar-agendados`: põe no ar os artigos agendados cuja hora chegou (spec painel-artigos-blogs, P5). Roda a
 * cada 5 minutos pela {@see \App\Shared\Scheduler\AgendaDeTarefas}.
 *
 * Sai com erro quando algum artigo falhou e voltou a rascunho: é o que faz o aviso de falha do agendador avisar o
 * Master. Nada a publicar termina com sucesso, sem saída.
 */
#[AsCommand(name: 'blog:publicar-agendados', description: 'Publica os artigos dos blogs agendados cuja hora chegou')]
final class PublicarAgendadosDosBlogsCommand extends Command
{
    public function __construct(private readonly PublicarAgendadosDosBlogsUseCase $publicarAgendados)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $resumo = $this->publicarAgendados->executar();
        if ($resumo['publicados'] === 0 && $resumo['falhas'] === []) {
            return Command::SUCCESS;
        }

        $output->writeln(sprintf('Publicados: %d. Falharam: %d.', $resumo['publicados'], \count($resumo['falhas'])));
        foreach ($resumo['falhas'] as $falha) {
            $output->writeln('<error>' . $falha . '</error>');
        }

        return $resumo['falhas'] === [] ? Command::SUCCESS : Command::FAILURE;
    }
}
