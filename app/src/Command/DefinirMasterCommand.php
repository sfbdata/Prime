<?php
declare(strict_types=1);

namespace App\Command;

use App\Entity\Auth\User;
use App\Master\Service\RegraDosMasters;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Define o Master PRIMÁRIO do sistema: a conta do e-mail informado fica com ROLE_SUPER_ADMIN e
 * ROLE_MASTER_PRIMARIO, e todos os outros Masters (primário anterior e secundários) perdem os dois.
 * Spec perfis-1.2-masters §4.2 e M4 (trocar o primário é deste comando, rodado pelo dono; a tela
 * só promove e remove secundários) e perfis-master §7 (a troca em PROD é do dono).
 *
 * Sem --confirmar só mostra o plano (quem ganha, quem perde) e não grava nada. Com --confirmar
 * aplica tudo numa transação: ou o novo primário entra e os outros saem, ou nada muda. Só mexe nos
 * dois papéis de Master; os demais papéis de cada conta ficam como estão.
 */
#[AsCommand(
    name: 'app:master:definir',
    description: 'Define o Master primário do sistema pelo e-mail (os demais Masters perdem o papel)',
)]
final class DefinirMasterCommand extends Command
{
    private const PAPEIS = [RegraDosMasters::PAPEL_MASTER, RegraDosMasters::PAPEL_MASTER_PRIMARIO];

    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $em,
        private readonly RegraDosMasters $regraDosMasters,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'E-mail da conta que será o Master primário')
            ->addOption('confirmar', null, InputOption::VALUE_NONE, 'Aplica a mudança (sem esta opção só mostra o que faria)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io    = new SymfonyStyle($input, $output);
        $email = mb_strtolower(trim((string) $input->getArgument('email')));

        $encontrados = $this->userRepository->encontrarPorEmailIgnorandoCaixa($email);
        if (count($encontrados) === 0) {
            $io->error(sprintf('Nenhuma conta com o e-mail "%s".', $email));

            return Command::FAILURE;
        }

        if (count($encontrados) > 1) {
            $io->error(sprintf('Mais de uma conta com o e-mail "%s" (diferem só na caixa). Resolva antes.', $email));

            return Command::FAILURE;
        }

        $novo = $encontrados[0];
        if (!$novo->isActive()) {
            $io->error(sprintf('A conta "%s" está desativada. Reative antes de torná-la Master.', $email));

            return Command::FAILURE;
        }

        $perdem = array_values(array_filter($this->mastersAtuais(), static fn (User $u): bool => $u->getId() !== $novo->getId()));
        $ganha  = !$this->regraDosMasters->ehMasterPrimario($novo);

        $io->section('Plano');
        $io->writeln($ganha
            ? sprintf('Ganha o papel de Master primário: %s (id %d)', $novo->getEmail(), $novo->getId())
            : sprintf('Já é o Master primário e continua: %s (id %d)', $novo->getEmail(), $novo->getId()));
        if ($perdem === []) {
            $io->writeln('Ninguém perde o papel de Master.');
        }
        foreach ($perdem as $user) {
            $io->writeln(sprintf('Perde o papel de Master: %s (id %d)', $user->getEmail(), $user->getId()));
        }

        if (!$ganha && $perdem === []) {
            $io->success('Nada a fazer: esta conta já é o único Master, e é o primário.');

            return Command::SUCCESS;
        }

        if (!$input->getOption('confirmar')) {
            $io->note('Nada foi gravado. Rode de novo com --confirmar para aplicar.');

            return Command::SUCCESS;
        }

        $this->em->wrapInTransaction(function () use ($novo, $perdem, $ganha): void {
            foreach ($perdem as $user) {
                RegraDosMasters::tirarPapeis($user, ...self::PAPEIS);
            }

            if ($ganha) {
                RegraDosMasters::darPapeis($novo, ...self::PAPEIS);
            }

            $this->em->flush();
        });

        $io->success(sprintf('Master primário definido: %s.', $novo->getEmail()));

        return Command::SUCCESS;
    }

    /**
     * Quem tem hoje algum dos dois papéis de Master, uma vez cada.
     *
     * @return list<User>
     */
    private function mastersAtuais(): array
    {
        $porId = [];
        foreach (self::PAPEIS as $papel) {
            foreach ($this->userRepository->findComPapelGlobal($papel) as $user) {
                $porId[$user->getId()] = $user;
            }
        }

        return array_values($porId);
    }
}
