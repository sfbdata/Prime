<?php

declare(strict_types=1);

namespace App\Tests\Produto\Functional;

use App\Entity\Tenant\Tenant;
use App\Produto\Entity\Contratacao;
use App\Produto\Enum\OrigemDaContratacao;
use App\Produto\Enum\Produto;
use App\Produto\Repository\ContratacaoRepository;
use App\Produto\Service\ProdutosDoEscritorio;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\NativeClock;

/**
 * Quais produtos valem para o escritório (spec multiproduto, §4): sem linha = todos; `completo` ativa = todos; outras
 * linhas ativas = só elas; só encerradas = nenhum. E a leitura é por escritório, com ou sem filtro de tenant.
 */
#[CoversClass(ProdutosDoEscritorio::class)]
#[CoversClass(ContratacaoRepository::class)]
#[CoversClass(Contratacao::class)]
final class ProdutosDoEscritorioTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    private function servico(): ProdutosDoEscritorio
    {
        // Ainda sem quem o injete (a guarda chega no PR 6), o container o remove: monta à mão com o repositório real.
        return new ProdutosDoEscritorio(static::getContainer()->get(ContratacaoRepository::class), new NativeClock());
    }

    private function escritorio(): Tenant
    {
        $tenant = (new Tenant())->setName('Produtos ' . uniqid());
        $this->em->persist($tenant);
        $this->em->flush();

        return $tenant;
    }

    private function ativar(Tenant $tenant, Produto $produto): Contratacao
    {
        $linha = Contratacao::ativar($tenant, $produto, OrigemDaContratacao::Dono, null, new \DateTimeImmutable('-1 hour'));
        $this->em->persist($linha);
        $this->em->flush();

        return $linha;
    }

    #[TestDox('Sem linha nenhuma, o escritório é completo: todos os produtos valem')]
    public function testSemLinhaEhCompleto(): void
    {
        $tenant = $this->escritorio();
        $servico = $this->servico();

        self::assertSame(Produto::cases(), $servico->doTenant($tenant));
        self::assertTrue($servico->ehCompleto($tenant));
        self::assertTrue($servico->inclui($tenant, Produto::Ponto));
    }

    #[TestDox('Com `completo` ativa, todos os produtos valem, mesmo com outra linha')]
    public function testCompletoAtiva(): void
    {
        $tenant = $this->escritorio();
        $this->ativar($tenant, Produto::Voto);
        $this->ativar($tenant, Produto::Completo);

        self::assertSame(Produto::cases(), $this->servico()->doTenant($tenant));
    }

    #[TestDox('Com outras linhas ativas, só esses produtos valem; a lista basta um')]
    public function testSoAsAtivas(): void
    {
        $tenant = $this->escritorio();
        $this->ativar($tenant, Produto::Voto);
        $this->ativar($tenant, Produto::Cobrancas);
        $servico = $this->servico();

        self::assertEqualsCanonicalizing([Produto::Voto, Produto::Cobrancas], $servico->doTenant($tenant));
        self::assertFalse($servico->ehCompleto($tenant));
        self::assertTrue($servico->inclui($tenant, Produto::Voto));
        self::assertFalse($servico->inclui($tenant, Produto::Expediente));
        self::assertTrue($servico->inclui($tenant, [Produto::Expediente, Produto::Cobrancas]), 'lista: basta um');
        self::assertFalse($servico->inclui($tenant, [Produto::Expediente, Produto::Ponto]));
    }

    #[TestDox('Só com linhas encerradas, nenhum produto vale (só o núcleo)')]
    public function testSoEncerradas(): void
    {
        $tenant = $this->escritorio();
        $linha = $this->ativar($tenant, Produto::Voto);
        $linha->encerrar(OrigemDaContratacao::Master, null, new \DateTimeImmutable());
        $this->em->flush();

        self::assertSame([], $this->servico()->doTenant($tenant));
    }

    #[TestDox('Linha ativa que ainda não começou não vale: com só ela, nenhum produto (só o núcleo)')]
    public function testAtivaQueAindaNaoComecou(): void
    {
        $tenant = $this->escritorio();
        $this->em->persist(Contratacao::ativar($tenant, Produto::Voto, OrigemDaContratacao::Master, null, new \DateTimeImmutable('+1 day')));
        $this->em->flush();

        self::assertSame([], $this->servico()->doTenant($tenant));
    }

    #[TestDox('Religar cria linha nova: a encerrada fica e o produto volta a valer')]
    public function testReligar(): void
    {
        $tenant = $this->escritorio();
        $this->ativar($tenant, Produto::Voto)->encerrar(OrigemDaContratacao::Dono, null, new \DateTimeImmutable());
        $this->em->flush();
        $this->ativar($tenant, Produto::Voto);

        self::assertCount(2, static::getContainer()->get(ContratacaoRepository::class)->todasDoTenant($tenant));
        self::assertSame([Produto::Voto], $this->servico()->doTenant($tenant));
    }

    #[TestDox('Um escritório não vê as linhas do outro; com o filtro ligado no escritório da sessão, a leitura do outro continua certa')]
    public function testIsolamentoEFiltro(): void
    {
        $daSessao = $this->escritorio();
        $outro = $this->escritorio();
        $this->ativar($daSessao, Produto::Ponto);
        $this->ativar($outro, Produto::Voto);
        $repositorio = static::getContainer()->get(ContratacaoRepository::class);

        $filtros = $this->em->getFilters();
        $filtros->enable('tenant')->setParameter('tenant', (int) $daSessao->getId(), Types::INTEGER);
        try {
            $doOutro = $repositorio->todasDoTenant($outro);
            $daSessaoLinhas = $repositorio->todasDoTenant($daSessao);

            self::assertSame([Produto::Voto], array_map(static fn (Contratacao $c): Produto => $c->getProduto(), $doOutro));
            self::assertSame([Produto::Ponto], array_map(static fn (Contratacao $c): Produto => $c->getProduto(), $daSessaoLinhas));
            self::assertTrue($filtros->isEnabled('tenant'), 'o filtro volta ligado depois da leitura');
        } finally {
            $filtros->disable('tenant');
        }
    }

    #[TestDox('A resposta fica em memória até o reset')]
    public function testMemoriaAteOReset(): void
    {
        $tenant = $this->escritorio();
        $servico = $this->servico();
        self::assertTrue($servico->ehCompleto($tenant));

        $this->ativar($tenant, Produto::Voto);

        self::assertTrue($servico->ehCompleto($tenant), 'dentro da requisição, a resposta não muda');
        $servico->reset();
        self::assertSame([Produto::Voto], $servico->doTenant($tenant));
    }

    #[TestDox('O índice único recusa duas linhas ativas do mesmo produto no mesmo escritório')]
    public function testIndiceUnicoRecusaDuasAtivas(): void
    {
        $tenant = $this->escritorio();
        $this->ativar($tenant, Produto::Voto);

        $this->expectException(UniqueConstraintViolationException::class);

        $this->ativar($tenant, Produto::Voto);
    }
}
