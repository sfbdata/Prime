<?php

declare(strict_types=1);

namespace App\Shared\EspelhoRemoto\Command;

use App\Shared\EspelhoRemoto\Outbox\OutboxDeReplicacao;
use App\Shared\EspelhoRemoto\PoliticaDeEspelhamento;
use App\Shared\EspelhoRemoto\ProvedorDoEspelhoRemoto;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * A situação do espelho para o dono e o coordenador acompanharem a P2.5: se as credenciais estão
 * preenchidas (só sim ou não, nunca o valor), quais categorias estão ligadas, a outbox por estado e há
 * quanto tempo espera o envio pendente mais antigo. Só leitura; não fala com o R2.
 */
#[AsCommand(
    name: 'armazenamento:espelho-situacao',
    description: 'Mostra se o espelho R2 está configurado e ligado, a outbox por estado e a idade da pendente mais antiga.',
)]
final class EspelhoSituacaoCommand extends Command
{
    public function __construct(
        private readonly ProvedorDoEspelhoRemoto $fabrica,
        private readonly PoliticaDeEspelhamento $politica,
        private readonly OutboxDeReplicacao $outbox,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io          = new SymfonyStyle($input, $output);
        $configurado = $this->fabrica->estaConfigurado();
        $ligadas     = $this->politica->categoriasLigadas();

        $io->title('Situação do espelho R2');
        $io->definitionList(
            ['Credenciais ESPELHO_R2_* preenchidas' => $configurado ? 'sim' : 'não'],
            ['Categorias ligadas' => $ligadas === [] ? 'nenhuma' : implode(', ', $ligadas)],
            ['Cópia em funcionamento' => $configurado && $ligadas !== [] ? 'sim' : 'não'],
        );

        $invalidas = $this->politica->entradasInvalidas();
        if ($invalidas !== []) {
            $io->warning(sprintf('%d entrada(s) de ARMAZENAMENTO_ESPELHO_CATEGORIAS não são categoria e foram ignoradas: %s', \count($invalidas), implode(', ', $invalidas)));
        }

        if (!$this->outbox->tabelaExiste()) {
            $io->note('A tabela da outbox (armazenamento_replicacao) não existe neste banco: falta a migration.');

            return Command::SUCCESS;
        }

        $porEstado = $this->outbox->contarPorEstado();
        $io->table(['outbox (estado)', 'linhas'], $porEstado === [] ? [['(vazia)', 0]] : array_map(null, array_keys($porEstado), array_values($porEstado)));

        $idade = $this->outbox->idadeDaPendenteMaisAntigaEmSegundos();
        $io->writeln('Pendente mais antiga: ' . ($idade === null ? 'nenhuma' : self::idade($idade)));

        return Command::SUCCESS;
    }

    private static function idade(int $segundos): string
    {
        if ($segundos < 3_600) {
            return sprintf('há %d min', intdiv($segundos, 60));
        }
        if ($segundos < 86_400) {
            return sprintf('há %d h %02d min', intdiv($segundos, 3_600), intdiv($segundos % 3_600, 60));
        }

        return sprintf('há %d dia(s) e %d h', intdiv($segundos, 86_400), intdiv($segundos % 86_400, 3_600));
    }
}
