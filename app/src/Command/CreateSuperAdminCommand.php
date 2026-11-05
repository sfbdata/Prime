<?php

namespace App\Command;

use App\Auth\Service\ForcaDaSenha;
use App\Entity\Auth\User;
use App\Master\Service\RegraDosMasters;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'app:create-super-admin',
    description: 'Cria o primeiro Master, já primário, quando ainda não existe nenhum',
)]
class CreateSuperAdminCommand extends Command
{
    /**
     * sha256 (do valor em minúsculas) das senhas de exemplo que já foram publicadas e nunca servem para o Master
     * (auditoria 4.08). A mesma da guarda de segredos (scripts/git-hooks/verificar-segredos.py): o literal não volta.
     */
    public const SENHAS_DE_EXEMPLO_SHA256 = [
        'eefd77d3820ea744f9271b91b52625cc3d1428fd50098ea2432584d6d41d35fd',
    ];

    private EntityManagerInterface $entityManager;
    private UserPasswordHasherInterface $passwordHasher;

    public function __construct(
        EntityManagerInterface $entityManager,
        UserPasswordHasherInterface $passwordHasher,
        private readonly UserRepository $userRepository,
        /** @var list<string> */
        private readonly array $senhasRecusadasSha256 = self::SENHAS_DE_EXEMPLO_SHA256,
    ) {
        parent::__construct();
        $this->entityManager = $entityManager;
        $this->passwordHasher = $passwordHasher;
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'E-mail do Master')
            ->addArgument('password', InputArgument::OPTIONAL, 'Senha do Master. Sem ela, o comando pergunta sem mostrar o que se digita (melhor: não fica no histórico do terminal)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = $input->getArgument('email');

        // Só cria o PRIMEIRO Master, já primário (spec perfis-1.2-masters §4.2). Havendo Master, não
        // cria outro: Master secundário se promove pela tela /master e a troca do primário é do
        // app:master:definir.
        $mastersAtuais = $this->userRepository->findComPapelGlobal(RegraDosMasters::PAPEL_MASTER);
        if ($mastersAtuais !== []) {
            $output->writeln(sprintf(
                '<error>Já existe Master (%s). Para trocar o Master primário, use app:master:definir com o e-mail e --confirmar; Master secundário se promove pela tela do Master.</error>',
                implode(', ', array_map(static fn (User $u): string => (string) $u->getEmail(), $mastersAtuais))
            ));

            return Command::FAILURE;
        }

        $password = $this->senhaConferida($input, $io);
        if ($password === null) {
            return Command::FAILURE;
        }

        $user = new User();
        // Normaliza a caixa como os demais caminhos de criação de conta: e-mail é identidade,
        // e `Ana@` some de qualquer busca normalizada (inclusive da recuperação de senha).
        $user->setEmail(mb_strtolower(trim((string) $email)));
        $user->setPassword($this->passwordHasher->hashPassword($user, $password));
        $user->setFullName('Master');
        $user->setRoles([RegraDosMasters::PAPEL_MASTER, RegraDosMasters::PAPEL_MASTER_PRIMARIO]);
        $user->setIsActive(true);

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $output->writeln("<info>Master primário criado com sucesso: $email</info>");

        return Command::SUCCESS;
    }

    /**
     * A senha do argumento ou, sem ele, perguntada sem eco (fora do histórico do terminal e da lista de processos).
     * Devolve null, já com o erro na tela, quando não há como perguntar ou a senha não passa na régua.
     */
    private function senhaConferida(InputInterface $input, SymfonyStyle $io): ?string
    {
        $senha = $input->getArgument('password');
        if ($senha === null) {
            if (!$input->isInteractive()) {
                $io->error('Informe a senha do Master: rode o comando num terminal, que ele pergunta sem mostrar o que se digita.');

                return null;
            }
            $senha = $io->askHidden('Senha do Master');
        }

        $erro = $this->erroDaSenha((string) $senha);
        if ($erro !== null) {
            $io->error($erro);

            return null;
        }

        return (string) $senha;
    }

    /** A régua do cadastro (ForcaDaSenha) e a recusa das senhas de exemplo já publicadas. */
    private function erroDaSenha(string $senha): ?string
    {
        if (strlen($senha) > ForcaDaSenha::MAXIMO_EM_BYTES || !ForcaDaSenha::aceita($senha)) {
            return ForcaDaSenha::ERRO;
        }

        if (in_array(hash('sha256', mb_strtolower($senha)), $this->senhasRecusadasSha256, true)) {
            return 'Essa é uma senha de exemplo que já foi publicada. Escolha outra.';
        }

        return null;
    }
}
