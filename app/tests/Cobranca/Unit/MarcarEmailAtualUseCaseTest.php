<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\DTO\MarcarEmailAtualInput;
use App\Cobranca\Entity\Pessoa;
use App\Cobranca\Entity\PessoaEmail;
use App\Cobranca\Exception\PessoaEmailNaoEncontradoException;
use App\Cobranca\Exception\PessoaNaoEncontradaException;
use App\Cobranca\Repository\PessoaEmailRepository;
use App\Cobranca\Repository\PessoaRepository;
use App\Cobranca\UseCase\MarcarEmailAtualUseCase;
use App\Entity\Tenant\Tenant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(MarcarEmailAtualUseCase::class)]
final class MarcarEmailAtualUseCaseTest extends TestCase
{
    private PessoaEmailRepository&MockObject $emailRepository;
    private PessoaRepository&MockObject $pessoaRepository;
    private MarcarEmailAtualUseCase $sut;
    // Tenant não é abstração do domínio: instância real, não mock.
    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->emailRepository = $this->createMock(PessoaEmailRepository::class);
        $this->pessoaRepository = $this->createMock(PessoaRepository::class);
        $this->sut = new MarcarEmailAtualUseCase($this->emailRepository, $this->pessoaRepository);
        $this->tenant = new Tenant();
    }

    #[Test]
    public function marcarOutroTrocaAFlagEPreservaOAntigoEmUmUnicoFlush(): void
    {
        $pessoa = $this->pessoaComId(1);
        $anterior = (new PessoaEmail())->setTenant($this->tenant)->setPessoa($pessoa)->setAtual(true);
        $novo = (new PessoaEmail())->setTenant($this->tenant)->setPessoa($pessoa)->setAtual(false);

        $this->pessoaRepository->method('findOneByIdDoTenant')->with(1, $this->tenant)->willReturn($pessoa);
        $this->emailRepository->method('findOneByIdDoTenant')->with(20, $this->tenant)->willReturn($novo);
        $this->emailRepository->method('buscarAtualDaPessoa')->with($pessoa)->willReturn($anterior);
        $this->emailRepository->expects($this->once())->method('salvar')->with($novo, true);

        $resultado = $this->sut->executar($this->input(1, 20), $this->tenant);

        self::assertSame($novo, $resultado);
        self::assertTrue($novo->isAtual());
        self::assertFalse($anterior->isAtual());
    }

    #[Test]
    public function marcarOItemQueJaEAtualEhIdempotente(): void
    {
        $pessoa = $this->pessoaComId(1);
        $jaAtual = (new PessoaEmail())->setTenant($this->tenant)->setPessoa($pessoa)->setAtual(true);

        $this->pessoaRepository->method('findOneByIdDoTenant')->willReturn($pessoa);
        $this->emailRepository->method('findOneByIdDoTenant')->willReturn($jaAtual);
        $this->emailRepository->expects($this->never())->method('buscarAtualDaPessoa');
        $this->emailRepository->expects($this->never())->method('salvar');

        $resultado = $this->sut->executar($this->input(1, 20), $this->tenant);

        self::assertSame($jaAtual, $resultado);
    }

    #[Test]
    public function rejeitaPessoaDeOutroTenant(): void
    {
        $this->pessoaRepository->method('findOneByIdDoTenant')->willReturn(null);
        $this->emailRepository->expects($this->never())->method('findOneByIdDoTenant');

        $this->expectException(PessoaNaoEncontradaException::class);

        $this->sut->executar($this->input(999, 20), $this->tenant);
    }

    #[Test]
    public function rejeitaEmailDeOutroTenant(): void
    {
        $pessoa = $this->pessoaComId(1);
        $this->pessoaRepository->method('findOneByIdDoTenant')->willReturn($pessoa);
        $this->emailRepository->method('findOneByIdDoTenant')->willReturn(null);
        $this->emailRepository->expects($this->never())->method('salvar');

        $this->expectException(PessoaEmailNaoEncontradoException::class);

        $this->sut->executar($this->input(1, 999), $this->tenant);
    }

    #[Test]
    public function rejeitaEmailQuePertenceAOutraPessoaDoMesmoTenant(): void
    {
        $pessoa = $this->pessoaComId(1);
        $outraPessoa = $this->pessoaComId(2);
        $emailDeOutraPessoa = (new PessoaEmail())->setTenant($this->tenant)->setPessoa($outraPessoa);

        $this->pessoaRepository->method('findOneByIdDoTenant')->willReturn($pessoa);
        $this->emailRepository->method('findOneByIdDoTenant')->willReturn($emailDeOutraPessoa);
        $this->emailRepository->expects($this->never())->method('salvar');

        $this->expectException(PessoaEmailNaoEncontradoException::class);

        $this->sut->executar($this->input(1, 20), $this->tenant);
    }

    private function input(int $pessoaId, int $emailId): MarcarEmailAtualInput
    {
        $input = new MarcarEmailAtualInput();
        $input->pessoaId = $pessoaId;
        $input->emailId = $emailId;

        return $input;
    }

    private function pessoaComId(int $id): Pessoa
    {
        $pessoa = (new Pessoa())->setTenant($this->tenant);
        $reflexao = new \ReflectionProperty(Pessoa::class, 'id');
        $reflexao->setValue($pessoa, $id);

        return $pessoa;
    }
}
