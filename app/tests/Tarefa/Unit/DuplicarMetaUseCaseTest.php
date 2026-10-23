<?php

declare(strict_types=1);

namespace App\Tests\Tarefa\Unit;

use App\Entity\Auth\User;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Tarefa\Enum\PrioridadeDaMeta;
use App\Tarefa\Enum\TipoDeDemanda;
use App\Tarefa\Exception\MetaNaoDuplicavelException;
use App\Tarefa\Service\GravacaoDeMetaNova;
use App\Tarefa\UseCase\DuplicarMetaUseCase;
use App\Repository\UserRepository;
use App\Tenant\Service\ColaboradoresAtribuiveis;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * "Duplicar" no ⋮ da meta (desenho 02, D:3725 `duplicar`): a cópia leva descrição, prazo, responsáveis, prioridade e
 * tipo de demanda; o título ganha " (cópia)" dentro do limite de 120; nasce aberta, por quem duplicou, na mesma
 * pasta, e é gravada pela `GravacaoDeMetaNova` (o `GravarMetaNovaUseCase`, que dá o número do escritório). A
 * gravação é mockada pela interface; a numeração no banco é do `NumeracaoDeMetasTest` e a rota, do
 * `DuplicarMetaControllerTest`.
 */
#[CoversClass(DuplicarMetaUseCase::class)]
final class DuplicarMetaUseCaseTest extends TestCase
{
    private GravacaoDeMetaNova&MockObject $gravacao;
    private DuplicarMetaUseCase $useCase;
    private Tenant $tenant;
    private Pasta $pasta;
    private User $autor;
    private User $quemDuplica;
    /** @var list<object> */
    private array $gravados = [];
    /** @var list<bool> o `herdarPrioridadeDaPasta` de cada gravação */
    private array $herdou = [];
    /** @var array<int, User> quem a Nova meta aceitaria hoje (aprovado no escritório), por id */
    private array $aptos = [];

    protected function setUp(): void
    {
        $this->gravacao = $this->createMock(GravacaoDeMetaNova::class);
        $this->gravacao->method('executar')->willReturnCallback(function (Tarefa $t, bool $herdar = true): void {
            $this->gravados[] = $t;
            $this->herdou[]   = $herdar;
        });

        // O UserRepository é nosso: a regra "só aprovado no escritório" é a do ColaboradoresAtribuiveis real.
        $usuarios = $this->createMock(UserRepository::class);
        $usuarios->method('findColaboradorAtivoPorIdETenant')->willReturnCallback(fn (int $id): ?User => $this->aptos[$id] ?? null);

        $this->useCase = new DuplicarMetaUseCase($this->gravacao, new ColaboradoresAtribuiveis($usuarios));

        $this->tenant = new Tenant();
        (new \ReflectionProperty(Tenant::class, 'id'))->setValue($this->tenant, 7);
        $this->pasta = new Pasta();
        $this->pasta->setTenant($this->tenant);
        $this->autor       = $this->usuario(1, 'Ana Autora');
        $this->quemDuplica = $this->usuario(2, 'Bruno Duplica');
        $this->aptos       = [1 => $this->autor, 2 => $this->quemDuplica];
    }

    private function usuario(int $id, string $nome): User
    {
        $user = (new User())->setFullName($nome);
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, $id);

