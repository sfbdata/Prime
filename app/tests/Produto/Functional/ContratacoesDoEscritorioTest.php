<?php

declare(strict_types=1);

namespace App\Tests\Produto\Functional;

use App\Entity\Tenant\Tenant;
use App\Produto\Entity\Contratacao;
use App\Produto\Enum\OrigemDaContratacao;
use App\Produto\Enum\Produto;
use App\Produto\Enum\SituacaoDaContratacao;
use App\Produto\Repository\ContratacaoRepository;
use App\Produto\Service\ContratacoesDoEscritorio;
use App\Produto\Service\ProdutosDoEscritorio;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * As regras das linhas de `contratacao` ao ligar e desligar (spec docs/specs/multiproduto-contratacao-e-voto.md, §9, D5
 * e D16), contra o banco: o índice único parcial, o CHECK do período e a trava de verdade.
 */
#[CoversClass(ContratacoesDoEscritorio::class)]
final class ContratacoesDoEscritorioTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private ContratacoesDoEscritorio $servico;
    private \DateTimeImmutable $agora;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->servico = static::getContainer()->get(ContratacoesDoEscritorio::class);
        $this->agora = new \DateTimeImmutable('-1 minute');
    }

    private function escritorio(): Tenant
    {
        $tenant = (new Tenant())->setName('Contratações ' . uniqid());
        $this->em->persist($tenant);
        $this->em->flush();

        return $tenant;
    }

    private function ativar(Tenant $tenant, Produto $produto, OrigemDaContratacao $origem = OrigemDaContratacao::Dono): Contratacao
    {
        $linha = Contratacao::ativar($tenant, $produto, $origem, null, new \DateTimeImmutable('-1 hour'));
        $this->em->persist($linha);
        $this->em->flush();

        return $linha;
    }

    /** @return list<Contratacao> lidas do banco (o que a trava desfez não volta) */
    private function linhas(Tenant $tenant): array
    {
        return static::getContainer()->get(ContratacaoRepository::class)->todasDoTenant($tenant);
    }

    /** @return list<string> os produtos com linha ativa, em ordem alfabética */
    private function ativos(Tenant $tenant): array
    {
        $ativos = [];
        foreach ($this->linhas($tenant) as $linha) {
            if (SituacaoDaContratacao::Ativa === $linha->getSituacao()) {
                $ativos[] = $linha->getProduto()->value;
            }
        }
        sort($ativos);

        return $ativos;
    }

    /** @return list<string> as ferramentas menos as pedidas, em ordem alfabética */
    private static function ferramentasMenos(Produto ...$fora): array
    {
        $lista = array_map(static fn (Produto $p): string => $p->value, array_values(array_filter(Produto::ferramentas(), static fn (Produto $p): bool => !in_array($p, $fora, true))));
        sort($lista);

        return $lista;
    }

    #[TestDox('desligar num escritório sem linhas materializa (D5): uma linha ativa, origem dono, para cada ferramenta menos a escolhida')]
    public function testMaterializaSemLinhas(): void
    {
        $tenant = $this->escritorio();
        $produtos = static::getContainer()->get(ProdutosDoEscritorio::class);
        self::assertTrue($produtos->inclui($tenant, Produto::Ponto), 'sem linha: completo, e a resposta fica na memória');

        $this->servico->sobTrava($tenant, fn () => $this->servico->excluir($tenant, Produto::Ponto, OrigemDaContratacao::Dono, null, $this->agora));

        $linhas = $this->linhas($tenant);
        self::assertCount(7, $linhas, '8 ferramentas menos o Ponto; nenhuma linha do Ponto nem do completo');
        self::assertSame(self::ferramentasMenos(Produto::Ponto), $this->ativos($tenant));
        foreach ($linhas as $linha) {
            self::assertSame(OrigemDaContratacao::Dono, $linha->getOrigem());
        }
        self::assertFalse($produtos->inclui($tenant, Produto::Ponto), 'a memória foi limpa depois da trava');
        self::assertTrue($produtos->inclui($tenant, Produto::Voto));
        self::assertTrue($produtos->inclui($tenant, Produto::Ia));
    }

    #[TestDox('desligar com `completo` ativa: a `completo` é encerrada e as outras ferramentas ganham linha, sem duplicar a que já estava ativa')]
    public function testMaterializaAPartirDoCompleto(): void
    {
        $tenant = $this->escritorio();
        $this->ativar($tenant, Produto::Completo, OrigemDaContratacao::Master);
        $this->ativar($tenant, Produto::Voto, OrigemDaContratacao::Master);

        $this->servico->sobTrava($tenant, fn () => $this->servico->excluir($tenant, Produto::Kanban, OrigemDaContratacao::Dono, null, $this->agora));

        self::assertSame(self::ferramentasMenos(Produto::Kanban), $this->ativos($tenant));
        $completo = array_values(array_filter($this->linhas($tenant), static fn (Contratacao $c): bool => Produto::Completo === $c->getProduto()));
        self::assertCount(1, $completo);
        self::assertSame(SituacaoDaContratacao::Encerrada, $completo[0]->getSituacao());
    }

    #[TestDox('desligar com linhas: só encerra a do produto; desligar de novo não faz nada')]
    public function testDesligaComLinhas(): void
    {
        $tenant = $this->escritorio();
        $this->ativar($tenant, Produto::Voto);
        $this->ativar($tenant, Produto::Ponto);

        $this->servico->sobTrava($tenant, fn () => $this->servico->excluir($tenant, Produto::Ponto, OrigemDaContratacao::Dono, null, $this->agora));
        $this->servico->sobTrava($tenant, fn () => $this->servico->excluir($tenant, Produto::Ponto, OrigemDaContratacao::Dono, null, $this->agora));

        self::assertSame(['voto'], $this->ativos($tenant));
        self::assertCount(2, $this->linhas($tenant));
    }

    #[TestDox('ligar num escritório sem linhas não grava nada: ele já tem tudo')]
    public function testLigarSemLinhasNaoGrava(): void
    {
        $tenant = $this->escritorio();

        $this->servico->sobTrava($tenant, fn () => $this->servico->incluir($tenant, Produto::Voto, OrigemDaContratacao::Dono, null, $this->agora));

        self::assertSame([], $this->linhas($tenant));
    }

    #[TestDox('ligar com linhas grava a linha nova; com o produto já ativo ou com `completo` ativa, não grava')]
    public function testLigarComLinhas(): void
    {
        $tenant = $this->escritorio();
        $this->ativar($tenant, Produto::Voto);

        $this->servico->sobTrava($tenant, fn () => $this->servico->incluir($tenant, Produto::Ponto, OrigemDaContratacao::Dono, null, $this->agora));
        $this->servico->sobTrava($tenant, fn () => $this->servico->incluir($tenant, Produto::Voto, OrigemDaContratacao::Dono, null, $this->agora));

        self::assertSame(['ponto', 'voto'], $this->ativos($tenant));
        self::assertCount(2, $this->linhas($tenant));

        $completo = $this->escritorio();
        $this->ativar($completo, Produto::Completo, OrigemDaContratacao::Master);
        $this->servico->sobTrava($completo, fn () => $this->servico->incluir($completo, Produto::Ia, OrigemDaContratacao::Dono, null, $this->agora));
        self::assertCount(1, $this->linhas($completo));
    }

    #[TestDox('o Master desligando a linha ativa: ela fica encerrada com origem master e o produto fica travado (D16)')]
    public function testTravaDoMasterComLinhaAtiva(): void
    {
        $tenant = $this->escritorio();
        $this->ativar($tenant, Produto::Voto);
        $this->ativar($tenant, Produto::Ia);

        $this->servico->sobTrava($tenant, fn () => $this->servico->excluir($tenant, Produto::Voto, OrigemDaContratacao::Master, null, $this->agora));

        self::assertCount(2, $this->linhas($tenant), 'encerrou a linha que existia, sem marcador');
        self::assertTrue($this->servico->travadoPeloMaster($tenant, Produto::Voto), 'trava mesmo sem usuário Master (alterada_por nulo)');
        self::assertFalse($this->servico->travadoPeloMaster($tenant, Produto::Ia));
    }

    #[TestDox('o Master desligando produto já encerrado pelo dono grava o marcador (aberto e encerrado no mesmo instante); de novo, não duplica')]
    public function testMarcadorDoMaster(): void
    {
        $tenant = $this->escritorio();
        $this->ativar($tenant, Produto::Voto);
        $this->ativar($tenant, Produto::Ponto)->encerrar(OrigemDaContratacao::Dono, null, new \DateTimeImmutable('-30 minutes'));
        $this->em->flush();
        self::assertFalse($this->servico->travadoPeloMaster($tenant, Produto::Ponto), 'encerrada pelo dono não é trava');

        $this->servico->sobTrava($tenant, fn () => $this->servico->excluir($tenant, Produto::Ponto, OrigemDaContratacao::Master, null, $this->agora));
        $this->servico->sobTrava($tenant, fn () => $this->servico->excluir($tenant, Produto::Ponto, OrigemDaContratacao::Master, null, $this->agora));

        $doPonto = array_values(array_filter($this->linhas($tenant), static fn (Contratacao $c): bool => Produto::Ponto === $c->getProduto()));
        self::assertCount(2, $doPonto, 'a linha do dono e um marcador só');
        self::assertSame(OrigemDaContratacao::Master, $doPonto[1]->getOrigem());
        self::assertSame(SituacaoDaContratacao::Encerrada, $doPonto[1]->getSituacao());
        self::assertEquals($doPonto[1]->getInicio(), $doPonto[1]->getFim());
        self::assertTrue($this->servico->travadoPeloMaster($tenant, Produto::Ponto));
    }

    #[TestDox('o Master desligando num escritório sem linhas: materializa com origem master e trava o produto pelo marcador')]
    public function testMasterSemLinhas(): void
    {
        $tenant = $this->escritorio();

        $this->servico->sobTrava($tenant, fn () => $this->servico->excluir($tenant, Produto::Ia, OrigemDaContratacao::Master, null, $this->agora));

        self::assertSame(self::ferramentasMenos(Produto::Ia), $this->ativos($tenant));
        self::assertTrue($this->servico->travadoPeloMaster($tenant, Produto::Ia));
    }

    #[TestDox('o Master religando desfaz a trava: linha nova, ativa, origem master')]
    public function testMasterReligaDestrava(): void
    {
        $tenant = $this->escritorio();
        $this->ativar($tenant, Produto::Voto);
        $this->servico->sobTrava($tenant, fn () => $this->servico->excluir($tenant, Produto::Voto, OrigemDaContratacao::Master, null, $this->agora));

        $this->servico->sobTrava($tenant, fn () => $this->servico->incluir($tenant, Produto::Voto, OrigemDaContratacao::Master, null, $this->agora));

        self::assertFalse($this->servico->travadoPeloMaster($tenant, Produto::Voto));
        self::assertSame(['voto'], $this->ativos($tenant));
    }

    #[TestDox('um escritório não mexe nas linhas do outro')]
    public function testIsolamento(): void
    {
        $um = $this->escritorio();
        $outro = $this->escritorio();
        $this->ativar($outro, Produto::Voto);

        $this->servico->sobTrava($um, fn () => $this->servico->excluir($um, Produto::Voto, OrigemDaContratacao::Master, null, $this->agora));

        self::assertSame(['voto'], $this->ativos($outro));
        self::assertCount(1, $this->linhas($outro));
        self::assertFalse($this->servico->travadoPeloMaster($outro, Produto::Voto));
    }

    #[TestDox('erro dentro da trava desfaz tudo o que foi gravado nela e relança')]
    public function testErroDesfaz(): void
    {
        $tenant = $this->escritorio();

        try {
            $this->servico->sobTrava($tenant, function () use ($tenant): void {
                $this->servico->excluir($tenant, Produto::Voto, OrigemDaContratacao::Dono, null, $this->agora);

                throw new \DomainException('recusa de teste');
            });
            self::fail('deveria relançar');
        } catch (\DomainException $e) {
            self::assertSame('recusa de teste', $e->getMessage());
        }

        self::assertSame([], $this->linhas($tenant));
    }
}
