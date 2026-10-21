<?php

declare(strict_types=1);

namespace App\Tests\Master\Unit;

use App\Entity\Auth\User;
use App\Master\DTO\AdicionarSubsecaoNaSeccionalInput;
use App\Master\Entity\SeccionalOab;
use App\Master\Entity\SubsecaoOab;
use App\Master\Exception\SeccionalNaoEncontrada;
use App\Master\Exception\SeccionalRecusada;
use App\Master\Repository\SeccionalOabRepository;
use App\Master\Repository\SubsecaoOabRepository;
use App\Master\UseCase\AdicionarSubsecaoNaSeccionalUseCase;
use App\Shared\Audit\EscopoDeAuditoriaDePlataforma;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * Adicionar subseção na janela da OAB (spec master-oab-estados-que-faltam, "SUBSEÇÕES"): nome (até 120) e
 * endereço (até 255) obrigatórios, telefone opcional (até 40); grava com autor e momento no escopo de plataforma.
 */
#[CoversClass(AdicionarSubsecaoNaSeccionalUseCase::class)]
#[CoversClass(AdicionarSubsecaoNaSeccionalInput::class)]
#[CoversClass(SubsecaoOab::class)]
final class AdicionarSubsecaoNaSeccionalUseCaseTest extends TestCase
{
    private SeccionalOabRepository&MockObject $seccionais;
    private SubsecaoOabRepository&MockObject $subsecoes;
    private EscopoDeAuditoriaDePlataforma $escopo;
    private SeccionalOab $seccional;
    private User $master;

    protected function setUp(): void
    {
        $this->seccionais = $this->createMock(SeccionalOabRepository::class);
        $this->subsecoes = $this->createMock(SubsecaoOabRepository::class);
        $this->escopo = new EscopoDeAuditoriaDePlataforma();
        $this->seccional = new SeccionalOab('BA', 'OAB Seccional Bahia');
        $this->master = (new User())->setRoles(['ROLE_SUPER_ADMIN']);
    }

    #[TestDox('grava a subseção aparada (telefone vazio vira nulo) com autor e momento, dentro do escopo de plataforma')]
    public function testAdiciona(): void
    {
        $this->seccionais->method('buscarPorUf')->with('BA')->willReturn($this->seccional);
        $this->subsecoes->expects($this->once())->method('salvar')->with(self::isInstanceOf(SubsecaoOab::class), true)
            ->willReturnCallback(fn () => self::assertTrue($this->escopo->estaLigado(), 'grava dentro do escopo de plataforma'));

        $subsecao = $this->useCase()->executar(AdicionarSubsecaoNaSeccionalInput::doJson($this->master, 'BA', ['nome' => ' Subseção de Feira de Santana ', 'endereco' => ' Rua Dois, 20 ', 'telefone' => ' ']));

        self::assertSame($this->seccional, $subsecao->getSeccional());
        self::assertSame('Subseção de Feira de Santana', $subsecao->getNome());
        self::assertSame('Rua Dois, 20', $subsecao->getEndereco());
        self::assertNull($subsecao->getTelefone());
        self::assertSame($this->master, $subsecao->getAutor());
        self::assertSame('2026-10-21 09:00', $subsecao->getCriadoEm()->format('Y-m-d H:i'));
        self::assertFalse($this->escopo->estaLigado());
    }

    /** @return iterable<string, array{array<string, string>, string}> */
    public static function recusas(): iterable
    {
        yield 'sem nome' => [['endereco' => 'Rua'], 'Informe o nome da subseção.'];
        yield 'nome só com espaços' => [['nome' => '   ', 'endereco' => 'Rua'], 'Informe o nome da subseção.'];
        yield 'sem endereço' => [['nome' => 'Subseção'], 'Informe o endereço da subseção.'];
        yield 'nome longo' => [['nome' => str_repeat('a', 121), 'endereco' => 'Rua'], 'O nome passa de 120 caracteres.'];
        yield 'endereço longo' => [['nome' => 'Subseção', 'endereco' => str_repeat('a', 256)], 'O endereço passa de 255 caracteres.'];
        yield 'telefone longo' => [['nome' => 'Subseção', 'endereco' => 'Rua', 'telefone' => str_repeat('9', 41)], 'O telefone passa de 40 caracteres.'];
    }

    /** @param array<string, string> $corpo */
    #[DataProvider('recusas')]
    #[TestDox('recusa $_dataName com a mensagem clara e não grava')]
    public function testRecusa(array $corpo, string $mensagem): void
    {
        $this->seccionais->method('buscarPorUf')->willReturn($this->seccional);
        $this->subsecoes->expects($this->never())->method('salvar');

        $this->expectException(SeccionalRecusada::class);
        $this->expectExceptionMessage($mensagem);
        $this->useCase()->executar(AdicionarSubsecaoNaSeccionalInput::doJson($this->master, 'BA', $corpo));
    }

    #[TestDox('limites exatos (120, 255 e 40) passam')]
    public function testLimitesExatos(): void
    {
        $this->seccionais->method('buscarPorUf')->willReturn($this->seccional);
        $this->subsecoes->expects($this->once())->method('salvar');

        $subsecao = $this->useCase()->executar(new AdicionarSubsecaoNaSeccionalInput($this->master, 'BA', str_repeat('á', 120), str_repeat('é', 255), str_repeat('9', 40)));

        self::assertSame(120, mb_strlen($subsecao->getNome()));
    }

    #[TestDox('linha da seccional ausente: SeccionalNaoEncontrada e nada é gravado')]
    public function testSemLinha(): void
    {
        $this->seccionais->method('buscarPorUf')->willReturn(null);
        $this->subsecoes->expects($this->never())->method('salvar');

        $this->expectException(SeccionalNaoEncontrada::class);
        $this->useCase()->executar(new AdicionarSubsecaoNaSeccionalInput($this->master, 'BA', 'Subseção', 'Rua'));
    }

    private function useCase(): AdicionarSubsecaoNaSeccionalUseCase
    {
        return new AdicionarSubsecaoNaSeccionalUseCase($this->seccionais, $this->subsecoes, new MockClock('2026-10-21 09:00:00'), $this->escopo);
    }
}
