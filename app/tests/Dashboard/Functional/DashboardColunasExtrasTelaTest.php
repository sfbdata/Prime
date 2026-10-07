<?php

declare(strict_types=1);

namespace App\Tests\Dashboard\Functional;

use App\Dashboard\Controller\DashboardController;
use App\Dashboard\Controller\PreferenciaDoDashboardController;
use App\Dashboard\Preferencia\CatalogoDePreferenciasDoDashboard;
use App\Dashboard\Preferencia\ColunasExtrasDoDashboard;
use App\Dashboard\Repository\PreferenciaDoUsuarioRepository;
use App\Entity\Auth\User;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PrioridadePasta;
use App\Tests\Factory\Pasta\PastaFactory;
use App\Tests\Factory\Tarefa\TarefaFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;
use Zenstruck\Foundry\Test\Factories;

/**
 * "Adicionar coluna" do menu ⋮ na TELA do Dashboard: a coluna escolhida entra no fim da tabela
 * (cabeçalho, cada linha, Total e card do celular), com o número calculado no servidor; a escolha
 * é gravada por usuário e o servidor recusa o que não está no catálogo (inclusive "Pastas
 * concluídas", que não tem lastro).
 *
 * PHPUnit lê HTML, não posição: o arranjo é travado com combinador de FILHO DIRETO. Que a coluna
 * apareça bonita na tela é smoke do dono.
 */
#[CoversClass(DashboardController::class)]
#[CoversClass(PreferenciaDoDashboardController::class)]
#[Group('dashboard')]
final class DashboardColunasExtrasTelaTest extends DashboardWebTestCase
{
    use Factories;

    private const MENU = 'section.db-page > .db-pref > .db-pref-menu[role="menu"]';

    private function repo(): PreferenciaDoUsuarioRepository
    {
        return static::getContainer()->get(PreferenciaDoUsuarioRepository::class);
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function pastaHospedeira(Tenant $tenant): Pasta
    {
        return PastaFactory::createOne([
            'tenant'       => $tenant,
            'dataAbertura' => new \DateTimeImmutable('2023-06-01 10:00'),
        ])->_real();
    }

    private function meta(Pasta $pasta, User $responsavel, string $status, string $criadaEm, ?string $concluidaEm = null): void
    {
        $tarefa = TarefaFactory::createOne(['pasta' => $pasta, 'status' => $status])->_real();
        $tarefa->addResponsavel($responsavel);
        $tarefa->setDataConclusao($concluidaEm === null ? null : new \DateTimeImmutable($concluidaEm));
        (new \ReflectionProperty(Tarefa::class, 'dataCriacao'))->setValue($tarefa, new \DateTimeImmutable($criadaEm));
        $this->em()->flush();
    }

    private function linhaDe(Crawler $crawler, string $nome): Crawler
    {
        $linha = $crawler->filter('.db-table-card table.db-table > tbody > tr')->reduce(
            static fn (Crawler $tr): bool => str_contains($tr->filter('.db-colab-nome')->attr('title') ?? '', $nome),
        );
        self::assertCount(1, $linha, 'Linha de ' . $nome);

        return $linha;
    }

    private function instalarCsrfStorage(): void
    {
        $storage = new class implements ClearableTokenStorageInterface {
            public function getToken(string $tokenId): string
            {
                return 'TOKEN_' . $tokenId;
            }

            public function setToken(string $tokenId, string $token): void {}

            public function removeToken(string $tokenId): ?string
            {
                return null;
            }

            public function hasToken(string $tokenId): bool
            {
                return true;
            }

            public function clear(): void {}
        };

        static::getContainer()->set('security.csrf.token_storage', $storage);
    }

    /** @param array<string, mixed> $corpo */
    private function postar(KernelBrowser $client, array $corpo): void
    {
        $client->request(
            'POST',
            '/dashboard/preferencias',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => 'TOKEN_ajax'],
            json_encode($corpo, JSON_THROW_ON_ERROR),
        );
    }

