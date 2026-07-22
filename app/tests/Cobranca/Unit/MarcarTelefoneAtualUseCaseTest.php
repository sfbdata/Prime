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
        $anterior = (new PessoaTelefone())->setTenant($this->tenant)->setNumero('(41) 91111-1111')->setAtual(true);
        $novo = (new PessoaTelefone())->setTenant($this->tenant)->setNumero('(41) 92222-2222')->setAtual(false);
        // adicionarTelefone() coloca os itens NA COLEÇÃO da pessoa (identidade compartilhada com
        // o que o repositório "encontra" abaixo) — é sobre essa coleção que o self-heal itera.
        $pessoa = $this->pessoaComId(1, $anterior, $novo);
        // Sombra pré-existente, sincronizada com o ANTIGO atual (estado antes da troca).
        $pessoa->sincronizarTelefoneSombra('(41) 91111-1111');

        $this->pessoaRepository->method('findOneByIdDoTenant')->with(1, $this->tenant)->willReturn($pessoa);
        $this->telefoneRepository->method('findOneByIdDoTenant')->with(20, $this->tenant)->willReturn($novo);
        // Self-healing: buscarAtualDaPessoa não é mais consultado pelo UseCase.
        $this->telefoneRepository->expects($this->never())->method('buscarAtualDaPessoa');
        $this->telefoneRepository->expects($this->once())->method('salvar')->with($novo, true);

        $resultado = $this->sut->executar($this->input(1, 20), $this->tenant);

        self::assertSame($novo, $resultado);
        self::assertTrue($novo->isAtual());
        self::assertFalse($anterior->isAtual());
        // SPEC §5.4: a sombra da pessoa segue o NOVO atual, não o antigo.
        self::assertSame('(41) 92222-2222', $pessoa->getTelefone());
    }

    #[Test]
    public function marcarOItemQueJaEAtualPermaneceIdempotente(): void
    {
        $jaAtual = (new PessoaTelefone())->setTenant($this->tenant)->setAtual(true);
        $pessoa = $this->pessoaComId(1, $jaAtual);

        $this->pessoaRepository->method('findOneByIdDoTenant')->willReturn($pessoa);
        $this->telefoneRepository->method('findOneByIdDoTenant')->willReturn($jaAtual);
        $this->telefoneRepository->expects($this->never())->method('buscarAtualDaPessoa');
        // A normalização sempre flusha (changeset vazio quando nada muda), preservando a
        // transação única mesmo no caso idempotente.
        $this->telefoneRepository->expects($this->once())->method('salvar')->with($jaAtual, true);

        $resultado = $this->sut->executar($this->input(1, 20), $this->tenant);

        self::assertSame($jaAtual, $resultado);
        self::assertTrue($resultado->isAtual());
    }

    #[Test]
    public function marcarSelfHealCorrigeDuplicidadePreExistenteDeAtual(): void
    {
        // Estado corrompido (janela de concorrência / duplo-submit): DOIS telefones já atuais.
        $duplicado1 = (new PessoaTelefone())->setTenant($this->tenant)->setAtual(true);
        $duplicado2 = (new PessoaTelefone())->setTenant($this->tenant)->setAtual(true);
        $alvo = (new PessoaTelefone())->setTenant($this->tenant)->setAtual(false);
        $pessoa = $this->pessoaComId(1, $duplicado1, $duplicado2, $alvo);

        $this->pessoaRepository->method('findOneByIdDoTenant')->willReturn($pessoa);
        $this->telefoneRepository->method('findOneByIdDoTenant')->willReturn($alvo);
        $this->telefoneRepository->expects($this->once())->method('salvar')->with($alvo, true);

        $resultado = $this->sut->executar($this->input(1, 20), $this->tenant);

        self::assertSame($alvo, $resultado);
        self::assertTrue($alvo->isAtual());
        self::assertFalse($duplicado1->isAtual());
        self::assertFalse($duplicado2->isAtual());
        // Exatamente um atual na lista inteira após a normalização.
        $atuais = array_filter(
            $pessoa->getTelefones()->toArray(),
            static fn (PessoaTelefone $t) => $t->isAtual(),
        );
        self::assertCount(1, $atuais);
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
        $telefoneDeOutraPessoa = (new PessoaTelefone())->setTenant($this->tenant);
        $outraPessoa->adicionarTelefone($telefoneDeOutraPessoa);

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
