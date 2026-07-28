<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\DTO\ExcluirTelefonePessoaInput;
use App\Cobranca\Entity\Pessoa;
use App\Cobranca\Entity\PessoaTelefone;
use App\Cobranca\Exception\PessoaNaoEncontradaException;
use App\Cobranca\Exception\PessoaTelefoneNaoEncontradoException;
use App\Cobranca\Repository\PessoaRepository;
use App\Cobranca\Repository\PessoaTelefoneRepository;
use App\Cobranca\UseCase\ExcluirTelefonePessoaUseCase;
use App\Entity\Tenant\Tenant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(ExcluirTelefonePessoaUseCase::class)]
final class ExcluirTelefonePessoaUseCaseTest extends TestCase
{
    private PessoaTelefoneRepository&MockObject $telefoneRepository;
    private PessoaRepository&MockObject $pessoaRepository;
    private ExcluirTelefonePessoaUseCase $sut;
    // Tenant não é abstração do domínio: instância real, não mock.
    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->telefoneRepository = $this->createMock(PessoaTelefoneRepository::class);
        $this->pessoaRepository = $this->createMock(PessoaRepository::class);
        $this->sut = new ExcluirTelefonePessoaUseCase($this->telefoneRepository, $this->pessoaRepository);
        $this->tenant = new Tenant();
    }

    #[Test]
    public function excluirUmItemDoHistoricoNaoMexeNoAtual(): void
    {
        $atual = $this->telefone(10, '(41) 91111-1111', '2026-01-10', atual: true);
        $velho = $this->telefone(11, '(41) 3333-0000', '2026-01-01', atual: false);
        $pessoa = $this->pessoaComId(1, $velho, $atual);
        $pessoa->sincronizarTelefoneSombra('(41) 91111-1111');

        $this->pessoaRepository->method('findOneByIdDoTenant')->with(1, $this->tenant)->willReturn($pessoa);
        $this->telefoneRepository->method('findOneByIdDoTenant')->with(11, $this->tenant)->willReturn($velho);
        $this->telefoneRepository->expects($this->once())->method('remover')->with($velho, true);

        $promovido = $this->sut->executar($this->input(1, 11), $this->tenant);

        self::assertNull($promovido, 'excluir item do histórico não promove ninguém');
        self::assertCount(1, $pessoa->getTelefones(), 'o excluído sai da coleção em memória');
        self::assertTrue($atual->isAtual());
        self::assertSame('(41) 91111-1111', $pessoa->getTelefone(), 'a sombra continua no atual');
    }

    #[Test]
    public function excluirOAtualPromoveOMaisRecenteQueSobrou(): void
    {
        // Decisão do dono (2026-07-28): a lista nunca fica com telefones e nenhum atual.
        $atual = $this->telefone(10, '(41) 91111-1111', '2026-01-10', atual: true);
        $antigo = $this->telefone(11, '(41) 3333-0000', '2026-01-01', atual: false);
        $meio = $this->telefone(12, '(41) 3333-5555', '2026-01-05', atual: false);
        $pessoa = $this->pessoaComId(1, $antigo, $meio, $atual);
        $pessoa->sincronizarTelefoneSombra('(41) 91111-1111');

        $this->pessoaRepository->method('findOneByIdDoTenant')->willReturn($pessoa);
        $this->telefoneRepository->method('findOneByIdDoTenant')->willReturn($atual);
        $this->telefoneRepository->expects($this->once())->method('remover')->with($atual, true);

        $promovido = $this->sut->executar($this->input(1, 10), $this->tenant);

        self::assertSame($meio, $promovido, 'vence o mais recente DOS QUE SOBRARAM, não o mais antigo');
        self::assertTrue($meio->isAtual());
        self::assertFalse($antigo->isAtual());
        // SPEC §5.4: a leitura escalar acompanha a promoção no MESMO flush.
        self::assertSame('(41) 3333-5555', $pessoa->getTelefone());
    }

    #[Test]
    public function empateNoMesmoInstanteDesempataPeloCadastradoDepois(): void
    {
        // Importação em lote cria vários itens no mesmo segundo — sem desempate, o "mais recente"
        // seria o primeiro que a coleção devolvesse, e a promoção viraria loteria.
        $atual = $this->telefone(10, '(41) 91111-1111', '2026-01-10', atual: true);
        $mesmoInstanteA = $this->telefone(11, '(41) 3333-1111', '2026-01-01', atual: false);
        $mesmoInstanteB = $this->telefone(12, '(41) 3333-2222', '2026-01-01', atual: false);
        $pessoa = $this->pessoaComId(1, $mesmoInstanteB, $mesmoInstanteA, $atual);

        $this->pessoaRepository->method('findOneByIdDoTenant')->willReturn($pessoa);
        $this->telefoneRepository->method('findOneByIdDoTenant')->willReturn($atual);

        $promovido = $this->sut->executar($this->input(1, 10), $this->tenant);

        self::assertSame($mesmoInstanteB, $promovido, 'empate no instante: vence o id maior (cadastrado depois)');
    }

    #[Test]
    public function excluirOUltimoTelefoneZeraASombraDaPessoa(): void
    {
        // A pessoa fica de fato SEM telefone. Deixar a sombra com o número recém-apagado faria as
        // listagens seguirem mostrando um número que não existe mais em lugar nenhum.
        $unico = $this->telefone(10, '(41) 91111-1111', '2026-01-10', atual: true);
        $pessoa = $this->pessoaComId(1, $unico);
        $pessoa->sincronizarTelefoneSombra('(41) 91111-1111');

        $this->pessoaRepository->method('findOneByIdDoTenant')->willReturn($pessoa);
        $this->telefoneRepository->method('findOneByIdDoTenant')->willReturn($unico);
        $this->telefoneRepository->expects($this->once())->method('remover')->with($unico, true);

        $promovido = $this->sut->executar($this->input(1, 10), $this->tenant);

        self::assertNull($promovido);
        self::assertCount(0, $pessoa->getTelefones());
        self::assertNull($pessoa->getTelefone());
    }

    #[Test]
    public function excluirOAtualCorrigeDuplicidadePreExistenteDeAtual(): void
    {
        // Estado corrompido (janela de concorrência / duplo-submit): dois `atual = true`. A promoção
        // normaliza a lista inteira, como faz o MarcarTelefoneAtualUseCase.
        $atual = $this->telefone(10, '(41) 91111-1111', '2026-01-10', atual: true);
        $duplicado = $this->telefone(11, '(41) 3333-0000', '2026-01-01', atual: true);
        $recente = $this->telefone(12, '(41) 3333-5555', '2026-01-05', atual: false);
        $pessoa = $this->pessoaComId(1, $duplicado, $recente, $atual);

        $this->pessoaRepository->method('findOneByIdDoTenant')->willReturn($pessoa);
        $this->telefoneRepository->method('findOneByIdDoTenant')->willReturn($atual);

        $this->sut->executar($this->input(1, 10), $this->tenant);

        $atuais = array_filter(
            $pessoa->getTelefones()->toArray(),
            static fn (PessoaTelefone $t) => $t->isAtual(),
        );
        self::assertCount(1, $atuais, 'sobra exatamente um atual depois da exclusão');
        self::assertTrue($recente->isAtual());
        self::assertFalse($duplicado->isAtual());
    }

    #[Test]
    public function rejeitaPessoaDeOutroTenant(): void
    {
        $this->pessoaRepository->method('findOneByIdDoTenant')->willReturn(null);
        $this->telefoneRepository->expects($this->never())->method('findOneByIdDoTenant');
        $this->telefoneRepository->expects($this->never())->method('remover');

        $this->expectException(PessoaNaoEncontradaException::class);

        $this->sut->executar($this->input(999, 10), $this->tenant);
    }

    #[Test]
    public function rejeitaTelefoneDeOutroTenant(): void
    {
        $pessoa = $this->pessoaComId(1);
        $this->pessoaRepository->method('findOneByIdDoTenant')->willReturn($pessoa);
        $this->telefoneRepository->method('findOneByIdDoTenant')->willReturn(null);
        $this->telefoneRepository->expects($this->never())->method('remover');

        $this->expectException(PessoaTelefoneNaoEncontradoException::class);

        $this->sut->executar($this->input(1, 999), $this->tenant);
    }

    #[Test]
    public function rejeitaTelefoneQuePertenceAOutraPessoaDoMesmoTenant(): void
    {
        // Vazamento entre pessoas do MESMO escritório: o id existe e é do tenant, mas é de outra
        // ficha. Sem esta guarda, o gestor apagaria o telefone de quem não está na tela.
        $pessoa = $this->pessoaComId(1);
        $outraPessoa = $this->pessoaComId(2);
        $telefoneDeOutraPessoa = $this->telefone(30, '(41) 3333-1111', '2026-01-01', atual: true);
        $outraPessoa->adicionarTelefone($telefoneDeOutraPessoa);

        $this->pessoaRepository->method('findOneByIdDoTenant')->willReturn($pessoa);
        $this->telefoneRepository->method('findOneByIdDoTenant')->willReturn($telefoneDeOutraPessoa);
        $this->telefoneRepository->expects($this->never())->method('remover');

        $this->expectException(PessoaTelefoneNaoEncontradoException::class);

        $this->sut->executar($this->input(1, 30), $this->tenant);

        self::assertCount(1, $outraPessoa->getTelefones());
    }

    private function input(int $pessoaId, int $telefoneId): ExcluirTelefonePessoaInput
    {
        $input = new ExcluirTelefonePessoaInput();
        $input->pessoaId = $pessoaId;
        $input->telefoneId = $telefoneId;

        return $input;
    }

    /** Telefone com id e `criadoEm` controlados — a promoção depende dos dois. */
    private function telefone(int $id, string $numero, string $criadoEm, bool $atual): PessoaTelefone
    {
        $telefone = (new PessoaTelefone())->setTenant($this->tenant)->setNumero($numero)->setAtual($atual);

        (new \ReflectionProperty(PessoaTelefone::class, 'id'))->setValue($telefone, $id);
        (new \ReflectionProperty(PessoaTelefone::class, 'criadoEm'))
            ->setValue($telefone, new \DateTimeImmutable($criadoEm));

        return $telefone;
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