    /** @return array<string, mixed> chave => valor gravado */
    private function noBanco(Tenant $tenant, User $usuario): array
    {
        $linhas = $this->em()->getConnection()->fetchAllAssociative(
            'SELECT chave, valor FROM preferencia_usuario WHERE tenant_id = :t AND user_id = :u ORDER BY chave',
            ['t' => $tenant->getId(), 'u' => $usuario->getId()],
        );

        $mapa = [];
        foreach ($linhas as $l) {
            $mapa[$l['chave']] = json_decode((string) $l['valor'], true, 512, JSON_THROW_ON_ERROR);
        }

        return $mapa;
    }

    /** @return list<string|null> data-coluna de cada filho direto */
    private function colunas(Crawler $crawler, string $seletor): array
    {
        return $crawler->filter($seletor)->each(static fn (Crawler $c): ?string => $c->attr('data-coluna'));
    }

    // ─── arranjo ─────────────────────────────────────────────────────

    #[TestDox('Extras gravadas entram no FIM da tabela, na ordem do usuário: cabeçalho, linha, Total e card do celular')]
    public function testArranjoNoFimNaOrdemDoUsuario(): void
    {
        $client = static::createClient();
        [$user, $tenant] = $this->criarGestorLogado($client);
        $this->repo()->gravar($tenant, $user, CatalogoDePreferenciasDoDashboard::COLUNAS_EXTRAS, ['tempo_medio', 'metas_concluidas']);
        PastaFactory::createOne(['tenant' => $tenant, 'criadoPor' => $user]);

        $crawler = $client->request('GET', '/dashboard');

        self::assertResponseIsSuccessful();
        $esperado = array_merge([null], CatalogoDePreferenciasDoDashboard::COLUNAS_OCULTAVEIS, ['tempo_medio', 'metas_concluidas']);
        $tabela   = '[data-filtro-resultado] > .db-table-card > .db-table-scroll > table.db-table';
        self::assertSame($esperado, $this->colunas($crawler, $tabela . ' > thead > tr > th'));
        self::assertSame($esperado, $this->colunas($crawler->filter($tabela . ' > tbody > tr')->first(), 'td'));
        self::assertSame($esperado, $this->colunas($crawler, $tabela . ' > tfoot > tr > *'));
        // cabeçalho ordenável pela própria chave, com o rótulo do desenho
        $th = $crawler->filter($tabela . ' > thead > tr > th[data-coluna="tempo_medio"][data-ordenar="tempo_medio"][data-extra].sortable');
        self::assertCount(1, $th);
        self::assertSame('Tempo médio', trim($th->text()));
        self::assertStringContainsString('só das metas concluídas que têm data de conclusão', (string) $th->attr('title'));
        // card do celular: um bloco por extra, depois dos de pastas, filho direto da grade
        $grade = '[data-filtro-resultado] > .db-table-card > .db-cel-lista > .db-cel-card:not(.db-cel-card--total) > .db-cel-grade';
        self::assertSame(['pastas_criadas', 'tempo_medio', 'metas_concluidas'], array_slice($this->colunas($crawler->filter($grade)->first(), '.db-cel-bloco'), -3));
        self::assertCount(1, $crawler->filter('.db-cel-card--total > .db-cel-grade > .db-cel-bloco--extra[data-coluna="tempo_medio"]'));
        // o "Ordenar" do celular oferece as extras no fim
        $opcoes = $crawler->filter('#db-cel-ordem-select > option')->each(static fn (Crawler $o): string => (string) $o->attr('value'));
        self::assertSame(['tempo_medio|desc', 'metas_concluidas|desc'], array_slice($opcoes, -2));
        // mais de 8 numéricas: a tabela ganha a classe do mínimo de 52px
        self::assertCount(1, $crawler->filter($tabela . '.db-table--larga'));
    }

    #[TestDox('Sem extra gravada a tabela segue com as 9 colunas de sempre e sem db-table--larga')]
    public function testSemExtraTabelaDeSempre(): void
    {
        $client = static::createClient();
        $this->criarGestorLogado($client);

        $crawler = $client->request('GET', '/dashboard');

        self::assertSame(9, $crawler->filter('.db-table > thead > tr > th')->count());
        self::assertSame(0, $crawler->filter('.db-table > thead > tr > th[data-extra]')->count());
        self::assertSame(0, $crawler->filter('table.db-table--larga')->count());
    }

