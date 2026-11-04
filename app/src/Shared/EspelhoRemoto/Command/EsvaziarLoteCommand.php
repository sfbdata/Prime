<?php

declare(strict_types=1);

namespace App\Shared\EspelhoRemoto\Command;

use App\Shared\EspelhoRemoto\EspelhoRemoto;
use App\Shared\EspelhoRemoto\EspelhoRemotoNaoConfigurado;
use App\Shared\EspelhoRemoto\Liberacao\EsvaziadorDeLote;
use App\Shared\EspelhoRemoto\Liberacao\LiberadorDeDisco;
use App\Shared\EspelhoRemoto\Liberacao\RegistroDeLiberacao;
use App\Shared\EspelhoRemoto\ProvedorDoEspelhoRemoto;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Lock\LockFactory;

/**
 * Esvazia um lote liberado (spec `leitura-pelo-r2-e-liberacao-de-disco.md` §5.4): apaga de vez as
 * cópias em `.liberados/<lote>/` que passam em todas as provas do {@see EsvaziadorDeLote}. As linhas
 * continuam abertas; voltar atrás é `armazenamento:restaurar-lote`, que baixa do R2.
 *
 * A spec pedia `liberar-disco --esvaziar-lote=ID`. Ficou um comando próprio porque o liberar exige
 * `--categoria` e as recusas da liberação, que aqui não se aplicam; o comportamento é o da spec.
 *
 * Sem `--executar`, só simula (sem baixar do R2). Com `--executar`, exige `--confirmo-backup-testado`:
 * depois disto, a cópia do arquivo existe só no R2 e no backup. Uma execução por vez (a trava da
 * liberação, {@see LiberadorDeDisco::TRAVA}).
 *
 * A tela mostra só contagens: nome de arquivo e chave remota não aparecem.
 */
#[AsCommand(
    name: 'armazenamento:esvaziar-lote',
    description: 'Apaga de vez as cópias de um lote em .liberados/ (o arquivo segue no R2). Simula por padrão.',
)]
final class EsvaziarLoteCommand extends Command
{
    public function __construct(
        private readonly EsvaziadorDeLote $esvaziador,
        private readonly RegistroDeLiberacao $liberacoes,
        private readonly ProvedorDoEspelhoRemoto $fabrica,
        #[Autowire(service: 'lock.agendador.factory')]
        private readonly LockFactory $travas,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('lote', null, InputOption::VALUE_REQUIRED, 'O lote a esvaziar (o id impresso pela liberação).')
            ->addOption('executar', null, InputOption::VALUE_NONE, 'Apaga de verdade. Sem ela, só simula.')
            ->addOption('confirmo-backup-testado', null, InputOption::VALUE_NONE, 'Confirma que a cópia diária do backup roda e que o teste de restauração do backup passou. Exigida com --executar.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io   = new SymfonyStyle($input, $output);
        $lote = trim((string) $input->getOption('lote'));
        if ($lote === '') {
            $io->error('Informe --lote.');

            return Command::INVALID;
        }

        $executar = (bool) $input->getOption('executar');
        if ($executar && !$input->getOption('confirmo-backup-testado')) {
            $io->error('Execução recusada: confirme com --confirmo-backup-testado que a cópia diária do backup roda e que o teste de restauração do backup passou. Depois do esvaziamento, o arquivo fica só no R2 e no backup.');

            return Command::FAILURE;
        }

        $linhas = $this->liberacoes->abertasDoLote($lote);
        if ($linhas === []) {
            $io->error('Esse lote não tem liberação aberta.');

            return Command::FAILURE;
        }

        $trava = $this->travas->createLock(LiberadorDeDisco::TRAVA, LiberadorDeDisco::DURACAO_DA_TRAVA_EM_SEGUNDOS);
        if ($executar && !$trava->acquire()) {
            $io->error('Outra operação nos liberados está em andamento. Espere ela terminar.');

            return Command::FAILURE;
        }

        try {
            $resumo = $this->esvaziador->esvaziar($linhas, $executar, fn (): EspelhoRemoto => $this->fabrica->criar(), $executar ? static function () use ($trava): void {
                $trava->refresh();
            } : null);
        } catch (EspelhoRemotoNaoConfigurado $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        } finally {
            if ($executar) {
                $trava->release();
            }
        }

        $io->title(sprintf('Esvaziamento do lote %s (%s): %d linha(s) aberta(s)', $lote, $executar ? 'execução' : 'simulação', \count($linhas)));
        $io->table(['resultado', 'arquivos'], array_map(null, array_keys($resumo->contagem()), array_values($resumo->contagem())));
        if (!$executar) {
            $io->note('Simulação: nada foi baixado nem apagado. Use --executar --confirmo-backup-testado para esvaziar.');

            return Command::SUCCESS;
        }
        $io->success(sprintf('Esvaziados: %d arquivo(s). Eles seguem no R2; voltar atrás: armazenamento:restaurar-lote --lote=%s --executar', $resumo->quantos(EsvaziadorDeLote::ESVAZIADO), $lote));

        return Command::SUCCESS;
    }
}
