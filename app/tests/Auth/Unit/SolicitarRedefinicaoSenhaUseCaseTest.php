<?php

declare(strict_types=1);

namespace App\Tests\Auth\Unit;

use App\Auth\DTO\SolicitarRedefinicaoSenhaInput;
use App\Auth\Entity\RedefinicaoSenha;
use App\Auth\Repository\RedefinicaoSenhaRepository;
use App\Auth\Service\RedefinicaoSenhaMailerInterface;
use App\Auth\UseCase\SolicitarRedefinicaoSenhaUseCase;
use App\Entity\Auth\User;
use App\Repository\UserRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mime\Exception\RfcComplianceException;

#[CoversClass(SolicitarRedefinicaoSenhaUseCase::class)]
final class SolicitarRedefinicaoSenhaUseCaseTest extends TestCase
{
    private UserRepository&MockObject $userRepository;
    private RedefinicaoSenhaRepository&MockObject $redefinicaoRepository;
    private RedefinicaoSenhaMailerInterface&MockObject $mailer;
    private LoggerInterface&MockObject $logger;
    private SolicitarRedefinicaoSenhaUseCase $useCase;

    protected function setUp(): void
    {
        $this->userRepository        = $this->createMock(UserRepository::class);
        $this->redefinicaoRepository = $this->createMock(RedefinicaoSenhaRepository::class);
        $this->mailer                = $this->createMock(RedefinicaoSenhaMailerInterface::class);
        $this->logger                = $this->createMock(LoggerInterface::class);
        $this->useCase               = new SolicitarRedefinicaoSenhaUseCase(
            $this->userRepository,
            $this->redefinicaoRepository,
            $this->mailer,
            $this->logger,
        );
    }

    private function input(string $email = 'ana@adv.com', string $armadilha = ''): SolicitarRedefinicaoSenhaInput
    {
        $in              = new SolicitarRedefinicaoSenhaInput();
        $in->email       = $email;
        $in->confirmacao = $armadilha;

        return $in;
    }

    private function usuario(bool $ativo = true): User
    {
        $user = new User();
        $user->setEmail('ana@adv.com');
        $user->setFullName('Dra. Ana');
        $user->setIsActive($ativo);

        return $user;
    }

    #[TestDox('Cria o pedido e envia o link quando o e-mail pertence a conta ativa')]
    public function testCriaPedidoEEnviaLink(): void
    {
        $this->userRepository->method('encontrarPorEmailIgnorandoCaixa')->willReturn([$this->usuario()]);
        $this->redefinicaoRepository->expects($this->once())->method('invalidarPendentesDoUsuario');

        $salvo = null;
        $this->redefinicaoRepository->expects($this->once())
            ->method('salvar')
            ->willReturnCallback(function (RedefinicaoSenha $r) use (&$salvo): void { $salvo = $r; });

        $tokenEnviado = null;
        $this->mailer->expects($this->once())
            ->method('enviarLink')
            ->willReturnCallback(function (User $u, string $token) use (&$tokenEnviado): void { $tokenEnviado = $token; });

        $this->useCase->executar($this->input(), '1.2.3.4', 'UA');

        self::assertInstanceOf(RedefinicaoSenha::class, $salvo);
        self::assertIsString($tokenEnviado);
        self::assertSame(64, strlen($tokenEnviado), 'O token em claro é hexadecimal de 32 bytes.');
    }

    #[TestDox('O banco guarda o HASH do token, nunca o token que vai no e-mail')]
    public function testPersisteApenasOHashDoToken(): void
    {
        $this->userRepository->method('encontrarPorEmailIgnorandoCaixa')->willReturn([$this->usuario()]);

        $salvo = null;
        $this->redefinicaoRepository->method('salvar')
            ->willReturnCallback(function (RedefinicaoSenha $r) use (&$salvo): void { $salvo = $r; });

        $tokenEnviado = null;
        $this->mailer->method('enviarLink')
            ->willReturnCallback(function (User $u, string $token) use (&$tokenEnviado): void { $tokenEnviado = $token; });

        $this->useCase->executar($this->input(), '1.2.3.4', 'UA');

        self::assertNotSame($tokenEnviado, $salvo->getTokenHash(), 'O token em claro não pode estar no banco.');
        self::assertSame(RedefinicaoSenha::hashDoToken($tokenEnviado), $salvo->getTokenHash());
    }

