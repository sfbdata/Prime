<?php

declare(strict_types=1);

namespace App\Tests\Recompensa\Unit;

use App\Entity\Auth\User;
use App\Indicacao\Repository\IndicacaoRepository;
use App\Recompensa\DTO\AlterarRegrasDoProgramaInput;
use App\Recompensa\DTO\DefinirCondicaoIndividualInput;
use App\Recompensa\DTO\RemoverCondicaoIndividualInput;
use App\Recompensa\Entity\CondicaoDeRecompensa;
use App\Recompensa\Entity\ProgramaDeRecompensa;
use App\Recompensa\Exception\IndicadorNaoEncontrado;
use App\Recompensa\Exception\RecompensaRecusada;
use App\Recompensa\Repository\CondicaoDeRecompensaRepository;
use App\Recompensa\Repository\ProgramaDeRecompensaRepository;
use App\Recompensa\UseCase\AlterarRegrasDoProgramaUseCase;
use App\Recompensa\UseCase\DefinirCondicaoIndividualUseCase;
use App\Recompensa\UseCase\RemoverCondicaoIndividualUseCase;
use App\Shared\Audit\EscopoDeAuditoriaDePlataforma;
use App\Tests\Indicacao\Support\Pessoas;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

#[CoversClass(AlterarRegrasDoProgramaUseCase::class)]
#[CoversClass(DefinirCondicaoIndividualUseCase::class)]
#[CoversClass(RemoverCondicaoIndividualUseCase::class)]
final class UseCasesDeEscritaDeRecompensaTest extends TestCase
{
    private const AGORA = '2026-10-26 12:00:00';

    private MockClock $relogio;
    private EscopoDeAuditoriaDePlataforma $escopo;
    /** @var list<bool> o escopo de plataforma estava ligado em cada gravação */
    private array $escopoNaGravacao = [];
    private User $master;

    protected function setUp(): void
    {
        $this->relogio = new MockClock(new \DateTimeImmutable(self::AGORA));
        $this->escopo = new EscopoDeAuditoriaDePlataforma();
        $this->master = Pessoas::master();
    }

    private function regras(?bool $ativo = false, ?int $valor = 2500, ?int $min = 4, ?int $carencia = 15, ?int $alerta = 9, ?bool $pasta = false): AlterarRegrasDoProgramaInput
    {
        return new AlterarRegrasDoProgramaInput($this->master, $ativo, $valor, $min, $carencia, $alerta, $pasta);
    }

    private function repositorioDoPrograma(?ProgramaDeRecompensa $existente, ?ProgramaDeRecompensa &$salvo): ProgramaDeRecompensaRepository
    {
        $repo = $this->createStub(ProgramaDeRecompensaRepository::class);
        $repo->method('buscarUnico')->willReturn($existente);
        $repo->method('salvar')->willReturnCallback(function (ProgramaDeRecompensa $p, bool $flush) use (&$salvo): void {
            self::assertTrue($flush);
            $this->escopoNaGravacao[] = $this->escopo->estaLigado();
            $salvo = $p;
        });

        return $repo;
    }

    #[TestDox('regras: cria a linha única, grava as seis regras e quem alterou, no escopo de plataforma')]
    public function testAlteraRegrasCriandoALinha(): void
    {
        $salvo = null;
        (new AlterarRegrasDoProgramaUseCase($this->repositorioDoPrograma(null, $salvo), $this->relogio, $this->escopo))->executar($this->regras());

        self::assertInstanceOf(ProgramaDeRecompensa::class, $salvo);
        self::assertFalse($salvo->isAtivo());
        self::assertSame(2500, $salvo->getValorCentavos());
        self::assertSame(4, $salvo->getMinEscritorios());
        self::assertSame(15, $salvo->getCarenciaDias());
        self::assertSame(9, $salvo->getAlertaQtd());
        self::assertFalse($salvo->isExigePasta());
        self::assertSame($this->master, $salvo->getAlteradoPor());
        self::assertSame(self::AGORA, $salvo->getAlteradoEm()->format('Y-m-d H:i:s'));
        self::assertSame([true], $this->escopoNaGravacao);
        self::assertFalse($this->escopo->estaLigado());
    }

    #[TestDox('regras: atualiza a linha existente em vez de criar outra')]
    public function testAtualizaALinhaExistente(): void
    {
        $existente = new ProgramaDeRecompensa();
        $salvo = null;

        (new AlterarRegrasDoProgramaUseCase($this->repositorioDoPrograma($existente, $salvo), $this->relogio, $this->escopo))->executar($this->regras(valor: 0));

        self::assertSame($existente, $salvo);
        self::assertSame(0, $existente->getValorCentavos());
    }

    #[TestDox('regras: faltando uma ou fora do intervalo recusa e não grava')]
    public function testRegrasRecusadas(): void
    {
        foreach ([$this->regras(ativo: null), $this->regras(valor: null), $this->regras(min: 0), $this->regras(valor: -1), $this->regras(carencia: 99999)] as $i => $input) {
            $salvo = null;
            $caso = new AlterarRegrasDoProgramaUseCase($this->repositorioDoPrograma(null, $salvo), $this->relogio, $this->escopo);
            try {
                $caso->executar($input);
                self::fail('devia recusar o caso ' . $i);
            } catch (RecompensaRecusada) {
                self::assertNull($salvo, 'caso ' . $i);
            }
        }
    }

