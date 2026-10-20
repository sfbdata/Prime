<?php

declare(strict_types=1);

namespace App\Tests\Auth\Unit;

use App\Auth\DTO\SolicitarCodigoSenhaInput;
use App\Auth\Entity\RedefinicaoSenha;
use App\Auth\Repository\RedefinicaoSenhaRepository;
use App\Auth\Service\RedefinicaoSenhaMailerInterface;
use App\Auth\UseCase\SolicitarCodigoSenhaUseCase;
use App\Entity\Auth\User;
use App\Repository\UserRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mime\Exception\RfcComplianceException;

#[CoversClass(SolicitarCodigoSenhaUseCase::class)]
final class SolicitarCodigoSenhaUseCaseTest extends TestCase
{
    private const SEGREDO = 'segredo-de-teste';

    private UserRepository&MockObject $userRepository;
    private RedefinicaoSenhaRepository&MockObject $redefinicaoRepository;
    private RedefinicaoSenhaMailerInterface&MockObject $mailer;
    private LoggerInterface&MockObject $logger;
    private SolicitarCodigoSenhaUseCase $useCase;

    /** Pedido gravado e código enviado na última execução. */
    private ?RedefinicaoSenha $salvo = null;
    private ?string $codigoEnviado = null;

    protected function setUp(): void
    {
        $this->userRepository        = $this->createMock(UserRepository::class);
        $this->redefinicaoRepository = $this->createMock(RedefinicaoSenhaRepository::class);
        $this->mailer                = $this->createMock(RedefinicaoSenhaMailerInterface::class);
        $this->logger                = $this->createMock(LoggerInterface::class);
        $this->useCase               = new SolicitarCodigoSenhaUseCase(
            $this->userRepository,
            $this->redefinicaoRepository,
            $this->mailer,
            $this->logger,
            self::SEGREDO,
        );
    }

    private function usuario(bool $ativo = true, string $email = 'ana@adv.com', int $id = 42): User
    {
        $user = new User();
        $user->setEmail($email);
        $user->setFullName('Dra. Ana');
        $user->setIsActive($ativo);
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, $id);