    #[TestDox('O pedido expira em 1 hora')]
    public function testExpiraEmUmaHora(): void
    {
        $this->userRepository->method('encontrarPorEmailIgnorandoCaixa')->willReturn([$this->usuario()]);

        $salvo = null;
        $this->redefinicaoRepository->method('salvar')
            ->willReturnCallback(function (RedefinicaoSenha $r) use (&$salvo): void { $salvo = $r; });

        $antes = new \DateTimeImmutable();
        $this->useCase->executar($this->input(), '1.2.3.4', 'UA');

        $margem = $salvo->getExpiraEm()->getTimestamp() - $antes->getTimestamp();
        self::assertGreaterThanOrEqual(3595, $margem);
        self::assertLessThanOrEqual(3605, $margem);
    }

    #[TestDox('E-mail sem conta não persiste nem envia nada (resposta neutra)')]
    public function testEmailInexistenteNaoFazNada(): void
    {
        $this->userRepository->method('encontrarPorEmailIgnorandoCaixa')->willReturn([]);
        $this->redefinicaoRepository->expects($this->never())->method('salvar');
        $this->redefinicaoRepository->expects($this->never())->method('invalidarPendentesDoUsuario');
        $this->mailer->expects($this->never())->method('enviarLink');

        $this->useCase->executar($this->input('ninguem@adv.com'), '1.2.3.4', 'UA');
    }

    #[TestDox('Conta desativada não recebe link de redefinição')]
    public function testUsuarioInativoNaoRecebeLink(): void
    {
        $this->userRepository->method('encontrarPorEmailIgnorandoCaixa')->willReturn([$this->usuario(ativo: false)]);
        $this->redefinicaoRepository->expects($this->never())->method('salvar');
        $this->mailer->expects($this->never())->method('enviarLink');

        $this->useCase->executar($this->input(), '1.2.3.4', 'UA');
    }

    #[TestDox('Honeypot preenchido descarta o pedido sem nem consultar o usuário')]
    public function testHoneypotDescarta(): void
    {
        $this->userRepository->expects($this->never())->method('encontrarPorEmailIgnorandoCaixa');
        $this->redefinicaoRepository->expects($this->never())->method('salvar');
        $this->mailer->expects($this->never())->method('enviarLink');

        $this->useCase->executar($this->input(armadilha: 'sou-um-robo'), '1.2.3.4', 'UA');
    }

    #[TestDox('Falha no envio do e-mail é registrada em log e não vaza para o chamador')]
    public function testFalhaDeEnvioNaoVazaParaOChamador(): void
    {
        $this->userRepository->method('encontrarPorEmailIgnorandoCaixa')->willReturn([$this->usuario()]);
        $this->mailer->method('enviarLink')->willThrowException(new \RuntimeException('SMTP fora do ar'));
        $this->logger->expects($this->once())->method('error');

        // Sem exceção: se o erro subisse, só apareceria para e-mail existente —
        // e isso, por si só, revelaria a conta (RN02).
        $this->useCase->executar($this->input(), '1.2.3.4', 'UA');
    }

    #[TestDox('A busca é a insensível à caixa — a exata deixaria conta com maiúscula trancada fora')]
    public function testUsaBuscaInsensivelACaixa(): void
    {
        // Quem gravou `Ana@Adv.com` loga normalmente (o login compara o valor cru) e
        // sumiria de uma busca exata por `ana@adv.com`. Aqui não pode sumir.
        // Espaços saem aqui (o desempate compara com o digitado); a caixa é preservada,
        // porque é o repositório que normaliza para o LOWER() da consulta.
        $this->userRepository->expects($this->once())
            ->method('encontrarPorEmailIgnorandoCaixa')
            ->with('ANA@Adv.com')
            ->willReturn([]);
        $this->userRepository->expects($this->never())->method('findOneBy');

        $this->useCase->executar($this->input('  ANA@Adv.com '), '1.2.3.4', 'UA');
    }

