<?php

declare(strict_types=1);

namespace App\Tests\Tarefa\Functional;

use App\Controller\TarefaController;
use App\Entity\Auth\User;
use App\Entity\Permission\ResourceAccess;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Tarefa\Enum\EtapaDaPasta;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Pasta\Functional\CriaCenarioDoCadeadoFuncionalTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * A esteira com `?etapa=` (lista das metas abertas da equipe e as contagens das caixas) aplica o "ver a pasta", a
 * mesma regra da lista normal e do `AcessoAMeta`: a meta só aparece para quem cuida dela (criador ou responsável) ou
 * pode ver a pasta (`resources.pasta.view` no perfil, acesso item a item, primário ou Master), além do cadeado.
 */
#[CoversClass(TarefaController::class)]
final class EsteiraVerAPastaTest extends JusPrimeWebTestCase
{
    use CriaCenarioDoCadeadoFuncionalTrait;

    private Tenant $tenant;
    private User $primario;
    private Pasta $pastaAlheia;

    protected function setUp(): void
    {
        $this->prepararCliente();
        [$this->primario, $this->tenant] = $this->criarEscritorio();
        $this->pastaAlheia = $this->criarPasta($this->tenant, 'CLIENTE DA PASTA ALHEIA');
        $this->meta('META DA PASTA ALHEIA', $this->pastaAlheia, $this->primario);
    }

    #[TestDox('membro da equipe sem ver a pasta não vê a meta na esteira nem na contagem; a meta de que cuida aparece')]
    public function testSemVerAPastaSoVeAPropria(): void
    {
        $ana = $this->criarColaborador($this->tenant, 'Ana Equipe', ['modules.tarefas.view']);
        $this->meta('META DA ANA', $this->criarPasta($this->tenant, 'CLIENTE DA ANA'), $ana);

        $html = $this->esteira($ana);

        self::assertStringContainsString('META DA ANA', $html, 'o responsável vê a própria meta');
        self::assertStringNotContainsString('META DA PASTA ALHEIA', $html);
        self::assertStringNotContainsString('CLIENTE DA PASTA ALHEIA', $html);
        self::assertSame('1', $this->totalDaCaixaAtiva());
    }

    #[TestDox('com resources.pasta.view no perfil, vê a meta da pasta na esteira e na contagem')]
    public function testComPermissaoDeVerAPasta(): void
    {
        $bia = $this->criarColaborador($this->tenant, 'Bia Equipe', ['modules.tarefas.view', 'resources.pasta.view']);

        $html = $this->esteira($bia);

        self::assertStringContainsString('META DA PASTA ALHEIA', $html);
        self::assertSame('1', $this->totalDaCaixaAtiva());
    }

    #[TestDox('com acesso item a item (ResourceAccess) à pasta, vê a meta dela; a de outra pasta não')]
    public function testComAcessoItemAItem(): void
    {
        $caio = $this->criarColaborador($this->tenant, 'Caio Equipe', ['modules.tarefas.view']);
        $this->meta('META DE OUTRA PASTA', $this->criarPasta($this->tenant, 'OUTRO CLIENTE'), $this->primario);
        $acesso = new ResourceAccess();
        $acesso->setUser($caio);
        $acesso->setTenant($this->tenant);
        $acesso->setResourceType(ResourceAccess::RESOURCE_PASTA);
        $acesso->setResourceId((int) $this->pastaAlheia->getId());
        $acesso->setCanView(true);
        $this->em()->persist($acesso);
        $this->em()->flush();

        $html = $this->esteira($caio);

        self::assertStringContainsString('META DA PASTA ALHEIA', $html);
        self::assertStringNotContainsString('META DE OUTRA PASTA', $html);
        self::assertSame('1', $this->totalDaCaixaAtiva());
    }

    #[TestDox('o primário vê todas as metas da caixa')]
    public function testPrimarioVeTudo(): void
    {
        $this->meta('META DE OUTRA PASTA', $this->criarPasta($this->tenant, 'OUTRO CLIENTE'), $this->criarColaborador($this->tenant, 'Dani Equipe', ['modules.tarefas.view']));

        $html = $this->esteira($this->primario);

        self::assertStringContainsString('META DA PASTA ALHEIA', $html);
        self::assertStringContainsString('META DE OUTRA PASTA', $html);
        self::assertSame('2', $this->totalDaCaixaAtiva());
    }

    private function esteira(User $quem): string
    {
        $this->entrar($quem, $this->tenant);
        $this->client->request('GET', '/tarefas/minhas?aba=todas&etapa=protocolo');
        self::assertResponseIsSuccessful();

        return (string) $this->client->getResponse()->getContent();
    }

    private function totalDaCaixaAtiva(): string
    {
        return trim($this->client->getCrawler()->filter('.mm-caixa.is-ativa .mm-caixa-total')->text());
    }

    private function meta(string $titulo, Pasta $pasta, User $responsavel): Tarefa
    {
        $meta = new Tarefa();
        $meta->setTitulo($titulo);
        $meta->setDescricao('Descrição');
        $meta->setPasta($pasta);
        $meta->setTenant($this->tenant);
        $meta->setEtapa(EtapaDaPasta::Protocolo);
        $meta->setCriadoPor($responsavel);
        $meta->addResponsavel($responsavel);
        $this->em()->persist($meta);
        $this->em()->flush();

        return $meta;
    }
}
