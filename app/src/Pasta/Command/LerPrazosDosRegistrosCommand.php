<?php

declare(strict_types=1);

namespace App\Pasta\Command;

use App\Pasta\Entity\Pasta;
use App\Pasta\Service\PrazosDosRegistros;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Lê as datas dos registros que já existiam antes do leitor (E12c). Daqui em diante cada registro criado, editado ou
 * excluído relê a própria pasta sozinho ({@see \App\Pasta\EventListener\PrazosDosRegistrosListener}); este comando
 * é para o acervo anterior.
 *
 * Idempotente: relê a pasta inteira e só cria o que falta e apaga o que sumiu, então rodar de novo não duplica.
 * Não toca prazo manual. `--dry-run` conta sem gravar. Em produção é do dono.
 */
#[AsCommand(
    name: 'app:pastas:ler-prazos-dos-registros',
    description: 'Lê as datas escritas nos registros das pastas e acerta os prazos lidos (Próximos prazos e filtros da Lista)',
)]
final class LerPrazosDosRegistrosCommand extends Command
{
    private const LOTE = 100;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PrazosDosRegistros $prazos,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('pasta', null, InputOption::VALUE_REQUIRED, 'Só esta pasta (id)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Conta o que mudaria, sem gravar');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $seco = (bool) $input->getOption('dry-run');

        $ids = $input->getOption('pasta') !== null
            ? [(int) $input->getOption('pasta')]
            : array_map('intval', $this->em->getConnection()->fetchFirstColumn(
                'SELECT pasta_id FROM pasta_mensagem WHERE sistema IS NULL
                 UNION SELECT pasta_id FROM pasta_observacao_detalhes
                 UNION SELECT pasta_id FROM pasta_observacao_financeira
                 ORDER BY 1',
            ));

        $criados = 0;
        $apagados = 0;
        $tocadas = 0;
        foreach (array_chunk($ids, self::LOTE) as $lote) {
            foreach ($lote as $id) {
                $pasta = $this->em->find(Pasta::class, $id);
                if ($pasta === null) {
                    continue;
                }
                $r = $this->prazos->sincronizar($pasta);
                $criados += $r['criados'];
                $apagados += $r['apagados'];
                if ($r['criados'] + $r['apagados'] > 0) {
                    ++$tocadas;
                }
            }
            if (!$seco) {
                $this->em->flush();
            }
            $this->em->clear();
        }

        $io->success(sprintf('%s%d pasta(s) lida(s), %d com mudança: %d prazo(s) criado(s), %d apagado(s).',
            $seco ? '[simulação] ' : '', count($ids), $tocadas, $criados, $apagados));

        return Command::SUCCESS;
    }
}
