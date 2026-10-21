<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\Contexto\MontadorDeContextoDaPasta;
use App\Inteligencia\Enum\Agente;
use App\Inteligencia\Service\VisibilidadeDoFinanceiroDaPasta;
use App\Pasta\Entity\AbaDaPasta;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaMensagem;
use App\Pasta\Service\PastaTimelineAssembler;
use App\Pasta\Service\VisibilidadeDasAbasDaPasta;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Os cartões do sistema de pró-bono e de contrato são dado financeiro (regra de
 * `VisibilidadeDoFinanceiroDaPasta`): para quem tem a aba Financeiro oculta, somem do Registro da aba Dados e do
 * contexto que vai para a I.A.; com o Financeiro visível, aparecem. Vale também para o cartão de contrato que o
 * andamento já gravava. O cartão comum do sistema (fase) segue visível nos dois casos.
 */
#[CoversClass(PastaTimelineAssembler::class)]
#[CoversClass(VisibilidadeDasAbasDaPasta::class)]
#[CoversClass(MontadorDeContextoDaPasta::class)]
final class PastaCartoesFinanceirosVisibilidadeTest extends JusPrimeWebTestCase
{
    use CriaCenarioDeAbaOcultaTrait;

    private Tenant $tenant;
    private User $primario;
    private User $ana;
    private Pasta $pasta;
    /** @var array<string, PastaMensagem> */
    private array $cartoes = [];

    #[TestDox('Financeiro oculto: os cartões de pró-bono e de contrato (inclusive o do andamento) somem da aba Dados; o de fase fica')]
    public function testFinanceiroOcultoNaAbaDados(): void
    {
        $this->cenario();
        $this->ocultarAba($this->pasta, AbaDaPasta::Financeiro, $this->primario, [$this->ana]);

        $c = $this->abrirComo($this->ana);

        self::assertCount(0, $c->filter('#pasta-msg-' . $this->cartoes['probono']->getId()));
        self::assertCount(0, $c->filter('#pasta-msg-' . $this->cartoes['contrato']->getId()));
        self::assertCount(0, $c->filter('#pasta-msg-' . $this->cartoes['contrato_andamento']->getId()));
        self::assertCount(1, $c->filter('#pasta-msg-' . $this->cartoes['fase']->getId() . ' > .ps-anotacao-corpo > i.bi-signpost-split'));
    }

    #[TestDox('Financeiro visível: os cartões de pró-bono e de contrato aparecem na aba Dados')]
    public function testFinanceiroVisivelNaAbaDados(): void
    {
        $this->cenario();

        $c = $this->abrirComo($this->ana);

        self::assertCount(1, $c->filter('#pasta-msg-' . $this->cartoes['probono']->getId() . ' > .ps-anotacao-corpo > i.bi-heart'));
        self::assertCount(1, $c->filter('#pasta-msg-' . $this->cartoes['contrato']->getId() . ' > .ps-anotacao-corpo > i.bi-pen'));
        self::assertCount(1, $c->filter('#pasta-msg-' . $this->cartoes['contrato_andamento']->getId() . ' > .ps-anotacao-corpo > i.bi-pen'));
    }

    #[TestDox('pedido da I.A.: sem o financeiro (aba oculta), as anotações não levam pró-bono nem contrato; com ele, levam')]
    public function testContextoDaIa(): void
    {
        $this->cenario();
        $this->ocultarAba($this->pasta, AbaDaPasta::Financeiro, $this->primario, [$this->ana]);
        $financeiro = static::getContainer()->get(VisibilidadeDoFinanceiroDaPasta::class);
        $montador = static::getContainer()->get(MontadorDeContextoDaPasta::class);

        $veAna = $financeiro->podeVer($this->ana, $this->tenant, $this->pasta);
        self::assertFalse($veAna);
        $semFinanceiro = $this->anotacoesDoContexto($montador, $veAna);
        self::assertStringNotContainsString('pasta marcada como pró-bono', $semFinanceiro);
        self::assertStringNotContainsString('marcado como assinado', $semFinanceiro);
        self::assertStringContainsString('Fase da pasta alterada', $semFinanceiro, 'o cartão de fase continua');

        $vePrimario = $financeiro->podeVer($this->primario, $this->tenant, $this->pasta);
        self::assertTrue($vePrimario);
        $comFinanceiro = $this->anotacoesDoContexto($montador, $vePrimario);
        self::assertStringContainsString('pasta marcada como pró-bono', $comFinanceiro);
        self::assertStringContainsString('marcado como assinado.', $comFinanceiro);
        self::assertStringContainsString('marcado como assinado (fase Contrato concluída no andamento).', $comFinanceiro);
    }

    // ── helpers ─────────────────────────────────────────────────────────

    private function cenario(): void
    {
        $this->cliente();
        $this->tenant = $this->criarTenant();
        $this->primario = $this->criarPrimario($this->tenant);
        $this->ana = $this->criarColaborador($this->tenant);
        [$this->pasta] = $this->criarPastaComPush($this->tenant);

        foreach ([
            'probono'            => ['probono', 'Pró-bono', 'pasta marcada como pró-bono.'],
            'contrato'           => ['contrato', 'Contrato', 'marcado como assinado.'],
            'contrato_andamento' => ['contrato', 'Contrato', 'marcado como assinado (fase Contrato concluída no andamento).'],
            'fase'               => ['fase', 'Andamento', 'Fase da pasta alterada de "Abertura" para "Documentação".'],
        ] as $chave => [$tipo, $rotulo, $texto]) {
            $m = new PastaMensagem();
            $m->setPasta($this->pasta);
            $m->setTenant($this->tenant);
            $m->setAutor($this->primario);
            $m->setSistema($tipo);
            $m->setConteudo('<p><strong>' . $rotulo . ':</strong> ' . htmlspecialchars($texto, ENT_QUOTES) . '</p>');
            $this->em()->persist($m);
            $this->cartoes[$chave] = $m;
        }
        $this->em()->flush();
    }

    private function abrirComo(User $quem): Crawler
    {
        $client = static::getClient() ?? $this->cliente();
        $this->logarComTenant($client, $quem, $this->tenant);
        $crawler = $client->request('GET', '/pasta/' . $this->pasta->getId());
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    private function anotacoesDoContexto(MontadorDeContextoDaPasta $montador, bool $incluirFinanceiro): string
    {
        $contexto = $montador->para($this->tenant, $this->pasta, Agente::Gestor, $incluirFinanceiro);
        $linhas = [];
        foreach ($contexto->secoes as $secao) {
            $linhas = array_merge($linhas, $secao->linhas);
        }

        return html_entity_decode(implode("\n", $linhas), ENT_QUOTES);
    }
}
