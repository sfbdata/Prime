<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\DTO\AdicionarEmailPessoaInput;
use App\Cobranca\Entity\Pessoa;
use App\Cobranca\Entity\PessoaEmail;
use App\Cobranca\Exception\PessoaNaoEncontradaException;
use App\Cobranca\Repository\PessoaEmailRepository;
use App\Cobranca\Repository\PessoaRepository;
use App\Cobranca\UseCase\AdicionarEmailPessoaUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(AdicionarEmailPessoaUseCase::class)]
final class AdicionarEmailPessoaUseCaseTest extends TestCase
{
    private PessoaEmailRepository&MockObject $emailRepository;
    private PessoaRepository&MockObject $pessoaRepository;
    private AdicionarEmailPessoaUseCase $sut;
    // Tenant/User não são abstrações do domínio: instâncias reais, não mocks.
    private Tenant $tenant;
    private User $criadoPor;

    protected function setUp(): void
    {
        $this->emailRepository = $this->createMock(PessoaEmailRepository::class);
        $this->pessoaRepository = $this->createMock(PessoaRepository::class);
        $this->sut = new AdicionarEmailPessoaUseCase($this->emailRepository, $this->pessoaRepository);
        $this->tenant = new Tenant();
        $this->criadoPor = new User();
    }

    #[Test]
    public function primeiroEmailDaListaNasceAtual(): void
    {
        $pessoa = (new Pessoa())->setTenant($this->tenant);

        $this->pessoaRepository->method('findOneByIdDoTenant')->with(7, $this->tenant)->willReturn($pessoa);
        $this->emailRepository->method('existePeloMenosUmDaPessoa')->with($pessoa)->willReturn(false);
        $this->emailRepository
            ->expects($this->once())
            ->method('salvar')
            ->with(self::isInstanceOf(PessoaEmail::class), true);

        $email = $this->sut->executar($this->input(7, 'fulano@example.com'), $this->tenant, $this->criadoPor);

        self::assertTrue($email->isAtual());
        self::assertSame($pessoa, $email->getPessoa());
        self::assertTrue($pessoa->getEmails()->contains($email));
        self::assertSame('fulano@example.com', $email->getEmail());
        // SPEC §5.4: o primeiro item nasce atual, então a sombra da pessoa fica sincronizada com ele.
        self::assertSame('fulano@example.com', $pessoa->getEmail());
    }

    #[Test]
    public function segundoEmailNaoNasceAtualSozinho(): void
    {
        $pessoa = (new Pessoa())->setTenant($this->tenant);

        $this->pessoaRepository->method('findOneByIdDoTenant')->willReturn($pessoa);
        $this->emailRepository->method('existePeloMenosUmDaPessoa')->willReturn(true);

        $email = $this->sut->executar($this->input(7, 'segundo@example.com'), $this->tenant, $this->criadoPor);

        self::assertFalse($email->isAtual());
        // Não é o primeiro item: a sombra da pessoa não é tocada por este UseCase.
        self::assertNull($pessoa->getEmail());
    }

    #[Test]
    public function rejeitaPessoaDeOutroTenant(): void
    {
        $this->pessoaRepository->method('findOneByIdDoTenant')->willReturn(null);
        $this->emailRepository->expects($this->never())->method('existePeloMenosUmDaPessoa');
        $this->emailRepository->expects($this->never())->method('salvar');

        $this->expectException(PessoaNaoEncontradaException::class);

        $this->sut->executar($this->input(999, 'x@x.com'), $this->tenant, $this->criadoPor);
    }

    private function input(int $pessoaId, string $email): AdicionarEmailPessoaInput
    {
        $input = new AdicionarEmailPessoaInput();
        $input->pessoaId = $pessoaId;
        $input->email = $email;

        return $input;
    }
}
