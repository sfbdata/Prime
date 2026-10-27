<?php

declare(strict_types=1);

namespace App\Contribua\Command;

use App\Contribua\UseCase\ConciliarAssinaturasUseCase;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Conciliação das assinaturas de espaço com os gateways (cron de hora em hora, `DEPLOY.md`). Idempotente:
 * rodar duas vezes seguidas, ou junto de um webhook, chega ao mesmo estado, porque tudo parte da
 * situação consultada no gateway.
 *
 * Sai com erro só se TODAS as reconsultas falharam (gateway fora do ar ou credencial errada): aí o
 * monitoramento do cron precisa acusar.
 */
#[AsCommand(
    name: 'app:contribua:conciliar-assinaturas',
    description: 'Reconsulta nos gateways as assinaturas de espaço e devolve ao Free o que venceu.',
)]
final class ConciliarAssinaturasCommand extends Command
{
    public function __construct(
        private readonly ConciliarAssinaturasUseCase $conciliar,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $r = $this->conciliar->executar(new \DateTimeImmutable());
        $io->writeln(sprintf(
            'Avisos reprocessados: %d · assinaturas reconsultadas: %d · falhas: %d · escritórios revistos: %d',
            $r['avisos'],
            $r['reconsultadas'],
            $r['falhas'],
            $r['escritorios'],
        ));

        if ($r['falhas'] > 0 && $r['reconsultadas'] === 0) {
            $io->error('Nenhuma assinatura pôde ser reconsultada. Confira as credenciais e o gateway.');

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
