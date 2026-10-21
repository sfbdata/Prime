<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Controller\PastaController;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\CadeadoDaPasta;
use App\Pasta\Entity\ModoDoCadeado;
use App\Pasta\Entity\NivelDeAcesso;
use App\Pasta\Entity\Pasta;
use App\Processo\Controller\ProcessoController;
use App\Processo\Service\DatajudClient;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * a4 (spec processo-cadastro-pela-pasta): o "Buscar no CNJ" da janela da pasta vale para quem pode vincular
 * processo nela (EDIT, com o cadeado), mesmo sem o módulo `processos`. Fora da pasta, nada muda.
 */
#[CoversClass(PastaController::class)]
#[CoversClass(ProcessoController::class)]
final class BuscarNoCnjPelaPastaTest extends JusPrimeWebTestCase
{
    use CriaFixturesPushDaPastaTrait;
    use CriaUsuarioComPermissoesTrait;

    private const NUMERO = '0001234-56.2020.8.26.0100';
    private const JSON = ['CONTENT_TYPE' => 'application/json', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'];

    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->client->disableReboot();
        $datajud = $this->createMock(DatajudClient::class);
        $datajud->method('searchByNumeroProcesso')->willReturn(['hits' => ['hits' => [['_source' => ['numeroProcesso' => '00012345620208260100', 'tribunal' => 'TJSP', 'classe' => ['codigo' => 7, 'nome' => 'Procedimento Comum Cível']]]]]]);
        static::getContainer()->set(DatajudClient::class, $datajud);
    }

    #[TestDox('editor da pasta SEM o módulo de processos consulta pela pasta (200); a rota de /processos continua 403 para ele')]
    public function testEditorSemModuloConsultaPelaPasta(): void
    {
        $tenant = $this->criarTenant();
        $pasta = $this->criarPasta($tenant);
        $editor = $this->criarUsuarioComPermissoes($tenant, ['resources.pasta.view', 'resources.pasta.edit']);
        $this->logarComTenant($this->client, $editor, $tenant);

        $this->buscarPelaPasta($pasta);
        self::assertResponseIsSuccessful();
        $resposta = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertTrue($resposta['success'] ?? false);
        self::assertSame('TJSP', $resposta['data']['siglaTribunal'] ?? null);

        $this->buscarFora();
        self::assertResponseStatusCodeSame(403);
    }

    #[TestDox('o atributo da pasta não se forja: mandado na query ou no corpo de /processos/api/search, continua 403')]
    public function testAtributoNaoSeForja(): void
    {
        $tenant = $this->criarTenant();
        $pasta = $this->criarPasta($tenant);
        $editor = $this->criarUsuarioComPermissoes($tenant, ['resources.pasta.view', 'resources.pasta.edit']);
        $this->logarComTenant($this->client, $editor, $tenant);

        $this->client->request('POST', '/processos/api/search?' . ProcessoController::CNJ_PELA_PASTA . '=' . $pasta->getId(), [], [], self::JSON, (string) json_encode(['numeroProcesso' => self::NUMERO, ProcessoController::CNJ_PELA_PASTA => $pasta->getId()]));

        self::assertResponseStatusCodeSame(403);
    }

    #[TestDox('só leitura na pasta: 403 pela pasta (consultar é passo do vínculo, que exige EDIT)')]
    public function testLeitorRecebe403(): void
    {
        $tenant = $this->criarTenant();
        $pasta = $this->criarPasta($tenant);
        $leitor = $this->criarUsuarioComPermissoes($tenant, ['resources.pasta.view']);
        $this->logarComTenant($this->client, $leitor, $tenant);

        $this->buscarPelaPasta($pasta);

        self::assertResponseStatusCodeSame(403);
    }

    #[TestDox('cadeado em Visualizar para o editor: 403 pela pasta')]
    public function testCadeadoVisualizarRecebe403(): void
    {
        $tenant = $this->criarTenant();
        $pasta = $this->criarPasta($tenant);
        $editor = $this->criarUsuarioComPermissoes($tenant, ['resources.pasta.view', 'resources.pasta.edit']);
        $this->trancar($tenant, $pasta, $editor, NivelDeAcesso::Visualizar);
        $this->logarComTenant($this->client, $editor, $tenant);

        $this->buscarPelaPasta($pasta);

        self::assertResponseStatusCodeSame(403);
    }

    #[TestDox('pasta de outro escritório: 404, sem consultar')]
    public function testPastaDeOutroEscritorio(): void
    {
        $tenant = $this->criarTenant();
        $alheia = $this->criarPasta($this->criarTenant());
        $editor = $this->criarUsuarioComPermissoes($tenant, ['resources.pasta.view', 'resources.pasta.edit']);
        $this->logarComTenant($this->client, $editor, $tenant);

        $this->buscarPelaPasta($alheia);

        self::assertResponseStatusCodeSame(404);
    }

    private function buscarPelaPasta(Pasta $pasta): void
    {
        // Sem a pasta no mapa de identidade: o filtro de escritório vale como numa requisição de verdade.
        $this->em()->clear();
        $this->client->request('POST', '/pasta/' . $pasta->getId() . '/processo/buscar-cnj', [], [], self::JSON, (string) json_encode(['numeroProcesso' => self::NUMERO]));
    }

    private function buscarFora(): void
    {
        $this->client->request('POST', '/processos/api/search', [], [], self::JSON, (string) json_encode(['numeroProcesso' => self::NUMERO]));
    }

    private function trancar(Tenant $tenant, Pasta $pasta, User $pessoa, NivelDeAcesso $nivel): void
    {
        $cadeado = new CadeadoDaPasta($tenant, $pasta, ModoDoCadeado::PorPessoa, null, new \DateTimeImmutable());
        $cadeado->definirPorPessoa([[$pessoa, $nivel]], null, new \DateTimeImmutable());
        $this->em()->persist($cadeado);
        $this->em()->flush();
    }
}
