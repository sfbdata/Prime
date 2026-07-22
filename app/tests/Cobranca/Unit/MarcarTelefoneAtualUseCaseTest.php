<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\DTO\MarcarTelefoneAtualInput;
use App\Cobranca\Entity\Pessoa;
use App\Cobranca\Entity\PessoaTelefone;
use App\Cobranca\Exception\PessoaNaoEncontradaException;
use App\Cobranca\Exception\PessoaTelefoneNaoEncontradoException;
use App\Cobranca\Repository\PessoaRepository;
use App\Cobranca\Repository\PessoaTelefoneRepository;
use App\Cobranca\UseCase\MarcarTelefoneAtualUseCase;
use App\Entity\Tenant\Tenant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(MarcarTelefoneAtualUseCase::class)]
final class MarcarTelefoneAtualUseCaseTest extends TestCase
{
    private PessoaTelefoneRepository&MockObject $telefoneRepository;
    private PessoaRepository&MockObject $pessoaRepository;
    private MarcarTelefoneAtualUseCase $sut;
    // Tenant não é abstração do domínio: instância real, não mock.
    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->telefoneRepository = $this->createMock(PessoaTelefoneRepository::class);
        $this->pessoaRepository = $this->createMock(PessoaRepository::class);
        $this->sut = new MarcarTelefoneAtualUseCase($this->telefoneRepository, $this->pessoaRepository);
        $this->tenant = new Tenant();
    }

    #[Test]
    public function marcarOutroTrocaAFlagEPreservaOAntigoEmUmUnicoFlush(): void
    {
        $pessoa = $this->pessoaComId(1);
        $anterior = (new PessoaTelefone())->setTenant($this->tenant)->setPessoa($pessoa)->setAtual(true);
        $novo = (new PessoaTelefone())->setTenant($this->tenant)->setPessoa($pessoa)->setAtual(false);

        $this->pessoaRepository->method('findOneByIdDoTenant')->with(1, $this->tenant)->willReturn($pessoa);
        $this->telefoneRepository->method('findOneByIdDoTenant')->with(20, $this->tenant)->willReturn($novo);
        $this->telefoneRepository->method('buscarAtualDaPessoa')->with($pessoa)->willReturn($anterior);
        $this->telefoneRepository->expects($this->once())->method('salvar')->with($novo, true);

        $resultado = $this->sut->executar($this->input(1, 20), $this->tenant);

        self::assertSame($novo, $resultado);
        self::assertTrue($novo->isAtual());
        self::assertFalse($anterior->isAtual());
    }

    #[Test]
    public function marcarOItemQueJaEAtualEhIdempotente(): void
    {
        $pessoa = $this->pessoaComId(1);
        $jaAtual = (new PessoaTelefone())->setTenant($this->tenant)->setPessoa($pessoa)->setAtual(true);

        $this->pessoaRepository->method('findOneByIdDoTenant')->willReturn($pessoa);
        $this->telefoneRepository->method('findOneByIdDoTenant')->willReturn($jaAtual);
        $this->telefoneRepository->expects($this->never())->method('buscarAtualDaPessoa');
        $this->telefoneRepository->expects($this->never())->method('salvar');

        $resultado = $this->sut->executar($this->input(1, 20), $this->tenant);

        self::assertSame($jaAtual, $resultado);
    }

    #[Test]
    public function rejeitaPessoaDeOutroTenant(): void
    {
        $this->pessoaRepository->method('findOneByIdDoTenant')->willReturn(null);
        $this->telefoneRepository->expects($this->never())->method('findOneByIdDoTenant');

        $this->expectException(PessoaNaoEncontradaException::class);

        $this->sut->executar($this->input(999, 20), $this->tenant);
    }

    #[Test]
    public function rejeitaTelefoneDeOutroTenant(): void
    {
        $pessoa = $this->pessoaComId(1);
        $this->pessoaRepository->method('findOneByIdDoTenant')->willReturn($pessoa);
        $this->telefoneRepository->method('findOneByIdDoTenant')->willReturn(null);
        $this->telefoneRepository->expects($this->never())->method('salvar');

        $this->expectException(PessoaTelefoneNaoEncontradoException::class);

        $this->sut->executar($this->input(1, 999), $this->tenant);
    }

    #[Test]
    public function rejeitaTelefoneQuePertenceAOutraPessoaDoMesmoTenant(): void
    {
        $pessoa = $this->pessoaComId(1);
        $outraPessoa = $this->pessoaComId(2);
        $telefoneDeOutraPessoa = (new PessoaTelefone())->setTenant($this->tenant)->setPessoa($outraPessoa);

        $this->pessoaRepository->method('findOneByIdDoTenant')->willReturn($pessoa);
        $this->telefoneRepository->method('findOneByIdDoTenant')->willReturn($telefoneDeOutraPessoa);
        $this->telefoneRepository->expects($this->never())->method('salvar');

        $this->expectException(PessoaTelefoneNaoEncontradoException::class);

        $this->sut->executar($this->input(1, 20), $this->tenant);
    }

    private function input(int $pessoaId, int $telefoneId): MarcarTelefoneAtualInput
    {
        $input = new MarcarTelefoneAtualInput();
        $input->pessoaId = $pessoaId;
        $input->telefoneId = $telefoneId;

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
