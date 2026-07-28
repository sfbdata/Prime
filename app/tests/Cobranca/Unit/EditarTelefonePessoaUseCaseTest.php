<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\DTO\EditarTelefonePessoaInput;
use App\Cobranca\Entity\Pessoa;
use App\Cobranca\Entity\PessoaTelefone;
use App\Cobranca\Exception\PessoaNaoEncontradaException;
use App\Cobranca\Exception\PessoaTelefoneNaoEncontradoException;
use App\Cobranca\Repository\PessoaRepository;
use App\Cobranca\Repository\PessoaTelefoneRepository;
use App\Cobranca\UseCase\EditarTelefonePessoaUseCase;
use App\Entity\Tenant\Tenant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(EditarTelefonePessoaUseCase::class)]
final class EditarTelefonePessoaUseCaseTest extends TestCase
{
    private PessoaTelefoneRepository&MockObject $telefoneRepository;
    private PessoaRepository&MockObject $pessoaRepository;
    private EditarTelefonePessoaUseCase $sut;
    // Tenant não é abstração do domínio: instância real, não mock.
    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->telefoneRepository = $this->createMock(PessoaTelefoneRepository::class);
        $this->pessoaRepository = $this->createMock(PessoaRepository::class);
        $this->sut = new EditarTelefonePessoaUseCase($this->telefoneRepository, $this->pessoaRepository);
        $this->tenant = new Tenant();
    }

    #[Test]
    public function corrigeONumeroNoLugarSemMexerNaFlagAtual(): void
    {
        $historico = (new PessoaTelefone())->setTenant($this->tenant)->setNumero('(41) 3333-1111')->setAtual(false);
        $pessoa = $this->pessoaComId(1, $historico);

        $this->pessoaRepository->method('findOneByIdDoTenant')->with(1, $this->tenant)->willReturn($pessoa);
        $this->telefoneRepository->method('findOneByIdDoTenant')->with(20, $this->tenant)->willReturn($historico);
        $this->telefoneRepository->expects($this->once())->method('salvar')->with($historico, true);

        $resultado = $this->sut->executar($this->input(1, 20, '(41) 3333-1112'), $this->tenant);

        self::assertSame($historico, $resultado);
        self::assertSame('(41) 3333-1112', $historico->getNumero());
        // Corrigir um item do HISTÓRICO não o promove: editar não é "marcar como atual".
        self::assertFalse($historico->isAtual());
    }

    #[Test]
    public function corrigirOAtualLevaAColunaSombraJunto(): void
    {
        // Sem isto, `Pessoa::getTelefone()` (leitura escalar das listagens, sem N+1) continuaria
        // devolvendo o número errado — a correção ficaria invisível onde mais se lê o telefone.
        $atual = (new PessoaTelefone())->setTenant($this->tenant)->setNumero('(41) 91111-1111')->setAtual(true);
        $pessoa = $this->pessoaComId(1, $atual);
        $pessoa->sincronizarTelefoneSombra('(41) 91111-1111');

        $this->pessoaRepository->method('findOneByIdDoTenant')->willReturn($pessoa);
        $this->telefoneRepository->method('findOneByIdDoTenant')->willReturn($atual);
        $this->telefoneRepository->expects($this->once())->method('salvar')->with($atual, true);

        $this->sut->executar($this->input(1, 20, '(41) 91111-9999'), $this->tenant);

        self::assertSame('(41) 91111-9999', $atual->getNumero());
        self::assertSame('(41) 91111-9999', $pessoa->getTelefone());
    }

    #[Test]
    public function corrigirUmItemDoHistoricoNaoTocaNaSombraDoAtual(): void
    {
        $atual = (new PessoaTelefone())->setTenant($this->tenant)->setNumero('(41) 91111-1111')->setAtual(true);
        $historico = (new PessoaTelefone())->setTenant($this->tenant)->setNumero('(41) 3333-0000')->setAtual(false);
        $pessoa = $this->pessoaComId(1, $atual, $historico);
        $pessoa->sincronizarTelefoneSombra('(41) 91111-1111');

        $this->pessoaRepository->method('findOneByIdDoTenant')->willReturn($pessoa);
        $this->telefoneRepository->method('findOneByIdDoTenant')->willReturn($historico);

        $this->sut->executar($this->input(1, 21, '(41) 3333-0001'), $this->tenant);

        self::assertSame('(41) 91111-1111', $pessoa->getTelefone(), 'a sombra segue o ATUAL, não o item corrigido');
    }

    #[Test]
    public function aparaEspacosDoNumero(): void
    {
        $telefone = (new PessoaTelefone())->setTenant($this->tenant)->setNumero('(41) 3333-1111');
        $pessoa = $this->pessoaComId(1, $telefone);

        $this->pessoaRepository->method('findOneByIdDoTenant')->willReturn($pessoa);
        $this->telefoneRepository->method('findOneByIdDoTenant')->willReturn($telefone);

        $this->sut->executar($this->input(1, 20, '  (41) 3333-2222  '), $this->tenant);

        self::assertSame('(41) 3333-2222', $telefone->getNumero());
    }

    #[Test]
    public function rejeitaPessoaDeOutroTenant(): void
    {
        $this->pessoaRepository->method('findOneByIdDoTenant')->willReturn(null);
        $this->telefoneRepository->expects($this->never())->method('findOneByIdDoTenant');
        $this->telefoneRepository->expects($this->never())->method('salvar');

        $this->expectException(PessoaNaoEncontradaException::class);

        $this->sut->executar($this->input(999, 20, '(41) 3333-1111'), $this->tenant);
    }

    #[Test]
    public function rejeitaTelefoneDeOutroTenant(): void
    {
        $pessoa = $this->pessoaComId(1);
        $this->pessoaRepository->method('findOneByIdDoTenant')->willReturn($pessoa);
        $this->telefoneRepository->method('findOneByIdDoTenant')->willReturn(null);
        $this->telefoneRepository->expects($this->never())->method('salvar');

        $this->expectException(PessoaTelefoneNaoEncontradoException::class);

        $this->sut->executar($this->input(1, 999, '(41) 3333-1111'), $this->tenant);
    }

    #[Test]
    public function rejeitaTelefoneQuePertenceAOutraPessoaDoMesmoTenant(): void
    {
        // Vazamento entre pessoas do MESMO escritório: o id do telefone existe e é do tenant, mas é
        // de outra ficha. Sem esta guarda, o gestor editaria o número de quem não está na tela.
        $pessoa = $this->pessoaComId(1);
        $outraPessoa = $this->pessoaComId(2);
        $telefoneDeOutraPessoa = (new PessoaTelefone())->setTenant($this->tenant)->setNumero('(41) 3333-1111');
        $outraPessoa->adicionarTelefone($telefoneDeOutraPessoa);

        $this->pessoaRepository->method('findOneByIdDoTenant')->willReturn($pessoa);
        $this->telefoneRepository->method('findOneByIdDoTenant')->willReturn($telefoneDeOutraPessoa);
        $this->telefoneRepository->expects($this->never())->method('salvar');

        $this->expectException(PessoaTelefoneNaoEncontradoException::class);

        $this->sut->executar($this->input(1, 20, '(41) 9999-9999'), $this->tenant);

        self::assertSame('(41) 3333-1111', $telefoneDeOutraPessoa->getNumero());
    }

    private function input(int $pessoaId, int $telefoneId, string $numero): EditarTelefonePessoaInput
    {
        $input = new EditarTelefonePessoaInput();
        $input->pessoaId = $pessoaId;
        $input->telefoneId = $telefoneId;
        $input->numero = $numero;

        return $input;
    }

    /** Monta a Pessoa e adiciona os telefones informados à SUA coleção (mesma instância). */
    private function pessoaComId(int $id, PessoaTelefone ...$telefones): Pessoa
    {
        $pessoa = (new Pessoa())->setTenant($this->tenant);
        $reflexao = new \ReflectionProperty(Pessoa::class, 'id');
        $reflexao->setValue($pessoa, $id);

        foreach ($telefones as $telefone) {
            $pessoa->adicionarTelefone($telefone);
        }

        return $pessoa;
    }
}
