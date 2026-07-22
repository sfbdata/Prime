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
        $anterior = (new PessoaEndereco())->setTenant($this->tenant)->setAtual(true);
        $novo = (new PessoaEndereco())->setTenant($this->tenant)->setAtual(false);
        // adicionarEndereco() coloca os itens NA COLEÇÃO da pessoa (identidade compartilhada com
        // o que o repositório "encontra" abaixo) — é sobre essa coleção que o self-heal itera.
        $pessoa = $this->pessoaComId(1, $anterior, $novo);

        $this->pessoaRepository->method('findOneByIdDoTenant')->with(1, $this->tenant)->willReturn($pessoa);
        $this->enderecoRepository->method('findOneByIdDoTenant')->with(20, $this->tenant)->willReturn($novo);
        // Self-healing: buscarAtualDaPessoa não é mais consultado pelo UseCase.
        $this->enderecoRepository->expects($this->never())->method('buscarAtualDaPessoa');
        $this->enderecoRepository->expects($this->once())->method('salvar')->with($novo, true);

        $resultado = $this->sut->executar($this->input(1, 20), $this->tenant);

        self::assertSame($novo, $resultado);
        self::assertTrue($novo->isAtual());
        // O antigo PERMANECE na lista, só deixa de ser o atual — não é removido.
        self::assertFalse($anterior->isAtual());
    }

    #[Test]
    public function marcarOItemQueJaEAtualPermaneceIdempotente(): void
    {
        $jaAtual = (new PessoaEndereco())->setTenant($this->tenant)->setAtual(true);
        $pessoa = $this->pessoaComId(1, $jaAtual);

        $this->pessoaRepository->method('findOneByIdDoTenant')->willReturn($pessoa);
        $this->enderecoRepository->method('findOneByIdDoTenant')->willReturn($jaAtual);
        $this->enderecoRepository->expects($this->never())->method('buscarAtualDaPessoa');
        // A normalização sempre flusha (changeset vazio quando nada muda), preservando a
        // transação única mesmo no caso idempotente.
        $this->enderecoRepository->expects($this->once())->method('salvar')->with($jaAtual, true);

        $resultado = $this->sut->executar($this->input(1, 20), $this->tenant);

        self::assertSame($jaAtual, $resultado);
        self::assertTrue($resultado->isAtual());
    }

    #[Test]
    public function marcarSelfHealCorrigeDuplicidadePreExistenteDeAtual(): void
    {
        // Estado corrompido (janela de concorrência / duplo-submit): DOIS endereços já atuais.
        $duplicado1 = (new PessoaEndereco())->setTenant($this->tenant)->setAtual(true);
        $duplicado2 = (new PessoaEndereco())->setTenant($this->tenant)->setAtual(true);
        $alvo = (new PessoaEndereco())->setTenant($this->tenant)->setAtual(false);
        $pessoa = $this->pessoaComId(1, $duplicado1, $duplicado2, $alvo);

        $this->pessoaRepository->method('findOneByIdDoTenant')->willReturn($pessoa);
        $this->enderecoRepository->method('findOneByIdDoTenant')->willReturn($alvo);
        $this->enderecoRepository->expects($this->once())->method('salvar')->with($alvo, true);

        $resultado = $this->sut->executar($this->input(1, 20), $this->tenant);

        self::assertSame($alvo, $resultado);
        self::assertTrue($alvo->isAtual());
        self::assertFalse($duplicado1->isAtual());
        self::assertFalse($duplicado2->isAtual());
        // Exatamente um atual na lista inteira após a normalização.
        $atuais = array_filter($pessoa->getEnderecos()->toArray(), static fn (PessoaEndereco $e) => $e->isAtual());
        self::assertCount(1, $atuais);
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
        $enderecoDeOutraPessoa = (new PessoaEndereco())->setTenant($this->tenant);
        $outraPessoa->adicionarEndereco($enderecoDeOutraPessoa);

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

    /** Monta a Pessoa e adiciona os endereços informados à SUA coleção (mesma instância). */
    private function pessoaComId(int $id, PessoaEndereco ...$enderecos): Pessoa
    {
        $pessoa = (new Pessoa())->setTenant($this->tenant);
        $reflexao = new \ReflectionProperty(Pessoa::class, 'id');
        $reflexao->setValue($pessoa, $id);

        foreach ($enderecos as $endereco) {
            $pessoa->adicionarEndereco($endereco);
        }

        return $pessoa;
    }
}