    #[TestDox('Com duas contas que diferem só na caixa, quem digitou o e-mail exato recupera')]
    public function testDesempatePorCorrespondenciaExata(): void
    {
        // Recusar as duas trocaria "uma conta trancada fora" por "duas trancadas fora" —
        // pior que o defeito original. Quem digita o próprio e-mail tem que recuperar.
        $maiuscula = $this->usuario();
        $maiuscula->setEmail('Ana@Adv.com');
        $minuscula = $this->usuario();
        $minuscula->setEmail('ana@adv.com');

        $this->userRepository->method('encontrarPorEmailIgnorandoCaixa')->willReturn([$maiuscula, $minuscula]);

        $destinatario = null;
        $this->mailer->expects($this->once())
            ->method('enviarLink')
            ->willReturnCallback(function (User $u) use (&$destinatario): void { $destinatario = $u; });

        $this->useCase->executar($this->input('Ana@Adv.com'), '1.2.3.4', 'UA');

        self::assertSame($maiuscula, $destinatario, 'O link é da conta que bate exatamente com o digitado.');
    }

    #[TestDox('Ambíguo de verdade (digitado não bate com nenhuma): nada é enviado e vai para o log')]
    public function testAmbiguidadeRealNaoEnviaNada(): void
    {
        $a = $this->usuario();
        $a->setEmail('Ana@Adv.com');
        $b = $this->usuario();
        $b->setEmail('ana@adv.com');

        $this->userRepository->method('encontrarPorEmailIgnorandoCaixa')->willReturn([$a, $b]);
        $this->redefinicaoRepository->expects($this->never())->method('salvar');
        $this->mailer->expects($this->never())->method('enviarLink');
        $this->logger->expects($this->once())->method('error');

        // Terceira variação: não dá para saber de quem é, e mandar para a errada é pior.
        $this->useCase->executar($this->input('ANA@ADV.COM'), '1.2.3.4', 'UA');
    }

    #[TestDox('Falha que NÃO é RuntimeException (remetente malformado) também é engolida')]
    public function testFalhaNaoRuntimeTambemEhEngolida(): void
    {
        // RfcComplianceException estende InvalidArgumentException. Com um catch estreito,
        // ela vazaria e produziria 500 só quando a conta existe — denunciando a conta.
        $this->userRepository->method('encontrarPorEmailIgnorandoCaixa')->willReturn([$this->usuario()]);
        $this->mailer->method('enviarLink')->willThrowException(
            new RfcComplianceException('Endereço de remetente inválido')
        );
        $this->logger->expects($this->once())->method('error');

        $this->useCase->executar($this->input(), '1.2.3.4', 'UA');
    }

    #[TestDox('O log da falha carrega a exceção (chave "exception"), para haver stack trace')]
    public function testLogCarregaExcecaoParaTerStackTrace(): void
    {
        $this->userRepository->method('encontrarPorEmailIgnorandoCaixa')->willReturn([$this->usuario()]);
        $falha = new \RuntimeException('SMTP fora do ar');
        $this->mailer->method('enviarLink')->willThrowException($falha);

        $contexto = null;
        $this->logger->expects($this->once())
            ->method('error')
            ->willReturnCallback(function (string $msg, array $ctx) use (&$contexto): void { $contexto = $ctx; });

        $this->useCase->executar($this->input(), '1.2.3.4', 'UA');

        self::assertSame($falha, $contexto['exception'] ?? null, 'Sem a chave "exception" o Monolog não grava o trace.');
    }
}