        return $user;
    }

    private function original(string $titulo = 'Protocolar recurso'): Tarefa
    {
        $meta = new Tarefa();
        $meta->setTitulo($titulo);
        $meta->setDescricao('Prazo de 15 dias úteis');
        $meta->setPrazo(new \DateTimeImmutable('2026-11-20'));
        $meta->setPrioridade(PrioridadeDaMeta::Urgente);
        $meta->setTipoDeDemanda(TipoDeDemanda::Consultoria);
        $meta->setStatus(Tarefa::STATUS_CONCLUIDA);
        $meta->setDataConclusao(new \DateTimeImmutable('-1 day'));
        $meta->setPasta($this->pasta);
        $meta->setTenant($this->tenant);
        $meta->setCriadoPor($this->autor);
        $meta->numerar(3);
        $meta->addResponsavel($this->autor);
        $meta->addResponsavel($this->quemDuplica);
        $this->pasta->getTarefas()->add($meta);

        return $meta;
    }

    private function copiaGravada(): Tarefa
    {
        self::assertCount(1, $this->gravados, 'uma meta nova gravada, e só ela');
        $copia = $this->gravados[0];
        self::assertInstanceOf(Tarefa::class, $copia);

        return $copia;
    }

    #[TestDox('a cópia leva descrição, prazo, responsáveis, prioridade e tipo de demanda; título com " (cópia)"')]
    public function testCopiaOsCampos(): void
    {
        $original = $this->original();

        $this->useCase->executar($original, $this->quemDuplica, $this->tenant);

        $copia = $this->copiaGravada();
        self::assertNotSame($original, $copia);
        self::assertSame('Protocolar recurso (cópia)', $copia->getTitulo());
        self::assertSame('Prazo de 15 dias úteis', $copia->getDescricao());
        self::assertSame('2026-11-20', $copia->getPrazo()?->format('Y-m-d'));
        self::assertSame([$this->autor, $this->quemDuplica], $copia->getResponsaveis()->toArray());
        self::assertSame(PrioridadeDaMeta::Urgente, $copia->getPrioridade());
        self::assertSame(TipoDeDemanda::Consultoria, $copia->getTipoDeDemanda());
        self::assertSame($this->pasta, $copia->getPasta());
        self::assertSame($this->tenant, $copia->getTenant());
        self::assertSame('Protocolar recurso', $original->getTitulo(), 'a original não muda');
    }

    #[TestDox('nasce aberta (pendente, sem data de conclusão), criada agora e por quem duplicou')]
    public function testNasceAbertaPorQuemDuplicou(): void
    {
        $antes = new \DateTimeImmutable();

        $this->useCase->executar($this->original(), $this->quemDuplica, $this->tenant);

        $copia = $this->copiaGravada();
        self::assertSame(Tarefa::STATUS_PENDENTE, $copia->getStatus());
        self::assertNull($copia->getDataConclusao());
        self::assertSame($this->quemDuplica, $copia->getCriadoPor());
        self::assertGreaterThanOrEqual($antes, $copia->getDataCriacao());
        self::assertNull($copia->getNumero(), 'o número é da gravação (GravarMetaNova), não da cópia: não herda o da original');
    }

    #[TestDox('a cópia entra na lista da pasta (a aba devolvida na mesma requisição a mostra), no fim')]
    public function testEntraNoFimDaListaDaPasta(): void
    {
        $original = $this->original();

        $this->useCase->executar($original, $this->quemDuplica, $this->tenant);

        self::assertSame([$original, $this->copiaGravada()], array_values($this->pasta->getTarefas()->toArray()));
    }

    /** @return iterable<string, array{string, string}> */
    public static function titulos(): iterable
    {
        yield 'curto' => ['Juntar procuração', 'Juntar procuração (cópia)'];
        yield 'espaços nas pontas' => ['  Juntar procuração  ', 'Juntar procuração (cópia)'];
        yield 'cabe exato (112)' => [str_repeat('a', 112), str_repeat('a', 112) . ' (cópia)'];
        yield 'corta o original (120)' => [str_repeat('é', 120), str_repeat('é', 112) . ' (cópia)'];
        yield 'corte sem espaço antes do sufixo' => [str_repeat('a', 111) . ' bbbb', str_repeat('a', 111) . ' (cópia)'];
    }

    #[TestDox('título da cópia: original + " (cópia)", cortado para caber em 120 caracteres')]
    #[DataProvider('titulos')]
    public function testTituloCabeNoLimite(string $original, string $esperado): void
    {
        $this->useCase->executar($this->original($original), $this->quemDuplica, $this->tenant);

        $titulo = $this->copiaGravada()->getTitulo();
        self::assertSame($esperado, $titulo);
        self::assertLessThanOrEqual(DuplicarMetaUseCase::TAMANHO_DO_TITULO, mb_strlen($titulo));
    }

    #[TestDox('pasta excluída (lápide): recusa e não grava nada')]
    public function testLapideRecusa(): void
    {
        $original = $this->original();
        $this->pasta->marcarExcluida($this->autor, new \DateTimeImmutable());
        $this->gravacao->expects(self::never())->method('executar');

        $this->expectException(MetaNaoDuplicavelException::class);
        try {
            $this->useCase->executar($original, $this->quemDuplica, $this->tenant);
        } finally {
            self::assertSame([], $this->gravados);
        }
    }

    #[TestDox('meta de outro escritório: segunda trava, nada é gravado')]
    public function testOutroEscritorioRecusa(): void
    {
        $outro = new Tenant();
        (new \ReflectionProperty(Tenant::class, 'id'))->setValue($outro, 8);
        $this->gravacao->expects(self::never())->method('executar');

        $this->expectException(\LogicException::class);
        $this->useCase->executar($this->original(), $this->quemDuplica, $outro);
    }

    #[TestDox('responsável suspenso (não aceito pela Nova meta) fica fora da cópia; continua na original')]
    public function testSuspensoFicaForaDaCopia(): void
    {
        $original = $this->original();
        unset($this->aptos[2]);

        $this->useCase->executar($original, $this->quemDuplica, $this->tenant);

        self::assertSame([$this->autor], array_values($this->copiaGravada()->getResponsaveis()->toArray()));
        self::assertCount(2, $original->getResponsaveis(), 'a original não muda');
    }

    #[TestDox('nenhum responsável apto: recusa com a mensagem para criar pela Nova meta, nada gravado')]
    public function testTodosInaptosRecusa(): void
    {
        $original    = $this->original();
        $this->aptos = [];
        $this->gravacao->expects(self::never())->method('executar');

        $this->expectException(MetaNaoDuplicavelException::class);
        $this->expectExceptionMessage('Nenhum responsável desta meta pode receber metas agora. Crie pela Nova meta.');
        $this->useCase->executar($original, $this->quemDuplica, $this->tenant);
    }

    #[TestDox('a prioridade vai exata, inclusive Normal: a gravação é pedida SEM herdar a da pasta')]
    public function testPrioridadeNormalSemHerdarDaPasta(): void
    {
        $original = $this->original();
        $original->setPrioridade(PrioridadeDaMeta::Normal);

        $this->useCase->executar($original, $this->quemDuplica, $this->tenant);

        self::assertSame(PrioridadeDaMeta::Normal, $this->copiaGravada()->getPrioridade());
        self::assertSame([false], $this->herdou);
    }
}
