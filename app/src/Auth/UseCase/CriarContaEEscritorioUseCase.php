<?php

declare(strict_types=1);

namespace App\Auth\UseCase;

use App\Auth\DTO\ContaCriadaOutput;
use App\Auth\DTO\CriarContaEEscritorioInput;
use App\Auth\DTO\IdentidadeDoGoogle;
use App\Auth\Entity\ConfirmacaoEmail;
use App\Auth\Event\ContaCriadaPeloCadastro;
use App\Auth\Event\EmailDaContaConfirmado;
use App\Auth\Exception\CadastroNaoDisponivel;
use App\Auth\Exception\EntradaPeloGoogleRecusadaException;
use App\Auth\Repository\ConfirmacaoEmailRepository;
use App\Auth\Service\CadastroMailer;
use App\Auth\Service\PoliticaDeCadastro;
use App\Auth\Service\TetoDoReenvioDeConfirmacao;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Legal\PoliticaPrivacidadeVigente;
use App\Profile\Entity\UserProfile;
use App\Profile\Enum\Genero;
use App\Repository\UserRepository;
use App\Service\TenantBootstrapService;
use App\Termo\DTO\RegistrarAceiteTermoInput;
use App\Termo\Entity\CienciaPolitica;
use App\Termo\Enum\OrigemDoAceite;
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
 * Numa transação só: User ativo (sem OAB, e-mail a confirmar) com o perfil e o gênero, Tenant, bootstrap (vínculo
 * dono, perfil admin, seed), aceite dos Termos, ciência da Política e o link de confirmação do e-mail. Depois do
 * commit: o e-mail de confirmação (falha só vai para o log) e o evento ContaCriadaPeloCadastro. O token em claro só
 * vai para o e-mail.
 *
 * A senha digitada no cadastro é ignorada (spec `cadastro-senha-no-link.md`): a conta nasce com uma senha
 * inutilizável e quem clica no link de confirmação cria a senha (CriarSenhaPeloLinkUseCase). Assim um terceiro que
 * cadastra o e-mail de outra pessoa não entra com a senha que escolheu.
 *
 * Validação na ordem e com os textos do desenho: \InvalidArgumentException.
 *
 * E-mail que já tem conta (achado de auditoria interna F14; spec `seguranca-a-convites.md`, raiz E): o cadastro não
 * diz que a conta existe. Nada é gravado, a dona da conta recebe um e-mail avisando que já tem conta e apontando o
 * "Esqueci a senha", e o caso de uso devolve null; o controller responde igual ao e-mail novo.
 *
 * Pelo Google (`executarPeloGoogle`, spec entrar-com-google, entrega 2; desenho 10 VS 24.10.26): o e-mail é o que o
 * Google provou (lido da sessão pelo controller, nunca do navegador), a conta nasce confirmada e ligada ao `sub`, sem
 * link de confirmação nem e-mail, e entra na hora. Quem chega aqui já provou ser dona do e-mail; por isso o e-mail que
 * já tem conta é recusado com mensagem própria em vez da resposta neutra.
 *
 * Pelo Facebook (`executar` com `$facebookId`, spec `login-cupom-e-facebook.md`, PR B): o Facebook não prova e-mail,
 * então é o mesmo cadastro por e-mail (conta NÃO confirmada, link de confirmação, resposta neutra para e-mail com
 * conta), só gravando o `facebook_id` na conta nova. `facebook_id` já ligado a outra conta: a mesma resposta neutra,
 * sem criar nada.
 *
 * Política de cadastro (configuração `cadastro` do Master, spec `login-config-cadastro.md`): as duas portas públicas
 * lançam CadastroNaoDisponivel, antes de qualquer leitura ou gravação, quando o cadastro não está aberto.
 */
