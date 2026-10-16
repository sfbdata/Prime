<?php
declare(strict_types=1);

namespace App\Command;

use App\Entity\Auth\User;
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
 * Deixa EXATAMENTE um Master (ROLE_SUPER_ADMIN) no sistema: a conta do e-mail informado.
 * Spec perfis-master D2 ("Master é único") e §7 (a troca em PROD é do dono).
 *
 * Sem --confirmar só mostra o plano (quem ganha, quem perde) e não grava nada. Com --confirmar
 * aplica tudo numa transação: ou o novo Master entra e os outros saem, ou nada muda. Só mexe no
 * papel ROLE_SUPER_ADMIN; os demais papéis de cada conta ficam como estão.
 */
#[AsCommand(
    name: 'app:master:definir',
    description: 'Define o único Master do sistema (ROLE_SUPER_ADMIN) pelo e-mail',
)]
final class DefinirMasterCommand extends Command
{
    private const PAPEL = 'ROLE_SUPER_ADMIN';

    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'E-mail da conta que será o único Master')
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

        $atuais = $this->userRepository->findComPapelGlobal(self::PAPEL);
        $perdem = array_values(array_filter($atuais, static fn (User $u): bool => $u->getId() !== $novo->getId()));
        $ganha  = !in_array(self::PAPEL, $novo->getRoles(), true);

        $io->section('Plano');
        $io->writeln($ganha
            ? sprintf('Ganha o papel de Master: %s (id %d)', $novo->getEmail(), $novo->getId())
            : sprintf('Já é Master e continua: %s (id %d)', $novo->getEmail(), $novo->getId()));
        if ($perdem === []) {
            $io->writeln('Ninguém perde o papel de Master.');
        }
        foreach ($perdem as $user) {
            $io->writeln(sprintf('Perde o papel de Master: %s (id %d)', $user->getEmail(), $user->getId()));
        }

        if (!$ganha && $perdem === []) {
            $io->success('Nada a fazer: o Master já é único.');

            return Command::SUCCESS;
        }

        if (!$input->getOption('confirmar')) {
            $io->note('Nada foi gravado. Rode de novo com --confirmar para aplicar.');

            return Command::SUCCESS;
        }

        $this->em->wrapInTransaction(function () use ($novo, $perdem, $ganha): void {
            foreach ($perdem as $user) {
                $user->setRoles(array_values(array_filter(
                    $this->papeisGravados($user),
                    static fn (string $papel): bool => $papel !== self::PAPEL
                )));
            }

            if ($ganha) {
                $novo->setRoles(array_values(array_unique([...$this->papeisGravados($novo), self::PAPEL])));
            }

            $this->em->flush();
        });

        $io->success(sprintf('Master definido: %s.', $novo->getEmail()));

        return Command::SUCCESS;
    }

    /**
     * Papéis como gravados na coluna, sem o ROLE_USER que o getRoles() acrescenta sempre.
     *
     * @return list<string>
     */
    private function papeisGravados(User $user): array
    {
        return array_values(array_filter(
            $user->getRoles(),
            static fn (string $papel): bool => $papel !== 'ROLE_USER'
        ));
    }
}
