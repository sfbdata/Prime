<?php
declare(strict_types=1);
namespace App\Tests\Profile\Unit;

use App\Entity\Auth\User;
use App\Profile\DTO\DadosPessoaisInput;
use App\Profile\Entity\UserProfile;
use App\Profile\Repository\UserProfileRepository;
use App\Profile\UseCase\AtualizarDadosPessoaisUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(AtualizarDadosPessoaisUseCase::class)]
final class AtualizarDadosPessoaisUseCaseTest extends TestCase
{
    private UserProfileRepository&MockObject $repository;
    private AtualizarDadosPessoaisUseCase $sut;
    private UserProfile $perfil;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(UserProfileRepository::class);
        $this->sut = new AtualizarDadosPessoaisUseCase($this->repository);
        $this->perfil = new UserProfile($this->createStub(User::class));
    }

    private function inputCompleto(): DadosPessoaisInput
    {
        $input = new DadosPessoaisInput();
        $input->nomeCompleto = 'Maria Souza';
        $input->cpf = '123.456.789-09';
        $input->dataNascimento = new \DateTime('1990-06-20');
        $input->ctps = '12345';
        $input->serie = '001';

        return $input;
    }

    #[TestDox('Input completo atualiza todos os campos do perfil')]
    public function testInputCompletoAtualizaTodosCampos(): void
    {
        $this->repository->expects($this->once())->method('salvar');

        $this->sut->executar($this->perfil, $this->inputCompleto());

        self::assertSame('Maria Souza', $this->perfil->getNomeCompleto());
        self::assertSame('123.456.789-09', $this->perfil->getCpf());
        self::assertEquals(new \DateTime('1990-06-20'), $this->perfil->getDataNascimento());
        self::assertSame('12345', $this->perfil->getCtps());
        self::assertSame('001', $this->perfil->getSerie());
    }

    #[TestDox('CPF vazio define null no perfil')]
    public function testCpfVazioDefineNull(): void
    {
        $input = $this->inputCompleto();
        $input->cpf = '';
        $this->repository->method('salvar');

        $this->sut->executar($this->perfil, $input);

        self::assertNull($this->perfil->getCpf());
    }

    #[TestDox('CTPS vazio define null no perfil')]
    public function testCtpsVazioDefineNull(): void
    {
        $input = $this->inputCompleto();
        $input->ctps = '';
        $this->repository->method('salvar');

        $this->sut->executar($this->perfil, $input);

        self::assertNull($this->perfil->getCtps());
    }

    #[TestDox('Série vazia define null no perfil')]
    public function testSerieVaziaDefineNull(): void
    {
        $input = $this->inputCompleto();
        $input->serie = '';
        $this->repository->method('salvar');

        $this->sut->executar($this->perfil, $input);

        self::assertNull($this->perfil->getSerie());
    }

    #[TestDox('Data de nascimento nula é permitida')]
    public function testDataNascimentoNulaPermitida(): void
    {
        $input = $this->inputCompleto();
        $input->dataNascimento = null;
        $this->repository->method('salvar');

        $this->sut->executar($this->perfil, $input);

        self::assertNull($this->perfil->getDataNascimento());
    }

    #[TestDox('Substitui dados existentes pelos novos')]
    public function testSubstituiDadosExistentes(): void
    {
        $this->perfil->setNomeCompleto('Nome Antigo');
        $this->perfil->setCpf('999.888.777-66');

        $input = new DadosPessoaisInput();
        $input->nomeCompleto = 'Nome Novo';
        $input->cpf = '111.222.333-44';
        $this->repository->expects($this->once())->method('salvar');

        $this->sut->executar($this->perfil, $input);

        self::assertSame('Nome Novo', $this->perfil->getNomeCompleto());
        self::assertSame('111.222.333-44', $this->perfil->getCpf());
    }

    #[TestDox('salvar é chamado com flush = true')]
    public function testSalvarChamadoComFlushTrue(): void
    {
        $this->repository
            ->expects($this->once())
            ->method('salvar')
            ->with($this->perfil, true);

        $this->sut->executar($this->perfil, $this->inputCompleto());
    }
}
