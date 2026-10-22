<?php

declare(strict_types=1);

namespace App\Tests\TrabalhoExterno\Functional;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\TrabalhoExterno\Controller\CampanhaController;
use App\TrabalhoExterno\Entity\LinkDeVagas;
use App\TrabalhoExterno\Repository\CurriculoRepository;
use App\TrabalhoExterno\Repository\InteressadoEmVagasRepository;
use App\TrabalhoExterno\Repository\VagaRepository;
use App\TrabalhoExterno\Service\DestinatariosDaCampanha;
use App\TrabalhoExterno\UseCase\ExportarDestinatariosDaCampanhaUseCase;
use App\TrabalhoExterno\UseCase\ExportarEmailDaCampanhaUseCase;
use App\TrabalhoExterno\UseCase\MontarCampanhaUseCase;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * A campanha por e-mail do Master pela HTTP (spec VS 18.10.26 §4.10): as quatro rotas são só do Master de
 * verdade (Equipe, externo e prévia `?como=` levam 403 em JSON); o CSV sai com as colunas do desenho e
 * a lista vazia é 404 com o toast; o HTML sai como anexo com o link de vagas próprio, que a prévia não
 * cria; o registro do "Copiar HTML" exige CSRF; o log de cada exportação não leva e-mail nenhum.
 * Com a regra padrão os currículos ficam de fora (o aceite não cobre campanha): os cenários que precisam
 * de lista trocam o serviço por um com `curriculosLiberados: true` ({@see liberarCurriculos()}).
 */
#[CoversClass(CampanhaController::class)]
#[CoversClass(MontarCampanhaUseCase::class)]
#[CoversClass(ExportarDestinatariosDaCampanhaUseCase::class)]
#[CoversClass(ExportarEmailDaCampanhaUseCase::class)]
final class CampanhaDoTrabalhoExternoTest extends JusPrimeWebTestCase
{
    use CriaFixturesTrabalhoExternoTrait;

