<?php

declare(strict_types=1);

namespace App\Tests\Auth\Unit;

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
use App\Auth\UseCase\CriarContaEEscritorioUseCase;
use App\Auth\UseCase\ReenviarConfirmacaoDeEmailUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Legal\PoliticaPrivacidadeVigente;
use App\Profile\Enum\Genero;
use App\Repository\UserRepository;
use App\Service\TenantBootstrapService;
use App\Shared\Email\IdentidadeDeEmail;
use App\Tests\Support\ConfiguracaoDaPlataformaFalsa;
use App\Termo\Entity\AceiteTermo;
use App\Termo\Entity\CienciaPolitica;
use App\Termo\Repository\AceiteTermoRepository;
use App\Termo\Repository\CienciaPoliticaRepository;
use App\Termo\TermoVigente;
use App\Termo\UseCase\RegistrarAceiteTermoUseCase;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactory;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasher;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * Sem banco: o EntityManager só executa a closure da transação (como no ConfirmarCadastroUseCaseTest) e o bootstrap
 * faz o papel do flush que dá id ao User e ao Tenant. O e-mail passa pelo CadastroMailer real, com um transporte de
 * mentira; o evento pelo EventDispatcher real. O caminho com banco está no CadastroControllerTest.
 */
#[CoversClass(CriarContaEEscritorioUseCase::class)]
final class CriarContaEEscritorioUseCaseTest extends TestCase
{
    private const AGORA = '2026-10-19 10:00:00';

    private EntityManagerInterface&MockObject $em;
    private UserRepository&MockObject $userRepository;
    private TenantBootstrapService&MockObject $bootstrap;
    private AceiteTermoRepository&MockObject $aceiteRepository;
    private CienciaPoliticaRepository&MockObject $cienciaRepository;
    private ConfirmacaoEmailRepository&MockObject $confirmacaoRepository;
    private EventDispatcher $eventos;
    private MailerInterface $transporte;
    private AbstractLogger $logger;
    private UserPasswordHasher $hasher;
    private ConfiguracaoDaPlataformaFalsa $configuracao;

    /** @var list<object> */
    private array $despachados = [];

    /** @var list<Email> */
    private array $enviados = [];

