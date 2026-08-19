<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Functional;

use App\Cobranca\DTO\ExpectativaDaLista;
use App\Cobranca\Entity\Acordo;
use App\Cobranca\Entity\Carteira;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\EventoHistorico;
use App\Cobranca\Entity\Obrigacao;
use App\Cobranca\Enum\BaseEncargo;
use App\Cobranca\Enum\FormaHonorarios;
use App\Cobranca\Enum\RegimeJuros;
use App\Cobranca\Enum\StatusAcordo;
use App\Cobranca\Exception\UniversoDaListaMudouException;
use App\Cobranca\UseCase\ImportarAcordosDetalhadosUseCase;
use App\Cobranca\UseCase\ReconciliarHonorarioDeParcelaUseCase;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Tests\Factory\Auth\UserFactory;
use App\Tests\Factory\Cobranca\AcordoFactory;
use App\Tests\Factory\Cobranca\CarteiraFactory;
use App\Tests\Factory\Cobranca\CasoCobrancaFactory;
use App\Tests\Factory\Cobranca\ObjetoCobrancaFactory;
use App\Tests\Factory\Cobranca\ObrigacaoFactory;
use App\Tests\Factory\Tenant\TenantFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * A correção das 135 contas reconstruídas — spec `cobranca-honorario-no-total.md` §10.5.
 *
 * 🔑 **O teste que mais importa aqui é o da POPULAÇÃO**, não o da subtração. Selecionar por
 * "parcela sem override" pegaria também a avulsa apenas VINCULADA a um acordo, cujo `valorOriginal`
 * NÃO contém o honorário — e zerá-la removeria cobrança legítima e mudaria a alocação da importação de
 * receitas. O que autoriza a correção é a MARCA DE PROCEDÊNCIA. Um teste que só conferisse
 * "honorário virou zero" ficaria verde com esse defeito.
 */
#[CoversClass(ReconciliarHonorarioDeParcelaUseCase::class)]
final class ReconciliarHonorarioDeParcelaTest extends KernelTestCase
{
    use Factories;

