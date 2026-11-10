<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\Entity\RegistroPonto;
use App\Ponto\Model\MarcacaoDoPonto;
use App\Ponto\Service\EscolhaDasBatidasDoDia;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * A batida gravada como `MarcacaoDoPonto` (E10, plano-e10-ponto §6): o alvo 'b{id}', sem cabeça nem ajuste, a mesma
 * regra de "feita pelo colaborador" da `EscolhaDasBatidasDoDia`, e os setters de data e hora, tipo, pessoa e escritório
 * travados depois do id (decisão 4: a batida gravada só se insere).
 */
#[CoversClass(RegistroPonto::class)]
final class RegistroPontoComoMarcacaoTest extends TestCase
{
    #[TestDox('Antes do id, os setters montam a batida como sempre')]
    public function testSettersLivresAntesDoId(): void
    {
        $batida = $this->batidaNova();

        self::assertSame('saida', $batida->getTipo());
        self::assertSame('2026-10-21 18:00:00', $batida->getDataHora()?->format('Y-m-d H:i:s'));
    }

    /** @return iterable<string, array{\Closure(RegistroPonto): mixed}> */
    public static function settersTravados(): iterable
    {
        yield 'data e hora' => [static fn (RegistroPonto $b): RegistroPonto => $b->setDataHora(new \DateTime('2026-10-21 19:00:00'))];
        yield 'tipo' => [static fn (RegistroPonto $b): RegistroPonto => $b->setTipo('entrada')];
        yield 'pessoa' => [static fn (RegistroPonto $b): RegistroPonto => $b->setUser(new User())];
        yield 'escritório' => [static fn (RegistroPonto $b): RegistroPonto => $b->setTenant(new Tenant())];
    }

    /** @param \Closure(RegistroPonto): mixed $mudar */
    #[DataProvider('settersTravados')]
    #[TestDox('Depois do id, o setter de $_dataName lança LogicException e não muda nada')]
    public function testSetterTravadoDepoisDoId(\Closure $mudar): void
    {
        $batida = $this->batidaGravada(31);
        $antes = [$batida->getTipo(), $batida->getDataHora()?->format('Y-m-d H:i:s'), $batida->getUser(), $batida->getTenant()];

        try {
            $mudar($batida);
            self::fail('O setter mudou a batida gravada.');
        } catch (\LogicException $erro) {
            self::assertStringContainsString('Batida já gravada não muda', $erro->getMessage());
        }

        self::assertSame($antes, [$batida->getTipo(), $batida->getDataHora()?->format('Y-m-d H:i:s'), $batida->getUser(), $batida->getTenant()]);
    }

    #[TestDox('Depois do id, as colunas livres (sede, snapshot, observação) seguem mudando')]
    public function testColunasLivresDepoisDoId(): void
    {
        $batida = $this->batidaGravada(31);

        $batida->setSede(null)->setSedeNomeSnapshot('Centro')->setObservacao('nota');

        self::assertSame(['Centro', 'nota'], [$batida->getSedeNomeSnapshot(), $batida->getObservacao()]);
    }

    #[TestDox('Como marcação: alvo b{id}, pessoa, sem cabeça e sem ajuste')]
    public function testComoMarcacao(): void
    {
        $batida = $this->batidaGravada(31);

        self::assertInstanceOf(MarcacaoDoPonto::class, $batida);
        self::assertSame(['b31', 5, null, null], [$batida->alvo(), $batida->pessoaId(), $batida->cabeca(), $batida->ajuste()]);
    }

    #[TestDox('Sem id não há alvo de ajuste: LogicException')]
    public function testAlvoSemId(): void
    {
        $semId = $this->batidaNova();
        $this->expectException(\LogicException::class);
        $semId->alvo();
    }

    #[TestDox('Sem pessoa gravada, pessoaId lança LogicException')]
    public function testPessoaIdSemPessoa(): void
    {
        $this->expectException(\LogicException::class);
        (new RegistroPonto())->pessoaId();
    }

    /** @return iterable<string, array{?string, ?string}> */
    public static function origens(): iterable
    {
        yield 'do colaborador, sem sede' => [null, null];
        yield 'do colaborador, na sede' => [null, 'Sede Centro'];
        yield 'lançamento manual do admin' => [null, RegistroPonto::SNAPSHOT_LANCAMENTO_MANUAL];
        yield 'aprovação de esquecimento (observação fixa)' => ['Registro aprovado por esquecimento', null];
        yield 'observação vazia' => ['', null];
        yield 'observação e lançamento manual' => ['x', RegistroPonto::SNAPSHOT_LANCAMENTO_MANUAL];
    }

    #[DataProvider('origens')]
    #[TestDox('feitaPeloColaborador dá o mesmo que EscolhaDasBatidasDoDia::ehDoColaborador ($_dataName)')]
    public function testFeitaPeloColaboradorIgualAEscolha(?string $observacao, ?string $snapshot): void
    {
        $batida = $this->batidaNova()->setObservacao($observacao)->setSedeNomeSnapshot($snapshot);
        $daEscolha = (new \ReflectionMethod(EscolhaDasBatidasDoDia::class, 'ehDoColaborador'))->invoke(new EscolhaDasBatidasDoDia(), $batida);

        self::assertSame($daEscolha, $batida->feitaPeloColaborador());
    }

    private function batidaNova(): RegistroPonto
    {
        return (new RegistroPonto())->setTipo('saida')->setDataHora(new \DateTime('2026-10-21 18:00:00'));
    }

    private function batidaGravada(int $id): RegistroPonto
    {
        $pessoa = new User();
        (new \ReflectionProperty(User::class, 'id'))->setValue($pessoa, 5);
        $batida = $this->batidaNova()->setUser($pessoa)->setTenant(new Tenant());
        (new \ReflectionProperty(RegistroPonto::class, 'id'))->setValue($batida, $id);

        return $batida;
    }
}
