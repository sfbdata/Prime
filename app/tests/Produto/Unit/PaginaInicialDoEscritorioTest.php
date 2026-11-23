<?php

declare(strict_types=1);

namespace App\Tests\Produto\Unit;

use App\Entity\Tenant\Tenant;
use App\Produto\Entity\Contratacao;
use App\Produto\Enum\OrigemDaContratacao;
use App\Produto\Enum\Produto;
use App\Produto\Repository\ContratacaoRepository;
use App\Produto\Service\PaginaInicialDoEscritorio;
use App\Produto\Service\ProdutosDoEscritorio;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * A primeira tela do escritório (spec multiproduto, §5, item 4): Expediente; depois Voto, Cobranças, Ponto, Cálculo,
 * Kanban e Assinatura; sem nenhum, o Meu Perfil. O completo continua no `expediente_index` de hoje.
 */
#[CoversClass(PaginaInicialDoEscritorio::class)]
final class PaginaInicialDoEscritorioTest extends TestCase
{
    private const AGORA = '2026-11-23 10:00:00';

    /**
     * @param list<Produto> $ativos
     * @param list<Produto> $encerrados
     */
    private function pagina(Tenant $tenant, array $ativos, array $encerrados = []): PaginaInicialDoEscritorio
    {
        $inicio = new \DateTimeImmutable('2026-01-01');
        $linhas = array_map(static fn (Produto $p): Contratacao => Contratacao::ativar($tenant, $p, OrigemDaContratacao::Dono, null, $inicio), $ativos);
        foreach ($encerrados as $produto) {
            $linha = Contratacao::ativar($tenant, $produto, OrigemDaContratacao::Dono, null, $inicio);
            $linha->encerrar(OrigemDaContratacao::Dono, null, $inicio->modify('+1 day'));
            $linhas[] = $linha;
        }
        $repositorio = $this->createStub(ContratacaoRepository::class);
        $repositorio->method('todasDoTenant')->willReturn($linhas);

        return new PaginaInicialDoEscritorio(new ProdutosDoEscritorio($repositorio, new MockClock(self::AGORA)));
    }

    private static function escritorio(): Tenant
    {
        $tenant = new Tenant();
        (new \ReflectionProperty($tenant, 'id'))->setValue($tenant, 7);

        return $tenant;
    }

    #[TestDox('Escritório completo (sem linha nenhuma, ou com `completo` ativa): a mesma rota fixa de hoje, expediente_index')]
    public function testCompletoVaiAoExpediente(): void
    {
        $tenant = self::escritorio();

        self::assertSame('expediente_index', $this->pagina($tenant, [])->rota($tenant));
        self::assertSame('expediente_index', $this->pagina($tenant, [Produto::Completo])->rota($tenant));
        self::assertSame('expediente_index', $this->pagina($tenant, [Produto::Voto, Produto::Completo])->rota($tenant));
    }

    /** @return iterable<string, array{list<Produto>, string}> */
    public static function combinacoes(): iterable
    {
        yield 'só Voto' => [[Produto::Voto], 'voto_index'];
        yield 'Voto e I.A.' => [[Produto::Ia, Produto::Voto], 'voto_index'];
        yield 'Expediente vence o Voto' => [[Produto::Voto, Produto::Expediente], 'expediente_index'];
        yield 'Voto vence Cobranças' => [[Produto::Cobrancas, Produto::Voto], 'voto_index'];
        yield 'Cobranças vence Ponto' => [[Produto::Ponto, Produto::Cobrancas], 'cobranca_carteira_index'];
        yield 'Ponto vence Cálculo' => [[Produto::Calculo, Produto::Ponto], 'ponto_index'];
        yield 'Cálculo vence Kanban' => [[Produto::Kanban, Produto::Calculo], 'calculo_index'];
        yield 'Kanban vence Assinatura' => [[Produto::Assinatura, Produto::Kanban], 'kanban_index'];
        yield 'só Assinatura' => [[Produto::Assinatura], 'assinatura_eletronica_index'];
        yield 'só I.A.: nenhum produto com tela inicial' => [[Produto::Ia], 'app_profile'];
    }

    /** @param list<Produto> $ativos */
    #[DataProvider('combinacoes')]
    #[TestDox('Ordem dos produtos: $_dataName')]
    public function testOrdem(array $ativos, string $rota): void
    {
        $tenant = self::escritorio();

        self::assertSame($rota, $this->pagina($tenant, $ativos)->rota($tenant));
    }

    #[TestDox('Só linhas encerradas (só o núcleo): o Meu Perfil')]
    public function testSoNucleo(): void
    {
        $tenant = self::escritorio();

        self::assertSame('app_profile', $this->pagina($tenant, [], [Produto::Voto])->rota($tenant));
    }

    #[TestDox('Sem escritório escolhido: a rota de hoje (expediente_index); com escritório, a do produto')]
    public function testRotaOuPadrao(): void
    {
        $tenant = self::escritorio();
        $pagina = $this->pagina($tenant, [Produto::Voto]);

        self::assertSame('expediente_index', $pagina->rotaOuPadrao(null));
        self::assertSame('voto_index', $pagina->rotaOuPadrao($tenant));
    }
}