final class CriarContaEEscritorioUseCase
{
    public const ERRO_NOME = 'Informe seu nome completo.';
    public const ERRO_ESCRITORIO = 'Informe o nome do escritório.';
    public const ERRO_EMAIL = 'Informe um e-mail válido.';
    public const ERRO_GENERO = Genero::ERRO_INFORME;
    public const ERRO_ACEITE = 'É preciso aceitar os Termos e a Política de privacidade.';
    public const ERRO_JA_TEM_CONTA_GOOGLE = 'Já existe uma conta com este e-mail. Entre com o Google.';

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
        private readonly ReenviarConfirmacaoDeEmailUseCase $reenviarConfirmacao,
        private readonly TetoDoReenvioDeConfirmacao $teto,
        private readonly PoliticaDeCadastro $politicaDeCadastro,
    ) {
    }

    /** O e-mail como o cadastro grava e responde: sem espaços nas pontas e em minúsculas. */
    public static function normalizarEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    /**
     * @return ContaCriadaOutput|null null quando o e-mail já tinha conta (nada gravado; a dona recebe o aviso)
     *
     * @throws CadastroNaoDisponivel política de cadastro diferente de aberto (nada é lido nem gravado)
     */
    public function executar(CriarContaEEscritorioInput $input, string $ip, ?string $userAgent, ?string $facebookId = null): ?ContaCriadaOutput
    {
        // Primeiro de tudo: com o cadastro fechado ao público, nem a validação nem a busca do e-mail rodam, e a
        // resposta não diz se o e-mail tem conta (spec login-config-cadastro, item 4).
        $this->politicaDeCadastro->exigirPublicoAberto();
        self::validar($input);
        $email = self::normalizarEmail($input->email);

        // Ignora a caixa: o UNIQUE do banco é case-sensitive e deixaria nascer uma segunda conta que só difere em
        // maiúsculas, indistinguível dali em diante.
        $existentes = $this->userRepository->encontrarPorEmailIgnorandoCaixa($email);
        if ($existentes !== []) {
            // O mesmo hash de senha do caminho novo, jogado fora: a resposta não sai muito mais rápido que a de
            // quem cria a conta. A dona da conta recebe o aviso, como a conta nova recebe a confirmação.
            $this->senhaInutilizavel(new User());
            $this->avisarContaExistente($existentes[0]);

            return null;
        }
        if ($facebookId !== null && $this->facebookIdJaLigado($facebookId)) {
            return null;
        }

        $token = bin2hex(random_bytes(32));

        try {
            $conta = $this->em->wrapInTransaction(
                fn (): ContaCriadaOutput => $this->criar($input, $email, $token, $ip, $userAgent, null, $facebookId),
            );
        } catch (UniqueConstraintViolationException) {
            // Corrida: outro cadastro do mesmo e-mail (ou do mesmo `id` do Facebook) gravou entre o SELECT e o INSERT. A mesma resposta do e-mail que
            // já tem conta; sem o aviso, porque o outro cadastro já mandou a confirmação para a mesma caixa.
            return null;
        }

        $this->enviarConfirmacao($conta->user, $token);
        $this->despacharEvento($conta);

        return $conta;
    }

    /** Este `id` do Facebook já é de outra conta: nada é criado nem ligado, e a resposta é a neutra (mesmo custo do hash). */
    private function facebookIdJaLigado(string $facebookId): bool
    {
        if ($this->userRepository->findOneBy(['facebookId' => $facebookId]) === null) {
            return false;
        }
        $this->senhaInutilizavel(new User());

        return true;
    }

    /** @throws \InvalidArgumentException|\DomainException */
    public function executarPeloGoogle(CriarContaEEscritorioInput $input, IdentidadeDoGoogle $identidade, string $ip, ?string $userAgent): ContaCriadaOutput
    {
        $this->politicaDeCadastro->exigirPublicoAberto();
        // Decisão 10: a garantia é conferida de novo aqui, além do autenticador que gravou a identidade na sessão.
        if (!$identidade->emailComGarantia()) {
            throw new \DomainException(EntradaPeloGoogleRecusadaException::SEM_GARANTIA_CADASTRO);
        }
        $input = new CriarContaEEscritorioInput($input->nome, $input->escritorio, $identidade->email, '', $input->aceite, $input->genero);
        self::validar($input);
        $email = self::normalizarEmail($identidade->email);

        if ($this->userRepository->encontrarPorEmailIgnorandoCaixa($email) !== []
            || $this->userRepository->findOneBy(['googleSub' => $identidade->sub]) !== null) {
            throw new \DomainException(self::ERRO_JA_TEM_CONTA_GOOGLE);
        }

        try {
            $conta = $this->em->wrapInTransaction(
                fn (): ContaCriadaOutput => $this->criar($input, $email, null, $ip, $userAgent, $identidade->sub),
            );
        } catch (UniqueConstraintViolationException) {
            // Corrida: o mesmo e-mail ou o mesmo `sub` gravou entre o SELECT e o INSERT.
            throw new \DomainException(self::ERRO_JA_TEM_CONTA_GOOGLE);
        }

        $this->despacharEvento($conta);
        // A conta nasce confirmada: quem ouve a confirmação do e-mail também precisa saber dela.
        try {
            $this->eventDispatcher->dispatch(new EmailDaContaConfirmado((int) $conta->user->getId()));
        } catch (\Throwable $e) {
            $this->logger->error('Um ouvinte de EmailDaContaConfirmado falhou.', ['exception' => $e, 'userId' => $conta->user->getId()]);
        }

        return $conta;
    }

    private static function validar(CriarContaEEscritorioInput $input): void
    {
        $erro = match (true) {
            !self::nomeCompleto($input->nome) => self::ERRO_NOME,
            !self::escritorioValido($input->escritorio) => self::ERRO_ESCRITORIO,
            !self::emailValido($input->email) => self::ERRO_EMAIL,
            Genero::tryFrom($input->genero)?->preenchido() !== true => self::ERRO_GENERO,
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

    /** `$token` nulo e `$googleSub` preenchido: a conta do Google, já confirmada e sem link de confirmação. */
    private function criar(CriarContaEEscritorioInput $input, string $email, ?string $token, string $ip, ?string $userAgent, ?string $googleSub = null, ?string $facebookId = null): ContaCriadaOutput
    {
        $agora = $this->relogio->now();
        $user = $this->novoUsuario($input, $email, $agora, $googleSub, $facebookId);

        $tenant = new Tenant();
        $tenant->setName(trim($input->escritorio));
        $this->em->persist($tenant);

        // bootstrap seta criadoPor, cria perfil admin + vínculo dono + seed, e faz flush().
        $this->bootstrap->bootstrap($tenant, $user);
        $this->registrarAceiteECiencia($user, $tenant, $ip, $userAgent, $agora);
        if ($token !== null) {
            $this->confirmacaoEmailRepository->salvar(new ConfirmacaoEmail(
                user: $user,
                tokenHash: ConfirmacaoEmail::hashDoToken($token),
                expiraEm: $agora->modify(ConfirmacaoEmail::VALIDADE),
                criadoEm: $agora,
            ));
        }
        $this->em->flush();

        return new ContaCriadaOutput($user, $tenant);
    }

    private function novoUsuario(CriarContaEEscritorioInput $input, string $email, \DateTimeImmutable $agora, ?string $googleSub, ?string $facebookId = null): User
    {
        $user = new User();
        $user->setEmail($email);
        $user->setFullName(trim($input->nome));
        $user->setIsActive(true);
        $user->setRoles(['ROLE_USER']);
        $user->setPassword($this->senhaInutilizavel($user));
        if ($googleSub === null) {
            $user->marcarEmailAConfirmar($agora);
        } else {
            // O Google provou o e-mail (decisão 10 conferida no autenticador): nasce confirmada e ligada.
            $user->ligarAoGoogle($googleSub);
        }
        if ($facebookId !== null) {
            // Só o `id` (prova da pessoa para o app); o e-mail segue a confirmar, pelo link.
            $user->ligarAoFacebook($facebookId);
        }
        // O perfil nasce com o gênero do cadastro (spec genero-no-cadastro); vai junto com o User, em cascata.
        UserProfile::daContaNova($user, Genero::informado($input->genero));
        $this->em->persist($user);

        return $user;
    }

    /**
     * 32 bytes aleatórios jogados fora: ninguém conhece a senha, então ninguém entra antes de criar a sua pelo link
     * (ou pelo código do "Esqueci a senha"). Em hexadecimal porque o bcrypt recusa o byte nulo.
     */
    private function senhaInutilizavel(User $user): string
    {
        return $this->passwordHasher->hashPassword($user, bin2hex(random_bytes(32)));
    }

    /** Decisão b: a caixa única grava o aceite dos Termos E a ciência da Política, cada um com a sua versão. */
    private function registrarAceiteECiencia(User $user, Tenant $tenant, string $ip, ?string $userAgent, \DateTimeImmutable $agora): void
    {
        $this->registrarAceite->executar(new RegistrarAceiteTermoInput(
            user: $user,
            ip: $ip,
            userAgent: $userAgent,
            tenant: $tenant,
            origem: OrigemDoAceite::Cadastro,
        ));
        $this->cienciaPoliticaRepository->salvar(new CienciaPolitica(
            user: $user,
            versao: PoliticaPrivacidadeVigente::VERSAO,
            ip: $ip,
            userAgent: $userAgent,
            cienteEm: $agora,
            origem: OrigemDoAceite::Cadastro,
        ));
    }

    /** A conta já existe e espera o link (cadastro-senha-no-link): uma falha no envio não desfaz o cadastro; o reenvio cobre. */
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

    /**
     * O aviso segue a situação da conta: inativa ou em quarentena não recebe nada; a confirmar (ainda sem senha)
     * recebe o link de confirmação normal, pelo reenvio que já existe e com o teto dele; a ativa e confirmada recebe
     * o "já tem conta" com o "Esqueci a senha". Falha no envio só vai para o log: a resposta do cadastro é a mesma.
     */
    private function avisarContaExistente(User $user): void
    {
        if (!$user->isActive()) {
            return;
        }
        if (!$user->isEmailConfirmado()) {
            if ($this->teto->recusa($user) === null) {
                $this->reenviarConfirmacao->executar($user);
            }

            return;
        }

        try {
            $this->cadastroMailer->enviarAvisoDeContaExistente($user);
        } catch (\Throwable $e) {
            $this->logger->error('Falha ao enviar o aviso de conta existente do cadastro.', [
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
