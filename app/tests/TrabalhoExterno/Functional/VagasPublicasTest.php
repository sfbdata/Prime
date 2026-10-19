<?php

declare(strict_types=1);

namespace App\Tests\TrabalhoExterno\Functional;

use App\Tests\Functional\JusPrimeWebTestCase;
use App\TrabalhoExterno\Controller\VagasPublicasController;
use App\TrabalhoExterno\Entity\InteressadoEmVagas;
use App\TrabalhoExterno\Entity\LinkDeVagas;
use App\TrabalhoExterno\Repository\InteressadoEmVagasRepository;
use App\TrabalhoExterno\UseCase\CompartilharVagasUseCase;
use App\TrabalhoExterno\UseCase\InscreverInteressadoUseCase;
use App\TrabalhoExterno\UseCase\MontarPaginaDeVagasUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * A página pública de vagas e o "Compartilhar vagas" (spec VS 18.10.26 §4.2). Sem login. O que
 * importa provar: nada de escritório, contato, remuneração ou requisitos sai na página; só vagas
 * abertas; token desconhecido não se distingue de link sem vagas; a inscrição exige consentimento.
 */
#[CoversClass(VagasPublicasController::class)]
#[CoversClass(CompartilharVagasUseCase::class)]
#[CoversClass(MontarPaginaDeVagasUseCase::class)]
#[CoversClass(InscreverInteressadoUseCase::class)]
#[CoversClass(LinkDeVagas::class)]
#[CoversClass(InteressadoEmVagas::class)]
final class VagasPublicasTest extends JusPrimeWebTestCase
{
    use CriaFixturesTrabalhoExternoTrait;

    #[TestDox('o link mostra só cargo, título, local, tipo e áreas; remuneração, escritório, contato e requisitos não saem; vaga encerrada some')]
    public function testLinkMostraSoODaPaginaPublica(): void
    {
        $client = static::createClient();
        $this->instalarCsrf($client);
        $sede = $this->criarTenant('Sede');
        $master = $this->criarMaster($sede);

        $this->logarComTenant($client, $master, $sede);
        $aberta = $this->postarJson($client, '/trabalho-externo/api/vagas', self::vaga('Advogado trabalhista pleno'))['codigo'];
        $encerrada = $this->postarJson($client, '/trabalho-externo/api/vagas', self::vaga('Estagiário de família'))['codigo'];
        $link = $this->postarJson($client, '/trabalho-externo/api/vagas/compartilhar', ['codigos' => [$aberta, $encerrada]]);
        self::assertSame(2, $link['vagas']);
        $this->postarJson($client, "/trabalho-externo/api/vagas/$encerrada/acoes/encerrar");

        $client->request('GET', '/logout');
        $client->request('GET', parse_url($link['url'], PHP_URL_PATH));
        self::assertResponseIsSuccessful('a página é pública');
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Advogado trabalhista pleno', $html);
        self::assertStringContainsString('Brasília/DF', $html);
        self::assertStringNotContainsString('Estagiário de família', $html, 'a encerrada sai do link');
        foreach (['R$ 9.999', 'Escritório Secreto Ltda', 'vagas@segredo.test', 'Requisito confidencial'] as $segredo) {
            self::assertStringNotContainsString($segredo, $html, "não pode sair na página pública: $segredo");
        }
        self::assertSame('no-referrer', $client->getResponse()->headers->get('Referrer-Policy'));
    }

    #[TestDox('token desconhecido é a página sem vagas, sem 404 que confirme se o link existiu; a página geral abre sem login')]
    public function testTokenDesconhecido(): void
    {
        $client = static::createClient();
        $client->request('GET', '/vagas-juridicas/' . str_repeat('x', 43));
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('As vagas deste link já foram preenchidas', (string) $client->getResponse()->getContent());

        $client->request('GET', '/vagas-juridicas');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Sua próxima vaga na área jurídica começa aqui', (string) $client->getResponse()->getContent());
        // P1 decidida pelo desenho: candidato e escritório criam conta pelo /cadastro; "Entrar" vai ao /login.
        $crawler = $client->getCrawler();
        self::assertSame('/cadastro', $crawler->filter('.vp-hero-ctas a.vp-cta[data-so="cand"]')->attr('href'));
        self::assertSame('/cadastro', $crawler->filter('.vp-barra-1')->attr('href'));
        self::assertSame('/login', $crawler->filter('.vp-entrar')->attr('href'));
    }

