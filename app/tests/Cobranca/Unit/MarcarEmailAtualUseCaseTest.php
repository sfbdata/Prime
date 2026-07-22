<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\DTO\MarcarEmailAtualInput;
use App\Cobranca\Entity\Pessoa;
use App\Cobranca\Entity\PessoaEmail;
use App\Cobranca\Exception\PessoaEmailNaoEncontradoException;
use App\Cobranca\Exception\PessoaNaoEncontradaException;
use App\Cobranca\Repository\PessoaEmailRepository;
use App\Cobranca\Repository\PessoaRepository;
use App\Cobranca\UseCase\MarcarEmailAtualUseCase;
use App\Entity\Tenant\Tenant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(MarcarEmailAtualUseCase::class)]
final class MarcarEmailAtualUseCaseTest extends TestCase
{
    private PessoaEmailRepository&MockObject $emailRepository;
    private PessoaRepository&MockObject $pessoaRepository;
    private MarcarEmailAtualUseCase $sut;
    // Tenant não é abstração do domínio: instância real, não mock.
    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->emailRepository = $this->createMock(PessoaEmailRepository::class);
        $this->pessoaRepository = $this->createMock(PessoaRepository::class);
        $this->sut = new MarcarEmailAtualUseCase($this->emailRepository, $this->pessoaRepository);
        $this->tenant = new Tenant();
    }

    #[Test]
    public function marcarOutroTrocaAFlagEPreservaOAntigoEmUmUnicoFlush(): void
    {
        $anterior = (new PessoaEmail())->setTenant($this->tenant)->setAtual(true);
        $novo = (new PessoaEmail())->setTenant($this->tenant)->setAtual(false);
        // adicionarEmail() coloca os itens NA COLEÇÃO da pessoa (identidade compartilhada com o
        // que o repositório "encontra" abaixo) — é sobre essa coleção que o self-heal itera.
        $pessoa = $this->pessoaComId(1, $anterior, $novo);

        $this->pessoaRepository->method('findOneByIdDoTenant')->with(1, $this->tenant)->willReturn($pessoa);
        $this->emailRepository->method('findOneByIdDoTenant')->with(20, $this->tenant)->willReturn($novo);
        // Self-healing: buscarAtualDaPessoa não é mais consultado pelo UseCase.
        $this->emailRepository->expects($this->never())->method('buscarAtualDaPessoa');
        $this->emailRepository->expects($this->once())->method('salvar')->with($novo, true);

        $resultado = $this->sut->executar($this->input(1, 20), $this->tenant);

        self::assertSame($novo, $resultado);
        self::assertTrue($novo->isAtual());
        self::assertFalse($anterior->isAtual());
    }

    #[Test]
    public function marcarOItemQueJaEAtualPermaneceIdempotente(): void
    {
        $jaAtual = (new PessoaEmail())->setTenant($this->tenant)->setAtual(true);
        $pessoa = $this->pessoaComId(1, $jaAtual);

        $this->pessoaRepository->method('findOneByIdDoTenant')->willReturn($pessoa);
        $this->emailRepository->method('findOneByIdDoTenant')->willReturn($jaAtual);
        $this->emailRepository->expects($this->never())->method('buscarAtualDaPessoa');
        // A normalização sempre flusha (changeset vazio quando nada muda), preservando a
        // transação única mesmo no caso idempotente.
        $this->emailRepository->expects($this->once())->method('salvar')->with($jaAtual, true);

        $resultado = $this->sut->executar($this->input(1, 20), $this->tenant);

        self::assertSame($jaAtual, $resultado);
    }

    #[Test]
    public function marcarSelfHealCorrigeDuplicidadePreExistenteDeAtual(): void
    {
        // Estado corrompido (janela de concorrência / duplo-submit): DOIS e-mails já atuais.
        $duplicado1 = (new PessoaEmail())->setTenant($this->tenant)->setAtual(true);
        $duplicado2 = (new PessoaEmail())->setTenant($this->tenant)->setAtual(true);
        $alvo = (new PessoaEmail())->setTenant($this->tenant)->setAtual(false);
        $pessoa = $this->pessoaComId(1, $duplicado1, $duplicado2, $alvo);

        $this->pessoaRepository->method('findOneByIdDoTenant')->willReturn($pessoa);
        $this->emailRepository->method('findOneByIdDoTenant')->willReturn($alvo);
        $this->emailRepository->expects($this->once())->method('salvar')->with($alvo, true);

        $resultado = $this->sut->executar($this->input(1, 20), $this->tenant);

        self::assertSame($alvo, $resultado);
        self::assertTrue($alvo->isAtual());
        self::assertFalse($duplicado1->isAtual());
        self::assertFalse($duplicado2->isAtual());
        // Exatamente um atual na lista inteira após a normalização.
        $atuais = array_filter($pessoa->getEmails()->toArray(), static fn (PessoaEmail $e) => $e->isAtual());
        self::assertCount(1, $atuais);
    }

    #[Test]
    public function rejeitaPessoaDeOutroTenant(): void
    {
        $this->pessoaRepository->method('findOneByIdDoTenant')->willReturn(null);
        $this->emailRepository->expects($this->never())->method('findOneByIdDoTenant');

        $this->expectException(PessoaNaoEncontradaException::class);

        $this->sut->executar($this->input(999, 20), $this->tenant);
    }

    #[Test]
    public function rejeitaEmailDeOutroTenant(): void
    {
        $pessoa = $this->pessoaComId(1);
        $this->pessoaRepository->method('findOneByIdDoTenant')->willReturn($pessoa);
        $this->emailRepository->method('findOneByIdDoTenant')->willReturn(null);
        $this->emailRepository->expects($this->never())->method('salvar');

        $this->expectException(PessoaEmailNaoEncontradoException::class);

        $this->sut->executar($this->input(1, 999), $this->tenant);
    }

    #[Test]
    public function rejeitaEmailQuePertenceAOutraPessoaDoMesmoTenant(): void
    {
        $pessoa = $this->pessoaComId(1);
        $outraPessoa = $this->pessoaComId(2);
        $emailDeOutraPessoa = (new PessoaEmail())->setTenant($this->tenant);
        $outraPessoa->adicionarEmail($emailDeOutraPessoa);

        $this->pessoaRepository->method('findOneByIdDoTenant')->willReturn($pessoa);
        $this->emailRepository->method('findOneByIdDoTenant')->willReturn($emailDeOutraPessoa);
        $this->emailRepository->expects($this->never())->method('salvar');

        $this->expectException(PessoaEmailNaoEncontradoException::class);

        $this->sut->executar($this->input(1, 20), $this->tenant);
    }

    private function input(int $pessoaId, int $emailId): MarcarEmailAtualInput
    {
        $input = new MarcarEmailAtualInput();
        $input->pessoaId = $pessoaId;
        $input->emailId = $emailId;

        return $input;
    }

    /** Monta a Pessoa e adiciona os e-mails informados à SUA coleção (mesma instância). */
    private function pessoaComId(int $id, PessoaEmail ...$emails): Pessoa
    {
        $pessoa = (new Pessoa())->setTenant($this->tenant);
        $reflexao = new \ReflectionProperty(Pessoa::class, 'id');
        $reflexao->setValue($pessoa, $id);

        foreach ($emails as $email) {
            $pessoa->adicionarEmail($email);
        }

        return $pessoa;
    }
}
