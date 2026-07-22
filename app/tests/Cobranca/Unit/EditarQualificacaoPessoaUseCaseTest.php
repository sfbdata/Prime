<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\DTO\EditarQualificacaoPessoaInput;
use App\Cobranca\Entity\Pessoa;
use App\Cobranca\Enum\EstadoCivil;
use App\Cobranca\Exception\PessoaNaoEncontradaException;
use App\Cobranca\Repository\PessoaRepository;
use App\Cobranca\UseCase\EditarQualificacaoPessoaUseCase;
use App\Entity\Tenant\Tenant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(EditarQualificacaoPessoaUseCase::class)]
final class EditarQualificacaoPessoaUseCaseTest extends TestCase
{
    private PessoaRepository&MockObject $pessoaRepository;
    private EditarQualificacaoPessoaUseCase $sut;
    // Tenant não é abstração do domínio: instância real, não mock.
    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->pessoaRepository = $this->createMock(PessoaRepository::class);
        $this->sut = new EditarQualificacaoPessoaUseCase($this->pessoaRepository);
        $this->tenant = new Tenant();
    }

    #[Test]
    public function editaTodosOsCamposDeQualificacao(): void
    {
        $pessoa = (new Pessoa())->setTenant($this->tenant)->setNome('Nome Antigo');
        $this->pessoaRepository->method('findOneByIdDoTenant')->with(7, $this->tenant)->willReturn($pessoa);
        $this->pessoaRepository->expects($this->once())->method('salvar')->with($pessoa, true);

        $nascimento = new \DateTimeImmutable('1990-05-20');
        $input = $this->input(7);
        $input->nome = 'Fulano da Silva';
        $input->cpf = '123.456.789-01';
        $input->cnpj = '12.345.678/0001-90';
        $input->observacao = 'Observação atualizada';
        $input->dataNascimento = $nascimento;
        $input->estadoCivil = EstadoCivil::Casado;
        $input->profissao = 'Engenheiro';
        $input->rg = '1234567';
        $input->orgaoEmissorRg = 'SSP/CE';

        $resultado = $this->sut->executar($input, $this->tenant);

        self::assertSame($pessoa, $resultado);
        self::assertSame('Fulano da Silva', $pessoa->getNome());
        self::assertSame('123.456.789-01', $pessoa->getCpf());
        self::assertSame('12.345.678/0001-90', $pessoa->getCnpj());
        self::assertSame('Observação atualizada', $pessoa->getObservacao());
        self::assertSame($nascimento, $pessoa->getDataNascimento());
        self::assertSame(EstadoCivil::Casado, $pessoa->getEstadoCivil());
        self::assertSame('Engenheiro', $pessoa->getProfissao());
        self::assertSame('1234567', $pessoa->getRg());
        self::assertSame('SSP/CE', $pessoa->getOrgaoEmissorRg());
    }

    #[Test]
    public function camposOpcionaisEmBrancoViramNull(): void
    {
        $pessoa = (new Pessoa())->setTenant($this->tenant);
        $this->pessoaRepository->method('findOneByIdDoTenant')->willReturn($pessoa);

        $input = $this->input(7);
        $input->cpf = '   ';
        $input->cnpj = '';
        $input->observacao = null;
        $input->profissao = '  ';
        $input->rg = null;
        $input->orgaoEmissorRg = '';

        $this->sut->executar($input, $this->tenant);

        self::assertNull($pessoa->getCpf());
        self::assertNull($pessoa->getCnpj());
        self::assertNull($pessoa->getObservacao());
        self::assertNull($pessoa->getProfissao());
        self::assertNull($pessoa->getRg());
        self::assertNull($pessoa->getOrgaoEmissorRg());
    }

    #[Test]
    public function nuncaTocaEmailOuTelefoneMesmoQuandoJaExistemNaLista(): void
    {
        $pessoa = (new Pessoa())->setTenant($this->tenant);
        // Bridge SPEC §6: setEmail()/setTelefone() criam o item atual da lista.
        $pessoa->setEmail('contato@example.com');
        $pessoa->setTelefone('(11) 99999-0000');
        $this->pessoaRepository->method('findOneByIdDoTenant')->willReturn($pessoa);

        $this->sut->executar($this->input(7), $this->tenant);

        // A ficha continua exibindo os MESMOS email/telefone — o UseCase não os tocou.
        self::assertSame('contato@example.com', $pessoa->getEmail());
        self::assertSame('(11) 99999-0000', $pessoa->getTelefone());
        self::assertCount(1, $pessoa->getEmails());
        self::assertCount(1, $pessoa->getTelefones());
    }

    #[Test]
    public function rejeitaPessoaDeOutroTenant(): void
    {
        // Pessoa inexistente OU de outro escritório: findOneByIdDoTenant devolve null.
        $this->pessoaRepository->method('findOneByIdDoTenant')->willReturn(null);
        $this->pessoaRepository->expects($this->never())->method('salvar');

        $this->expectException(PessoaNaoEncontradaException::class);

        $this->sut->executar($this->input(999), $this->tenant);
    }

    private function input(int $pessoaId): EditarQualificacaoPessoaInput
    {
        $input = new EditarQualificacaoPessoaInput();
        $input->pessoaId = $pessoaId;
        $input->nome = 'Nome Qualquer';

        return $input;
    }
}
