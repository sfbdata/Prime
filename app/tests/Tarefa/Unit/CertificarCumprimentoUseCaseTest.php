<?php

declare(strict_types=1);

namespace App\Tests\Tarefa\Unit;

use App\Entity\Auth\User;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tarefa\TarefaMensagem;
use App\Entity\Tenant\Tenant;
use App\Repository\TarefaMensagemRepository;
use App\Tarefa\DTO\CertificarCumprimentoInput;
use App\Tarefa\Exception\CertidaoRecusadaException;
use App\Tarefa\Service\QuemCuidaDaMeta;
use App\Tarefa\UseCase\CertificarCumprimentoUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;

/** C15: certidão de cumprimento, por responsável ou criador, uma por despacho. */
#[CoversClass(CertificarCumprimentoUseCase::class)]
#[CoversClass(CertificarCumprimentoInput::class)]
#[Group('tarefa')]
final class CertificarCumprimentoUseCaseTest extends TestCase
{
    private Tenant $escritorio;
    private User $criador;
    private User $responsavel;
    private TarefaMensagem $despacho;
    private TarefaMensagemRepository&MockObject $mensagens;

    protected function setUp(): void
    {
        $this->escritorio = new Tenant();
        (new \ReflectionProperty(Tenant::class, 'id'))->setValue($this->escritorio, 3);
        $this->criador = self::pessoa(1);
        $this->responsavel = self::pessoa(2);
        $meta = new Tarefa();
        $meta->setTenant($this->escritorio);
        $meta->setCriadoPor($this->criador);
        $meta->addResponsavel($this->responsavel);
        $this->despacho = (new TarefaMensagem())->setUsuario($this->criador)->setMensagem('Cumpra-se.')->setTenant($this->escritorio);
        $this->despacho->setPrazoCumprimento(new \DateTimeImmutable('2026-10-29'));
        $meta->addMensagem($this->despacho);
        $this->mensagens = $this->createMock(TarefaMensagemRepository::class);
    }

    #[TestDox('Responsável certifica: grava texto, quem e quando')]
    public function testResponsavelCertifica(): void
    {
        $agora = new \DateTimeImmutable('2026-10-23 09:00');
        $this->mensagens->expects(self::once())->method('confirmar');

        $this->useCase()->executar($this->despacho, $this->responsavel, $this->escritorio, new CertificarCumprimentoInput('Certifico que juntei a procuração.'), $agora);

        self::assertSame('Certifico que juntei a procuração.', $this->despacho->getCertidao());
        self::assertSame($this->responsavel, $this->despacho->getCertificadoPor());
        self::assertSame($agora, $this->despacho->getCertificadoEm());
    }

    #[TestDox('Quem não é responsável nem criador: semPermissao, nada gravado')]
    public function testSemPermissao(): void
    {
        $this->mensagens->expects(self::never())->method('confirmar');

        try {
            $this->useCase()->executar($this->despacho, self::pessoa(9), $this->escritorio, new CertificarCumprimentoInput('Certifico que fiz.'), new \DateTimeImmutable());
            self::fail('Deveria recusar.');
        } catch (CertidaoRecusadaException $e) {
            self::assertTrue($e->ehFaltaDePermissao());
        }
        self::assertNull($this->despacho->getCertidao());
    }

    #[TestDox('Segunda certidão no mesmo despacho: jaCertificado')]
    public function testUmaPorDespacho(): void
    {
        $this->useCase()->executar($this->despacho, $this->criador, $this->escritorio, new CertificarCumprimentoInput('Certifico que fiz.'), new \DateTimeImmutable());

        $this->expectExceptionObject(CertidaoRecusadaException::jaCertificado());

        $this->useCase()->executar($this->despacho, $this->responsavel, $this->escritorio, new CertificarCumprimentoInput('Certifico que refiz.'), new \DateTimeImmutable());
    }

    #[TestDox('Despacho de outro escritório: foraDoEscritorio')]
    public function testOutroEscritorio(): void
    {
        $outro = new Tenant();
        (new \ReflectionProperty(Tenant::class, 'id'))->setValue($outro, 99);

        $this->expectExceptionObject(CertidaoRecusadaException::foraDoEscritorio());

        $this->useCase()->executar($this->despacho, $this->criador, $outro, new CertificarCumprimentoInput('Certifico que fiz.'), new \DateTimeImmutable());
    }

    #[TestDox('Input: exige começar por "Certifico que" com conteúdo e até 1.000 caracteres')]
    public function testValidacaoDoTexto(): void
    {
        $validador = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();

        self::assertCount(0, $validador->validate(new CertificarCumprimentoInput('Certifico que fiz.')));
        self::assertCount(1, $validador->validate(new CertificarCumprimentoInput('Certifico que')));
        self::assertCount(1, $validador->validate(new CertificarCumprimentoInput('Fiz tudo.')));
        self::assertCount(1, $validador->validate(new CertificarCumprimentoInput('Certifico que ' . str_repeat('a', 987))));
        self::assertCount(0, $validador->validate(new CertificarCumprimentoInput('Certifico que ' . str_repeat('a', 986))));
    }

    private function useCase(): CertificarCumprimentoUseCase
    {
        return new CertificarCumprimentoUseCase($this->mensagens, new QuemCuidaDaMeta());
    }

    private static function pessoa(int $id): User
    {
        $pessoa = (new User())->setFullName('Pessoa ' . $id);
        (new \ReflectionProperty(User::class, 'id'))->setValue($pessoa, $id);

        return $pessoa;
    }
}