    private function definir(?CondicaoDeRecompensa $existente, int $indicacoes, ?CondicaoDeRecompensa &$salva, bool &$removida): DefinirCondicaoIndividualUseCase
    {
        $condicoes = $this->createStub(CondicaoDeRecompensaRepository::class);
        $condicoes->method('buscarDoIndicador')->willReturn($existente);
        $condicoes->method('salvar')->willReturnCallback(function (CondicaoDeRecompensa $c) use (&$salva): void {
            $this->escopoNaGravacao[] = $this->escopo->estaLigado();
            $salva = $c;
        });
        $condicoes->method('remover')->willReturnCallback(function () use (&$removida): void {
            $this->escopoNaGravacao[] = $this->escopo->estaLigado();
            $removida = true;
        });
        $indicador = $this->createStub(IndicacaoRepository::class);
        $indicador->method('contarDoUsuario')->willReturn($indicacoes);
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('find')->willReturnCallback(static fn (string $classe, int $id): ?User => $classe === User::class ? Pessoas::usuario($id) : null);

        return new DefinirCondicaoIndividualUseCase($condicoes, $indicador, $em, $this->relogio, $this->escopo);
    }

    private function condicao(int $userId = 7, ?int $valor = 3000, ?int $min = null, ?int $carencia = null, bool $rec = false, ?int $mensal = null): DefinirCondicaoIndividualInput
    {
        return new DefinirCondicaoIndividualInput($this->master, $userId, $valor, $min, $carencia, $rec, $mensal, 'Parceria');
    }

    #[TestDox('condição: cria a do indicador com os campos próprios, quem alterou e o motivo, no escopo de plataforma')]
    public function testDefineCondicao(): void
    {
        $salva = null;
        $removida = false;

        $this->definir(null, 3, $salva, $removida)->executar($this->condicao(rec: true, mensal: 200, min: 5));

        self::assertInstanceOf(CondicaoDeRecompensa::class, $salva);
        self::assertSame(7, $salva->getUserId());
        self::assertSame(3000, $salva->getValorCentavos());
        self::assertSame(5, $salva->getMinEscritorios());
        self::assertNull($salva->getCarenciaDias());
        self::assertTrue($salva->isRecorrente());
        self::assertSame(200, $salva->getMensalCentavos());
        self::assertSame('Parceria', $salva->getMotivo());
        self::assertSame($this->master, $salva->getAlteradaPor());
        self::assertSame([true], $this->escopoNaGravacao);
        self::assertFalse($removida);
    }

    #[TestDox('condição: atualiza a existente; sem nenhum campo próprio nem recorrência a condição é removida')]
    public function testCondicaoSemEfeitoERemovida(): void
    {
        $existente = new CondicaoDeRecompensa(Pessoas::usuario(7));
        $existente->definir(3000, null, null, false, null, null);
        $salva = null;
        $removida = false;

        $this->definir($existente, 3, $salva, $removida)->executar($this->condicao(valor: null));

        self::assertTrue($removida);
        self::assertNull($salva);
    }

    #[TestDox('condição: quem nunca indicou não é indicador (404) e nada é gravado')]
    public function testIndicadorInexistente(): void
    {
        $salva = null;
        $removida = false;

        try {
            $this->definir(null, 0, $salva, $removida)->executar($this->condicao());
            self::fail('devia lançar');
        } catch (IndicadorNaoEncontrado) {
            self::assertNull($salva);
            self::assertSame([], $this->escopoNaGravacao);
        }
    }

    #[TestDox('condição: valor negativo, recorrente sem mensal e "libera com" zero recusam sem gravar')]
    public function testCondicaoRecusada(): void
    {
        foreach ([$this->condicao(valor: -1), $this->condicao(rec: true), $this->condicao(min: 0)] as $i => $input) {
            $salva = null;
            $removida = false;
            try {
                $this->definir(null, 3, $salva, $removida)->executar($input);
                self::fail('devia recusar o caso ' . $i);
            } catch (RecompensaRecusada) {
                self::assertNull($salva, 'caso ' . $i);
                self::assertFalse($removida);
            }
        }
    }

    #[TestDox('voltar à regra geral: remove a condição existente no escopo de plataforma; sem condição não faz nada')]
    public function testRemove(): void
    {
        $existente = new CondicaoDeRecompensa(Pessoas::usuario(7));
        $removidas = [];
        $repo = $this->createStub(CondicaoDeRecompensaRepository::class);
        $repo->method('buscarDoIndicador')->willReturnCallback(static fn (int $id): ?CondicaoDeRecompensa => $id === 7 ? $existente : null);
        $repo->method('remover')->willReturnCallback(function (CondicaoDeRecompensa $c, bool $flush) use (&$removidas): void {
            self::assertTrue($flush);
            $this->escopoNaGravacao[] = $this->escopo->estaLigado();
            $removidas[] = $c;
        });
        $caso = new RemoverCondicaoIndividualUseCase($repo, $this->escopo);

        $caso->executar(new RemoverCondicaoIndividualInput($this->master, 8));
        self::assertSame([], $removidas);

        $caso->executar(new RemoverCondicaoIndividualInput($this->master, 7));
        self::assertSame([$existente], $removidas);
        self::assertSame([true], $this->escopoNaGravacao);
    }
}