    // ─── valores ─────────────────────────────────────────────────────

    #[TestDox('Valores calculados no servidor por pessoa: concluídas, taxa (%) e tempo (d); sem meta = "—"; Total pela base inteira')]
    public function testValoresPorPessoaETotal(): void
    {
        $client = static::createClient();
        [$user, $tenant] = $this->criarGestorLogado($client);
        $this->criarColaborador($tenant, 'Zeca Sem Meta');
        $this->repo()->gravar($tenant, $user, CatalogoDePreferenciasDoDashboard::COLUNAS_EXTRAS, ['metas_concluidas', 'taxa_conclusao', 'tempo_medio']);
        $pasta = $this->pastaHospedeira($tenant);
        $this->meta($pasta, $user, Tarefa::STATUS_CONCLUIDA, '2024-01-01 09:00', '2024-01-03 09:00'); // 2 dias
        $this->meta($pasta, $user, Tarefa::STATUS_CONCLUIDA, '2024-01-01 09:00', '2024-01-05 09:00'); // 4 dias
        $this->meta($pasta, $user, Tarefa::STATUS_PENDENTE, '2024-01-01 09:00');
        $this->meta($pasta, $user, Tarefa::STATUS_EM_REVISAO, '2024-01-01 09:00');

        $crawler = $client->request('GET', '/dashboard');

        self::assertResponseIsSuccessful();
        $gestora = $this->linhaDe($crawler, 'Gestora da Tela');
        self::assertSame('2', trim($gestora->filter('td[data-coluna="metas_concluidas"]')->text()));
        self::assertSame('50%', trim($gestora->filter('td[data-coluna="taxa_conclusao"]')->text()));
        self::assertSame('3d', trim($gestora->filter('td[data-coluna="tempo_medio"]')->text()));

        $zeca = $this->linhaDe($crawler, 'Zeca Sem Meta');
        self::assertSame('0', trim($zeca->filter('td[data-coluna="metas_concluidas"]')->text()));
        self::assertSame('—', trim($zeca->filter('td[data-coluna="taxa_conclusao"]')->text()), 'sem meta não há taxa: nada inventado');
        self::assertSame('—', trim($zeca->filter('td[data-coluna="tempo_medio"]')->text()));

        $total = $crawler->filter('.db-table > tfoot > tr');
        self::assertSame('2', trim($total->filter('td[data-coluna="metas_concluidas"] .db-total-num')->text()));
        self::assertSame('50%', trim($total->filter('td[data-coluna="taxa_conclusao"] .db-total-num')->text()));
        self::assertSame('3d', trim($total->filter('td[data-coluna="tempo_medio"] .db-total-num')->text()));
    }

    #[TestDox('Pastas urgentes > 0 é link para o Acervo geral urgente DA PESSOA; o número do outro escritório não entra')]
    public function testUrgentesLinkEIsolamento(): void
    {
        $client = static::createClient();
        [$user, $tenant] = $this->criarGestorLogado($client);
        $this->repo()->gravar($tenant, $user, CatalogoDePreferenciasDoDashboard::COLUNAS_EXTRAS, ['pastas_urgentes', 'eventos_agenda']);
        PastaFactory::createMany(2, ['tenant' => $tenant, 'responsavel' => $user, 'prioridade' => PrioridadePasta::Urgente]);
        $outro = $this->criarTenant();
        PastaFactory::createMany(5, ['tenant' => $outro, 'responsavel' => $user, 'prioridade' => PrioridadePasta::Urgente]);

        $crawler = $client->request('GET', '/dashboard');

        $cel  = $this->linhaDe($crawler, 'Gestora da Tela')->filter('td[data-coluna="pastas_urgentes"] > a.db-num-link');
        self::assertCount(1, $cel);
        self::assertSame('2', trim($cel->text()));
        $href = (string) $cel->attr('href');
        parse_str((string) parse_url($href, PHP_URL_QUERY), $q);
        self::assertSame('/expediente', parse_url($href, PHP_URL_PATH));
        self::assertSame(['painel' => 'acervo-geral', 'prioridade' => 'urgente', 'responsavel' => (string) $user->getId()], $q);
        // eventos: zero não é link e é zero (dado), não "—"
        self::assertSame('0', trim($this->linhaDe($crawler, 'Gestora da Tela')->filter('td[data-coluna="eventos_agenda"]')->text()));
        self::assertCount(0, $this->linhaDe($crawler, 'Gestora da Tela')->filter('td[data-coluna="eventos_agenda"] a'));
    }

