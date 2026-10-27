<?php
declare(strict_types=1);
namespace App\Tests\Profile\Unit;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Profile\Entity\VinculoNoEscritorio;
use App\Profile\Enum\VinculoColaborador;
use App\Profile\Repository\VinculoNoEscritorioRepository;
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
    private VinculoNoEscritorioRepository&MockObject $vinculos;
    private AtualizarDadosPessoaisUseCase $sut;
    private UserProfile $perfil;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(UserProfileRepository::class);
        $this->vinculos   = $this->createMock(VinculoNoEscritorioRepository::class);
        $this->sut = new AtualizarDadosPessoaisUseCase($this->repository, $this->vinculos);
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

    #[TestDox('chave PIX para recompensas: grava aparada, vazia vira nula e não mexe nas outras colunas')]
    public function testChavePixDeRecompensas(): void
    {
        $this->repository->method('salvar');
        $input = $this->inputCompleto();
        $input->chavePixRecompensa = '  ana@pix.com.br ';

        $this->sut->executar($this->perfil, $input);
        self::assertSame('ana@pix.com.br', $this->perfil->getChavePixRecompensa());
        self::assertSame('12345', $this->perfil->getCtps());

        $input->chavePixRecompensa = '   ';
        $this->sut->executar($this->perfil, $input);
        self::assertNull($this->perfil->getChavePixRecompensa());
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

    // ── desenho 07, VS 18.10.26: frase, telefone e vínculo (spec meu-perfil-pagina §2) ──

    #[TestDox('frase e telefone vão para o perfil; telefone guarda só os dígitos')]
    public function testFraseETelefone(): void
    {
        $input           = $this->inputCompleto();
        $input->status   = '  O topo é o seu lugar.  ';
        $input->telefone = '(82) 99999-1234';

        $this->sut->executar($this->perfil, $input);

        self::assertSame('O topo é o seu lugar.', $this->perfil->getStatus());
        self::assertSame('82999991234', $this->perfil->getTelefone());
    }

    #[TestDox('primário troca o tipo: PJ grava início do contrato e CNPJ, não a admissão')]
    public function testPrimarioTrocaParaPj(): void
    {
        [$userTenant, $gravado] = $this->vinculoSemLinha();
        $input                 = $this->inputCompleto();
        $input->vinculo        = VinculoColaborador::Pj;
        $input->contratoInicio = new \DateTime('2026-03-01');
        $input->cnpj           = '12.345.678/0001-90';
        $input->dataAdmissao   = new \DateTime('2020-01-01');

        $this->sut->executar($this->perfil, $input, $userTenant, true);

        self::assertSame(VinculoColaborador::Pj, $gravado->valor?->getTipo());
        self::assertSame('2026-03-01', $gravado->valor?->getContratoInicio()?->format('Y-m-d'));
        self::assertSame('12345678000190', $gravado->valor?->getCnpj());
        self::assertNull($userTenant->getDataAdmissao(), 'PJ não mexe na admissão');
    }

    #[TestDox('secundário: o tipo que vier no formulário é ignorado, mas a admissão (CLT) é gravada')]
    public function testSecundarioNaoTrocaOTipoMasGravaAdmissao(): void
    {
        [$userTenant, $gravado] = $this->vinculoSemLinha();
        $input               = $this->inputCompleto();
        $input->vinculo      = VinculoColaborador::Socio;
        $input->dataAdmissao = new \DateTime('2024-02-10');

        $this->sut->executar($this->perfil, $input, $userTenant, false);

        self::assertSame(VinculoColaborador::Clt, $gravado->valor?->getTipo());
        self::assertSame('2024-02-10', $userTenant->getDataAdmissao()?->format('Y-m-d'));
    }

    #[TestDox('sem escritório na sessão, grava só os dados da pessoa')]
    public function testSemEscritorioNaoMexeNoVinculo(): void
    {
        $this->vinculos->expects($this->never())->method('salvar');
        $this->repository->expects($this->once())->method('salvar')->with($this->perfil, true);

        $this->sut->executar($this->perfil, $this->inputCompleto());
    }

    /** @return array{0: UserTenant, 1: \stdClass} vínculo sem linha gravada e o que o repositório recebe */
    private function vinculoSemLinha(): array
    {
        $userTenant = new UserTenant(new User(), new Tenant());
        $gravado    = new \stdClass();
        $gravado->valor = null;
        $this->vinculos->method('doUsuarioNoEscritorio')->willReturn(null);
        $this->vinculos->expects($this->once())->method('salvar')
            ->willReturnCallback(static function (VinculoNoEscritorio $v) use ($gravado): void { $gravado->valor = $v; });

        return [$userTenant, $gravado];
    }
}