        return $user;
    }

    private function capturar(): void
    {
        $this->redefinicaoRepository->method('salvar')
            ->willReturnCallback(function (RedefinicaoSenha $r): void {
                $this->salvo = $r;
            });
        $this->mailer->method('enviarCodigo')
            ->willReturnCallback(function (User $u, string $codigo): void {
                $this->codigoEnviado = $codigo;
            });
    }

    private function executar(string $email = 'ana@adv.com'): void
    {
        $this->useCase->executar(new SolicitarCodigoSenhaInput($email), '1.2.3.4', 'UA');
    }

    #[TestDox('Conta ativa: invalida os pendentes, grava o pedido e envia um código de 6 dígitos')]
    public function testCriaPedidoEEnviaCodigo(): void
    {
        $user = $this->usuario();
        $this->userRepository->method('encontrarPorEmailIgnorandoCaixa')->willReturn([$user]);
        $this->redefinicaoRepository->expects($this->once())->method('encerrarPendentesDoUsuario')->with($user);
        $this->capturar();

        $this->executar();

        self::assertInstanceOf(RedefinicaoSenha::class, $this->salvo);
        self::assertSame($user, $this->salvo->getUser());
        self::assertMatchesRegularExpression('/^\d{6}$/', (string) $this->codigoEnviado);
    }

    #[TestDox('O banco guarda o HMAC de "userId:codigo" com o segredo, nunca o código em claro')]
    public function testPersisteApenasOHmacDoCodigo(): void
    {
        $this->userRepository->method('encontrarPorEmailIgnorandoCaixa')->willReturn([$this->usuario(id: 42)]);
        $this->capturar();

        $this->executar();

        $esperado = hash_hmac('sha256', '42:' . $this->codigoEnviado, self::SEGREDO);
        self::assertSame($esperado, $this->salvo?->getTokenHash());
        self::assertSame($esperado, SolicitarCodigoSenhaUseCase::hashDoCodigo(42, (string) $this->codigoEnviado, self::SEGREDO));
        self::assertStringNotContainsString((string) $this->codigoEnviado, (string) $this->salvo?->getTokenHash());
    }

    #[TestDox('O pedido vale por 15 minutos')]
    public function testExpiraEmQuinzeMinutos(): void
    {
        $this->userRepository->method('encontrarPorEmailIgnorandoCaixa')->willReturn([$this->usuario()]);
        $this->capturar();

        $antes = new \DateTimeImmutable();
        $this->executar();

        $margem = (int) $this->salvo?->getExpiraEm()->getTimestamp() - $antes->getTimestamp();
        self::assertGreaterThanOrEqual(895, $margem);
        self::assertLessThanOrEqual(905, $margem);
    }

    #[TestDox('Código sorteado de novo quando o hash já existe (índice único de token_hash)')]
    public function testSorteiaDeNovoQuandoHashJaExiste(): void
    {
        $this->userRepository->method('encontrarPorEmailIgnorandoCaixa')->willReturn([$this->usuario()]);
        $this->redefinicaoRepository->expects($this->exactly(2))
            ->method('existeTokenHash')
            ->willReturnOnConsecutiveCalls(true, false);
        $this->capturar();

        $this->executar();

        self::assertNotNull($this->salvo);
    }

    #[TestDox('E-mail sem conta não invalida, não grava nem envia nada (resposta neutra)')]
    public function testEmailInexistenteNaoFazNada(): void
    {
        $this->userRepository->method('encontrarPorEmailIgnorandoCaixa')->willReturn([]);
        $this->redefinicaoRepository->expects($this->never())->method('encerrarPendentesDoUsuario');
        $this->redefinicaoRepository->expects($this->never())->method('salvar');
        $this->mailer->expects($this->never())->method('enviarCodigo');

        $this->executar('ninguem@adv.com');
    }

    #[TestDox('Teto diário: com 10 pedidos nas últimas 24 h, não encerra, não grava nem envia, e registra no log')]
    public function testTetoDiarioAtingidoFicaEmSilencio(): void
    {
        $user = $this->usuario();
        $this->userRepository->method('encontrarPorEmailIgnorandoCaixa')->willReturn([$user]);
        $this->redefinicaoRepository->expects($this->once())
            ->method('contarPedidosDesde')
            ->with($user, self::callback(static function (\DateTimeImmutable $desde): bool {
                $diferenca = (new \DateTimeImmutable())->getTimestamp() - $desde->getTimestamp();

                return abs($diferenca - 86400) <= 5;
            }))
            ->willReturn(SolicitarCodigoSenhaUseCase::TETO_DIARIO);
        $this->redefinicaoRepository->expects($this->never())->method('encerrarPendentesDoUsuario');
        $this->redefinicaoRepository->expects($this->never())->method('salvar');
        $this->mailer->expects($this->never())->method('enviarCodigo');
        $this->logger->expects($this->once())->method('warning')
            ->with(self::stringContains('Teto diário'), self::callback(static fn (array $c): bool => $c['user_id'] === 42));

        $this->executar();
    }

    #[TestDox('Teto diário: com 9 pedidos nas últimas 24 h, o décimo ainda sai')]
    public function testAbaixoDoTetoDiarioEnvia(): void
    {
        $this->userRepository->method('encontrarPorEmailIgnorandoCaixa')->willReturn([$this->usuario()]);
        $this->redefinicaoRepository->method('contarPedidosDesde')->willReturn(SolicitarCodigoSenhaUseCase::TETO_DIARIO - 1);
        $this->capturar();

        $this->executar();

        self::assertNotNull($this->salvo);
        self::assertNotNull($this->codigoEnviado);
    }

    #[TestDox('Conta desativada não recebe código (RS04)')]
    public function testUsuarioInativoNaoRecebeCodigo(): void
    {
        $this->userRepository->method('encontrarPorEmailIgnorandoCaixa')->willReturn([$this->usuario(ativo: false)]);
        $this->redefinicaoRepository->expects($this->never())->method('salvar');
        $this->mailer->expects($this->never())->method('enviarCodigo');

        $this->executar();
    }

    #[TestDox('A busca é a insensível à caixa, com o digitado sem espaços')]
    public function testUsaBuscaInsensivelACaixa(): void
    {
        $this->userRepository->expects($this->once())
            ->method('encontrarPorEmailIgnorandoCaixa')
            ->with('ANA@Adv.com')
            ->willReturn([]);

        $this->executar('  ANA@Adv.com ');
    }

    #[TestDox('Duas contas que diferem só na caixa: recebe a que bate exatamente com o digitado')]
    public function testDesempatePorCorrespondenciaExata(): void
    {
        $maiuscula = $this->usuario(email: 'Ana@Adv.com', id: 1);
        $minuscula = $this->usuario(email: 'ana@adv.com', id: 2);
        $this->userRepository->method('encontrarPorEmailIgnorandoCaixa')->willReturn([$maiuscula, $minuscula]);

        $destinatario = null;
        $this->mailer->expects($this->once())
            ->method('enviarCodigo')
            ->willReturnCallback(function (User $u) use (&$destinatario): void {
                $destinatario = $u;
            });

        $this->executar('Ana@Adv.com');

        self::assertSame($maiuscula, $destinatario);
    }

    #[TestDox('Ambíguo de verdade (digitado não bate com nenhuma): nada é enviado e vai para o log')]
    public function testAmbiguidadeRealNaoEnviaNada(): void
    {
        $this->userRepository->method('encontrarPorEmailIgnorandoCaixa')->willReturn([
            $this->usuario(email: 'Ana@Adv.com', id: 1),
            $this->usuario(email: 'ana@adv.com', id: 2),
        ]);
        $this->redefinicaoRepository->expects($this->never())->method('salvar');
        $this->mailer->expects($this->never())->method('enviarCodigo');
        $this->logger->expects($this->once())->method('error');

        $this->executar('ANA@ADV.COM');
    }

    #[TestDox('Falha no envio (inclusive não Runtime) vai para o log com a exceção e não vaza')]
    public function testFalhaDeEnvioNaoVaza(): void
    {
        $this->userRepository->method('encontrarPorEmailIgnorandoCaixa')->willReturn([$this->usuario()]);
        $falha = new RfcComplianceException('Endereço de remetente inválido');
        $this->mailer->method('enviarCodigo')->willThrowException($falha);

        $contexto = null;
        $this->logger->expects($this->once())
            ->method('error')
            ->willReturnCallback(function (string $msg, array $ctx) use (&$contexto): void {
                $contexto = $ctx;
            });

        // Sem exceção: se o erro subisse, só apareceria para conta existente (RN02).
        $this->executar();

        self::assertSame($falha, $contexto['exception'] ?? null, 'Sem a chave "exception" o Monolog não grava o trace.');
    }
}
