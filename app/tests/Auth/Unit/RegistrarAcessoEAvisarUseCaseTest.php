<?php

declare(strict_types=1);

namespace App\Tests\Auth\Unit;

use App\Auth\DTO\RegistrarAcessoInput;
use App\Auth\Repository\AcessoConhecidoRepository;
use App\Auth\Service\AvisoDeAcessoNovoMailerInterface;
use App\Auth\Service\ImpressaoDoAcesso;
use App\Auth\UseCase\RegistrarAcessoEAvisarUseCase;
use App\Entity\Auth\User;
use App\Tests\Support\ConfiguracaoDaPlataformaFalsa;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\MockClock;

#[CoversClass(RegistrarAcessoEAvisarUseCase::class)]
final class RegistrarAcessoEAvisarUseCaseTest extends TestCase
{
    private const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0.0.0 Safari/537.36';

    private AcessoConhecidoRepository&MockObject $repository;
    private AvisoDeAcessoNovoMailerInterface&MockObject $mailer;
    private LoggerInterface&MockObject $logger;
    private ConfiguracaoDaPlataformaFalsa $configuracao;
    private User $user;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(AcessoConhecidoRepository::class);
        $this->mailer = $this->createMock(AvisoDeAcessoNovoMailerInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->configuracao = new ConfiguracaoDaPlataformaFalsa(['fuso' => 'America/Manaus']);
        $this->user = (new User())->setEmail('ana@adv.com')->setFullName('Ana');
    }

    private function useCase(): RegistrarAcessoEAvisarUseCase
    {
        return new RegistrarAcessoEAvisarUseCase(
            $this->repository,
            new ImpressaoDoAcesso('segredo'),
            $this->mailer,
            $this->configuracao,
            new MockClock('2026-10-27 15:00:00 UTC'),
            $this->logger,
        );
    }

    private function entrar(): void
    {
        $this->useCase()->executar(new RegistrarAcessoInput($this->user, self::UA, '200.10.20.5'));
    }

    #[TestDox('primeiro login: grava a impressão e não envia e-mail')]
    public function testPrimeiroLoginNaoEnvia(): void
    {
        $this->repository->method('usuarioTemAcessos')->willReturn(false);
        $this->repository->expects(self::once())->method('registrar')->willReturn(true);
        $this->mailer->expects(self::never())->method('avisar');

        $this->entrar();
    }

    #[TestDox('mesma impressão: só renova o acesso e não envia e-mail')]
    public function testMesmaImpressaoNaoEnvia(): void
    {
        $this->repository->method('usuarioTemAcessos')->willReturn(true);
        $this->repository->method('registrar')->willReturn(false);
        $this->mailer->expects(self::never())->method('avisar');

        $this->entrar();
    }

    #[TestDox('impressão nova de quem já tinha acessos: envia 1 e-mail, no fuso do sistema, com o navegador')]
    public function testImpressaoNovaEnvia(): void
    {
        $this->repository->method('usuarioTemAcessos')->willReturn(true);
        $this->repository->method('registrar')->willReturn(true);
        $this->mailer->expects(self::once())->method('avisar')->with(
            $this->user,
            self::callback(static fn (\DateTimeImmutable $q): bool => $q->format('Y-m-d H:i') === '2026-10-27 11:00' && $q->getTimezone()->getName() === 'America/Manaus'),
            self::callback(static fn ($n): bool => $n->rotuloDoNavegador() === 'Chrome 120' && $n->sistema === 'Windows'),
        );

        $this->entrar();
    }

    #[TestDox('o que é gravado nunca é o IP cru')]
    public function testGravaSoAImpressao(): void
    {
        $this->repository->method('usuarioTemAcessos')->willReturn(false);
        $this->repository->expects(self::once())->method('registrar')->with(
            $this->user,
            self::matchesRegularExpression('/^[0-9a-f]{64}$/'),
            self::anything(),
        )->willReturn(true);

        $this->entrar();
    }

    #[TestDox('chave off: grava e não envia')]
    public function testOffNaoEnvia(): void
    {
        $this->configuracao->definir('avisoLoginNovo', 'off');
        $this->repository->method('usuarioTemAcessos')->willReturn(true);
        $this->repository->expects(self::once())->method('registrar')->willReturn(true);
        $this->mailer->expects(self::never())->method('avisar');

        $this->entrar();
    }

    #[TestDox('chave em branco (leitor caiu): vale on e envia')]
    public function testLeitorEmBrancoEnvia(): void
    {
        $this->repository->method('usuarioTemAcessos')->willReturn(true);
        $this->repository->method('registrar')->willReturn(true);
        $this->mailer->expects(self::once())->method('avisar');

        $this->entrar();
    }

    #[TestDox('falha do e-mail não propaga e vai para o log')]
    public function testFalhaDoEmailNaoPropaga(): void
    {
        $this->repository->method('usuarioTemAcessos')->willReturn(true);
        $this->repository->method('registrar')->willReturn(true);
        $this->mailer->method('avisar')->willThrowException(new \RuntimeException('SMTP fora'));
        $this->logger->expects(self::once())->method('error');

        $this->entrar();
    }

    #[TestDox('falha do repositório não propaga, não envia e vai para o log')]
    public function testFalhaDoRepositorioNaoPropaga(): void
    {
        $this->repository->method('usuarioTemAcessos')->willThrowException(new \RuntimeException('banco fora'));
        $this->mailer->expects(self::never())->method('avisar');
        $this->logger->expects(self::once())->method('error');

        $this->entrar();
    }
}
