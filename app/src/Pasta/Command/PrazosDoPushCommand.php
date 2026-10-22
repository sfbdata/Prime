<?php

declare(strict_types=1);

namespace App\Pasta\Command;

use App\Pasta\Entity\Pasta;
use App\Pasta\Service\PrazosDoPush;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Calcula os prazos do Push das publicações que já existiam (docs/specs/prazo-do-push.md §3). Daqui em diante a
 * captação do DJEN e o vínculo de processo acertam a pasta sozinhos; este comando é para o histórico.
 *
 * SIMULA por padrão: conta o que mudaria e não grava. Só grava com `--aplicar`. Idempotente: rodar de novo não
 * duplica, não apaga prazo cumprido e não toca prazo manual nem o lido dos registros. Em produção é do dono.
 */
#[AsCommand(
    name: 'app:pastas:prazos-do-push',
    description: 'Calcula os prazos das publicações do Push nas pastas (simula; grava só com --aplicar)',
)]
final class PrazosDoPushCommand extends Command
{
    private const LOTE = 100;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PrazosDoPush $prazos,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('pasta', null, InputOption::VALUE_REQUIRED, 'Só esta pasta (id)')
            ->addOption('aplicar', null, InputOption::VALUE_NONE, 'Grava os prazos (sem ela, só simula)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $aplicar = (bool) $input->getOption('aplicar');
        $ids = $input->getOption('pasta') !== null
            ? [(int) $input->getOption('pasta')]
            : array_map('intval', $this->em->getConnection()->fetchFirstColumn(
                'SELECT DISTINCT pp.pasta_id FROM pasta_processo pp JOIN pasta p ON p.id = pp.pasta_id
                 WHERE p.excluida_em IS NULL ORDER BY 1',
            ));

        $total = ['criados' => 0, 'apagados' => 0, 'tocadas' => 0];
        foreach (array_chunk($ids, self::LOTE) as $lote) {
            $this->sincronizarLote($lote, $total);
            if ($aplicar) {
                $this->em->flush();
            }
            $this->em->clear();
        }

        (new SymfonyStyle($input, $output))->success(sprintf(
            '%s%d pasta(s) lida(s), %d com mudança: %d prazo(s) criado(s), %d apagado(s).',
            $aplicar ? '' : '[simulação, nada gravado; use --aplicar] ',
            count($ids),
            $total['tocadas'],
            $total['criados'],
            $total['apagados']
        ));

        return Command::SUCCESS;
    }

    /**
     * @param list<int>                                         $lote
     * @param array{criados: int, apagados: int, tocadas: int} $total
     */
    private function sincronizarLote(array $lote, array &$total): void
    {
        foreach ($lote as $id) {
            $pasta = $this->em->find(Pasta::class, $id);
            if ($pasta === null) {
                continue;
            }
            $r = $this->prazos->sincronizar($pasta);
            $total['criados'] += $r['criados'];
            $total['apagados'] += $r['apagados'];
            $total['tocadas'] += $r['criados'] + $r['apagados'] > 0 ? 1 : 0;
        }
    }
}
