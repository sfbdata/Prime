<?php

namespace App\Command;

use App\Entity\Auth\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'app:create-super-admin',
    description: 'Cria o Master (ROLE_SUPER_ADMIN) quando ainda não existe nenhum',
)]
class CreateSuperAdminCommand extends Command
{
    private EntityManagerInterface $entityManager;
    private UserPasswordHasherInterface $passwordHasher;

    public function __construct(
        EntityManagerInterface $entityManager,
        UserPasswordHasherInterface $passwordHasher,
        private readonly UserRepository $userRepository,
    ) {
        parent::__construct();
        $this->entityManager = $entityManager;
        $this->passwordHasher = $passwordHasher;
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'admin@prime.com')
            ->addArgument('password', InputArgument::REQUIRED, 'Prime123!');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $email = $input->getArgument('email');
        $password = $input->getArgument('password');

        // O Master é único (spec perfis-master D2). Havendo um, este comando não cria outro: a
        // troca de Master é do app:master:definir, que tira o papel de quem tem.
        $mastersAtuais = $this->userRepository->findComPapelGlobal('ROLE_SUPER_ADMIN');
        if ($mastersAtuais !== []) {
            $output->writeln(sprintf(
                '<error>Já existe um Master (%s). O Master é único: para trocá-lo, use app:master:definir com o e-mail e --confirmar.</error>',
                implode(', ', array_map(static fn (User $u): string => (string) $u->getEmail(), $mastersAtuais))
            ));

            return Command::FAILURE;
        }

        $user = new User();
        // Normaliza a caixa como os demais caminhos de criação de conta: e-mail é identidade,
        // e `Ana@` some de qualquer busca normalizada (inclusive da recuperação de senha).
        $user->setEmail(mb_strtolower(trim((string) $email)));
        $user->setPassword($this->passwordHasher->hashPassword($user, $password));
        $user->setFullName('Master');
        $user->setRoles(['ROLE_SUPER_ADMIN']);
        $user->setIsActive(true);

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $output->writeln("<info>Master criado com sucesso: $email</info>");

        return Command::SUCCESS;
    }
}
