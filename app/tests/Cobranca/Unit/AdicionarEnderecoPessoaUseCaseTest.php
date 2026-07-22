<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\DTO\AdicionarEnderecoPessoaInput;
use App\Cobranca\Entity\Pessoa;
use App\Cobranca\Entity\PessoaEndereco;
use App\Cobranca\Exception\PessoaNaoEncontradaException;
use App\Cobranca\Repository\PessoaEnderecoRepository;
use App\Cobranca\Repository\PessoaRepository;
use App\Cobranca\UseCase\AdicionarEnderecoPessoaUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(AdicionarEnderecoPessoaUseCase::class)]
final class AdicionarEnderecoPessoaUseCaseTest extends TestCase
{
    private PessoaEnderecoRepository&MockObject $enderecoRepository;
    private PessoaRepository&MockObject $pessoaRepository;
    private AdicionarEnderecoPessoaUseCase $sut;
    // Tenant/User não são abstrações do domínio: instâncias reais, não mocks.
    private Tenant $tenant;
    private User $criadoPor;

    protected function setUp(): void
    {
        $this->enderecoRepository = $this->createMock(PessoaEnderecoRepository::class);
        $this->pessoaRepository = $this->createMock(PessoaRepository::class);
        $this->sut = new AdicionarEnderecoPessoaUseCase($this->enderecoRepository, $this->pessoaRepository);
        $this->tenant = new Tenant();
        $this->criadoPor = new User();
    }

    #[Test]
    public function primeiroEnderecoDaListaNasceAtual(): void
    {
        $pessoa = (new Pessoa())->setTenant($this->tenant);

        $this->pessoaRepository->method('findOneByIdDoTenant')->with(7, $this->tenant)->willReturn($pessoa);
        $this->enderecoRepository->method('existePeloMenosUmDaPessoa')->with($pessoa)->willReturn(false);
        $this->enderecoRepository
            ->expects($this->once())
            ->method('salvar')
            ->with(self::isInstanceOf(PessoaEndereco::class), true);

        $endereco = $this->sut->executar($this->input(7), $this->tenant, $this->criadoPor);

        self::assertTrue($endereco->isAtual());
        self::assertSame($pessoa, $endereco->getPessoa());
        self::assertTrue($pessoa->getEnderecos()->contains($endereco));
        self::assertSame('Rua das Flores', $endereco->getLogradouro());
        self::assertSame('SP', $endereco->getUf());
    }

    #[Test]
    public function segundoEnderecoNaoNasceAtualSozinho(): void
    {
        $pessoa = (new Pessoa())->setTenant($this->tenant);

        $this->pessoaRepository->method('findOneByIdDoTenant')->willReturn($pessoa);
        // A pessoa já tem pelo menos um endereço: o novo entra como histórico.
        $this->enderecoRepository->method('existePeloMenosUmDaPessoa')->willReturn(true);

        $endereco = $this->sut->executar($this->input(7), $this->tenant, $this->criadoPor);

        self::assertFalse($endereco->isAtual());
    }

    #[Test]
    public function complementoVazioViraNull(): void
    {
        $pessoa = (new Pessoa())->setTenant($this->tenant);
        $this->pessoaRepository->method('findOneByIdDoTenant')->willReturn($pessoa);
        $this->enderecoRepository->method('existePeloMenosUmDaPessoa')->willReturn(false);

        $input = $this->input(7);
        $input->complemento = '   ';

        $endereco = $this->sut->executar($input, $this->tenant, $this->criadoPor);

        self::assertNull($endereco->getComplemento());
    }

    #[Test]
    public function rejeitaPessoaDeOutroTenant(): void
    {
        // Pessoa inexistente OU de outro escritório: findOneByIdDoTenant devolve null.
        $this->pessoaRepository->method('findOneByIdDoTenant')->willReturn(null);
        $this->enderecoRepository->expects($this->never())->method('existePeloMenosUmDaPessoa');
        $this->enderecoRepository->expects($this->never())->method('salvar');

        $this->expectException(PessoaNaoEncontradaException::class);

        $this->sut->executar($this->input(999), $this->tenant, $this->criadoPor);
    }

    private function input(int $pessoaId): AdicionarEnderecoPessoaInput
    {
        $input = new AdicionarEnderecoPessoaInput();
        $input->pessoaId = $pessoaId;
        $input->logradouro = 'Rua das Flores';
        $input->numero = '123';
        $input->complemento = 'Apto 45';
        $input->bairro = 'Centro';
        $input->cidade = 'São Paulo';
        $input->uf = 'sp';
        $input->cep = '01000-000';

        return $input;
    }
}
