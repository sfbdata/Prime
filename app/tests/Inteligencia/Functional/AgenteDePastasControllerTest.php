<?php

declare(strict_types=1);

namespace App\Tests\Inteligencia\Functional;

use App\Entity\Auth\User;
use App\Entity\Permission\ResourceAccess;
use App\Entity\Tenant\Tenant;
use App\Expediente\Entity\Marcador;
use App\Inteligencia\Controller\AgenteDePastasController;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Entity\PrioridadePasta;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Inteligencia\Support\CriaFixturesInteligenciaTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Fase 1 do Agente de Pastas — ferramentas de LEITURA (`docs/specs/designer-central-ia.md` §3).
 *
 * O que se prova aqui: a busca enxerga o mesmo que a Lista do Expediente (só o escritório atual,
 * lápide marcada), os filtros são os da Lista, valor torto vira 422 em vez de "todas as pastas", e os
 * documentos exigem acesso por item à pasta — com a prova do guarda (o mesmo usuário ganha acesso e
 * passa), para o 403 não ser de outra barreira.
 */
#[CoversClass(AgenteDePastasController::class)]
final class AgenteDePastasControllerTest extends JusPrimeWebTestCase
{
    use CriaFixturesInteligenciaTrait;

    private function cliente(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();

        return $client;
    }

