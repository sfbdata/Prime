<?php

declare(strict_types=1);

namespace App\Tests\Pagamento\Functional;

use App\Entity\Tenant\Tenant;
use App\Pagamento\DTO\ConfirmarPagamentoInput;
use App\Pagamento\DTO\CriarPagamentoInput;
use App\Pagamento\Enum\MetodoDePagamento;
use App\Pagamento\Enum\OrigemDoPagamento;
use App\Pagamento\Enum\ResultadoDaConfirmacao;
use App\Pagamento\Enum\StatusDoPagamento;
use App\Pagamento\Event\PagamentoConfirmado;
use App\Pagamento\Repository\PagamentoEventoRepository;
use App\Pagamento\Repository\PagamentoRepository;
use App\Pagamento\UseCase\ConfirmarPagamentoUseCase;
use App\Pagamento\UseCase\CriarPagamentoUseCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

#[CoversClass(CriarPagamentoUseCase::class)]
#[CoversClass(ConfirmarPagamentoUseCase::class)]
final class PagamentoUseCasesTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private CriarPagamentoUseCase $criar;
    private ConfirmarPagamentoUseCase $confirmar;
    /** @var list<PagamentoConfirmado> */
    private array $avisos = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $c = static::getContainer();
        $this->em = $c->get(EntityManagerInterface::class);
        $pagamentos = $c->get(PagamentoRepository::class);
        $eventos = $c->get(PagamentoEventoRepository::class);
        $this->criar = new CriarPagamentoUseCase($pagamentos, $eventos, $this->em);
        $this->confirmar = new ConfirmarPagamentoUseCase($pagamentos, $eventos, $this->em, $c->get(EventDispatcherInterface::class));
        $this->avisos = [];
        $c->get(EventDispatcherInterface::class)->addListener(
            PagamentoConfirmado::class,
            function (PagamentoConfirmado $e): void {
                $this->avisos[] = $e;
            },
        );
    }

    private function escritorio(): Tenant
    {
        $t = new Tenant();
        $t->setName('Tenant PAGAMENTO ' . uniqid());
        $this->em->persist($t);
        $this->em->flush();

        return $t;
    }

    private function cobrar(Tenant $t, string $referencia = null, int $valor = 150_000): string
    {
        $referencia ??= 'TE' . strtoupper(bin2hex(random_bytes(6)));
        $this->criar->executar(new CriarPagamentoInput(
            (int) $t->getId(),
            OrigemDoPagamento::TrabalhoExterno,
            42,
            $referencia,
            $valor,
            MetodoDePagamento::Pix,
            'cora',
            new \DateTimeImmutable('+24 hours'),
        ));

        return $referencia;
    }

    private function entrada(string $referencia, int $valor, ?string $e2e = 'E' . 'X1'): ConfirmarPagamentoInput
    {
        return new ConfirmarPagamentoInput($referencia, $valor, $e2e, new \DateTimeImmutable(), 'banco', 'Maria da Silva', '12345678909');
    }

    private function recarregar(string $referencia): \App\Pagamento\Entity\Pagamento
    {
        $this->em->clear();

        return static::getContainer()->get(PagamentoRepository::class)->porReferenciaDoAviso($referencia);
    }

    #[TestDox('Criar: grava a cobrança aguardando com o valor congelado e a trilha "criado"')]
    public function testCriar(): void
    {
        $t = $this->escritorio();
        $ref = $this->cobrar($t);

        $p = $this->recarregar($ref);
        self::assertSame(StatusDoPagamento::Aguardando, $p->getStatus());
        self::assertSame(150_000, $p->getValorCentavos());
        self::assertSame($t->getId(), $p->getTenant()->getId());
        $trilha = static::getContainer()->get(PagamentoEventoRepository::class)->doPagamento((int) $p->getId(), (int) $t->getId());
        self::assertSame(['criado'], array_map(static fn ($e) => $e->getTipo(), $trilha));
    }

    #[TestDox('Criar de novo com a mesma referência devolve a mesma cobrança e não muda o valor')]
    public function testCriarIdempotente(): void
    {
        $t = $this->escritorio();
        $ref = $this->cobrar($t, null, 150_000);

        $segunda = $this->criar->executar(new CriarPagamentoInput(
            (int) $t->getId(),
            OrigemDoPagamento::TrabalhoExterno,
            42,
            $ref,
            999_000,
            MetodoDePagamento::Pix,
            'cora',
        ));

        self::assertFalse($segunda->nova);
        self::assertSame(150_000, $this->recarregar($ref)->getValorCentavos());
    }

    #[TestDox('Referência já usada por outro escritório é recusada')]
    public function testReferenciaDeOutroEscritorio(): void
    {
        $ref = $this->cobrar($this->escritorio());

        $this->expectException(\DomainException::class);
        $this->criar->executar(new CriarPagamentoInput(
            (int) $this->escritorio()->getId(),
            OrigemDoPagamento::TrabalhoExterno,
            1,
            $ref,
            2000,
            MetodoDePagamento::Pix,
            'cora',
        ));
    }

    #[TestDox('Confirmar com o valor exato: paga, grava a trilha e avisa o consumidor uma vez')]
    public function testConfirmar(): void
    {
        $t = $this->escritorio();
        $ref = $this->cobrar($t);

        $saida = $this->confirmar->executar($this->entrada($ref, 150_000, 'E' . uniqid()));

        self::assertSame(ResultadoDaConfirmacao::Confirmado, $saida->resultado);
        $p = $this->recarregar($ref);
        self::assertSame(StatusDoPagamento::Paga, $p->getStatus());
        self::assertSame('8909', $p->getPagadorDocumentoFinal());
        self::assertCount(1, $this->avisos);
        self::assertSame($ref, $this->avisos[0]->referencia);
        self::assertSame(OrigemDoPagamento::TrabalhoExterno, $this->avisos[0]->origem);
        self::assertSame(42, $this->avisos[0]->origemId);
        $tipos = array_map(static fn ($e) => $e->getTipo(), static::getContainer()->get(PagamentoEventoRepository::class)->doPagamento((int) $p->getId(), (int) $t->getId()));
        self::assertSame(['criado', 'confirmado'], $tipos);
    }

    #[TestDox('Confirmar duas vezes não duplica o aviso nem a trilha')]
    public function testConfirmarDuasVezes(): void
    {
        $t = $this->escritorio();
        $ref = $this->cobrar($t);
        $e2e = 'E' . uniqid();
        $this->confirmar->executar($this->entrada($ref, 150_000, $e2e));

        $segunda = $this->confirmar->executar($this->entrada($ref, 150_000, $e2e));

        self::assertSame(ResultadoDaConfirmacao::JaConfirmado, $segunda->resultado);
        self::assertCount(1, $this->avisos);
        $p = $this->recarregar($ref);
        self::assertCount(2, static::getContainer()->get(PagamentoEventoRepository::class)->doPagamento((int) $p->getId(), (int) $t->getId()));
    }

    #[TestDox('Valor menor: divergente, NÃO avisa o consumidor')]
    public function testValorMenor(): void
    {
        $ref = $this->cobrar($this->escritorio());

        $saida = $this->confirmar->executar($this->entrada($ref, 100_000, 'E' . uniqid()));

        self::assertSame(ResultadoDaConfirmacao::ValorMenor, $saida->resultado);
        self::assertSame(StatusDoPagamento::Divergente, $this->recarregar($ref)->getStatus());
        self::assertSame([], $this->avisos);
    }

    #[TestDox('Valor maior: libera e o aviso carrega a diferença para o Master')]
    public function testValorMaior(): void
    {
        $ref = $this->cobrar($this->escritorio());

        $saida = $this->confirmar->executar($this->entrada($ref, 151_250, 'E' . uniqid()));

        self::assertSame(ResultadoDaConfirmacao::ConfirmadoComDiferenca, $saida->resultado);
        self::assertSame(1_250, $saida->diferencaCentavos);
        self::assertSame(1_250, $this->avisos[0]->diferencaCentavos);
    }

    #[TestDox('O mesmo end_to_end_id em outra cobrança não paga duas vezes')]
    public function testEndToEndRepetido(): void
    {
        $t = $this->escritorio();
        $e2e = 'E' . uniqid();
        $this->confirmar->executar($this->entrada($this->cobrar($t), 150_000, $e2e));
        $outra = $this->cobrar($t);

        $saida = $this->confirmar->executar($this->entrada($outra, 150_000, $e2e));

        self::assertSame(ResultadoDaConfirmacao::PagamentoRepetido, $saida->resultado);
        self::assertSame(StatusDoPagamento::Aguardando, $this->recarregar($outra)->getStatus());
        self::assertCount(1, $this->avisos);
    }

    #[TestDox('Referência que não existe: resultado NaoEncontrado, sem exceção')]
    public function testNaoEncontrado(): void
    {
        $saida = $this->confirmar->executar($this->entrada('NAO-EXISTE-' . uniqid(), 1000));

        self::assertSame(ResultadoDaConfirmacao::NaoEncontrado, $saida->resultado);
        self::assertNull($saida->pagamentoId);
    }

    #[TestDox('Com o filtro de escritório ligado em outro escritório, o aviso do banco ainda acha a cobrança pela referência')]
    public function testAvisoDoBancoComFiltroDeOutroEscritorio(): void
    {
        $ref = $this->cobrar($this->escritorio());
        $outro = $this->escritorio();
        $this->em->clear();
        $this->em->getFilters()->enable('tenant')->setParameter('tenant', $outro->getId(), \Doctrine\DBAL\Types\Types::INTEGER);

        // Documenta o contrato: o webhook roda sem escritório no contexto. Com filtro de OUTRO escritório ligado a
        // busca não acha: por isso o endpoint público nunca habilita o filtro.
        self::assertNull(static::getContainer()->get(PagamentoRepository::class)->porReferenciaDoAviso($ref));
        $this->em->getFilters()->disable('tenant');
        self::assertNotNull(static::getContainer()->get(PagamentoRepository::class)->porReferenciaDoAviso($ref));
    }
}
