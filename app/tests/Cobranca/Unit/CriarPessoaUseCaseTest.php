<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\DTO\CriarPessoaInput;
use App\Cobranca\Entity\Pessoa;
use App\Cobranca\Repository\PessoaRepository;
use App\Cobranca\UseCase\CriarPessoaUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(CriarPessoaUseCase::class)]
final class CriarPessoaUseCaseTest extends TestCase
{
    private PessoaRepository&MockObject $pessoaRepository;
    private CriarPessoaUseCase $sut;
    private Tenant $tenant;
    private User $criadoPor;

    protected function setUp(): void
    {
        $this->pessoaRepository = $this->createMock(PessoaRepository::class);
        $this->sut = new CriarPessoaUseCase($this->pessoaRepository);
        // Tenant/User não são abstrações do domínio: instâncias reais, não mocks.
        $this->tenant = new Tenant();
        $this->criadoPor = new User();
    }

    #[Test]
    public function criaPessoaComNomeTenantECriadoPor(): void
    {
        $this->pessoaRepository
            ->expects($this->once())
            ->method('salvar')
            ->with(self::isInstanceOf(Pessoa::class), true);

        $input = new CriarPessoaInput();
        $input->nome = 'Fulano de Tal';
        $input->cpf = '123.456.789-01';
        $input->cnpj = '12.345.678/0001-90';
        $input->email = 'fulano@example.com';
        $input->telefone = '(41) 99999-0000';
        $input->observacao = 'Devedor principal';

        $pessoa = $this->sut->executar($input, $this->tenant, $this->criadoPor);

        self::assertSame('Fulano de Tal', $pessoa->getNome());
        self::assertSame($this->tenant, $pessoa->getTenant());
        self::assertSame($this->criadoPor, $pessoa->getCriadoPor());
        self::assertSame('123.456.789-01', $pessoa->getCpf());
        self::assertSame('12.345.678/0001-90', $pessoa->getCnpj());
        self::assertSame('fulano@example.com', $pessoa->getEmail());
        self::assertSame('(41) 99999-0000', $pessoa->getTelefone());
        self::assertSame('Devedor principal', $pessoa->getObservacao());
    }

    #[Test]
    public function cpfCnpjEmailTelefoneObservacaoSaoOpcionaisEViramNull(): void
    {
        $this->pessoaRepository
            ->expects($this->once())
            ->method('salvar')
            ->with(self::isInstanceOf(Pessoa::class), true);

        $input = new CriarPessoaInput();
        $input->nome = 'Empresa Sem Documentos Ltda';
        // Ausentes (null) ou em branco: a normalização os transforma em null.
        $input->cpf = null;
        $input->cnpj = '   ';
        $input->email = null;
        $input->telefone = '';
        $input->observacao = null;

        $pessoa = $this->sut->executar($input, $this->tenant, $this->criadoPor);

        self::assertSame('Empresa Sem Documentos Ltda', $pessoa->getNome());
        self::assertSame($this->tenant, $pessoa->getTenant());
        self::assertNull($pessoa->getCpf());
        self::assertNull($pessoa->getCnpj());
        self::assertNull($pessoa->getEmail());
        self::assertNull($pessoa->getTelefone());
        self::assertNull($pessoa->getObservacao());
    }
}