    /** @return array<string, mixed> */
    private function json(KernelBrowser $client): array
    {
        return json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function pasta(Tenant $tenant, string $nup, string $identificador, ?User $responsavel = null): Pasta
    {
        $pasta = new Pasta();
        $pasta->setTenant($tenant);
        $pasta->setNup($nup);
        $pasta->setNomeCliente($identificador);
        $pasta->setNomeAcao('Cobrança de honorários');
        $pasta->setResponsavel($responsavel);
        $this->em()->persist($pasta);
        $this->em()->flush();

        return $pasta;
    }

    private function marcador(Tenant $tenant, User $por, string $nome): Marcador
    {
        $marcador = new Marcador($nome, $tenant, $por);
        $this->em()->persist($marcador);
        $this->em()->flush();

        return $marcador;
    }

    private function documento(Pasta $pasta, Tenant $tenant, string $titulo): PastaDocumento
    {
        $doc = new PastaDocumento();
        $doc->setTitulo($titulo);
        $doc->setCategoria(PastaDocumento::CATEGORIA_CONTRATO);
        $doc->setCaminhoArquivo(bin2hex(random_bytes(16)) . '.pdf');
        $doc->setNomeOriginal(strtolower($titulo) . '.pdf');
        $doc->setMimeType('application/pdf');
        $doc->setTamanhoBytes(2048);
        $doc->setPasta($pasta);
        $doc->setTenant($tenant);
        $this->em()->persist($doc);
        $this->em()->flush();

        return $doc;
    }

    /** @return list<string> */
    private static function nups(array $corpo): array
    {
        $nups = array_map(static fn (array $p): string => (string) $p['nup'], $corpo['pastas']);
        sort($nups);

        return $nups;
    }

    // ── buscar_pastas / contar_pastas ───────────────────────────────────────────

    #[TestDox('Busca devolve só as pastas do escritório atual, com o formato do contrato')]
    public function testBuscaIsolaOEscritorio(): void
    {
        $client = $this->cliente();
        [$admin, $tenant] = $this->criarAdmin();
        [, $outro] = $this->criarAdmin();
        $this->pasta($tenant, 'AG-1', 'MARIA', $admin);
        $this->pasta($outro, 'AG-OUTRO', 'MARIA');
        $this->logarComTenant($client, $admin, $tenant);

        $client->request('GET', '/api/agente/pastas');

        self::assertResponseIsSuccessful();
        $corpo = $this->json($client);
        self::assertSame(['AG-1'], self::nups($corpo));
        self::assertSame(1, $corpo['total']);
        self::assertSame(1, $corpo['pagina']);
        self::assertSame(50, $corpo['porPagina']);

        $linha = $corpo['pastas'][0];
        self::assertSame('MARIA', $linha['identificador']);
        self::assertSame([], $linha['clientes']);
        self::assertSame('COBRANÇA DE HONORÁRIOS', $linha['acao']);
        self::assertSame(['id' => $admin->getId(), 'nome' => $admin->getFullName()], $linha['responsavel']);
        self::assertSame('ativo', $linha['situacao']);
        self::assertSame('normal', $linha['prioridade']);
        self::assertFalse($linha['excluida']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $linha['abertaEm']);
    }

    #[TestDox('Contar devolve só o total, com os mesmos filtros')]
    public function testContar(): void
    {
        $client = $this->cliente();
        [$admin, $tenant] = $this->criarAdmin();
        $this->pasta($tenant, 'AG-1', 'MARIA');
        $this->pasta($tenant, 'AG-2', 'MARIA');
        $this->pasta($tenant, 'AG-3', 'JOÃO');
        $this->logarComTenant($client, $admin, $tenant);

        $client->request('GET', '/api/agente/pastas', ['contar' => '1', 'busca' => 'maria']);

        self::assertResponseIsSuccessful();
        self::assertSame(['total' => 2], $this->json($client));
    }

    #[TestDox('A busca é a da Lista: ignora acento e caixa, e casa o identificador e a ação')]
    public function testBuscaComoNaLista(): void
    {
        $client = $this->cliente();
        [$admin, $tenant] = $this->criarAdmin();
        $this->pasta($tenant, 'AG-1', 'JOÃO DA SILVA');
        $outra = $this->pasta($tenant, 'AG-2', 'MARIA');
        $outra->setNomeAcao('Revisional de aluguel');
        $this->em()->flush();
        $this->logarComTenant($client, $admin, $tenant);

        $client->request('GET', '/api/agente/pastas', ['busca' => 'joao']);
        self::assertSame(['AG-1'], self::nups($this->json($client)));

        $client->request('GET', '/api/agente/pastas', ['busca' => 'revisional']);
        self::assertSame(['AG-2'], self::nups($this->json($client)));
    }

    #[TestDox('cliente/acao do desenho e parâmetro em forma de lista são 422, nunca "todas as pastas"')]
    public function testParametrosQueALista422(): void
    {
        $client = $this->cliente();
        [$admin, $tenant] = $this->criarAdmin();
        $this->pasta($tenant, 'AG-1', 'A');
        $this->logarComTenant($client, $admin, $tenant);

        foreach (['cliente' => ['cliente' => 'a'], 'acao' => ['acao' => 'cobrança'], 'nups' => ['nups' => ['AG-1', 'AG-2']]] as $campo => $query) {
            $client->request('GET', '/api/agente/pastas', $query);
            self::assertResponseStatusCodeSame(422, $campo);
            self::assertSame($campo, $this->json($client)['campo']);
        }
    }

    #[TestDox('Responsável de outro escritório é 422, não "nenhuma pasta dele"')]
    public function testResponsavelDeOutroEscritorio(): void
    {
        $client = $this->cliente();
        [$admin, $tenant] = $this->criarAdmin();
        [$adminOutro] = $this->criarAdmin();
        $this->pasta($tenant, 'AG-1', 'A', $admin);
        $this->logarComTenant($client, $admin, $tenant);

        $client->request('GET', '/api/agente/pastas', ['responsavel' => (string) $adminOutro->getId()]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('responsavel', $this->json($client)['campo']);
    }

    #[TestDox('Filtros de prioridade, responsável e situação são os da Lista')]
    public function testFiltros(): void
    {
        $client = $this->cliente();
        [$admin, $tenant] = $this->criarAdmin();
        $urgente = $this->pasta($tenant, 'AG-URG', 'A', $admin);
        $urgente->setPrioridade(PrioridadePasta::Urgente);
        $arquivada = $this->pasta($tenant, 'AG-ARQ', 'B');
        $arquivada->setSituacao(Pasta::SITUACAO_ARQUIVADA);
        $this->pasta($tenant, 'AG-NOR', 'C');
        $this->em()->flush();
        $this->logarComTenant($client, $admin, $tenant);

        $client->request('GET', '/api/agente/pastas', ['prioridade' => 'urgente']);
        self::assertSame(['AG-URG'], self::nups($this->json($client)));

        $client->request('GET', '/api/agente/pastas', ['responsavel' => (string) $admin->getId()]);
        self::assertSame(['AG-URG'], self::nups($this->json($client)));

        $client->request('GET', '/api/agente/pastas', ['situacao' => 'arquivado']);
        self::assertSame(['AG-ARQ'], self::nups($this->json($client)));
    }

    #[TestDox('Filtro por marcador do escritório; marcador de outro escritório é 422, não lista vazia')]
    public function testMarcador(): void
    {
        $client = $this->cliente();
        [$admin, $tenant] = $this->criarAdmin();
        [$adminOutro, $outro] = $this->criarAdmin();
        $despachar = $this->marcador($tenant, $admin, 'Despachar');
        $alheio = $this->marcador($outro, $adminOutro, 'Alheio');
        $this->pasta($tenant, 'AG-M', 'A')->addMarcador($despachar);
        $this->pasta($tenant, 'AG-SEM', 'B');
        $this->em()->flush();
        $this->logarComTenant($client, $admin, $tenant);

        $client->request('GET', '/api/agente/pastas', ['marcador' => (string) $despachar->getId()]);
        self::assertResponseIsSuccessful();
        $corpo = $this->json($client);
        self::assertSame(['AG-M'], self::nups($corpo));
        self::assertSame('Despachar', $corpo['pastas'][0]['marcadores'][0]['nome']);

        $client->request('GET', '/api/agente/pastas', ['marcador' => (string) $alheio->getId()]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('marcador', $this->json($client)['campo']);
    }

    #[TestDox('Lista de NUPs devolve só as pastas pedidas, e nunca a de outro escritório com o mesmo NUP')]
    public function testNups(): void
    {
        $client = $this->cliente();
        [$admin, $tenant] = $this->criarAdmin();
        [, $outro] = $this->criarAdmin();
        $this->pasta($tenant, 'AG-101', 'A');
        $this->pasta($tenant, 'AG-102', 'B');
        $this->pasta($tenant, 'AG-103', 'C');
        $this->pasta($outro, 'AG-101', 'DE OUTRO');
        $this->logarComTenant($client, $admin, $tenant);

        $this->pasta($tenant, 'AG-99', 'D');
        $client->request('GET', '/api/agente/pastas', ['nups' => 'AG-103,AG-101,AG-99']);

        self::assertResponseIsSuccessful();
        $corpo = $this->json($client);
        self::assertSame(['AG-99', 'AG-101', 'AG-103'], array_column($corpo['pastas'], 'nup'), 'ordem natural do NUP');
        self::assertSame(3, $corpo['total']);
        self::assertNotContains('DE OUTRO', array_column($corpo['pastas'], 'identificador'));
    }

    #[TestDox('Lápide aparece marcada, como na Lista')]
    public function testLapide(): void
    {
        $client = $this->cliente();
        [$admin, $tenant] = $this->criarAdmin();
        $this->pasta($tenant, 'AG-X', 'A')->marcarExcluida($admin, new \DateTimeImmutable());
        $this->em()->flush();
        $this->logarComTenant($client, $admin, $tenant);

        $client->request('GET', '/api/agente/pastas');

        self::assertTrue($this->json($client)['pastas'][0]['excluida']);
    }

    #[TestDox('Filtro torto é 422 com o campo, nunca "todas as pastas"')]
    public function testFiltroInvalido(): void
    {
        $client = $this->cliente();
        [$admin, $tenant] = $this->criarAdmin();
        $this->pasta($tenant, 'AG-1', 'A');
        $this->logarComTenant($client, $admin, $tenant);

        $client->request('GET', '/api/agente/pastas', ['situacao' => 'suspenso']);

        self::assertResponseStatusCodeSame(422);
        $corpo = $this->json($client);
        self::assertSame('situacao', $corpo['campo']);
        self::assertArrayNotHasKey('pastas', $corpo);
    }

    #[TestDox('Sem o módulo Expediente: 403 nas três rotas')]
    public function testSemModulo(): void
    {
        $client = $this->cliente();
        [$admin, $tenant] = $this->criarAdmin();
        $pasta = $this->pasta($tenant, 'AG-1', 'A');
        $semModulo = $this->criarUsuarioComPermissoes($tenant, ['modules.inteligencia.view'], 'sem_expediente');
        $this->logarComTenant($client, $semModulo, $tenant);

        foreach (['/api/agente/pastas', '/api/agente/opcoes', '/api/agente/pastas/' . $pasta->getId() . '/documentos'] as $url) {
            $client->request('GET', $url);
            self::assertResponseStatusCodeSame(403, $url);
        }
    }

    #[TestDox('Com só o módulo Expediente (sem acesso a pasta alguma) a busca funciona, como a Lista')]
    public function testSoComOModulo(): void
    {
        $client = $this->cliente();
        [, $tenant] = $this->criarAdmin();
        $this->pasta($tenant, 'AG-1', 'A');
        $colega = $this->criarUsuarioComPermissoes($tenant, ['modules.expediente.view'], 'so_expediente');
        $this->logarComTenant($client, $colega, $tenant);

        $client->request('GET', '/api/agente/pastas');

        self::assertResponseIsSuccessful();
        self::assertSame(['AG-1'], self::nups($this->json($client)));
    }

    // ── listar_documentos ───────────────────────────────────────────────────────

    #[TestDox('Documentos da pasta, sem os da lixeira, com o formato do contrato')]
    public function testDocumentos(): void
    {
        $client = $this->cliente();
        [$admin, $tenant] = $this->criarAdmin();
        $pasta = $this->pasta($tenant, 'AG-DOC', 'A');
        $this->documento($pasta, $tenant, 'Procuração');
        $this->documento($pasta, $tenant, 'Contrato')->marcarExcluido($admin, new \DateTimeImmutable());
        $this->em()->flush();
        $this->logarComTenant($client, $admin, $tenant);

        $client->request('GET', '/api/agente/pastas/' . $pasta->getId() . '/documentos');

        self::assertResponseIsSuccessful();
        $corpo = $this->json($client);
        self::assertSame(['id' => $pasta->getId(), 'nup' => 'AG-DOC'], $corpo['pasta']);
        self::assertCount(1, $corpo['documentos']);
        $doc = $corpo['documentos'][0];
        self::assertSame('PROCURAÇÃO', $doc['titulo']);
        self::assertSame('procuração.pdf', $doc['nome']);
        self::assertSame('application/pdf', $doc['tipo']);
        self::assertSame(2048, $doc['tamanho']);
        self::assertNull($doc['secao']);
        self::assertArrayHasKey('sha256', $doc);
        self::assertArrayNotHasKey('caminhoArquivo', $doc);
    }

    #[TestDox('Pasta de outro escritório: 404 (não revela que existe)')]
    public function testDocumentosDeOutroEscritorio(): void
    {
        $client = $this->cliente();
        [$admin, $tenant] = $this->criarAdmin();
        [, $outro] = $this->criarAdmin();
        $alheia = $this->pasta($outro, 'AG-ALHEIA', 'A');
        $this->documento($alheia, $outro, 'Segredo');
        $this->logarComTenant($client, $admin, $tenant);

        $client->request('GET', '/api/agente/pastas/' . $alheia->getId() . '/documentos');

        self::assertResponseStatusCodeSame(404);
        self::assertStringNotContainsString('SEGREDO', (string) $client->getResponse()->getContent());
    }

    #[TestDox('Colega com o módulo mas sem acesso à pasta: 403; com acesso de leitura, passa (prova do guarda)')]
    public function testDocumentosSemAcessoAPasta(): void
    {
        $client = $this->cliente();
        [$admin, $tenant] = $this->criarAdmin();
        $pasta = $this->pasta($tenant, 'AG-RESTRITA', 'A');
        $this->documento($pasta, $tenant, 'Sigiloso');
        $colega = $this->criarUsuarioComPermissoes($tenant, ['modules.expediente.view'], 'sem_acesso');
        $this->logarComTenant($client, $colega, $tenant);
        $url = '/api/agente/pastas/' . $pasta->getId() . '/documentos';

        $client->request('GET', $url);
        self::assertResponseStatusCodeSame(403);
        self::assertStringNotContainsString('SIGILOSO', (string) $client->getResponse()->getContent());

        $acesso = new ResourceAccess();
        $acesso->setUser($colega);
        $acesso->setTenant($tenant);
        $acesso->setResourceType(ResourceAccess::RESOURCE_PASTA);
        $acesso->setResourceId((int) $pasta->getId());
        $acesso->setCanView(true);
        $acesso->setGrantedBy($admin);
        $this->em()->persist($acesso);
        $this->em()->flush();

        $client->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertSame('SIGILOSO', $this->json($client)['documentos'][0]['titulo']);
    }

    // ── opções ──────────────────────────────────────────────────────────────────

    #[TestDox('Opções: responsáveis e marcadores só do escritório, mais as situações e prioridades válidas')]
    public function testOpcoes(): void
    {
        $client = $this->cliente();
        [$admin, $tenant] = $this->criarAdmin();
        [$adminOutro, $outro] = $this->criarAdmin();
        $this->marcador($tenant, $admin, 'Despachar');
        $this->marcador($outro, $adminOutro, 'Alheio');
        $this->logarComTenant($client, $admin, $tenant);

        $client->request('GET', '/api/agente/opcoes');

        self::assertResponseIsSuccessful();
        $corpo = $this->json($client);
        self::assertSame(['Despachar'], array_column($corpo['marcadores'], 'nome'));
        self::assertContains($admin->getId(), array_column($corpo['responsaveis'], 'id'));
        self::assertNotContains($adminOutro->getId(), array_column($corpo['responsaveis'], 'id'));
        self::assertSame(['ativo', 'arquivado'], array_column($corpo['situacoes'], 'valor'));
        self::assertSame(['normal', 'prioridade', 'urgente'], array_column($corpo['prioridades'], 'valor'));
    }
}