    #[TestDox('O XHR do filtro devolve a tabela com as extras do usuário (preferência lida também no XHR)')]
    public function testXhrTrazAsExtras(): void
    {
        $client = static::createClient();
        [$user, $tenant] = $this->criarGestorLogado($client);
        $this->repo()->gravar($tenant, $user, CatalogoDePreferenciasDoDashboard::COLUNAS_EXTRAS, ['metas_revisao']);

        $crawler = $client->xmlHttpRequest('GET', '/dashboard');

        self::assertResponseIsSuccessful();
        self::assertSame(10, $crawler->filter('.db-table > thead > tr > th')->count());
        self::assertSame(1, $crawler->filter('.db-table > thead > tr > th[data-coluna="metas_revisao"]')->count());
    }

    #[TestDox('A extra de um colega não aparece na minha tabela (preferência é por usuário)')]
    public function testExtraDoColegaNaoVaza(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarGestorLogado($client);
        $colega = $this->criarColaborador($tenant, 'Colega Curioso');
        $this->repo()->gravar($tenant, $colega, CatalogoDePreferenciasDoDashboard::COLUNAS_EXTRAS, ['eventos_agenda']);

        $crawler = $client->request('GET', '/dashboard');

        self::assertSame(0, $crawler->filter('.db-table > thead > tr > th[data-extra]')->count());
    }

    // ─── menu ⋮ ──────────────────────────────────────────────────────

    #[TestDox('Menu: "Adicionar coluna" lista as 6 métricas com lastro (sem "Pastas concluídas"); as ligadas aparecem em Colunas como "(extra)"')]
    public function testMenuAdicionarColuna(): void
    {
        $client = static::createClient();
        [$user, $tenant] = $this->criarGestorLogado($client);
        $this->repo()->gravar($tenant, $user, CatalogoDePreferenciasDoDashboard::COLUNAS_EXTRAS, ['eventos_agenda', 'taxa_conclusao']);

        $crawler = $client->request('GET', '/dashboard');

        self::assertResponseIsSuccessful();
        $add = $crawler->filter(self::MENU . ' > button.db-pref-add[data-pref-extra-adicionar]');
        self::assertSame(ColunasExtrasDoDashboard::chaves(), $add->each(static fn (Crawler $b): string => (string) $b->attr('data-pref-extra-adicionar')));
        self::assertNotContains('pastas_concluidas', ColunasExtrasDoDashboard::chaves());
        self::assertStringNotContainsString('Pastas concluídas', $crawler->filter(self::MENU)->text());
        // as ligadas somem de "Adicionar coluna"…
        self::assertCount(2, $crawler->filter(self::MENU . ' > button.db-pref-add[hidden]'));
        self::assertCount(1, $crawler->filter(self::MENU . ' > button.db-pref-add[data-pref-extra-adicionar="taxa_conclusao"][hidden]'));
        // …e aparecem marcadas em Colunas, na ordem da tabela, como "(extra)"
        $ativas = $crawler->filter(self::MENU . ' > .db-pref-extras-ativas > button.db-pref-col[data-pref-extra-remover]:not([hidden])');
        self::assertSame(['eventos_agenda', 'taxa_conclusao'], $ativas->each(static fn (Crawler $b): string => (string) $b->attr('data-pref-extra-remover')));
        self::assertSame('Eventos na agenda (extra)', trim($ativas->first()->filter('.db-pref-rotulo')->text()));
        self::assertSame('true', $ativas->first()->attr('aria-checked'));
        // o cabeçalho da seção vem logo depois de um divisor (dc L688-692)
        self::assertCount(1, $crawler->filter(self::MENU . ' > .db-pref-div + .db-pref-secao--add'));
        self::assertCount(1, $crawler->filter(self::MENU . ' > [data-pref-extras-todas][hidden]'));
    }

