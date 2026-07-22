<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\DTO\MarcarEnderecoAtualInput;
use App\Cobranca\Entity\Pessoa;
use App\Cobranca\Entity\PessoaEndereco;
use App\Cobranca\Exception\PessoaEnderecoNaoEncontradoException;
use App\Cobranca\Exception\PessoaNaoEncontradaException;
use App\Cobranca\Repository\PessoaEnderecoRepository;
use App\Cobranca\Repository\PessoaRepository;
use App\Cobranca\UseCase\MarcarEnderecoAtualUseCase;
use App\Entity\Tenant\Tenant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(MarcarEnderecoAtualUseCase::class)]
final class MarcarEnderecoAtualUseCaseTest extends TestCase
{
    private PessoaEnderecoRepository&MockObject $enderecoRepository;
    private PessoaRepository&MockObject $pessoaRepository;
    private MarcarEnderecoAtualUseCase $sut;
    // Tenant não é abstração do domínio: instância real, não mock.
    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->enderecoRepository = $this->createMock(PessoaEnderecoRepository::class);
        $this->pessoaRepository = $this->createMock(PessoaRepository::class);
        $this->sut = new MarcarEnderecoAtualUseCase($this->enderecoRepository, $this->pessoaRepository);
        $this->tenant = new Tenant();
    }

    #[Test]
    public function marcarOutroTrocaAFlagEPreservaOAntigoEmUmUnicoFlush(): void
    {
        $pessoa = $this->pessoaComId(1);
        $anterior = (new PessoaEndereco())->setTenant($this->tenant)->setPessoa($pessoa)->setAtual(true);
        $novo = (new PessoaEndereco())->setTenant($this->tenant)->setPessoa($pessoa)->setAtual(false);

        $this->pessoaRepository->method('findOneByIdDoTenant')->with(1, $this->tenant)->willReturn($pessoa);
        $this->enderecoRepository->method('findOneByIdDoTenant')->with(20, $this->tenant)->willReturn($novo);
        $this->enderecoRepository->method('buscarAtualDaPessoa')->with($pessoa)->willReturn($anterior);
        // Transação única: um só flush troca as duas flags.
        $this->enderecoRepository->expects($this->once())->method('salvar')->with($novo, true);

        $resultado = $this->sut->executar($this->input(1, 20), $this->tenant);

        self::assertSame($novo, $resultado);
        self::assertTrue($novo->isAtual());
        // O antigo PERMANECE na lista, só deixa de ser o atual — não é removido.
        self::assertFalse($anterior->isAtual());
    }

    #[Test]
    public function marcarOItemQueJaEAtualEhIdempotente(): void
    {
        $pessoa = $this->pessoaComId(1);
        $jaAtual = (new PessoaEndereco())->setTenant($this->tenant)->setPessoa($pessoa)->setAtual(true);

        $this->pessoaRepository->method('findOneByIdDoTenant')->willReturn($pessoa);
        $this->enderecoRepository->method('findOneByIdDoTenant')->willReturn($jaAtual);
        $this->enderecoRepository->expects($this->never())->method('buscarAtualDaPessoa');
        $this->enderecoRepository->expects($this->never())->method('salvar');

        $resultado = $this->sut->executar($this->input(1, 20), $this->tenant);

        self::assertSame($jaAtual, $resultado);
        self::assertTrue($resultado->isAtual());
    }

    #[Test]
    public function rejeitaPessoaDeOutroTenant(): void
    {
        $this->pessoaRepository->method('findOneByIdDoTenant')->willReturn(null);
        $this->enderecoRepository->expects($this->never())->method('findOneByIdDoTenant');

        $this->expectException(PessoaNaoEncontradaException::class);

        $this->sut->executar($this->input(999, 20), $this->tenant);
    }

    #[Test]
    public function rejeitaEnderecoDeOutroTenant(): void
    {
        $pessoa = $this->pessoaComId(1);
        $this->pessoaRepository->method('findOneByIdDoTenant')->willReturn($pessoa);
        // Endereço inexistente OU de outro escritório: findOneByIdDoTenant devolve null.
        $this->enderecoRepository->method('findOneByIdDoTenant')->willReturn(null);
        $this->enderecoRepository->expects($this->never())->method('salvar');

        $this->expectException(PessoaEnderecoNaoEncontradoException::class);

        $this->sut->executar($this->input(1, 999), $this->tenant);
    }

    #[Test]
    public function rejeitaEnderecoQuePertenceAOutraPessoaDoMesmoTenant(): void
    {
        $pessoa = $this->pessoaComId(1);
        $outraPessoa = $this->pessoaComId(2);
        $enderecoDeOutraPessoa = (new PessoaEndereco())->setTenant($this->tenant)->setPessoa($outraPessoa);

        $this->pessoaRepository->method('findOneByIdDoTenant')->willReturn($pessoa);
        // O endereço existe e é do mesmo tenant, mas pertence a OUTRA pessoa — tratado como não
        // encontrado (evita vazamento de existência entre pessoas do mesmo escritório).
        $this->enderecoRepository->method('findOneByIdDoTenant')->willReturn($enderecoDeOutraPessoa);
        $this->enderecoRepository->expects($this->never())->method('salvar');

        $this->expectException(PessoaEnderecoNaoEncontradoException::class);

        $this->sut->executar($this->input(1, 20), $this->tenant);
    }

    private function input(int $pessoaId, int $enderecoId): MarcarEnderecoAtualInput
    {
        $input = new MarcarEnderecoAtualInput();
        $input->pessoaId = $pessoaId;
        $input->enderecoId = $enderecoId;

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