    #[TestDox('inscrição nos alertas: pede consentimento e e-mail válido; repetir atualiza sem duplicar; sem CSRF é 403')]
    public function testInscricaoNosAlertas(): void
    {
        $client = static::createClient();
        $client->request('GET', '/vagas-juridicas');
        $csrf = $client->getCrawler()->filter('#alertas')->attr('data-csrf');

        $this->alerta($client, ['email' => 'pessoa@teste.com', 'lgpd' => false, '_token' => $csrf]);
        self::assertResponseStatusCodeSame(422);
        $this->alerta($client, ['email' => 'nao-e-email', 'lgpd' => true, '_token' => $csrf]);
        self::assertResponseStatusCodeSame(422);
        $this->alerta($client, ['email' => 'pessoa@teste.com', 'lgpd' => true, '_token' => 'errado']);
        self::assertResponseStatusCodeSame(403);

        $this->alerta($client, ['email' => 'Pessoa@Teste.com', 'uf' => 'DF', 'areas' => ['Cível', 'Inexistente'], 'lgpd' => true, '_token' => $csrf]);
        self::assertResponseIsSuccessful();
        $this->alerta($client, ['email' => 'pessoa@teste.com', 'uf' => 'SP', 'areas' => ['Trabalhista'], 'lgpd' => true, '_token' => $csrf]);
        self::assertResponseIsSuccessful('repetir responde igual');

        $interessado = static::getContainer()->get(InteressadoEmVagasRepository::class)->doEmail('pessoa@teste.com');
        self::assertNotNull($interessado);
        self::assertSame('SP', $interessado->getUf());
        self::assertSame(['Trabalhista'], $interessado->getAreas());
        self::assertSame(InteressadoEmVagas::VERSAO_DO_CONSENTIMENTO, $interessado->getVersaoDoConsentimento());
        self::assertCount(1, static::getContainer()->get(InteressadoEmVagasRepository::class)->findBy(['email' => 'pessoa@teste.com']));
    }

    #[TestDox('compartilhar: só vagas abertas que o usuário vê; o escritório só compartilha as dele; sem nenhuma é 422')]
    public function testQuemCompartilha(): void
    {
        $client = static::createClient();
        $this->instalarCsrf($client);
        $sede = $this->criarTenant('Sede');
        $b = $this->criarTenant('B');
        $master = $this->criarMaster($sede);
        $doB = $this->criarUsuario($b);

        $this->logarComTenant($client, $master, $sede);
        $daCentral = $this->postarJson($client, '/trabalho-externo/api/vagas', self::vaga('Correspondente em Campinas'))['codigo'];

        $this->logarComTenant($client, $doB, $b);
        $this->postarJson($client, '/trabalho-externo/api/vagas/compartilhar', ['codigos' => [$daCentral]]);
        self::assertResponseStatusCodeSame(422, 'a vaga da Central não é do escritório B');
        $pendente = $this->postarJson($client, '/trabalho-externo/api/vagas', self::vaga('Assistente jurídico'))['codigo'];
        $this->postarJson($client, '/trabalho-externo/api/vagas/compartilhar', ['codigos' => [$pendente]]);
        self::assertResponseStatusCodeSame(422, 'pendente não se compartilha');
    }