    #[TestDox('Menu: com as 6 ligadas, "Adicionar coluna" mostra a frase do desenho no lugar da lista')]
    public function testMenuTodasLigadas(): void
    {
        $client = static::createClient();
        [$user, $tenant] = $this->criarGestorLogado($client);
        $this->repo()->gravar($tenant, $user, CatalogoDePreferenciasDoDashboard::COLUNAS_EXTRAS, ColunasExtrasDoDashboard::chaves());

        $crawler = $client->request('GET', '/dashboard');

        self::assertCount(0, $crawler->filter(self::MENU . ' > button.db-pref-add:not([hidden])'));
        $vazio = $crawler->filter(self::MENU . ' > [data-pref-extras-todas]:not([hidden])');
        self::assertCount(1, $vazio);
        self::assertSame('Todas as colunas disponíveis já estão na tabela.', trim($vazio->text()));
    }

    // ─── gravação ────────────────────────────────────────────────────

    #[TestDox('POST grava a lista de extras do usuário logado (na ordem dele) e a próxima tela já vem com a coluna')]
    public function testGravaEAplica(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->instalarCsrfStorage();
        [$user, $tenant] = $this->criarGestorLogado($client);

        $this->postar($client, ['chave' => 'dashboard.colunas_extras', 'valor' => ['pastas_urgentes', 'metas_concluidas', 'pastas_urgentes']]);

        self::assertResponseIsSuccessful();
        $resposta = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['pastas_urgentes', 'metas_concluidas'], $resposta['preferencias']['dashboard.colunas_extras']);
        self::assertSame('', $resposta['classes'], 'extra não vira classe: o servidor desenha a coluna');
        self::assertSame(['dashboard.colunas_extras' => ['pastas_urgentes', 'metas_concluidas']], $this->noBanco($tenant, $user));

        $crawler = $client->request('GET', '/dashboard');
        self::assertSame(['pastas_urgentes', 'metas_concluidas'], $crawler->filter('.db-table > thead > tr > th[data-extra]')->each(static fn (Crawler $th): string => (string) $th->attr('data-coluna')));
    }

    #[TestDox('POST recusa extra fora do catálogo ("Pastas concluídas", inventada, formato errado) com 400 e não grava nada')]
    public function testRecusaInvalida(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->instalarCsrfStorage();
        [$user, $tenant] = $this->criarGestorLogado($client);

        foreach ([['pastas_concluidas'], ['metas_concluidas', 'salario'], 'tempo_medio', ['tempo_medio' => true], [null]] as $valor) {
            $this->postar($client, ['chave' => 'dashboard.colunas_extras', 'valor' => $valor]);
            self::assertResponseStatusCodeSame(400, 'valor: ' . json_encode($valor));
        }

        self::assertSame([], $this->noBanco($tenant, $user));
    }

    #[TestDox('Restaurar padrão também tira as extras')]
    public function testRestaurarTiraExtras(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->instalarCsrfStorage();
        [$user, $tenant] = $this->criarGestorLogado($client);
        $this->repo()->gravar($tenant, $user, CatalogoDePreferenciasDoDashboard::COLUNAS_EXTRAS, ['metas_revisao']);

        $this->postar($client, ['restaurar' => true]);

        self::assertResponseIsSuccessful();
        self::assertSame([], $this->noBanco($tenant, $user));
    }

    #[TestDox('O JS recarrega o fragmento só depois que o servidor confirma uma lista de extras diferente (sem localStorage)')]
    public function testContratoDoJs(): void
    {
        $js = (string) file_get_contents(static::getContainer()->getParameter('kernel.project_dir') . '/public/js/dashboard-preferencias.js');

        self::assertStringContainsString("var CH_EXTRAS = 'dashboard.colunas_extras';", $js);
        self::assertStringContainsString('data-pref-extra-adicionar', $js);
        self::assertStringContainsString('data-pref-extra-remover', $js);
        self::assertStringContainsString('recarregarTabela();', $js);
        self::assertStringNotContainsString('localStorage', $js);
    }
}
