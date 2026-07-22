<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\DTO\AdicionarTelefonePessoaInput;
use App\Cobranca\Entity\Pessoa;
use App\Cobranca\Entity\PessoaTelefone;
use App\Cobranca\Exception\PessoaNaoEncontradaException;
use App\Cobranca\Repository\PessoaRepository;
use App\Cobranca\Repository\PessoaTelefoneRepository;
use App\Cobranca\UseCase\AdicionarTelefonePessoaUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(AdicionarTelefonePessoaUseCase::class)]
final class AdicionarTelefonePessoaUseCaseTest extends TestCase
{
    private PessoaTelefoneRepository&MockObject $telefoneRepository;
    private PessoaRepository&MockObject $pessoaRepository;
    private AdicionarTelefonePessoaUseCase $sut;
    // Tenant/User não são abstrações do domínio: instâncias reais, não mocks.
    private Tenant $tenant;
    private User $criadoPor;

    protected function setUp(): void
    {
        $this->telefoneRepository = $this->createMock(PessoaTelefoneRepository::class);
        $this->pessoaRepository = $this->createMock(PessoaRepository::class);
        $this->sut = new AdicionarTelefonePessoaUseCase($this->telefoneRepository, $this->pessoaRepository);
        $this->tenant = new Tenant();
        $this->criadoPor = new User();
    }

    #[Test]
    public function primeiroTelefoneDaListaNasceAtual(): void
    {
        $pessoa = (new Pessoa())->setTenant($this->tenant);

        $this->pessoaRepository->method('findOneByIdDoTenant')->with(7, $this->tenant)->willReturn($pessoa);
        $this->telefoneRepository->method('existePeloMenosUmDaPessoa')->with($pessoa)->willReturn(false);
        $this->telefoneRepository
            ->expects($this->once())
            ->method('salvar')
            ->with(self::isInstanceOf(PessoaTelefone::class), true);

        $telefone = $this->sut->executar($this->input(7, '(41) 99999-0000'), $this->tenant, $this->criadoPor);

        self::assertTrue($telefone->isAtual());
        self::assertSame($pessoa, $telefone->getPessoa());
        self::assertTrue($pessoa->getTelefones()->contains($telefone));
        self::assertSame('(41) 99999-0000', $telefone->getNumero());
        // SPEC §5.4: o primeiro item nasce atual, então a sombra da pessoa fica sincronizada com ele.
        self::assertSame('(41) 99999-0000', $pessoa->getTelefone());
    }

    #[Test]
    public function segundoTelefoneNaoNasceAtualSozinho(): void
    {
        $pessoa = (new Pessoa())->setTenant($this->tenant);

        $this->pessoaRepository->method('findOneByIdDoTenant')->willReturn($pessoa);
        $this->telefoneRepository->method('existePeloMenosUmDaPessoa')->willReturn(true);

        $telefone = $this->sut->executar($this->input(7, '(41) 98888-1111'), $this->tenant, $this->criadoPor);

        self::assertFalse($telefone->isAtual());
        // Não é o primeiro item: a sombra da pessoa não é tocada por este UseCase.
        self::assertNull($pessoa->getTelefone());
    }

    #[Test]
    public function rejeitaPessoaDeOutroTenant(): void
    {
        $this->pessoaRepository->method('findOneByIdDoTenant')->willReturn(null);
        $this->telefoneRepository->expects($this->never())->method('existePeloMenosUmDaPessoa');
        $this->telefoneRepository->expects($this->never())->method('salvar');

        $this->expectException(PessoaNaoEncontradaException::class);

        $this->sut->executar($this->input(999, '(41) 90000-0000'), $this->tenant, $this->criadoPor);
    }

    private function input(int $pessoaId, string $numero): AdicionarTelefonePessoaInput
    {
        $input = new AdicionarTelefonePessoaInput();
        $input->pessoaId = $pessoaId;
        $input->numero = $numero;

        return $input;
    }
}