    #[TestDox('o escritório compartilha a própria vaga sem aparecer: o nome dele não sai na página pública')]
    public function testEscritorioCompartilhaSemSeIdentificar(): void
    {
        $client = static::createClient();
        $this->instalarCsrf($client);
        $sede = $this->criarTenant('Sede');
        $b = $this->criarTenant('Banca Discreta');
        $master = $this->criarMaster($sede);
        $doB = $this->criarUsuario($b);

        $this->logarComTenant($client, $doB, $b);
        $codigo = $this->postarJson($client, '/trabalho-externo/api/vagas', self::vaga('Advogado cível júnior'))['codigo'];
        $this->logarComTenant($client, $master, $sede);
        $this->postarJson($client, "/trabalho-externo/api/vagas/$codigo/acoes/aprovar");

        $this->logarComTenant($client, $doB, $b);
        $link = $this->postarJson($client, '/trabalho-externo/api/vagas/compartilhar', ['codigos' => [$codigo]]);
        self::assertSame(1, $link['vagas']);

        $client->request('GET', '/logout');
        $client->request('GET', parse_url($link['url'], PHP_URL_PATH));
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Advogado cível júnior', $html);
        self::assertStringNotContainsString('Banca Discreta', $html);
        self::assertStringNotContainsString('Indicadas por', $html);
    }

    #[TestDox('vaga pausada some do link; a prévia do Master não gera link')]
    public function testPausadaSomeEPreviaNaoCompartilha(): void
    {
        $client = static::createClient();
        $this->instalarCsrf($client);
        $sede = $this->criarTenant('Sede');
        $master = $this->criarMaster($sede);

        $this->logarComTenant($client, $master, $sede);
        $codigo = $this->postarJson($client, '/trabalho-externo/api/vagas', self::vaga('Controladoria jurídica'))['codigo'];
        $link = $this->postarJson($client, '/trabalho-externo/api/vagas/compartilhar', ['codigos' => [$codigo]]);
        $this->postarJson($client, "/trabalho-externo/api/vagas/$codigo/acoes/pausar");

        $client->request('GET', parse_url($link['url'], PHP_URL_PATH));
        self::assertStringNotContainsString('Controladoria jurídica', (string) $client->getResponse()->getContent());
        self::assertStringContainsString('As vagas deste link já foram preenchidas', (string) $client->getResponse()->getContent());

        $this->postarJson($client, '/trabalho-externo/api/vagas/compartilhar?como=solicitante', ['codigos' => [$codigo]]);
        self::assertResponseStatusCodeSame(422, 'a escrita ignora o como= e a vaga pausada não entra');
    }

    #[TestDox('o consentimento que a tela mostra é o texto da versão gravada; corpo com tipo errado é 422, não erro')]
    public function testConsentimentoETiposErrados(): void
    {
        $client = static::createClient();
        $client->request('GET', '/vagas-juridicas');
        self::assertSame(InteressadoEmVagas::CONSENTIMENTO, trim($client->getCrawler()->filter('.vp-lead-lgpd span')->text()));
        $csrf = $client->getCrawler()->filter('#alertas')->attr('data-csrf');

        $this->alerta($client, ['email' => ['x@y.com'], 'uf' => ['DF'], 'areas' => [['Cível']], 'lgpd' => true, '_token' => $csrf]);
        self::assertResponseStatusCodeSame(422);
    }

    /** @param array<string, mixed> $corpo */
    private function alerta(KernelBrowser $client, array $corpo): void
    {
        $client->request('POST', '/vagas-juridicas/alertas', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($corpo, JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    private static function vaga(string $titulo): array
    {
        return [
            'titulo' => $titulo, 'cargo' => 'Advogado(a)', 'contratacao' => 'CLT', 'modalidade' => 'Híbrido', 'cidade' => 'Brasília', 'uf' => 'DF',
            'remuneracao' => 'R$ 9.999', 'requisitos' => 'Requisito confidencial', 'areas' => ['Trabalhista'],
            'ofertante' => ['nome' => 'Escritório Secreto Ltda', 'email' => 'vagas@segredo.test'],
        ];
    }
}
