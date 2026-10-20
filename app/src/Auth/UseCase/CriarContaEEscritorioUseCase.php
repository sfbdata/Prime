<?php

declare(strict_types=1);

namespace App\Auth\UseCase;

use App\Auth\DTO\ContaCriadaOutput;
use App\Auth\DTO\CriarContaEEscritorioInput;
use App\Auth\Entity\ConfirmacaoEmail;
use App\Auth\Event\ContaCriadaPeloCadastro;
use App\Auth\Repository\ConfirmacaoEmailRepository;
use App\Auth\Service\CadastroMailer;
use App\Auth\Service\ForcaDaSenha;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Legal\PoliticaPrivacidadeVigente;
use App\Repository\UserRepository;
use App\Service\TenantBootstrapService;
use App\Termo\DTO\RegistrarAceiteTermoInput;
use App\Termo\Entity\CienciaPolitica;
use App\Termo\Repository\CienciaPoliticaRepository;
use App\Termo\UseCase\RegistrarAceiteTermoUseCase;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validation;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * "Criar minha conta" da tela de entrada (desenho 10, decisões a, b e c da spec `login-desenho-10.md`).
 *
 * Numa transação só: User ativo (sem OAB, e-mail a confirmar), Tenant, bootstrap (vínculo dono, perfil admin,
 * seed), aceite dos Termos, ciência da Política e o link de confirmação do e-mail. Depois do commit: o e-mail de
 * confirmação (falha só vai para o log) e o evento ContaCriadaPeloCadastro. O token em claro só vai para o e-mail.
 *
 * Validação na ordem e com os textos do desenho: \InvalidArgumentException; e-mail já usado: \DomainException.
 */
final class CriarContaEEscritorioUseCase
{
    public const ERRO_NOME = 'Informe seu nome completo.';
    public const ERRO_ESCRITORIO = 'Informe o nome do escritório.';
    public const ERRO_EMAIL = 'Informe um e-mail válido.';
    public const ERRO_ACEITE = 'É preciso aceitar os Termos e a Política de privacidade.';
    public const ERRO_EMAIL_EXISTENTE = 'Já existe uma conta com este e-mail. Faça login para criar o escritório por dentro.';

