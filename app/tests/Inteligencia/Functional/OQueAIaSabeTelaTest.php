<?php

declare(strict_types=1);

namespace App\Tests\Inteligencia\Functional;

use App\Entity\Tarefa\Tarefa;
use App\Inteligencia\Contexto\FonteDeContagensDaPastaDoctrine;
use App\Inteligencia\Contexto\MontadorDeContextoDaPasta;
use App\Inteligencia\Controller\AnaliseDaPastaController;
use App\Inteligencia\UseCase\ContarOQueAIaSabeDaPastaUseCase;
use App\Pasta\Entity\PastaObservacaoDetalhes;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Inteligencia\Support\CriaFixturesInteligenciaTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;

/**
 * IA-006: grade "O que a IA sabe desta pasta" no painel do drawer. Cada número é uma contagem
 * real desta pasta, deste escritório; o que é de outro escritório ou de outra pasta não entra.
 */
#[CoversClass(AnaliseDaPastaController::class)]
#[CoversClass(FonteDeContagensDaPastaDoctrine::class)]
#[CoversClass(ContarOQueAIaSabeDaPastaUseCase::class)]
final class OQueAIaSabeTelaTest extends JusPrimeWebTestCase
{
    use CriaFixturesInteligenciaTrait;

    // Desenho 02 (dc L963): a grade abre a aba Análise do drawer (spec inteligencia-drawer-da-pasta, F1).
    private const GRADE = '.ia-agentes[data-ia-painel] > .ia-aba-painel[data-ia-aba-painel="analise"] > .ia-sabe[data-ia-sabe]:first-child';

    private function cliente(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();

        return $client;
    }

    #[TestDox('documentos fora da lixeira, todas as metas, metas abertas com prazo e anotações + observações, nesta ordem')]
    public function testNumerosConferemComAsFixtures(): void
    {
        $client = $this->cliente();
        [$user, $tenant] = $this->criarAdmin();
        [$pasta] = $this->criarPastaComPublicacao($tenant);

        // Documentos: 2 vivos + 1 na lixeira → 2.
        $this->criarDocumento($tenant, $pasta, 'Procuração');
        $this->criarDocumento($tenant, $pasta, 'Contrato');
        $lixo = $this->criarDocumento($tenant, $pasta, 'Rascunho');
        $lixo->marcarExcluido($user, new \DateTimeImmutable());

        // Metas: 4 ao todo; prazos = abertas COM prazo → 2.
        $this->criarMeta($tenant, $pasta, $user, 'Aberta com prazo 1');
        $this->criarMeta($tenant, $pasta, $user, 'Aberta com prazo 2', '-2 days');
        $this->criarMeta($tenant, $pasta, $user, 'Concluída')->setStatus(Tarefa::STATUS_CONCLUIDA);
        $this->criarMeta($tenant, $pasta, $user, 'Aberta sem prazo')->setPrazo(null);

        // Anotações (aba Dados) 2 + observações (aba Detalhes) 1 → 3.
        $this->criarAnotacao($tenant, $pasta, $user, 'Anotação 1');
        $this->criarAnotacao($tenant, $pasta, $user, 'Anotação 2');
        $obs = new PastaObservacaoDetalhes();
        $obs->setTenant($tenant);
        $obs->setPasta($pasta);
        $obs->setAutor($user);
        $obs->setConteudo('Observação');
        $this->em()->persist($obs);
        $this->em()->flush();

        // Ruído: pasta irmã do mesmo escritório e pasta de outro escritório.
        [$irma] = $this->criarPastaComPublicacao($tenant, '07099999999999999999');
        $this->criarDocumento($tenant, $irma, 'Da irmã');
        $this->criarMeta($tenant, $irma, $user, 'Da irmã');
        [$userB, $tenantB] = $this->criarAdmin();
        [$pastaB] = $this->criarPastaComPublicacao($tenantB);
        $this->criarDocumento($tenantB, $pastaB, 'De B');
        $this->criarMeta($tenantB, $pastaB, $userB, 'De B');
        $this->criarAnotacao($tenantB, $pastaB, $userB, 'De B');

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}/ia/agentes");

