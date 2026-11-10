<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Sede;
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
 * regra de "feita pelo colaborador" da `EscolhaDasBatidasDoDia`, e todos os setters travados depois do id (decisão 4: a
 * batida gravada só se insere; o gatilho do banco recusa qualquer UPDATE, decisão do Coordenador de 09/11).
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

    /** @return iterable<string, array{\Closure(RegistroPonto): mixed}> a chave é o nome do setter */
    public static function settersTravados(): iterable
    {
        yield 'setDataHora' => [static fn (RegistroPonto $b): RegistroPonto => $b->setDataHora(new \DateTime('2026-10-21 19:00:00'))];
        yield 'setTipo' => [static fn (RegistroPonto $b): RegistroPonto => $b->setTipo('entrada')];
        yield 'setUser' => [static fn (RegistroPonto $b): RegistroPonto => $b->setUser(new User())];
        yield 'setTenant' => [static fn (RegistroPonto $b): RegistroPonto => $b->setTenant(new Tenant())];
        yield 'setLatitude' => [static fn (RegistroPonto $b): RegistroPonto => $b->setLatitude('-23.5')];
        yield 'setLongitude' => [static fn (RegistroPonto $b): RegistroPonto => $b->setLongitude('-46.6')];
        yield 'setPrecisaoGps' => [static fn (RegistroPonto $b): RegistroPonto => $b->setPrecisaoGps('10')];
        yield 'setSede' => [static fn (RegistroPonto $b): RegistroPonto => $b->setSede(new Sede())];
        yield 'setSedeNomeSnapshot' => [static fn (RegistroPonto $b): RegistroPonto => $b->setSedeNomeSnapshot('Centro')];
        yield 'setObservacao' => [static fn (RegistroPonto $b): RegistroPonto => $b->setObservacao('nota')];
        yield 'setHomeOffice' => [static fn (RegistroPonto $b): RegistroPonto => $b->setHomeOffice(true)];
        yield 'setHoraAparelho' => [static fn (RegistroPonto $b): RegistroPonto => $b->setHoraAparelho(new \DateTimeImmutable('2026-10-21 18:00:00'))];
        yield 'setHoraChegada' => [static fn (RegistroPonto $b): RegistroPonto => $b->setHoraChegada(new \DateTimeImmutable('2026-10-21 18:00:00'))];
        yield 'setEnviadaSemInternet' => [static fn (RegistroPonto $b): RegistroPonto => $b->setEnviadaSemInternet(true)];
        yield 'setIdEnvio' => [static fn (RegistroPonto $b): RegistroPonto => $b->setIdEnvio('envio-1')];
        yield 'setNsr' => [static fn (RegistroPonto $b): RegistroPonto => $b->setNsr(7)];
        yield 'setTrabalhoExterno' => [static fn (RegistroPonto $b): RegistroPonto => $b->setTrabalhoExterno(true)];
        yield 'setMotivoTrabalhoExterno' => [static fn (RegistroPonto $b): RegistroPonto => $b->setMotivoTrabalhoExterno('Cliente')];
    }

    /** @param \Closure(RegistroPonto): mixed $mudar */
    #[DataProvider('settersTravados')]
    #[TestDox('Depois do id, o setter de $_dataName lança LogicException e não muda nada')]
    public function testSetterTravadoDepoisDoId(\Closure $mudar): void
    {
        $batida = $this->batidaGravada(31);
        $antes = $this->retrato($batida);

        try {
            $mudar($batida);
            self::fail('O setter mudou a batida gravada.');
        } catch (\LogicException $erro) {
            self::assertStringContainsString('Batida já gravada não muda', $erro->getMessage());
        }

        self::assertSame($antes, $this->retrato($batida));
    }

    #[TestDox('Todo setter da batida está na lista dos travados: setter novo (o setNsr da E15) entra travado')]
    public function testTodoSetterEstaNaListaDosTravados(): void
    {
        $setters = [];
        foreach ((new \ReflectionClass(RegistroPonto::class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $metodo) {
            if (str_starts_with($metodo->getName(), 'set')) {
                $setters[] = $metodo->getName();
            }
        }
        $travados = array_keys(iterator_to_array(self::settersTravados()));
        sort($setters);
        sort($travados);

        self::assertSame($setters, $travados);
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

    /** @return array<string, mixed> */
    private function retrato(RegistroPonto $batida): array
    {
        return [
            'tipo' => $batida->getTipo(),
            'dataHora' => $batida->getDataHora()?->format('Y-m-d H:i:s'),
            'user' => $batida->getUser(),
            'tenant' => $batida->getTenant(),
            'latitude' => $batida->getLatitude(),
            'longitude' => $batida->getLongitude(),
            'precisaoGps' => $batida->getPrecisaoGps(),
            'sede' => $batida->getSede(),
            'snapshot' => $batida->getSedeNomeSnapshot(),
            'observacao' => $batida->getObservacao(),
            'homeOffice' => $batida->isHomeOffice(),
            'horaAparelho' => $batida->getHoraAparelho(),
            'horaChegada' => $batida->getHoraChegada(),
            'semInternet' => $batida->isEnviadaSemInternet(),
            'idEnvio' => $batida->getIdEnvio(),
        ];
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