    /** Acima disso o hasher do Symfony recusa a senha com exceção; a mensagem é a mesma da senha fraca. */
    private const SENHA_MAXIMA = 4096;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $userRepository,
        private readonly TenantBootstrapService $bootstrap,
        private readonly RegistrarAceiteTermoUseCase $registrarAceite,
        private readonly CienciaPoliticaRepository $cienciaPoliticaRepository,
        private readonly ConfirmacaoEmailRepository $confirmacaoEmailRepository,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly CadastroMailer $cadastroMailer,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly LoggerInterface $logger,
        private readonly ClockInterface $relogio,
    ) {
    }

    public function executar(CriarContaEEscritorioInput $input, string $ip, ?string $userAgent): ContaCriadaOutput
    {
        self::validar($input);
        $email = mb_strtolower(trim($input->email));

        // Ignora a caixa: o UNIQUE do banco é case-sensitive e deixaria nascer uma segunda conta que só difere em
        // maiúsculas, indistinguível dali em diante.
        if ($this->userRepository->encontrarPorEmailIgnorandoCaixa($email) !== []) {
            throw new \DomainException(self::ERRO_EMAIL_EXISTENTE);
        }

        $token = bin2hex(random_bytes(32));

        try {
            $conta = $this->em->wrapInTransaction(
                fn (): ContaCriadaOutput => $this->criar($input, $email, $token, $ip, $userAgent),
            );
        } catch (UniqueConstraintViolationException) {
            // Corrida: outro cadastro do mesmo e-mail gravou entre o SELECT e o INSERT.
            throw new \DomainException(self::ERRO_EMAIL_EXISTENTE);
        }

        $this->enviarConfirmacao($conta->user, $token);
        $this->despacharEvento($conta);

        return $conta;
    }

    private static function validar(CriarContaEEscritorioInput $input): void
    {
        $erro = match (true) {
            !self::nomeCompleto($input->nome) => self::ERRO_NOME,
            !self::escritorioValido($input->escritorio) => self::ERRO_ESCRITORIO,
            !self::emailValido($input->email) => self::ERRO_EMAIL,
            strlen($input->senha) > self::SENHA_MAXIMA || !ForcaDaSenha::aceita($input->senha) => ForcaDaSenha::ERRO,
            !$input->aceite => self::ERRO_ACEITE,
            default => null,
        };

        if ($erro !== null) {
            throw new \InvalidArgumentException($erro);
        }
    }

    /** Igual ao desenho: pelo menos duas palavras separadas por espaço. */
    private static function nomeCompleto(string $nome): bool
    {
        $nome = trim($nome);
        $palavras = preg_split('/\s+/u', $nome, -1, PREG_SPLIT_NO_EMPTY);

        return is_array($palavras) && count($palavras) >= 2 && mb_strlen($nome) <= 255;
    }

    private static function escritorioValido(string $escritorio): bool
    {
        $escritorio = trim($escritorio);

        return $escritorio !== '' && mb_strlen($escritorio) <= 255;
    }

    /**
     * A constraint Email do Symfony no modo estrito (RFC, via egulias), e cabe na coluna. O `\S+@\S+\.\S+` do
     * desenho deixava passar "a@b.com?cc=c@d.com" e "a@b.com,c@d.com", que viram mais de um destinatário.
     */
    private static function emailValido(string $email): bool
    {
        $email = trim($email);

        return mb_strlen($email) <= 180
            && count(Validation::createValidator()->validate($email, [
                new Assert\NotBlank(),
                new Assert\Email(mode: Assert\Email::VALIDATION_MODE_STRICT),
            ])) === 0;
    }

    private function criar(CriarContaEEscritorioInput $input, string $email, string $token, string $ip, ?string $userAgent): ContaCriadaOutput
    {
        $agora = $this->relogio->now();
        $user = $this->novoUsuario($input, $email, $agora);

        $tenant = new Tenant();
        $tenant->setName(trim($input->escritorio));
        $this->em->persist($tenant);

        // bootstrap seta criadoPor, cria perfil admin + vínculo dono + seed, e faz flush().
        $this->bootstrap->bootstrap($tenant, $user);
        $this->registrarAceiteECiencia($user, $tenant, $ip, $userAgent, $agora);
        $this->confirmacaoEmailRepository->salvar(new ConfirmacaoEmail(
            user: $user,
            tokenHash: ConfirmacaoEmail::hashDoToken($token),
            expiraEm: $agora->modify(ConfirmacaoEmail::VALIDADE),
            criadoEm: $agora,
        ));
        $this->em->flush();

        return new ContaCriadaOutput($user, $tenant);
    }

    private function novoUsuario(CriarContaEEscritorioInput $input, string $email, \DateTimeImmutable $agora): User
    {
        $user = new User();
        $user->setEmail($email);
        $user->setFullName(trim($input->nome));
        $user->setIsActive(true);
        $user->setRoles(['ROLE_USER']);
        $user->setPassword($this->passwordHasher->hashPassword($user, $input->senha));
        $user->marcarEmailAConfirmar($agora);
        $this->em->persist($user);

        return $user;
    }

    /** Decisão b: a caixa única grava o aceite dos Termos E a ciência da Política, cada um com a sua versão. */
    private function registrarAceiteECiencia(User $user, Tenant $tenant, string $ip, ?string $userAgent, \DateTimeImmutable $agora): void
    {
        $this->registrarAceite->executar(new RegistrarAceiteTermoInput(
            user: $user,
            ip: $ip,
            userAgent: $userAgent,
            tenant: $tenant,
        ));
        $this->cienciaPoliticaRepository->salvar(new CienciaPolitica(
            user: $user,
            versao: PoliticaPrivacidadeVigente::VERSAO,
            ip: $ip,
            userAgent: $userAgent,
            cienteEm: $agora,
        ));
    }

    /** Decisão c: a conta já existe e já entra; o e-mail não pode travar nada. */
    private function enviarConfirmacao(User $user, string $token): void
    {
        try {
            $this->cadastroMailer->enviarConfirmacaoDeEmail($user, $token);
        } catch (\Throwable $e) {
            $this->logger->error('Falha ao enviar o e-mail de confirmação da conta criada pelo cadastro.', [
                'exception' => $e,
                'userId' => $user->getId(),
            ]);
        }
    }

    /** Um ouvinte nunca derruba o cadastro: a conta já está gravada e a pessoa precisa entrar. */
    private function despacharEvento(ContaCriadaOutput $conta): void
    {
        try {
            $this->eventDispatcher->dispatch(new ContaCriadaPeloCadastro(
                (int) $conta->user->getId(),
                (int) $conta->tenant->getId(),
            ));
        } catch (\Throwable $e) {
            $this->logger->error('Um ouvinte de ContaCriadaPeloCadastro falhou.', [
                'exception' => $e,
                'userId' => $conta->user->getId(),
            ]);
        }
    }
}