        self::assertResponseIsSuccessful();
        $itens = $crawler->filter(self::GRADE . ' > .ia-sabe-item');
        self::assertSame(4, $itens->count(), 'quatro dos cinco do desenho: "fontes" fica de fora');
        self::assertSame(
            [
                ['documentos', '2', 'documentos'],
                ['tarefas', '4', 'tarefas'],
                ['prazos', '2', 'prazos de metas abertas'],
                ['anotacoes', '3', 'anotações'],
            ],
            $itens->each(static fn (Crawler $i): array => [
                (string) $i->attr('data-ia-sabe-item'),
                trim($i->filter('.ia-sabe-v')->text()),
                trim($i->filter('.ia-sabe-l')->text()),
            ]),
        );
        self::assertSame('O que a IA sabe desta pasta', $crawler->filter(self::GRADE)->attr('title'));
        // Abaixo do teto: número exato, sem frase de corte.
        self::assertSame(0, $crawler->filter(self::GRADE . ' [data-ia-sabe-corte]')->count());
    }

    #[TestDox('IA-006b: acima do teto do montador, o quadro mostra o teto (o que a IA recebe) e diz "de N"')]
    public function testAcimaDoTetoMostraOTetoEDeN(): void
    {
        $client = $this->cliente();
        [$user, $tenant] = $this->criarAdmin();
        $pasta = $this->criarPasta($tenant);
        $total = MontadorDeContextoDaPasta::LIMITE_DOCUMENTOS + 2;
        for ($i = 1; $i <= $total; ++$i) {
            $this->criarDocumento($tenant, $pasta, 'Doc ' . $i);
        }
        $this->criarMeta($tenant, $pasta, $user, 'Única meta');
        $this->logarComTenant($client, $user, $tenant);

        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}/ia/agentes");

        self::assertResponseIsSuccessful();
        $documentos = $crawler->filter(self::GRADE . ' > .ia-sabe-item[data-ia-sabe-item="documentos"]');
        self::assertSame((string) MontadorDeContextoDaPasta::LIMITE_DOCUMENTOS, trim($documentos->filter('.ia-sabe-v')->text()));
        self::assertSame(
            sprintf('lê os %d mais recentes de %d', MontadorDeContextoDaPasta::LIMITE_DOCUMENTOS, $total),
            trim($documentos->filter('[data-ia-sabe-corte]')->text()),
        );
        // O quadro que não passou do teto segue exato e sem frase.
        $tarefas = $crawler->filter(self::GRADE . ' > .ia-sabe-item[data-ia-sabe-item="tarefas"]');
        self::assertSame('1', trim($tarefas->filter('.ia-sabe-v')->text()));
        self::assertSame(0, $tarefas->filter('[data-ia-sabe-corte]')->count());
        self::assertSame(1, $crawler->filter(self::GRADE . ' [data-ia-sabe-corte]')->count());
    }

    #[TestDox('pasta vazia: os quatro números são zero (zero é resposta, não ausência)')]
    public function testPastaVazia(): void
    {
        $client = $this->cliente();
        [$user, $tenant] = $this->criarAdmin();
        $pasta = $this->criarPasta($tenant);
        $this->logarComTenant($client, $user, $tenant);

        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}/ia/agentes");

        self::assertResponseIsSuccessful();
        self::assertSame(['0', '0', '0', '0'], $crawler->filter(self::GRADE . ' .ia-sabe-v')->each(static fn (Crawler $v): string => trim($v->text())));
    }

    #[TestDox('o painel de uma pasta de OUTRO escritório responde 404: a grade não é montada')]
    public function testOutroEscritorio(): void
    {
        $client = $this->cliente();
        [$userA, $tenantA] = $this->criarAdmin();
        [$userB, $tenantB] = $this->criarAdmin();
        [$pastaB] = $this->criarPastaComPublicacao($tenantB);
        $this->criarDocumento($tenantB, $pastaB, 'De B');
        $this->logarComTenant($client, $userA, $tenantA);

        $client->request('GET', "/pasta/{$pastaB->getId()}/ia/agentes");

        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('no HTML da pasta (painel ainda não carregado) a grade não aparece: nenhum número sem consulta')]
    public function testSemGradeAntesDeCarregar(): void
    {
        $client = $this->cliente();
        [$user, $tenant] = $this->criarAdmin();
        [$pasta] = $this->criarPastaComPublicacao($tenant);
        $this->ligarIaNoTenant($tenant, $user);
        $this->logarComTenant($client, $user, $tenant);

        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}");

        self::assertResponseIsSuccessful();
        self::assertSame(0, $crawler->filter('#psIaDrawer .ia-sabe')->count());
    }
}
