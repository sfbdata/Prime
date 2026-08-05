<?php

declare(strict_types=1);

namespace App\Command;

use App\Auth\UseCase\PurgarCadastrosPendentesUseCase;
use App\Auth\UseCase\PurgarRedefinicoesSenhaUseCase;
use App\Repository\TenantRepository;
use App\Tenant\UseCase\PurgarEscritorioUseCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Job de manutenção (rodado por cron no host → docker exec) que faz duas faxinas de
 * dados sem uso futuro:
 *
 *  (A) cadastro_pendente — apaga registros que guardam senha_hash + PII (confirmados e
 *      pendentes expirados). Ver PurgarCadastrosPendentesUseCase.
 *  (B) escritórios em quarentena — hard delete definitivo dos Tenant soft-deletados cuja
 *      carência já venceu (RN09). Ver PurgarEscritorioUseCase.
 *
 * Segurança: --dry-run só relata; sem --force num TTY pede confirmação; sem --force e sem
 * TTY recusa (evita disparo acidental). Um lock (flock) impede execuções sobrepostas.
 */
#[AsCommand(
    name: 'app:purgar-dados-expirados',
    description: 'Purga cadastros pendentes expirados, pedidos de redefinição de senha consumidos/vencidos e escritórios em quarentena vencida (hard delete).',
)]
final class PurgarDadosExpiradosCommand extends Command
{
    /** @var resource|null */
    private $lockHandle = null;

    public function __construct(
        private readonly PurgarCadastrosPendentesUseCase $purgarCadastros,
        private readonly PurgarRedefinicoesSenhaUseCase $purgarRedefinicoes,
        private readonly TenantRepository $tenantRepository,
        private readonly PurgarEscritorioUseCase $purgarEscritorio,
        private readonly EntityManagerInterface $em,
        private readonly int $carenciaPurgaDias,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Simula: apenas relata o que seria apagado, sem apagar nada.')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Executa sem confirmação interativa (uso no cron).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $force  = (bool) $input->getOption('force');

        if ($this->adquirirLock() === false) {
            $io->warning('Outra execução da purga já está em andamento. Abortando.');

            return Command::SUCCESS;
        }

        try {
            if ($dryRun === true) {
                $io->note('Modo simulação (--dry-run): nada será apagado.');
            } elseif ($force === false) {
                if ($input->isInteractive() === false) {
                    $io->error('Operação destrutiva: rode com --force (ex.: no cron) ou num terminal interativo.');

                    return Command::FAILURE;
                }

                if ($io->confirm('Isto apaga DEFINITIVAMENTE cadastros expirados e escritórios em quarentena vencida. Continuar?', false) === false) {
                    $io->comment('Cancelado.');

                    return Command::SUCCESS;
                }
            }

            $agora = new \DateTimeImmutable();

            // (A) cadastro_pendente
            $cadastros = $this->purgarCadastros->executar($agora, $dryRun);
            $io->section('Cadastros pendentes');
            $io->text(sprintf('%d registro(s) %s.', $cadastros, $dryRun ? 'seriam purgados' : 'purgados'));

            // (A2) redefinicao_senha — guarda IP + user agent de cada pedido
            $redefinicoes = $this->purgarRedefinicoes->executar($agora, $dryRun);
            $io->section('Pedidos de redefinição de senha');
            $io->text(sprintf('%d registro(s) %s.', $redefinicoes, $dryRun ? 'seriam purgados' : 'purgados'));

            // (B) escritórios em quarentena vencida
            $limite    = $agora->modify(sprintf('-%d days', $this->carenciaPurgaDias));
            $purgaveis = $this->tenantRepository->encontrarPurgaveis($limite);

            $io->section(sprintf('Escritórios em quarentena vencida (carência de %d dias)', $this->carenciaPurgaDias));

            $falhas = 0;

            if ($purgaveis === []) {
                $io->text('Nenhum escritório elegível.');
            } else {
                $linhas = [];

                foreach ($purgaveis as $tenant) {
                    $tenantId = (int) $tenant->getId();
                    $nome     = $tenant->getName() ?? '';

                    // Isola a falha por tenant: um erro num escritório (guard anti-drift, FK
                    // inesperada, falha DBAL) faz rollback só dele; os demais seguem.
                    try {
                        $resultado = $this->purgarEscritorio->executar($tenant, $dryRun);
                        $linhas[]  = [$resultado->tenantId, $resultado->nome, $resultado->totalLinhas(), $resultado->arquivosRemovidos];
                    } catch (\Throwable $e) {
                        ++$falhas;
                        $io->error(sprintf('Falha ao purgar o escritório #%d (%s): %s', $tenantId, $nome, $e->getMessage()));
                    } finally {
                        // Higiene de memória entre iterações (command potencialmente longo).
                        $this->em->clear();
                    }
                }

                if ($linhas !== []) {
                    $io->table(['ID', 'Escritório', 'Linhas (diretas)', 'Arquivos'], $linhas);
                }
            }

            if ($dryRun === true) {
                $io->note('Contagem por deleção direta; as cascatas do banco (kanban, anexos, junções) removem linhas-filhas adicionais.');
            }

            if ($falhas > 0) {
                $io->warning(sprintf('%d escritório(s) falharam na purga — ver erros acima.', $falhas));

                return Command::FAILURE;
            }

            $io->success($dryRun ? 'Simulação concluída — nada foi apagado.' : 'Purga concluída.');

            return Command::SUCCESS;
        } finally {
            $this->liberarLock();
        }
    }

    /**
     * Lock exclusivo por lockfile (flock) para impedir execuções sobrepostas. Evita a
     * dependência symfony/lock — coerente com a stack minimalista do projeto.
     */
    private function adquirirLock(): bool
    {
        $handle = fopen(sys_get_temp_dir() . '/jusprime-purga.lock', 'c');

        if ($handle === false) {
            return false;
        }

        if (flock($handle, \LOCK_EX | \LOCK_NB) === false) {
            fclose($handle);

            return false;
        }

        $this->lockHandle = $handle;

        return true;
    }

    private function liberarLock(): void
    {
        if (is_resource($this->lockHandle)) {
            flock($this->lockHandle, \LOCK_UN);
            fclose($this->lockHandle);
            $this->lockHandle = null;
        }
    }
}
