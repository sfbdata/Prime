<?php

declare(strict_types=1);

namespace App\Profile\Command;

use App\Entity\Tenant\Tenant;
use App\Profile\UseCase\EnviarResumoDiarioDoEscritorioUseCase;
use App\Repository\TenantRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Cron das 8h de Brasília (DEPLOY.md, "Resumo diário por e-mail"): manda a cada pessoa os prazos e as metas do dia,
 * um escritório por vez (spec docs/specs/resumo-diario-por-email.md). Pode rodar de novo no mesmo dia: quem já recebeu
 * não recebe outra vez.
 */
#[AsCommand(
    name: 'app:perfil:resumo-diario',
    description: 'Envia o resumo diário por e-mail (prazos e metas do dia) a quem deixou a preferência ligada.',
)]
final class ResumoDiarioCommand extends Command
{
    /** @var resource|null */
    private $trava = null;

    public function __construct(
        private readonly TenantRepository $escritorios,
        private readonly EnviarResumoDiarioDoEscritorioUseCase $enviar,
        private readonly EntityManagerInterface $em,
        private readonly ManagerRegistry $doctrine,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Simula: conta quem receberia, sem gravar nem enviar.')
            ->addOption('tenant', null, InputOption::VALUE_REQUIRED, 'Restringe a um escritório (id).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if (!$this->travar()) {
            $io->warning('Outro envio do resumo diário já está em andamento. Abortando.');

            return Command::SUCCESS;
        }

        try {
            $ids = $this->escritoriosPedidos($input, $io);
            if ($ids === null) {
                return Command::FAILURE;
            }
            $simular = (bool) $input->getOption('dry-run');
            if ($simular) {
                $io->note('Modo simulação (--dry-run): nada será gravado nem enviado.');
            }

            return $this->enviarParaTodos($ids, $simular, $io);
        } finally {
            $this->destravar();
        }
    }

    /** @param list<int> $ids */
    private function enviarParaTodos(array $ids, bool $simular, SymfonyStyle $io): int
    {
        $linhas = [];
        $falhas = 0;
        foreach ($ids as $id) {
            $tenant = $this->escritorios->find($id);
            if (!$tenant instanceof Tenant) {
                continue;
            }
            try {
                $r = $this->enviar->executar($tenant, $simular);
                $linhas[] = [$id, (string) $tenant->getName(), $r->enviados, $r->semNada, $r->desligados, $r->jaEnviados, $r->motivo ?? ''];
            } catch (\Throwable $e) {
                ++$falhas;
                $io->error(sprintf('Falha no escritório #%d: %s', $id, $e->getMessage()));
                if (!$this->em->isOpen()) {
                    $this->doctrine->resetManager();
                }
            } finally {
                $this->em->clear();
            }
        }
        if ($linhas !== []) {
            $io->table(['ID', 'Escritório', 'Enviados', 'Sem nada', 'Desligados', 'Já enviados', 'Motivo'], $linhas);
        }

        return $falhas > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /** @return list<int>|null */
    private function escritoriosPedidos(InputInterface $input, SymfonyStyle $io): ?array
    {
        $opcao = $input->getOption('tenant');
        if ($opcao !== null) {
            $tenant = $this->escritorios->find((int) $opcao);
            if (!$tenant instanceof Tenant) {
                $io->error(sprintf('Escritório #%d não encontrado.', (int) $opcao));

                return null;
            }

            return [(int) $tenant->getId()];
        }
        $ids = [];
        foreach ($this->escritorios->findAll() as $tenant) {
            if ($tenant->isActive() === true) {
                $ids[] = (int) $tenant->getId();
            }
        }

        return $ids;
    }

    private function travar(): bool
    {
        $trava = fopen(sys_get_temp_dir() . '/jusprime-resumo-diario.lock', 'c');
        if ($trava === false || !flock($trava, LOCK_EX | LOCK_NB)) {
            return false;
        }
        $this->trava = $trava;

        return true;
    }

    private function destravar(): void
    {
        if ($this->trava !== null) {
            flock($this->trava, LOCK_UN);
            fclose($this->trava);
            $this->trava = null;
        }
    }
}