    private const API = '/trabalho-externo/api/campanha';
    private const CSV = '/trabalho-externo/campanha/destinatarios.csv';
    private const HTML = '/trabalho-externo/campanha/email.html';
    private const REGISTRO = '/trabalho-externo/api/campanha/registro';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->instalarCsrf($this->client);
    }

    #[TestDox('só o Master: a Equipe, o externo e a prévia ?como= levam 403 em JSON nas quatro rotas, sem gravar link')]
    public function testSoOMaster(): void
    {
        [$sede, $master, $doA, $a] = $this->cenario();
        $equipe = $this->criarUsuario($sede, nome: 'Equipe');

        foreach ([[$equipe, $sede, ''], [$doA, $a, ''], [$master, $sede, '?como=equipe'], [$master, $sede, '?como=solicitante']] as [$quem, $onde, $como]) {
            $this->logarComTenant($this->client, $quem, $onde);
            $this->client->request('GET', self::API . $como);
            self::assertResponseStatusCodeSame(403, self::API . $como);
            self::assertSame(false, $this->json($this->client)['ok'] ?? null, 'a recusa sai em JSON');
            foreach ([self::CSV, self::HTML, self::REGISTRO] as $url) {
                $r = $this->postarJson($this->client, $url . $como, ['acao' => 'copiar']);
                self::assertResponseStatusCodeSame(403, $url . $como);
                self::assertSame(false, $r['ok'] ?? null, 'a recusa sai em JSON: ' . $url);
            }
        }
        self::assertSame(0, $this->links(), 'ninguém sem permissão gera link de vagas');
    }

    #[TestDox('o Master monta o modal com a regra padrão: 0 destinatários, o currículo e o inscrito de fora com os motivos, Escritórios desabilitado, CSV 404; a prévia não grava link')]
    public function testMontaSemGravarLink(): void
    {
        [$sede, $master] = $this->cenario();
        $this->logarComTenant($this->client, $master, $sede);
        $this->postarJson($this->client, '/trabalho-externo/api/vagas', self::vaga());

        $this->client->request('GET', self::API . '?uf=DF');
        self::assertResponseIsSuccessful();
        $c = $this->json($this->client);
        self::assertSame(['cand', 0, 1], [$c['pub'], $c['destinatarios'], $c['vagas']]);
        self::assertSame([
            ['n' => 1, 'motivo' => '1 currículo(s) ficam de fora: o aceite do currículo não cobre e-mail de campanha.'],
            ['n' => 1, 'motivo' => '1 inscrito(s) nos alertas ficam de fora até confirmarem o e-mail.'],
        ], $c['deFora']);
        self::assertSame([false, 'A confirmar'], [$c['publicos'][1]['disponivel'], $c['publicos'][1]['dica']]);
        self::assertSame('⚖️ 1 vaga(s) jurídica(s) abertas para você', $c['assuntoPadrao']);
        self::assertSame('0 destinatário(s) com autorização (LGPD)', $c['resumo']['destinatarios']);
        self::assertStringContainsString('href="http://localhost/vagas-juridicas"', $c['html']);
        self::assertStringContainsString('{{descadastrar}}', $c['html']);
        self::assertSame(0, $this->links(), 'a prévia usa a página pública geral e não grava link de vagas');
        self::assertSame('Sem destinatários', $this->postarJson($this->client, self::CSV, ['uf' => 'DF'])['titulo'] ?? null);
        self::assertResponseStatusCodeSame(404, 'com a regra padrão ninguém sai no CSV');

        $this->client->request('GET', self::API . '?assunto=' . rawurlencode('Novidades da semana'));
        self::assertSame('Novidades da semana', $this->json($this->client)['assunto']);
    }

    #[TestDox('CSV: anexo com as colunas do desenho e só quem autorizou; vazio é 404 com o toast "Sem destinatários"; o log não leva e-mail')]
    public function testCsv(): void
    {
        [$sede, $master] = $this->cenario();
        $this->liberarCurriculos();
        $this->logarComTenant($this->client, $master, $sede);
        $log = $this->escutarLog();

        $this->postarJson($this->client, self::CSV, ['pub' => 'cand', 'uf' => 'DF', 'area' => 'Cível']);
        self::assertResponseIsSuccessful();
        $resposta = $this->client->getResponse();
        self::assertStringContainsString('attachment; filename=destinatarios-campanha.csv', (string) $resposta->headers->get('Content-Disposition'));
        self::assertStringStartsWith('text/csv', (string) $resposta->headers->get('Content-Type'));
        self::assertSame(["\u{FEFF}" . '"E-mail";"Nome";"Origem";"UF"', '"ana@exemplo.test";"Ana Souza";"Currículo";"DF"'], explode("\n", (string) $resposta->getContent()));

        $this->postarJson($this->client, self::CSV, ['uf' => 'SP']);
        self::assertResponseStatusCodeSame(404);
        self::assertSame(['ok' => false, 'titulo' => 'Sem destinatários', 'mensagem' => 'Ainda não há e-mails autorizados para este público.'], $this->json($this->client));

        $registros = $this->registrosDaCampanha($log);
        self::assertCount(1, $registros, 'só a exportação que saiu vai para o log');
        self::assertSame(['csv', $master->getId(), 'cand', ['uf' => 'DF', 'area' => 'Cível'], 1], [$registros[0]['acao'], $registros[0]['usuario'], $registros[0]['publico'], $registros[0]['filtros'], $registros[0]['destinatarios']]);
        self::assertStringNotContainsString('@', json_encode($registros, JSON_THROW_ON_ERROR), 'nunca a lista no log');
    }

    #[TestDox('Baixar HTML com a regra padrão (0 destinatários): anexo text/html com o marcador e um link de vagas novo (gravado), que leva às vagas abertas')]
    public function testBaixarHtml(): void
    {
        [$sede, $master] = $this->cenario();
        $this->logarComTenant($this->client, $master, $sede);
        $this->postarJson($this->client, '/trabalho-externo/api/vagas', self::vaga());
        $log = $this->escutarLog();

        $this->postarJson($this->client, self::HTML, ['uf' => 'DF']);
        self::assertResponseIsSuccessful();
        $resposta = $this->client->getResponse();
        self::assertStringContainsString('attachment; filename=email-campanha-bluejus.html', (string) $resposta->headers->get('Content-Disposition'));
        self::assertStringStartsWith('text/html', (string) $resposta->headers->get('Content-Type'));
        $html = (string) $resposta->getContent();
        self::assertStringContainsString('<a href="{{descadastrar}}"', $html);
        self::assertSame(1, $this->links(), 'a exportação grava o link de vagas');
        self::assertSame(1, preg_match('#href="http://localhost(/vagas-juridicas/[A-Za-z0-9_-]{43})"#', $html, $m));

        $this->client->request('GET', $m[1]);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Advogado trabalhista pleno', (string) $this->client->getResponse()->getContent());
        self::assertSame(['baixar', 0], [$this->registrosDaCampanha($log)[0]['acao'], $this->registrosDaCampanha($log)[0]['destinatarios']]);
    }

    #[TestDox('Copiar HTML: o registro exige CSRF; com ele, devolve o HTML com o link de vagas próprio e grava o log sem e-mails')]
    public function testRegistroDoCopiar(): void
    {
        [$sede, $master] = $this->cenario();
        $this->liberarCurriculos();
        $this->logarComTenant($this->client, $master, $sede);
        $this->postarJson($this->client, '/trabalho-externo/api/vagas', self::vaga());
        $log = $this->escutarLog();

        $this->postarJson($this->client, self::REGISTRO, ['acao' => 'copiar'], 'TOKEN_errado');
        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->links());

        $r = $this->postarJson($this->client, self::REGISTRO, ['acao' => 'copiar', 'pub' => 'cand', 'uf' => 'DF']);
        self::assertResponseIsSuccessful();
        self::assertTrue($r['ok']);
        self::assertSame([1, 1], [$r['destinatarios'], $r['vagas']]);
        self::assertMatchesRegularExpression('#href="http://localhost/vagas-juridicas/[A-Za-z0-9_-]{43}"#', $r['html']);
        self::assertSame(1, $this->links());
        $registros = $this->registrosDaCampanha($log);
        self::assertSame(['copiar', 1], [$registros[0]['acao'], $registros[0]['destinatarios']]);
        self::assertStringNotContainsString('@', json_encode($registros, JSON_THROW_ON_ERROR));

        $this->postarJson($this->client, self::REGISTRO, ['acao' => 'enviar']);
        self::assertResponseStatusCodeSame(422, 'a campanha não envia');
    }

    #[TestDox('as exportações gravam (log e link de vagas): GET é 405 e o POST sem o CSRF certo é 403, sem log nem link')]
    public function testExportacoesSaoPostComCsrf(): void
    {
        [$sede, $master] = $this->cenario();
        $this->liberarCurriculos();
        $this->logarComTenant($this->client, $master, $sede);
        $this->postarJson($this->client, '/trabalho-externo/api/vagas', self::vaga());
        $log = $this->escutarLog();

        foreach ([self::CSV, self::HTML] as $url) {
            $this->client->request('GET', $url . '?uf=DF');
            self::assertResponseStatusCodeSame(405, 'GET ' . $url);
            $this->postarJson($this->client, $url, ['uf' => 'DF'], 'TOKEN_errado');
            self::assertResponseStatusCodeSame(403, 'POST sem CSRF ' . $url);
        }
        self::assertSame(0, $this->links());
        self::assertSame([], $this->registrosDaCampanha($log));
    }

    /**
     * Sede, Master, um externo com currículo (LGPD, DF, Cível) e um inscrito nos alertas pela página pública.
     *
     * @return array{Tenant, User, User, Tenant}
     */
    private function cenario(): array
    {
        [$sede, $a] = [$this->criarTenant('Sede'), $this->criarTenant('A')];
        $this->definirSede($sede);
        $master = $this->criarMaster($sede);
        $doA = $this->criarUsuario($a);
        $this->logarComTenant($this->client, $doA, $a);
        $this->postarJson($this->client, '/trabalho-externo/api/curriculos/meu', [
            'nome' => 'Ana Souza', 'email' => 'Ana@Exemplo.test', 'tel' => '(61) 99999-0000', 'situacao' => 'bach',
            'cargo' => 'Advogado(a)', 'cidade' => 'Brasília', 'uf' => 'DF', 'areas' => ['Cível'], 'lgpd' => true,
        ]);
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/logout');
        $this->client->request('GET', '/vagas-juridicas');
        $csrf = $this->client->getCrawler()->filter('#alertas')->attr('data-csrf');
        $this->client->request('POST', '/vagas-juridicas/alertas', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['email' => 'lead@teste.com', 'uf' => 'DF', 'areas' => [], 'lgpd' => true, '_token' => $csrf], JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();

        return [$sede, $master, $doA, $a];
    }

    /**
     * Troca a regra no container de teste por uma com os currículos liberados, para os cenários que
     * precisam de lista. Antes de qualquer pedido à campanha (o serviço ainda não foi criado).
     */
    private function liberarCurriculos(): void
    {
        $c = static::getContainer();
        $c->set(DestinatariosDaCampanha::class, new DestinatariosDaCampanha(
            $c->get(CurriculoRepository::class),
            $c->get(InteressadoEmVagasRepository::class),
            $c->get(VagaRepository::class),
            curriculosLiberados: true,
        ));
    }

    private function escutarLog(): TestHandler
    {
        $log = new TestHandler();
        $logger = static::getContainer()->get('monolog.logger.audit');
        self::assertInstanceOf(Logger::class, $logger);
        $logger->pushHandler($log);

        return $log;
    }

    /** @return list<array<string, mixed>> o contexto dos registros da campanha */
    private function registrosDaCampanha(TestHandler $log): array
    {
        $daCampanha = array_filter($log->getRecords(), static fn ($r): bool => $r->message === 'Trabalho Externo: campanha por e-mail exportada');

        return array_values(array_map(static fn ($r): array => $r->context, $daCampanha));
    }

    private function links(): int
    {
        return $this->em()->getRepository(LinkDeVagas::class)->count([]);
    }

    /** @return array<string, mixed> */
    private static function vaga(): array
    {
        return [
            'titulo' => 'Advogado trabalhista pleno', 'cargo' => 'Advogado(a)', 'contratacao' => 'CLT', 'modalidade' => 'Híbrido',
            'cidade' => 'Brasília', 'uf' => 'DF', 'areas' => ['Cível'], 'ofertante' => ['nome' => 'Escritório', 'email' => 'rh@escritorio.test'],
        ];
    }
}
