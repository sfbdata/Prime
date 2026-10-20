<?php

declare(strict_types=1);

namespace App\Tests\TrabalhoExterno\Unit;

use App\Auth\UseCase\CriarConviteEscritorioUseCase;
use App\Shared\Email\IdentidadeDeEmail;
use App\TrabalhoExterno\Entity\ConviteDemanda;
use App\TrabalhoExterno\Entity\DemandaExterna;
use App\TrabalhoExterno\Enum\Perfil;
use App\TrabalhoExterno\Enum\Situacao;
use App\TrabalhoExterno\Exception\DadosInvalidos;
use App\TrabalhoExterno\Repository\ConviteDemandaRepository;
use App\TrabalhoExterno\Repository\DemandaExternaRepository;
use App\TrabalhoExterno\Service\Contexto;
use App\TrabalhoExterno\Service\GuardaDeConvites;
use App\TrabalhoExterno\Service\LocalizadorDeDemanda;
use App\TrabalhoExterno\Service\PoliticaDaDemanda;
use App\TrabalhoExterno\Service\TrabalhoExternoMailer;
use App\TrabalhoExterno\UseCase\ConvidarProfissionalUseCase;
use App\TrabalhoExterno\UseCase\ReenviarConviteUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

/**
 * Conta com o e-mail a confirmar não manda e-mail a terceiros (spec login-desenho-10, decisão c): convidar e
 * reenviar convite do Trabalho Externo recusam com o mesmo texto do convite de escritório, sem gravar nem enviar.
 */
#[CoversClass(ConvidarProfissionalUseCase::class)]
#[CoversClass(ReenviarConviteUseCase::class)]
final class ConviteComEmailAConfirmarTest extends TestCase
{
    private const CONVITE_ID = 31;

    private DemandaExterna $demanda;
    private DemandaExternaRepository&MockObject $repositorio;
    private MailerInterface&MockObject $mailer;

    protected function setUp(): void
    {
        $this->demanda = Apoio::demanda(Situacao::Execucao);
        $this->repositorio = $this->createMock(DemandaExternaRepository::class);
        $this->repositorio->method('daCentralPorId')->willReturn($this->demanda);
        $this->mailer = $this->createMock(MailerInterface::class);
    }

    private function guarda(): GuardaDeConvites
    {
        $politica = new PoliticaDaDemanda();
        $localizador = new LocalizadorDeDemanda($this->repositorio, $this->createStub(ConviteDemandaRepository::class), $politica);

        return new GuardaDeConvites($localizador, $politica);
    }

    private function mailerDoModulo(): TrabalhoExternoMailer
    {
        $urls = $this->createStub(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturn('https://app.test/trabalho-externo/convite/x');

        return new TrabalhoExternoMailer($this->mailer, $this->createStub(Environment::class), $urls, new IdentidadeDeEmail('nao-responda@bluejus.com.br', ''));
    }

    private function contexto(bool $aConfirmar): Contexto
    {
        $usuario = Apoio::usuario(77, ['ROLE_MASTER']);
        if ($aConfirmar) {
            $usuario->marcarEmailAConfirmar(new \DateTimeImmutable('-1 hour'));
        }

        return Apoio::contexto(Perfil::Master, usuario: $usuario);
    }

    private function convidar(bool $aConfirmar): DemandaExterna
    {
        $useCase = new ConvidarProfissionalUseCase($this->guarda(), $this->repositorio, $this->mailerDoModulo());

        return $useCase->executar($this->contexto($aConfirmar), $this->demanda->getCodigo(), ['nome' => 'Profissional', 'email' => 'prof@test.com']);
    }

    /** Um convite já enviado na demanda, com id, para reenviar. */
    private function comConvite(): void
    {
        $convite = new ConviteDemanda($this->demanda, 'Profissional', 'prof@test.com', null, new \DateTimeImmutable('+10 days'), str_repeat('a', 64), [], Apoio::autor());
        $this->demanda->convidar($convite, Apoio::autor());
        (new \ReflectionProperty($convite, 'id'))->setValue($convite, self::CONVITE_ID);
    }

    private function reenviar(bool $aConfirmar): DemandaExterna
    {
        $useCase = new ReenviarConviteUseCase($this->guarda(), $this->repositorio, $this->mailerDoModulo());

        return $useCase->executar($this->contexto($aConfirmar), $this->demanda->getCodigo(), self::CONVITE_ID);
    }

    #[TestDox('Convidar com o e-mail a confirmar: recusa com o texto do convite de escritório, sem gravar nem enviar')]
    public function testConvidarRecusa(): void
    {
        $this->repositorio->expects($this->never())->method('salvar');
        $this->mailer->expects($this->never())->method('send');

        $this->expectException(DadosInvalidos::class);
        $this->expectExceptionMessage(CriarConviteEscritorioUseCase::RECUSA_EMAIL_A_CONFIRMAR);

        try {
            $this->convidar(aConfirmar: true);
        } finally {
            self::assertSame([], $this->demanda->getConvites(), 'o convite não nasce');
        }
    }

    #[TestDox('Convidar com o e-mail confirmado: grava e envia')]
    public function testConvidarComEmailConfirmado(): void
    {
        $this->repositorio->expects($this->once())->method('salvar');
        $this->mailer->expects($this->once())->method('send');

        $this->convidar(aConfirmar: false);

        self::assertCount(1, $this->demanda->getConvites());
    }

    #[TestDox('Reenviar com o e-mail a confirmar: recusa com o mesmo texto, sem gravar nem enviar')]
    public function testReenviarRecusa(): void
    {
        $this->comConvite();
        $this->repositorio->expects($this->never())->method('salvar');
        $this->mailer->expects($this->never())->method('send');

        $this->expectException(DadosInvalidos::class);
        $this->expectExceptionMessage(CriarConviteEscritorioUseCase::RECUSA_EMAIL_A_CONFIRMAR);

        $this->reenviar(aConfirmar: true);
    }

    #[TestDox('Reenviar com o e-mail confirmado: grava e envia')]
    public function testReenviarComEmailConfirmado(): void
    {
        $this->comConvite();
        $this->repositorio->expects($this->once())->method('salvar');
        $this->mailer->expects($this->once())->method('send');

        $this->reenviar(aConfirmar: false);
    }
}