    private bool $transporteFalha = false;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->userRepository = $this->createMock(UserRepository::class);
        $this->bootstrap = $this->createMock(TenantBootstrapService::class);
        $this->aceiteRepository = $this->createMock(AceiteTermoRepository::class);
        $this->cienciaRepository = $this->createMock(CienciaPoliticaRepository::class);
        $this->confirmacaoRepository = $this->createMock(ConfirmacaoEmailRepository::class);
        $this->configuracao = new ConfiguracaoDaPlataformaFalsa(['cadastro' => 'on']);
        $this->eventos = new EventDispatcher();
        $this->eventos->addListener(ContaCriadaPeloCadastro::class, function (object $evento): void {
            $this->despachados[] = $evento;
        });
        $this->eventos->addListener(EmailDaContaConfirmado::class, function (object $evento): void {
            $this->despachados[] = $evento;
        });
        $this->hasher = new UserPasswordHasher(new PasswordHasherFactory([
            User::class => ['algorithm' => 'pbkdf2', 'iterations' => 1],
        ]));
        $this->transporte = $this->transporteDeMentira();
        $this->logger = $this->loggerQueGuarda();
    }

    private function useCase(): CriarContaEEscritorioUseCase
    {
        return new CriarContaEEscritorioUseCase(
            $this->em,
            $this->userRepository,
            $this->bootstrap,
            new RegistrarAceiteTermoUseCase($this->aceiteRepository, new TermoVigente()),
            $this->cienciaRepository,
            $this->confirmacaoRepository,
            $this->hasher,
            $this->mailer(),
            $this->eventos,
            $this->logger,
            new MockClock(new \DateTimeImmutable(self::AGORA)),
            new ReenviarConfirmacaoDeEmailUseCase($this->confirmacaoRepository, $this->mailer(), $this->logger, new MockClock(new \DateTimeImmutable(self::AGORA))),
            new TetoDoReenvioDeConfirmacao($this->confirmacaoRepository, new MockClock(new \DateTimeImmutable(self::AGORA))),
            new PoliticaDeCadastro($this->configuracao),
        );
    }

    private function mailer(): CadastroMailer
    {
        $rotas = new RouteCollection();
        $rotas->add('auth_cadastro_confirmar_email', new Route('/cadastro/confirmar-email/{token}'));
        $rotas->add('auth_senha_esqueci', new Route('/senha/esqueci'));

        return new CadastroMailer(
            $this->transporte,
            new Environment(new ArrayLoader([
                'auth/cadastro/email_confirmacao.html.twig' => '{{ nome }} {{ link }}',
                'auth/cadastro/email_conta_existente.html.twig' => 'existente {{ nome }} {{ link }}',
            ])),
            new UrlGenerator($rotas, new RequestContext(host: 'bluejus.test', scheme: 'https')),
            new IdentidadeDeEmail('BlueJus <nao-responda@bluejus.test>', 'infra@bluejus.test'),
            new EventDispatcher(),
        );
    }

    private function transporteDeMentira(): MailerInterface
    {
        $teste = $this;

        return new class ($teste) implements MailerInterface {
            public function __construct(private readonly CriarContaEEscritorioUseCaseTest $teste)
            {
            }

            public function send(RawMessage $message, ?Envelope $envelope = null): void
            {
                $this->teste->registrarEnvio($message);
            }
        };
    }

    public function registrarEnvio(RawMessage $mensagem): void
    {
        if ($this->transporteFalha) {
            throw new TransportException('SMTP fora do ar');
        }

        self::assertInstanceOf(Email::class, $mensagem);
        $this->enviados[] = $mensagem;
    }

    private function loggerQueGuarda(): AbstractLogger
    {
        return new class () extends AbstractLogger {
            /** @var list<array{nivel: mixed, mensagem: string, contexto: array<mixed>}> */
            public array $registros = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->registros[] = ['nivel' => $level, 'mensagem' => (string) $message, 'contexto' => $context];
            }
        };
    }

    /** O bootstrap real dá flush; aqui ele faz o que o flush faria: dá id ao User e ao Tenant. */
    private function transacaoExecuta(): void
    {
        $this->em->method('wrapInTransaction')->willReturnCallback(static fn (callable $cb) => $cb());
        $this->bootstrap->method('bootstrap')->willReturnCallback(function (Tenant $tenant, User $user): TenantRole {
            self::definirId($tenant, 7);
            self::definirId($user, 42);

            return $this->createStub(TenantRole::class);
        });
    }

    private static function definirId(object $entidade, int $id): void
    {
        (new \ReflectionProperty($entidade, 'id'))->setValue($entidade, $id);
    }

    private static function input(
        string $nome = 'Ana Souza',
        string $escritorio = 'Souza Advogados',
        string $email = 'ana@adv.com',
        string $senha = 'segredo123',
        bool $aceite = true,
        string $genero = 'feminino',
    ): CriarContaEEscritorioInput {
        return new CriarContaEEscritorioInput($nome, $escritorio, $email, $senha, $aceite, $genero);
    }

    #[TestDox('Cria User ativo sem OAB com e-mail a confirmar, Tenant, aceite dos Termos, ciência da Política e o link')]
    public function testCriaContaCompleta(): void
    {
        $this->transacaoExecuta();
        $this->userRepository->method('encontrarPorEmailIgnorandoCaixa')->willReturn([]);
        $aceite = null;
        $this->aceiteRepository->method('existeAceiteVigente')->willReturn(false);
        $this->aceiteRepository->expects($this->once())->method('salvar')
            ->willReturnCallback(static function (AceiteTermo $a) use (&$aceite): void {
                $aceite = $a;
            });
        $ciencia = null;
        $this->cienciaRepository->expects($this->once())->method('salvar')
            ->willReturnCallback(static function (CienciaPolitica $c) use (&$ciencia): void {
                $ciencia = $c;
            });
        $confirmacao = null;
        $this->confirmacaoRepository->expects($this->once())->method('salvar')
            ->willReturnCallback(static function (ConfirmacaoEmail $c) use (&$confirmacao): void {
                $confirmacao = $c;
            });
        $this->em->expects($this->atLeastOnce())->method('flush');

        $conta = $this->useCase()->executar(self::input(nome: '  Ana Souza ', escritorio: ' Souza Advogados ', email: ' Ana@ADV.com '), '10.0.0.1', 'Navegador X');

        $user = $conta->user;
        self::assertSame('ana@adv.com', $user->getEmail());
        self::assertSame('Ana Souza', $user->getFullName());
        self::assertTrue($user->isActive());
        self::assertContains('ROLE_USER', $user->getRoles());
        self::assertNull($user->getOabNumero());
        // A senha do cadastro é ignorada (spec cadastro-senha-no-link): a conta nasce com uma senha que ninguém conhece.
        self::assertNotEmpty($user->getPassword());
        self::assertFalse($this->hasher->isPasswordValid($user, 'segredo123'));
        self::assertFalse($user->isEmailConfirmado());
        self::assertEquals(new \DateTimeImmutable(self::AGORA), $user->getEmailAConfirmarDesde());
        self::assertSame('Souza Advogados', $conta->tenant->getName());
        // O perfil nasce com o gênero do cadastro (spec genero-no-cadastro), ligado ao User para a cascata.
        self::assertSame(Genero::Feminino, $user->getProfile()?->getGenero());
        self::assertSame($user, $user->getProfile()?->getUser());

        self::assertInstanceOf(AceiteTermo::class, $aceite);
        self::assertInstanceOf(CienciaPolitica::class, $ciencia);
        self::assertSame(PoliticaPrivacidadeVigente::VERSAO, $ciencia->getVersao());
        self::assertSame('10.0.0.1', $ciencia->getIp());
        self::assertSame('Navegador X', $ciencia->getUserAgent());
        self::assertSame($user, $ciencia->getUser());

        self::assertInstanceOf(ConfirmacaoEmail::class, $confirmacao);
        self::assertFalse($confirmacao->isLembrete());
        self::assertEquals(new \DateTimeImmutable('2026-10-26 10:00:00'), $confirmacao->getExpiraEm());

        // O token em claro só existe no e-mail; o banco recebe o hash dele.
        self::assertCount(1, $this->enviados);
        self::assertSame('ana@adv.com', $this->enviados[0]->getTo()[0]->getAddress());
        self::assertSame(1, preg_match('#/cadastro/confirmar-email/([a-f0-9]{64})#', (string) $this->enviados[0]->getHtmlBody(), $achado));
        self::assertSame(ConfirmacaoEmail::hashDoToken($achado[1]), $confirmacao->getTokenHash());
        self::assertNotSame($achado[1], $confirmacao->getTokenHash());

        self::assertCount(1, $this->despachados);
        self::assertInstanceOf(ContaCriadaPeloCadastro::class, $this->despachados[0]);
        self::assertSame(42, $this->despachados[0]->userId);
        self::assertSame(7, $this->despachados[0]->tenantId);
    }

    /** @return iterable<string, array{CriarContaEEscritorioInput, string}> */
    public static function entradasInvalidas(): iterable
    {
        yield 'nome de uma palavra' => [self::input(nome: 'Ana'), 'Informe seu nome completo.'];
        yield 'nome só com espaços' => [self::input(nome: '   '), 'Informe seu nome completo.'];
        yield 'nome longo demais' => [self::input(nome: 'Ana ' . str_repeat('a', 252)), 'Informe seu nome completo.'];
        yield 'escritório vazio' => [self::input(escritorio: '  '), 'Informe o nome do escritório.'];
        yield 'escritório longo demais' => [self::input(escritorio: str_repeat('e', 256)), 'Informe o nome do escritório.'];
        yield 'e-mail sem ponto no domínio' => [self::input(email: 'ana@adv'), 'Informe um e-mail válido.'];
        yield 'e-mail com espaço' => [self::input(email: 'ana souza@adv.com'), 'Informe um e-mail válido.'];
        yield 'e-mail longo demais' => [self::input(email: str_repeat('a', 173) . '@adv.com'), 'Informe um e-mail válido.'];
        yield 'e-mail com cópia na query' => [self::input(email: 'a@b.com?cc=c@d.com'), 'Informe um e-mail válido.'];
        yield 'dois e-mails com vírgula' => [self::input(email: 'a@b.com,c@d.com'), 'Informe um e-mail válido.'];
        yield 'aceite desmarcado' => [self::input(aceite: false), 'É preciso aceitar os Termos e a Política de privacidade.'];
        yield 'gênero vazio' => [self::input(genero: ''), 'Informe o gênero.'];
        yield 'gênero fora das opções' => [self::input(genero: 'outro'), 'Informe o gênero.'];
        yield 'gênero legado "prefiro não informar"' => [self::input(genero: 'nao_informado'), 'Informe o gênero.'];
        yield 'gênero vem antes do aceite' => [self::input(genero: '', aceite: false), 'Informe o gênero.'];
        yield 'nome vem antes do e-mail' => [self::input(nome: 'Ana', email: 'x'), 'Informe seu nome completo.'];
        yield 'senha fraca não importa: o erro é o do aceite' => [self::input(senha: 'curta', aceite: false), 'É preciso aceitar os Termos e a Política de privacidade.'];
    }

    #[DataProvider('entradasInvalidas')]
    #[TestDox('Validação na ordem do desenho: $mensagem')]
    public function testValidacao(CriarContaEEscritorioInput $input, string $mensagem): void
    {
        $this->em->expects($this->never())->method('wrapInTransaction');
        $this->userRepository->expects($this->never())->method('encontrarPorEmailIgnorandoCaixa');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($mensagem);

        $this->useCase()->executar($input, '10.0.0.1', null);
    }

    #[TestDox('Pelo Google: e-mail do Google (não o do formulário), conta confirmada e ligada ao sub, sem link nem e-mail, com aceite e evento')]
    public function testCriaPeloGoogle(): void
    {
        $this->transacaoExecuta();
        $this->userRepository->method('encontrarPorEmailIgnorandoCaixa')->willReturn([]);
        $this->userRepository->method('findOneBy')->willReturn(null);
        $this->aceiteRepository->method('existeAceiteVigente')->willReturn(false);
        $this->aceiteRepository->expects($this->once())->method('salvar');
        $this->cienciaRepository->expects($this->once())->method('salvar');
        $this->confirmacaoRepository->expects($this->never())->method('salvar');

        $conta = $this->useCase()->executarPeloGoogle(
            self::input(email: 'outro@adv.com', senha: 'qualquer'),
            new IdentidadeDoGoogle('sub-9', 'Ana.Souza@Gmail.com', 'Ana do Google'),
            '10.0.0.1',
            'Navegador X',
        );

        self::assertSame('ana.souza@gmail.com', $conta->user->getEmail());
        self::assertTrue($conta->user->isEmailConfirmado());
        self::assertSame('sub-9', $conta->user->getGoogleSub());
        self::assertFalse($this->hasher->isPasswordValid($conta->user, 'qualquer'));
        self::assertSame([], $this->enviados);
        self::assertCount(2, $this->despachados);
        self::assertInstanceOf(ContaCriadaPeloCadastro::class, $this->despachados[0]);
        self::assertInstanceOf(EmailDaContaConfirmado::class, $this->despachados[1], 'a conta nasce confirmada');
    }

    #[TestDox('Pelo Facebook: é o cadastro por e-mail (NÃO confirmada, com link e e-mail, e-mail do corpo) e só acrescenta o facebook_id')]
    public function testCriaComFacebookId(): void
    {
        $this->transacaoExecuta();
        $this->userRepository->method('encontrarPorEmailIgnorandoCaixa')->willReturn([]);
        $this->userRepository->method('findOneBy')->with(['facebookId' => 'fb-9'])->willReturn(null);
        $this->aceiteRepository->method('existeAceiteVigente')->willReturn(false);
        $this->confirmacaoRepository->expects($this->once())->method('salvar');

        $conta = $this->useCase()->executar(self::input(email: 'Digitado@Adv.com'), '10.0.0.1', null, 'fb-9');

        self::assertSame('digitado@adv.com', $conta->user->getEmail());
        self::assertSame('fb-9', $conta->user->getFacebookId());
        self::assertFalse($conta->user->isEmailConfirmado(), 'o Facebook não prova e-mail');
        self::assertNull($conta->user->getGoogleSub());
        self::assertCount(1, $this->enviados);
        self::assertCount(1, $this->despachados, 'ContaCriadaPeloCadastro uma vez só, e nenhum EmailDaContaConfirmado');
        self::assertInstanceOf(ContaCriadaPeloCadastro::class, $this->despachados[0]);
    }

    #[TestDox('Sem facebookId o cadastro por e-mail segue igual: nenhuma conta ligada ao Facebook')]
    public function testSemFacebookIdNaoLiga(): void
    {
        $this->transacaoExecuta();
        $this->userRepository->method('encontrarPorEmailIgnorandoCaixa')->willReturn([]);
        $this->userRepository->expects($this->never())->method('findOneBy');
        $this->aceiteRepository->method('existeAceiteVigente')->willReturn(false);

        $conta = $this->useCase()->executar(self::input(), '10.0.0.1', null);

        self::assertNull($conta->user->getFacebookId());
    }

    #[TestDox('Pelo Facebook: e-mail que já tem conta devolve null e não liga o facebook_id à conta existente')]
    public function testFacebookComEmailExistenteNaoLiga(): void
    {
        $dona = (new User())->setEmail('ana@adv.com')->setFullName('Ana Dona');
        $this->userRepository->method('encontrarPorEmailIgnorandoCaixa')->willReturn([$dona]);
        $this->em->expects($this->never())->method('wrapInTransaction');

        self::assertNull($this->useCase()->executar(self::input(email: 'ana@adv.com'), '10.0.0.1', null, 'fb-9'));

        self::assertNull($dona->getFacebookId());
    }

    #[TestDox('Pelo Facebook: facebook_id já ligado a outra conta devolve null (resposta neutra) e não cria nada')]
    public function testFacebookIdJaUsado(): void
    {
        $this->userRepository->method('encontrarPorEmailIgnorandoCaixa')->willReturn([]);
        $this->userRepository->method('findOneBy')->willReturn((new User())->setEmail('outra@adv.com'));
        $this->em->expects($this->never())->method('wrapInTransaction');

        self::assertNull($this->useCase()->executar(self::input(), '10.0.0.1', null, 'fb-9'));

        self::assertSame([], $this->enviados);
        self::assertSame([], $this->despachados);
    }

    #[TestDox('Pelo Google: e-mail sem garantia (nem Gmail nem Workspace) recusa e não grava, mesmo que chegue à sessão')]
    public function testPeloGoogleSemGarantia(): void
    {
        $this->em->expects($this->never())->method('wrapInTransaction');

        $this->expectExceptionMessage(EntradaPeloGoogleRecusadaException::SEM_GARANTIA_CADASTRO);

        $this->useCase()->executarPeloGoogle(self::input(), new IdentidadeDoGoogle('sub-9', 'ana@adv.com', 'Ana'), '10.0.0.1', null);
    }

    #[TestDox('Pelo Google: e-mail que já tem conta, ou sub já ligado a outra conta, recusa com a mensagem própria e não grava')]
    public function testPeloGoogleContaExistente(): void
    {
        $this->userRepository->method('encontrarPorEmailIgnorandoCaixa')->willReturn([(new User())->setEmail('ana@gmail.com')]);
        $this->em->expects($this->never())->method('wrapInTransaction');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage(CriarContaEEscritorioUseCase::ERRO_JA_TEM_CONTA_GOOGLE);

        $this->useCase()->executarPeloGoogle(self::input(), new IdentidadeDoGoogle('sub-9', 'ana@gmail.com', 'Ana'), '10.0.0.1', null);
    }

    #[TestDox('Pelo Google: a validação do formulário vale igual (gênero obrigatório)')]
    public function testPeloGoogleValida(): void
    {
        $this->em->expects($this->never())->method('wrapInTransaction');

        $this->expectExceptionMessage(CriarContaEEscritorioUseCase::ERRO_GENERO);

        $this->useCase()->executarPeloGoogle(self::input(genero: ''), new IdentidadeDoGoogle('sub-9', 'ana@gmail.com', 'Ana'), '10.0.0.1', null);
    }

    #[TestDox('A senha do cadastro é ignorada: fraca ou vazia, a conta nasce e a senha digitada não entra')]
    public function testSenhaDoCadastroEIgnorada(): void
    {
        $this->transacaoExecuta();
        $this->userRepository->method('encontrarPorEmailIgnorandoCaixa')->willReturn([]);
        $this->aceiteRepository->method('existeAceiteVigente')->willReturn(false);

        $fraca = $this->useCase()->executar(self::input(senha: 'abc'), '10.0.0.1', null)->user;
        $vazia = $this->useCase()->executar(self::input(email: 'bia@adv.com', senha: ''), '10.0.0.1', null)->user;

        self::assertFalse($this->hasher->isPasswordValid($fraca, 'abc'));
        self::assertFalse($this->hasher->isPasswordValid($vazia, ''));
    }

    #[TestDox('Raiz E: e-mail já usado (com outra caixa) não grava nada, devolve null e manda à dona o aviso com o Esqueci a senha')]
    public function testEmailExistenteIgnorandoCaixa(): void
    {
        $dona = (new User())->setEmail('ana@adv.com')->setFullName('Ana Dona');
        $this->userRepository->expects($this->once())->method('encontrarPorEmailIgnorandoCaixa')
            ->with('ana@adv.com')->willReturn([$dona]);
        $this->em->expects($this->never())->method('wrapInTransaction');
        $this->confirmacaoRepository->expects($this->never())->method('salvar');

        self::assertNull($this->useCase()->executar(self::input(email: ' ANA@adv.com '), '10.0.0.1', null));

        self::assertCount(1, $this->enviados);
        self::assertSame('ana@adv.com', $this->enviados[0]->getTo()[0]->getAddress());
        self::assertStringContainsString('Você já tem uma conta no BlueJus', (string) $this->enviados[0]->getSubject());
        self::assertSame('existente Ana Dona https://bluejus.test/senha/esqueci', $this->enviados[0]->getHtmlBody());
        self::assertSame([], $this->despachados);
    }

    #[TestDox('Raiz E: conta existente inativa (ou em quarentena) não recebe nada e nada é gravado')]
    public function testContaExistenteInativaNaoRecebeNada(): void
    {
        $this->userRepository->method('encontrarPorEmailIgnorandoCaixa')->willReturn([(new User())->setEmail('ana@adv.com')->setIsActive(false)]);
        $this->confirmacaoRepository->expects($this->never())->method('salvar');

        self::assertNull($this->useCase()->executar(self::input(), '10.0.0.1', null));
        self::assertSame([], $this->enviados);
    }

    #[TestDox('Raiz E: conta existente a confirmar recebe o link de confirmação normal (link novo gravado), não o "já tem conta"')]
    public function testContaExistenteAConfirmarRecebeAConfirmacao(): void
    {
        $dona = (new User())->setEmail('ana@adv.com')->setFullName('Ana Dona');
        $dona->marcarEmailAConfirmar(new \DateTimeImmutable('-1 hour'));
        $this->userRepository->method('encontrarPorEmailIgnorandoCaixa')->willReturn([$dona]);
        $this->confirmacaoRepository->expects($this->once())->method('salvar');

        self::assertNull($this->useCase()->executar(self::input(), '10.0.0.1', null));

        self::assertCount(1, $this->enviados);
        self::assertSame(1, preg_match('#/cadastro/confirmar-email/[a-f0-9]{64}#', (string) $this->enviados[0]->getHtmlBody()));
        self::assertStringNotContainsString('já tem uma conta', (string) $this->enviados[0]->getSubject());
    }

    #[TestDox('Raiz E: conta existente a confirmar que bateu no teto do reenvio não recebe outro link')]
    public function testContaExistenteAConfirmarNoTetoNaoRecebe(): void
    {
        $dona = (new User())->setEmail('ana@adv.com');
        $dona->marcarEmailAConfirmar(new \DateTimeImmutable('-1 hour'));
        $this->userRepository->method('encontrarPorEmailIgnorandoCaixa')->willReturn([$dona]);
        $this->confirmacaoRepository->method('houveEmissaoDesde')->willReturn(true);
        $this->confirmacaoRepository->expects($this->never())->method('salvar');

        self::assertNull($this->useCase()->executar(self::input(), '10.0.0.1', null));
        self::assertSame([], $this->enviados);
    }

    #[TestDox('Raiz E: falha no envio do aviso de conta existente só vai para o log; a resposta continua null')]
    public function testFalhaNoAvisoDeContaExistenteSoVaiParaOLog(): void
    {
        $this->userRepository->method('encontrarPorEmailIgnorandoCaixa')->willReturn([(new User())->setEmail('ana@adv.com')]);
        $this->transporteFalha = true;

        self::assertNull($this->useCase()->executar(self::input(), '10.0.0.1', null));
        self::assertSame('Falha ao enviar o aviso de conta existente do cadastro.', $this->logger->registros[0]['mensagem'] ?? null);
    }

    #[TestDox('Corrida no UNIQUE de user.email vira a mesma resposta do e-mail existente (null), sem e-mail nem evento')]
    public function testCorridaNoUniqueViraEmailExistente(): void
    {
        $this->userRepository->method('encontrarPorEmailIgnorandoCaixa')->willReturn([]);
        $this->em->method('wrapInTransaction')->willThrowException($this->createStub(UniqueConstraintViolationException::class));

        self::assertNull($this->useCase()->executar(self::input(), '10.0.0.1', null));

        self::assertSame([], $this->enviados);
        self::assertSame([], $this->despachados);
    }

    #[TestDox('Falha no envio do e-mail só vai para o log (com a exceção) e o evento sai assim mesmo')]
    public function testFalhaNoEmailNaoDerrubaOCadastro(): void
    {
        $this->transacaoExecuta();
        $this->userRepository->method('encontrarPorEmailIgnorandoCaixa')->willReturn([]);
        $this->aceiteRepository->method('existeAceiteVigente')->willReturn(false);
        $this->transporteFalha = true;

        $conta = $this->useCase()->executar(self::input(), '10.0.0.1', null);

        self::assertSame(42, $conta->user->getId());
        self::assertCount(1, $this->despachados);
        self::assertCount(1, $this->logger->registros);
        self::assertInstanceOf(\Throwable::class, $this->logger->registros[0]['contexto']['exception']);
    }

    #[TestDox('Ouvinte do evento com defeito não derruba o cadastro: vai para o log')]
    public function testOuvinteComDefeitoNaoDerrubaOCadastro(): void
    {
        $this->transacaoExecuta();
        $this->userRepository->method('encontrarPorEmailIgnorandoCaixa')->willReturn([]);
        $this->aceiteRepository->method('existeAceiteVigente')->willReturn(false);
        $this->eventos->addListener(ContaCriadaPeloCadastro::class, static function (): void {
            throw new \RuntimeException('ouvinte quebrado');
        });

        $conta = $this->useCase()->executar(self::input(), '10.0.0.1', null);

        self::assertSame(7, $conta->tenant->getId());
        self::assertCount(1, $this->enviados);
        self::assertCount(1, $this->logger->registros);
        self::assertArrayHasKey('exception', $this->logger->registros[0]['contexto']);
    }

    /** @return iterable<string, array{string}> */
    public static function politicasQueFecham(): iterable
    {
        yield 'só por convite' => ['convite'];
        yield 'fechado' => ['off'];
    }

    #[DataProvider('politicasQueFecham')]
    #[TestDox('Política $politica: as três portas públicas (e-mail, Facebook, Google) recusam antes de ler ou gravar qualquer coisa')]
    public function testPoliticaQueFechaBloqueiaAsPortasPublicas(string $politica): void
    {
        $this->configuracao->definir('cadastro', $politica);
        $this->userRepository->expects($this->never())->method('encontrarPorEmailIgnorandoCaixa');
        $this->userRepository->expects($this->never())->method('findOneBy');
        $this->em->expects($this->never())->method('wrapInTransaction');
        $this->em->expects($this->never())->method('persist');
        $this->em->expects($this->never())->method('flush');
        $this->confirmacaoRepository->expects($this->never())->method('salvar');
        $portas = [
            fn () => $this->useCase()->executar(self::input(), '10.0.0.1', null),
            fn () => $this->useCase()->executar(self::input(), '10.0.0.1', null, 'fb-1'),
            fn () => $this->useCase()->executarPeloGoogle(self::input(), new IdentidadeDoGoogle('sub-9', 'ana@gmail.com', 'Ana'), '10.0.0.1', null),
        ];

        foreach ($portas as $porta) {
            try {
                $porta();
                self::fail('Deveria lançar CadastroNaoDisponivel.');
            } catch (CadastroNaoDisponivel $e) {
                self::assertSame($politica, $e->politica);
                self::assertSame('entrada.cadastro.aviso.' . $politica, $e->chaveDeTraducao());
            }
        }
        self::assertSame([], $this->despachados);
        self::assertSame([], $this->enviados);
    }

    #[TestDox('Política recusa antes até da validação: entrada inválida e e-mail existente não mudam a resposta')]
    public function testPoliticaRecusaAntesDaValidacao(): void
    {
        $this->configuracao->definir('cadastro', 'off');

        $this->expectException(CadastroNaoDisponivel::class);

        $this->useCase()->executar(self::input(nome: ''), '10.0.0.1', null);
    }

    #[TestDox('Valor desconhecido ou vazio na política cai em aberto (padrão do catálogo): a conta nasce')]
    public function testPoliticaDesconhecidaCaiEmAberto(): void
    {
        $this->configuracao->definir('cadastro', 'qualquer-coisa');
        $this->transacaoExecuta();
        $this->userRepository->method('encontrarPorEmailIgnorandoCaixa')->willReturn([]);

        self::assertNotNull($this->useCase()->executar(self::input(), '10.0.0.1', null));
    }
}
