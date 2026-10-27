<?php

declare(strict_types=1);

namespace App\Tests\ServiceDesk\Functional;

use App\Entity\Tenant\Tenant;
use App\ServiceDesk\DTO\AbrirChamadoInput;
use App\ServiceDesk\DTO\ConfirmarRecargaInput;
use App\ServiceDesk\DTO\SolicitarRecargaInput;
use App\ServiceDesk\Entity\ChamadoDeSuporte;
use App\ServiceDesk\Entity\HistoricoDoChamado;
use App\ServiceDesk\Entity\MovimentoDeSaldo;
use App\ServiceDesk\Entity\PrecoDoAtendimento;
use App\ServiceDesk\Entity\SaldoDoEscritorio;
use App\ServiceDesk\Enum\FormaDeRecarga;
use App\ServiceDesk\Enum\PrioridadeDoChamado;
use App\ServiceDesk\Enum\StatusDoChamado;
use App\ServiceDesk\Enum\TipoDeChamado;
use App\ServiceDesk\Exception\ChamadoNaoEncontrado;
use App\ServiceDesk\Exception\SaldoInsuficiente;
use App\ServiceDesk\Pagamento\RecargaManual;
use App\ServiceDesk\Repository\ChamadoDeSuporteRepository;
use App\ServiceDesk\Repository\HistoricoDoChamadoRepository;
use App\ServiceDesk\Repository\MovimentoDeSaldoRepository;
use App\ServiceDesk\Repository\PrecoDoAtendimentoRepository;
use App\ServiceDesk\Repository\SaldoDoEscritorioRepository;
use App\ServiceDesk\Service\TransacaoDoRazaoNoDoctrine;
use App\ServiceDesk\UseCase\AbrirChamadoDeSuporteUseCase;
use App\ServiceDesk\UseCase\ConfirmarRecargaDeSaldoUseCase;
use App\ServiceDesk\UseCase\DefinirPrecoDoAtendimentoUseCase;
use App\ServiceDesk\UseCase\ListarChamadosDoEscritorioUseCase;
use App\ServiceDesk\UseCase\SolicitarRecargaDeSaldoUseCase;
use App\ServiceDesk\UseCase\VerChamadoDoEscritorioUseCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** Chamado de suporte ponta a ponta no banco: abrir com saldo, numerar, listar, filtrar, ver e isolar por escritório. */
#[CoversNothing]
final class ChamadosDoEscritorioTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    #[TestDox('sem saldo de R$ 20,00 não abre; com saldo abre, numera em sequência e não debita')]
    public function testAbrirExigeSaldoENumeraEmSequencia(): void
    {
        $tenant = $this->criarTenant();
        try {
            $this->abrir($tenant, 'Sem saldo');
            self::fail('Esperava SaldoInsuficiente');
        } catch (SaldoInsuficiente) {
        }
        $this->em->clear();
        $tenant = $this->em->find(Tenant::class, $tenant->getId());
        $this->darSaldo($tenant, 2_000);

        $primeiro = $this->abrir($tenant, 'Primeiro');
        $segundo = $this->abrir($tenant, 'Segundo');

        self::assertSame(1, $primeiro->numero);
        self::assertSame(2, $segundo->numero);
        self::assertSame(2_000, $this->saldos()->doEscritorio($tenant)->getSaldoCentavos(), 'abrir não debita');
        self::assertSame(2, $this->chamados()->count(['tenant' => $tenant]));
    }

    #[TestDox('o valor estimado vem do preço do Master e fica nulo sem preço')]
    public function testValorEstimadoVemDoPreco(): void
    {
        $tenant = $this->criarTenant();
        $this->darSaldo($tenant, 5_000);
        $semPreco = $this->abrir($tenant, 'Sem preço');
        (new DefinirPrecoDoAtendimentoUseCase($this->precos()))->executar(TipoDeChamado::Suporte, 2_500);
        $comPreco = $this->abrir($tenant, 'Com preço');

        self::assertNull($this->chamados()->find($semPreco->id)?->getValorEstimadoCentavos());
        self::assertSame(2_500, $this->chamados()->find($comPreco->id)?->getValorEstimadoCentavos());
    }

    #[TestDox('lista filtra por status e busca; os KPIs contam o escritório todo')]
    public function testListaFiltroEBusca(): void
    {
        $tenant = $this->criarTenant();
        $this->darSaldo($tenant, 2_000);
        $this->abrir($tenant, 'Impressora travada');
        $outro = $this->abrir($tenant, 'Treinar a equipe');
        $this->em->getConnection()->executeStatement("UPDATE service_desk_chamado SET status = 'andamento' WHERE id = ?", [$outro->id]);
        $this->em->clear();

        $todos = $this->listar()->executar($tenant);
        $andamento = $this->listar()->executar($tenant, StatusDoChamado::Andamento);
        $busca = $this->listar()->executar($tenant, null, 'impressora');
        $porNumero = $this->listar()->executar($tenant, null, '#0002');
        $numeroGigante = $this->listar()->executar($tenant, null, '99999999999999');

        self::assertCount(2, $todos->chamados);
        self::assertSame(2, $todos->totalDoEscritorio);
        self::assertCount(1, $andamento->chamados);
        self::assertSame(2, $andamento->totalDoEscritorio, 'KPI conta tudo, mesmo filtrando');
        self::assertSame(1, $andamento->quantosEm(StatusDoChamado::Andamento));
        self::assertSame(1, $andamento->quantosEm(StatusDoChamado::Aberto));
        self::assertSame('Impressora travada', $busca->chamados[0]->assunto);
        self::assertSame('Treinar a equipe', $porNumero->chamados[0]->assunto);
        self::assertSame([], $numeroGigante->chamados, 'número gigante não estoura o inteiro da coluna');
    }

    #[TestDox('ver mostra o andamento e chamado de outro escritório é não encontrado')]
    public function testVerEIsolamento(): void
    {
        $a = $this->criarTenant();
        $b = $this->criarTenant();
        $this->darSaldo($a, 2_000);
        $aberto = $this->abrir($a, 'Do escritório A');
        $this->em->clear();

        $detalhe = (new VerChamadoDoEscritorioUseCase($this->chamados(), $this->historicos()))->executar($a, $aberto->id);

        self::assertSame('Do escritório A', $detalhe->resumo->assunto);
        self::assertSame('Chamado aberto por usuário', $detalhe->andamento[0]->texto);
        self::assertSame([], $this->listar()->executar($b)->chamados);
        $this->expectException(ChamadoNaoEncontrado::class);
        (new VerChamadoDoEscritorioUseCase($this->chamados(), $this->historicos()))->executar($b, $aberto->id);
    }

    private function abrir(Tenant $tenant, string $assunto): \App\ServiceDesk\DTO\ChamadoAbertoOutput
    {
        return (new AbrirChamadoDeSuporteUseCase(
            $this->chamados(),
            $this->historicos(),
            $this->precos(),
            $this->saldos(),
            new TransacaoDoRazaoNoDoctrine($this->em),
        ))->executar(new AbrirChamadoInput($tenant, null, TipoDeChamado::Suporte, PrioridadeDoChamado::Media, $assunto, ''));
    }

    private function darSaldo(Tenant $tenant, int $centavos): void
    {
        $referencia = (new SolicitarRecargaDeSaldoUseCase($this->movimentos(), new RecargaManual()))
            ->executar(new SolicitarRecargaInput($tenant, null, $centavos, FormaDeRecarga::Pix))->referencia;
        (new ConfirmarRecargaDeSaldoUseCase($this->movimentos(), $this->saldos(), new TransacaoDoRazaoNoDoctrine($this->em)))
            ->executar(new ConfirmarRecargaInput($referencia, $centavos));
    }

    private function listar(): ListarChamadosDoEscritorioUseCase
    {
        return new ListarChamadosDoEscritorioUseCase($this->chamados());
    }

    private function chamados(): ChamadoDeSuporteRepository
    {
        return $this->em->getRepository(ChamadoDeSuporte::class);
    }

    private function historicos(): HistoricoDoChamadoRepository
    {
        return $this->em->getRepository(HistoricoDoChamado::class);
    }

    private function precos(): PrecoDoAtendimentoRepository
    {
        return $this->em->getRepository(PrecoDoAtendimento::class);
    }

    private function movimentos(): MovimentoDeSaldoRepository
    {
        return $this->em->getRepository(MovimentoDeSaldo::class);
    }

    private function saldos(): SaldoDoEscritorioRepository
    {
        return $this->em->getRepository(SaldoDoEscritorio::class);
    }

    private function criarTenant(): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName('Tenant ' . uniqid());
        $this->em->persist($tenant);
        $this->em->flush();

        return $tenant;
    }
}