    private EntityManagerInterface $em;
    private ReconciliarHonorarioDeParcelaUseCase $reconciliar;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->reconciliar = static::getContainer()->get(ReconciliarHonorarioDeParcelaUseCase::class);
    }

    #[TestDox('Simulação acha a conta reconstruída, diz o que sairia e NÃO grava nada')]
    public function testSimulacaoNaoGrava(): void
    {
        $carteira = $this->carteiraTopLife();
        $caso = $this->caso($carteira, 'QD 05 CH 03');
        $reconstruida = $this->parcelaSemOverride($caso, '60049', honorarios: 3658);

        $r = $this->reconciliar->prever([$carteira], $this->tenantDe($carteira));

        self::assertFalse($r->aplicou);
        self::assertSame(1, $r->candidatas);
        self::assertCount(1, $r->corrigidas);
        self::assertSame(3658, $r->honorarioRemovidoEmCentavos());
        self::assertTrue($r->contasFecham());

        $this->em->refresh($reconstruida);
        self::assertSame(3658, $reconstruida->getHonorarios(), 'a SIMULAÇÃO não pode gravar');
        self::assertNull($reconstruida->getTaxaHonorariosBp(), 'a SIMULAÇÃO não pode gravar');
    }

    #[TestDox('Aplicar zera o honorário E grava o override, preservando juros, multa e a data do snapshot')]
    public function testAplicarZeraHonorarioEGravaOverride(): void
    {
        $carteira = $this->carteiraTopLife();
        $caso = $this->caso($carteira, 'QD 05 CH 03');
        $reconstruida = $this->parcelaSemOverride($caso, '60049', honorarios: 3658, juros: 1200, multa: 340);
        $usuario = $this->usuarioDo($carteira);

        $r = $this->reconciliar->confirmar([$carteira], $this->tenantDe($carteira), $usuario);

        self::assertTrue($r->aplicou);
        self::assertSame(3658, $r->honorarioRemovidoEmCentavos());

        $this->em->refresh($reconstruida);
        self::assertSame(0, $reconstruida->getHonorarios(), 'o honorário indevido tem de SAIR do campo');
        self::assertSame(0, $reconstruida->getTaxaHonorariosBp(), 'sem o override, a hidratação ao vivo o recoloca na próxima leitura da tela do acordo');

        // INV-H3: os outros encargos não são tocados — é outra pergunta, de outra natureza.
        self::assertSame(1200, $reconstruida->getJuros(), 'juros não entram nesta correção');
        self::assertSame(340, $reconstruida->getMulta(), 'multa não entra nesta correção');

        // INV-H2: a data do snapshot é PRESERVADA — a correção remove uma soma indevida de um snapshot
        // tirado na data do acordo; ela não recalcula nada.
        self::assertSame(
            '2026-06-30',
            $reconstruida->getEncargosAtualizadosEm()?->format('Y-m-d'),
            'recarimbar com hoje mentiria sobre a procedência do número e quebraria a régua',
        );
    }

    /**
     * 🔴 O teste que guarda os R$ 102.126,32. A conta original reconstruída tem a MESMA marca na
     * descrição e a MESMA origem das 135 — só o papel muda. Mirar na procedência em vez do papel foi o
     * erro da primeira versão desta fatia, e é este teste que o impede de voltar.
     */
    #[TestDox('NÃO toca a conta original reconstruída: dívida velha engolida cobra honorário')]
    public function testNaoTocaContaOriginalReconstruida(): void
    {
        $carteira = $this->carteiraTopLife();
        $caso = $this->caso($carteira, 'QD 05 CH 03');

        $velha = $this->contaOriginalReconstruida($caso, '70001', honorarios: 5000);

        $r = $this->reconciliar->prever([$carteira], $this->tenantDe($carteira));

        self::assertSame(0, $r->candidatas, 'dívida velha engolida NÃO é parcela — em produção são 3.473 assim, de propósito');
        self::assertSame(0, $r->honorarioRemovidoEmCentavos());

        $this->em->refresh($velha);
        self::assertSame(5000, $velha->getHonorarios(), 'apagar isto seria remover honorário que a carteira cobra com direito');
    }

    /**
     * ⚠️ Duas carteiras de escritórios diferentes NÃO provam nada aqui: a consulta já filtra por
     * `obj.carteira`, então esse teste passaria com o `andWhere` do tenant apagado — foi o furo que a
     * revisão apontou. O que exercita a cláusula do tenant é a obrigação cujo `tenant` DIVERGE do
     * escritório pedido, que é exatamente o vazamento que ela existe para barrar.
     */
    #[TestDox('Obrigação com tenant de OUTRO escritório não é alcançada, mesmo na carteira pedida')]
    public function testIsolamentoEntreEscritorios(): void
    {
        $minha = $this->carteiraTopLife();
        $caso = $this->caso($minha, 'QD 05 CH 03');
        $intrusa = $this->parcelaSemOverride($caso, '60049', honorarios: 9900);

        // Mesma carteira, mesmo caso — só o tenant da obrigação é de outro escritório.
        $intrusa->setTenant(TenantFactory::createOne()->_real());
        $this->em->flush();
        $this->em->clear();

        $r = $this->reconciliar->prever([$minha], $this->tenantDe($minha));

        self::assertSame(0, $r->candidatas, 'o TenantFilter não liga em console — o filtro tem de ser explícito na consulta');
        self::assertSame(0, $r->honorarioRemovidoEmCentavos());
    }

    #[TestDox('Congelada é PULADA e o honorário que fica sai no relatório, com valor')]
    public function testCongeladaEhPulada(): void
    {
        $carteira = $this->carteiraTopLife();
        $caso = $this->caso($carteira, 'QD 05 CH 03');
        $congelada = $this->parcelaSemOverride($caso, '60049', honorarios: 3658);
        $congelada->congelarEncargos(new \DateTimeImmutable('2026-06-30'));
        $this->em->flush();

        $r = $this->reconciliar->prever([$carteira], $this->tenantDe($carteira));

        self::assertSame(1, $r->candidatas);
        self::assertCount(0, $r->corrigidas);
        self::assertCount(1, $r->puladas);
        self::assertSame(3658, $r->honorarioQueFicouEmCentavos(), 'pular não é no-op: é dinheiro que fica inflado');
        self::assertTrue($r->contasFecham());
    }

    #[TestDox('Rodar de novo não acha mais nada (idempotente)')]
    public function testIdempotente(): void
    {
        $carteira = $this->carteiraTopLife();
        $this->parcelaSemOverride($this->caso($carteira, 'QD 05 CH 03'), '60049', honorarios: 3658);
        $usuario = $this->usuarioDo($carteira);

        $this->reconciliar->confirmar([$carteira], $this->tenantDe($carteira), $usuario);
        $segunda = $this->reconciliar->confirmar([$carteira], $this->tenantDe($carteira), $usuario);

        self::assertSame(0, $segunda->candidatas, 'com o override gravado a consulta não casa mais a obrigação');
    }

    #[TestDox('A correção fica no histórico do caso, com os NNs e o valor')]
    public function testRegistraNoHistorico(): void
    {
        $carteira = $this->carteiraTopLife();
        $caso = $this->caso($carteira, 'QD 05 CH 03');
        $this->parcelaSemOverride($caso, '60049', honorarios: 3658);

        $r = $this->reconciliar->confirmar([$carteira], $this->tenantDe($carteira), $this->usuarioDo($carteira));

        self::assertSame(1, $r->casosComEvento);

        $eventos = $this->em->getRepository(EventoHistorico::class)->findBy(['caso' => $caso]);
        $desta = array_values(array_filter(
            $eventos,
            static fn (EventoHistorico $e): bool => ($e->getDados()['origem'] ?? null) === 'reconciliacao_honorario_conta_reconstruida',
        ));

        self::assertCount(1, $desta, 'sem rastro, uma correção de dinheiro não tem como ser desfeita três meses depois');
        self::assertSame(3658, $desta[0]->getDados()['honorarioRemovidoCentavos']);
        self::assertSame(['60049'], $desta[0]->getDados()['referencias']);
    }

    #[TestDox('A trava da lista aprovada aborta e NÃO grava nada quando o universo mudou')]
    public function testTravaDaListaAprovadaNaoGrava(): void
    {
        $carteira = $this->carteiraTopLife();
        $reconstruida = $this->parcelaSemOverride($this->caso($carteira, 'QD 05 CH 03'), '60049', honorarios: 3658);
        $usuario = $this->usuarioDo($carteira);

        try {
            $this->reconciliar->confirmar(
                [$carteira],
                $this->tenantDe($carteira),
                $usuario,
                new ExpectativaDaLista(9, 999999),
            );
            self::fail('a trava tinha de abortar');
        } catch (UniversoDaListaMudouException $e) {
            self::assertSame(9, $e->dividasEsperadas);
            self::assertSame(1, $e->dividasEncontradas);
        }

        $this->em->clear();
        $depois = $this->em->getRepository(Obrigacao::class)->find($reconstruida->getId());
        self::assertNotNull($depois);
        self::assertSame(3658, $depois->getHonorarios(), 'a transação tem de ter desfeito TUDO');
        self::assertNull($depois->getTaxaHonorariosBp(), 'a transação tem de ter desfeito TUDO');
    }

    // ---------------------------------------------------------------------------------------------

    /**
     * A forma exata das 135 em produção: é PARCELA de um acordo (`acordoOrigem`) E foi substituída por
     * outro (`acordoSubstituto`), sem o override. A descrição carrega a marca porque em produção elas
     * nasceram da reconstrução — mas a marca NÃO é o que a seleciona; o `acordoOrigem` é.
     */
    private function parcelaSemOverride(CasoCobranca $caso, string $nn, int $honorarios, int $juros = 0, int $multa = 0): Obrigacao
    {
        return $this->obrigacaoDeAcordo(
            $caso,
            $nn,
            descricao: '1.1 - Taxa de condomínio — competência 01/2026 | '
                . ImportarAcordosDetalhadosUseCase::MARCA_RECONSTRUIDA . ' (emissão 29/07/2026)',
            honorarios: $honorarios,
            juros: $juros,
            multa: $multa,
            ehParcela: true,
        );
    }

    /**
     * A conta original RECONSTRUÍDA: mesma origem, mesma marca na descrição, mas papel DIFERENTE — é a
     * dívida velha que o acordo engoliu. Nasce só com `acordoSubstituto`, nunca com `acordoOrigem`.
     */
    private function contaOriginalReconstruida(CasoCobranca $caso, string $nn, int $honorarios): Obrigacao
    {
        return $this->obrigacaoDeAcordo(
            $caso,
            $nn,
            descricao: '1.1 - Taxa de condomínio — competência 01/2026 | '
                . ImportarAcordosDetalhadosUseCase::MARCA_RECONSTRUIDA . ' (emissão 29/07/2026)',
            honorarios: $honorarios,
            ehParcela: false,
        );
    }

    private function obrigacaoDeAcordo(
        CasoCobranca $caso,
        string $nn,
        string $descricao,
        int $honorarios,
        int $juros = 0,
        int $multa = 0,
        bool $ehParcela = true,
    ): Obrigacao {
        /** @var Obrigacao $obrigacao */
        $obrigacao = ObrigacaoFactory::createOne([
            'tenant' => $caso->getTenant(),
            'caso' => $caso,
            'descricao' => $descricao,
            'referenciaExterna' => $nn,
            'competencia' => '01/2026',
            'valorOriginal' => 17000,
            'vencimentoOriginal' => new \DateTimeImmutable('2026-01-13'),
        ])->_real();

        if ($ehParcela) {
            $obrigacao->setAcordoOrigem($this->acordo($caso));
        }
        $obrigacao->setAcordoSubstituto($this->acordo($caso));
        $obrigacao->definirEncargos($juros, $multa, 0, $honorarios, new \DateTimeImmutable('2026-06-30'));
        $this->em->flush();

        return $obrigacao;
    }

    private function acordo(CasoCobranca $caso): Acordo
    {
        /** @var Acordo $acordo */
        $acordo = AcordoFactory::createOne([
            'tenant' => $caso->getTenant(),
            'caso' => $caso,
            'status' => StatusAcordo::Cumprido,
            'dataAcordo' => new \DateTimeImmutable('2026-06-30'),
        ])->_real();

        return $acordo;
    }

    private function carteiraTopLife(): Carteira
    {
        return CarteiraFactory::createOne([
            'tenant' => TenantFactory::createOne(),
            'taxaJurosMensalBp' => 100,
            'regimeJuros' => RegimeJuros::Simples,
            'taxaMultaBp' => 200,
            'baseMulta' => BaseEncargo::Principal,
            'taxaCorrecaoBp' => 0,
            'baseCorrecao' => BaseEncargo::Principal,
            'baseHonorarios' => BaseEncargo::Composta,
            'formaHonorarios' => FormaHonorarios::AcrescidoDivida,
            'percentualHonorarios' => '20.00',
            'carenciaHonorariosDias' => 30,
            'toleranciaJurosMultaDias' => 0,
        ])->_real();
    }

    private function caso(Carteira $carteira, string $identificacao): CasoCobranca
    {
        $objeto = ObjetoCobrancaFactory::createOne([
            'tenant' => $carteira->getTenant(),
            'carteira' => $carteira,
            'identificacao' => $identificacao,
        ]);

        /** @var CasoCobranca $caso */
        $caso = CasoCobrancaFactory::createOne([
            'tenant' => $carteira->getTenant(),
            'objeto' => $objeto,
        ])->_real();

        return $caso;
    }

    private function tenantDe(Carteira $carteira): Tenant
    {
        $tenant = $carteira->getTenant();
        self::assertNotNull($tenant);

        return $tenant;
    }

    private function usuarioDo(Carteira $carteira): User
    {
        /** @var User $user */
        $user = UserFactory::createOne()->_real();

        // O vínculo usuário↔escritório mora em `UserTenant`; o `User` não carrega tenant.
        $this->em->persist(new UserTenant($user, $this->tenantDe($carteira)));
        $this->em->flush();

        return $user;
    }
}
