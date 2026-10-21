<?php

declare(strict_types=1);

namespace App\Tests\Auth\Unit;

use App\Auth\DTO\CriarContaEEscritorioInput;
use App\Auth\Entity\ConfirmacaoEmail;
use App\Auth\Event\ContaCriadaPeloCadastro;
use App\Auth\Repository\ConfirmacaoEmailRepository;
use App\Auth\Service\CadastroMailer;
use App\Auth\Service\ForcaDaSenha;
use App\Auth\UseCase\CriarContaEEscritorioUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Legal\PoliticaPrivacidadeVigente;
use App\Repository\UserRepository;
use App\Service\TenantBootstrapService;
use App\Shared\Email\IdentidadeDeEmail;
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
        $this->eventos = new EventDispatcher();
        $this->eventos->addListener(ContaCriadaPeloCadastro::class, function (object $evento): void {
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
        );
    }

    private function mailer(): CadastroMailer
    {
        $rotas = new RouteCollection();
        $rotas->add('auth_cadastro_confirmar_email', new Route('/cadastro/confirmar-email/{token}'));

        return new CadastroMailer(
            $this->transporte,
            new Environment(new ArrayLoader(['auth/cadastro/email_confirmacao.html.twig' => '{{ nome }} {{ link }}'])),
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
    ): CriarContaEEscritorioInput {
        return new CriarContaEEscritorioInput($nome, $escritorio, $email, $senha, $aceite);
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
        self::assertNotSame('segredo123', $user->getPassword());
        self::assertTrue($this->hasher->isPasswordValid($user, 'segredo123'));
        self::assertFalse($user->isEmailConfirmado());
        self::assertEquals(new \DateTimeImmutable(self::AGORA), $user->getEmailAConfirmarDesde());
        self::assertSame('Souza Advogados', $conta->tenant->getName());

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
        yield 'senha só com letras minúsculas' => [self::input(senha: 'abcdefgh'), ForcaDaSenha::ERRO];
        yield 'senha curta' => [self::input(senha: 'Ab1!'), ForcaDaSenha::ERRO];
        yield 'aceite desmarcado' => [self::input(aceite: false), 'É preciso aceitar os Termos e a Política de privacidade.'];
        yield 'nome vem antes do e-mail' => [self::input(nome: 'Ana', email: 'x'), 'Informe seu nome completo.'];
        yield 'senha vem antes do aceite' => [self::input(senha: 'curta', aceite: false), ForcaDaSenha::ERRO];
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

    #[TestDox('E-mail já usado (com outra caixa) é recusado antes de qualquer gravação')]
    public function testEmailExistenteIgnorandoCaixa(): void
    {
        $this->userRepository->expects($this->once())->method('encontrarPorEmailIgnorandoCaixa')
            ->with('ana@adv.com')->willReturn([new User()]);
        $this->em->expects($this->never())->method('wrapInTransaction');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage(CriarContaEEscritorioUseCase::ERRO_EMAIL_EXISTENTE);

        $this->useCase()->executar(self::input(email: 'ANA@adv.com'), '10.0.0.1', null);
    }

    #[TestDox('Corrida no UNIQUE de user.email vira a mesma mensagem do e-mail existente, sem e-mail nem evento')]
    public function testCorridaNoUniqueViraEmailExistente(): void
    {
        $this->userRepository->method('encontrarPorEmailIgnorandoCaixa')->willReturn([]);
        $this->em->method('wrapInTransaction')->willThrowException($this->createStub(UniqueConstraintViolationException::class));

        try {
            $this->useCase()->executar(self::input(), '10.0.0.1', null);
            self::fail('Esperava DomainException.');
        } catch (\DomainException $e) {
            self::assertSame(CriarContaEEscritorioUseCase::ERRO_EMAIL_EXISTENTE, $e->getMessage());
        }

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
}
