<?php

declare(strict_types=1);

namespace App\Tests\Expediente\Functional;

use App\Entity\Auth\User;
use App\Entity\Permission\ResourceAccess;
use App\Entity\Tenant\Tenant;
use App\Expediente\Controller\LixeiraDoEscritorioController;
use App\Pasta\Entity\AbaDaPasta;
use App\Pasta\Entity\AbaOcultaDaPasta;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Entity\PastaSecao;
use App\Pasta\Repository\PastaDocumentoRepository;
use App\Pasta\Repository\PastaSecaoRepository;
use App\Pasta\UseCase\ListarLixeiraDoEscritorioUseCase;
use App\Shared\Doctrine\Filter\LixeiraFilter;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Pasta\Functional\CriaUsuarioComPermissoesTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * `GET /expediente/lixeira` (lixeira do escritório, 03/11/2026), com banco: a agregação por pasta,
 * o isolamento entre escritórios, a permissão por pasta, o contrato de honorários oculto, a
 * subárvore de uma subpasta (as contas batem com a lixeira da própria pasta) e as guardas
 * (anônimo, sem o módulo). Também a tela: o botão e a janela estão no Expediente.
 */
#[CoversClass(LixeiraDoEscritorioController::class)]
#[CoversClass(ListarLixeiraDoEscritorioUseCase::class)]
#[CoversClass(PastaDocumentoRepository::class)]
#[CoversClass(PastaSecaoRepository::class)]
final class LixeiraDoEscritorioControllerTest extends JusPrimeWebTestCase
{
    use CriaUsuarioComPermissoesTrait;

    private const QUEM_LE = ['modules.expediente.view', 'resources.pasta.view', 'resources.pasta.edit'];

    #[TestDox('anônimo é mandado ao login; sem o módulo Expediente é 403')]
    public function testGuardas(): void
    {
        $client = $this->cliente();
        $client->request('GET', '/expediente/lixeira');
        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $client->getResponse()->headers->get('Location'));

        $tenant = $this->criarTenant();
        $semModulo = $this->criarUsuarioComPermissoes($tenant, ['resources.pasta.edit']);
        $this->logarComTenant($client, $semModulo, $tenant);
        $client->request('GET', '/expediente/lixeira');
        self::assertResponseStatusCodeSame(403);
    }

    #[TestDox('agrupa por pasta: quantos documentos e subpastas, a exclusão mais antiga e a mais recente, quem excluiu; escritório alheio não aparece')]
    public function testContasEIsolamento(): void
    {
        $client = $this->cliente();
        $tenant = $this->criarTenant();
        $quem   = $this->criarUsuarioComPermissoes($tenant, self::QUEM_LE);
        $ana    = $this->pessoa($tenant, 'Ana Excluiu');
        $bruno  = $this->pessoa($tenant, 'Bruno Excluiu');
        $pasta  = $this->criarPasta($tenant, 'LIXE-1', 'MARIA DA SILVA');
        $this->marcar('pasta_documento', $this->criarDocumento($pasta, $tenant, null, 'a.pdf')->getId(), $ana, '2026-10-01 09:00:00');
        $this->marcar('pasta_documento', $this->criarDocumento($pasta, $tenant, null, 'b.pdf')->getId(), $bruno, '2026-10-03 11:00:00');
        $this->criarDocumento($pasta, $tenant, null, 'vivo.pdf');
        $this->marcar('pasta_secao', $this->criarSecao($pasta, $tenant, 'VAZIA')->getId(), $ana, '2026-10-02 10:00:00');

        $alheio      = $this->criarTenant();
        $pastaAlheia = $this->criarPasta($alheio, 'ALHEIA-1');
        $this->marcar('pasta_documento', $this->criarDocumento($pastaAlheia, $alheio, null, 'alheio.pdf')->getId(), $ana, '2026-10-04 09:00:00');

        $json = $this->pedir($client, $quem, $tenant);

        self::assertSame([(int) $pasta->getId()], array_column($json['grupos'], 'pastaId'), 'só a pasta deste escritório');
        $g = $json['grupos'][0];
        self::assertSame(['LIXE-1', 'MARIA DA SILVA', 2, 1, false], [$g['nup'], $g['nome'], $g['documentos'], $g['subpastas'], $g['pastaExcluida']]);
        self::assertSame(self::atom('2026-10-01 09:00:00'), $g['maisAntiga']);
        self::assertSame(self::atom('2026-10-03 11:00:00'), $g['maisRecente']);
        self::assertSame(['Ana Excluiu', 'Bruno Excluiu'], $g['excluidoPor']);
        self::assertSame('/pasta/' . $pasta->getId() . '/documentos/lixeira', $g['urlLixeira']);
        self::assertSame('/pasta/' . $pasta->getId() . '/documentos/restaurar', $g['urlRestaurar']);
        self::assertSame('/pasta/' . $pasta->getId() . '/documentos/excluir-de-vez', $g['urlExcluirDeVez']);
        self::assertTrue(static::getContainer()->get(CsrfTokenManagerInterface::class)->isTokenValid(new CsrfToken('pex_lote_' . $pasta->getId(), $g['csrfLote'])), 'o token de lote da pasta');
        self::assertStringContainsString('pex_lixeira=1', $g['urlPasta']);
        self::assertNull($json['proximo']);
        self::assertTrue($this->em()->getFilters()->isEnabled(LixeiraFilter::NOME), 'o filtro da lixeira volta ligado');
    }

    #[TestDox('Restaurar tudo pela rota da pasta, com a URL e o token que o grupo entrega: a pasta sai da lista')]
    public function testRestaurarTudoPelaRotaDaPasta(): void
    {
        $client = $this->cliente();
        $tenant = $this->criarTenant();
        $quem   = $this->criarUsuarioComPermissoes($tenant, self::QUEM_LE);
        $pasta  = $this->criarPasta($tenant, 'VOLTA');
        $doc    = $this->criarDocumento($pasta, $tenant, null, 'volta.pdf');
        $this->marcar('pasta_documento', $doc->getId(), $quem, '2026-10-01 09:00:00');

        $g = $this->pedir($client, $quem, $tenant)['grupos'][0];
        $itens = $this->lixeiraDaPasta($client, $pasta)['itens'];
        $this->em()->clear();
        $client->request('POST', $g['urlRestaurar'], ['_token' => $g['csrfLote'], 'documentos' => array_map(static fn (array $i): string => (string) $i['id'], $itens)]);
        self::assertResponseIsSuccessful((string) $client->getResponse()->getContent());

        self::assertSame([], $this->pedir($client, $quem, $tenant)['grupos']);
    }

    #[TestDox('por que a janela manda só as raízes: a filha num lote seguinte ao da mãe dá 404; raízes em rodadas restauram tudo, até o que saiu antes com outro carimbo')]
    public function testRestaurarSoAsRaizesEmRodadas(): void
    {
        $client = $this->cliente();
        $tenant = $this->criarTenant();
        $quem   = $this->criarUsuarioComPermissoes($tenant, self::QUEM_LE);
        $pasta  = $this->criarPasta($tenant, 'RAIZES');
        $a      = $this->criarSecao($pasta, $tenant, 'A');
        $emA    = $this->criarDocumento($pasta, $tenant, $a, 'em-a.pdf');
        $antes  = $this->criarDocumento($pasta, $tenant, $a, 'antes.pdf');
        $this->logarComTenant($client, $quem, $tenant);
        $token = $this->tokenDoLote($client, $quem, $tenant, $pasta);
        $this->excluirLote($client, $pasta, $token, ['documentos' => [(string) $antes->getId()]]);
        $this->excluirLote($client, $pasta, $token, ['secoes' => [(string) $a->getId()]]);
        // "antes" saiu numa exclusão anterior, com outro carimbo (no mesmo segundo os dois carimbos coincidiriam).
        $this->em()->getConnection()->executeStatement("UPDATE pasta_documento SET excluido_em = '2026-10-01 09:00:00' WHERE id = :id", ['id' => $antes->getId()]);

        // Lote 1 com a mãe; lote 2 com o filho que já voltou junto: 404 (o que a janela evitava mal antes).
        $this->restaurar($client, $pasta, $token, ['secoes' => [(string) $a->getId()]]);
        self::assertResponseIsSuccessful();
        $this->restaurar($client, $pasta, $token, ['documentos' => [(string) $emA->getId()]]);
        self::assertResponseStatusCodeSame(404);

        // O que ficou ("antes", outro carimbo) agora está numa seção viva: é raiz na leitura seguinte.
        $itens = $this->lixeiraDaPasta($client, $pasta)['itens'];
        self::assertSame([[(int) $antes->getId(), false]], array_map(static fn (array $i): array => [$i['id'], $i['secaoNaLixeira']], $itens));
        $this->restaurar($client, $pasta, $token, ['documentos' => [(string) $antes->getId()]]);
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->lixeiraDaPasta($client, $pasta)['itens']);
    }

    #[TestDox('número fixo de consultas por página: 2 pastas ou 8 pastas, a mesma conta')]
    public function testConsultasFixasPorPagina(): void
    {
        $client = $this->cliente();
        $poucas = $this->criarTenant();
        $muitas = $this->criarTenant();
        $quemPoucas = $this->criarUsuarioComPermissoes($poucas, ['modules.expediente.view', 'resources.pasta.view']);
        $quemMuitas = $this->criarUsuarioComPermissoes($muitas, ['modules.expediente.view', 'resources.pasta.view']);
        $this->comPastas($poucas, $quemPoucas, 2);
        $this->comPastas($muitas, $quemMuitas, 8);

        // O primeiro pedido do kernel aquece caches (metadados, catálogo): não entra na conta.
        $this->consultasDoPedido($client, $quemMuitas, $muitas);
        $comPoucas = $this->consultasDoPedido($client, $quemPoucas, $poucas);
        $comMuitas = $this->consultasDoPedido($client, $quemMuitas, $muitas);

        self::assertSame(2, $this->contarGrupos($client, $quemPoucas, $poucas), 'pré-condição');
        self::assertSame(8, $this->contarGrupos($client, $quemMuitas, $muitas), 'pré-condição');

        self::assertSame($comPoucas, $comMuitas, 'a conta não cresce com o número de pastas');
    }

    #[TestDox('pasta em que a pessoa não pode editar (restaurar) não aparece')]
    public function testPastaSemPermissaoNaoAparece(): void
    {
        $client  = $this->cliente();
        $tenant  = $this->criarTenant();
        $quem    = $this->criarUsuarioComPermissoes($tenant, ['modules.expediente.view', 'resources.pasta.view']);
        $minha   = $this->criarPasta($tenant, 'MINHA');
        $outra   = $this->criarPasta($tenant, 'OUTRA');
        $this->darEdicao($quem, $tenant, $minha);
        $this->marcar('pasta_documento', $this->criarDocumento($minha, $tenant, null, 'm.pdf')->getId(), $quem, '2026-10-01 09:00:00');
        $this->marcar('pasta_documento', $this->criarDocumento($outra, $tenant, null, 'o.pdf')->getId(), $quem, '2026-10-02 09:00:00');

        $json = $this->pedir($client, $quem, $tenant);

        self::assertSame([(int) $minha->getId()], array_column($json['grupos'], 'pastaId'));
    }

    #[TestDox('contrato de honorários com o Financeiro oculto não conta; pasta em que só ele está na lixeira não aparece')]
    public function testContratoOcultoNaoConta(): void
    {
        $client = $this->cliente();
        $tenant = $this->criarTenant();
        $quem   = $this->criarUsuarioComPermissoes($tenant, self::QUEM_LE);
        $comum  = $this->criarPasta($tenant, 'COMUM');
        $soContrato = $this->criarPasta($tenant, 'SO-CONTRATO');
        $this->marcar('pasta_documento', $this->criarDocumento($comum, $tenant, null, 'peticao.pdf')->getId(), $quem, '2026-10-01 09:00:00');
        $this->marcar('pasta_documento', $this->criarDocumento($comum, $tenant, null, 'contrato.pdf', PastaDocumento::CATEGORIA_CONTRATO)->getId(), $quem, '2026-10-05 09:00:00');
        $this->marcar('pasta_documento', $this->criarDocumento($soContrato, $tenant, null, 'contrato.pdf', PastaDocumento::CATEGORIA_CONTRATO)->getId(), $quem, '2026-10-06 09:00:00');
        $this->ocultarFinanceiro($comum, $tenant, $quem);
        $this->ocultarFinanceiro($soContrato, $tenant, $quem);

        $json = $this->pedir($client, $quem, $tenant);

        self::assertSame([(int) $comum->getId()], array_column($json['grupos'], 'pastaId'));
        self::assertSame(1, $json['grupos'][0]['documentos']);
        self::assertSame(self::atom('2026-10-01 09:00:00'), $json['grupos'][0]['maisRecente'], 'a data do contrato oculto não aparece');

        $lixeiraDaPasta = $this->lixeiraDaPasta($client, $comum);
        self::assertSame($lixeiraDaPasta['totalArquivos'], $json['grupos'][0]['documentos'], 'a mesma conta da lixeira da pasta');
    }

    #[TestDox('subpasta na lixeira conta com a subárvore (filhas e documentos), como a lixeira da pasta')]
    public function testSubarvoreContaComoNaLixeiraDaPasta(): void
    {
        $client = $this->cliente();
        $tenant = $this->criarTenant();
        $quem   = $this->criarUsuarioComPermissoes($tenant, self::QUEM_LE);
        $pasta  = $this->criarPasta($tenant, 'ARVORE');
        $a      = $this->criarSecao($pasta, $tenant, 'A');
        $b      = $this->criarSecao($pasta, $tenant, 'B', $a);
        $this->criarDocumento($pasta, $tenant, $a, 'em-a.pdf');
        $this->criarDocumento($pasta, $tenant, $b, 'em-b.pdf');
        $this->criarDocumento($pasta, $tenant, null, 'vivo.pdf');

        $this->logarComTenant($client, $quem, $tenant);
        $this->em()->clear();
        $client->request('POST', "/pasta/{$pasta->getId()}/documentos/excluir-lote", ['_token' => 'TOKEN_pex_lote_' . $pasta->getId(), 'secoes' => [(string) $a->getId()]]);
        self::assertResponseIsSuccessful('pré-condição: excluir-lote ' . (string) $client->getResponse()->getContent());

        $json = $this->pedir($client, $quem, $tenant);
        $lixeiraDaPasta = $this->lixeiraDaPasta($client, $pasta);

        self::assertSame([2, 2], [$json['grupos'][0]['documentos'], $json['grupos'][0]['subpastas']]);
        self::assertSame([$lixeiraDaPasta['totalArquivos'], $lixeiraDaPasta['totalPastas']], [$json['grupos'][0]['documentos'], $json['grupos'][0]['subpastas']]);
    }

    #[TestDox('pasta excluída (lápide) aparece só para olhar: sem URLs de escrita nem token')]
    public function testLapideSemAcoes(): void
    {
        $client = $this->cliente();
        $tenant = $this->criarTenant();
        $quem   = $this->criarUsuarioComPermissoes($tenant, self::QUEM_LE);
        $pasta  = $this->criarPasta($tenant, 'LAPIDE');
        $this->marcar('pasta_documento', $this->criarDocumento($pasta, $tenant, null, 'x.pdf')->getId(), $quem, '2026-10-01 09:00:00');
        $this->em()->getConnection()->executeStatement('UPDATE pasta SET excluida_em = NOW() WHERE id = :id', ['id' => $pasta->getId()]);

        $g = $this->pedir($client, $quem, $tenant)['grupos'][0];

        self::assertTrue($g['pastaExcluida']);
        self::assertArrayNotHasKey('urlRestaurar', $g);
        self::assertArrayNotHasKey('csrfLote', $g);
        self::assertArrayHasKey('urlLixeira', $g);
    }

    #[TestDox('o Expediente tem o botão Lixeira e a janela que lê a rota')]
    public function testTelaTemBotaoEJanela(): void
    {
        $client = $this->cliente();
        $tenant = $this->criarTenant();
        $quem   = $this->criarUsuarioComPermissoes($tenant, self::QUEM_LE);
        $this->logarComTenant($client, $quem, $tenant);

        $crawler = $client->request('GET', '/expediente');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.content-header > .container-fluid > button#expLixeiraAbrir'));
        self::assertSame('/expediente/lixeira', $crawler->filter('#expLixeira')->attr('data-url'));
        self::assertCount(1, $crawler->filter('script[src*="expediente-lixeira.js"]'));
        // O mesmo modal da lixeira da pasta abre aqui (03/11/2026): tipos e modal ANTES do script da janela,
        // com o catálogo e o CSS do explorador.
        $scripts = $crawler->filter('script[src]')->each(static fn ($n): string => (string) $n->attr('src'));
        $posicao = static function (string $nome) use ($scripts): int {
            foreach ($scripts as $i => $src) {
                if (str_contains($src, $nome)) {
                    return $i;
                }
            }
            self::fail("script {$nome} ausente");
        };
        self::assertLessThan($posicao('pasta-lixeira.js'), $posicao('pasta-tipos-de-arquivo.js'));
        self::assertLessThan($posicao('expediente-lixeira.js'), $posicao('pasta-lixeira.js'));
        self::assertCount(1, $crawler->filter('link[href*="css/pasta-explorador.css"]'));
        self::assertStringContainsString('"pasta_scripts.js.explorador.lix_abrir"', (string) $client->getResponse()->getContent());
    }

    // ── apoio ──────────────────────────────────────────────────────────────────

    private function cliente(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();
        $storage = new class () implements ClearableTokenStorageInterface {
            public function getToken(string $tokenId): string
            {
                return 'TOKEN_' . $tokenId;
            }
            public function setToken(string $tokenId, string $token): void
            {
            }
            public function removeToken(string $tokenId): ?string
            {
                return null;
            }
            public function hasToken(string $tokenId): bool
            {
                return true;
            }
            public function clear(): void
            {
            }
        };
        static::getContainer()->set('security.csrf.token_storage', $storage);

        return $client;
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    /** @return array<string, mixed> */
    private function pedir(KernelBrowser $client, User $quem, Tenant $tenant): array
    {
        $this->logarComTenant($client, $quem, $tenant);
        $this->em()->clear();
        $client->request('GET', '/expediente/lixeira', [], [], ['HTTP_X-Requested-With' => 'XMLHttpRequest']);
        self::assertResponseIsSuccessful((string) $client->getResponse()->getContent());

        return json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    private function lixeiraDaPasta(KernelBrowser $client, Pasta $pasta): array
    {
        $this->em()->clear();
        $client->request('GET', "/pasta/{$pasta->getId()}/documentos/lixeira", [], [], ['HTTP_X-Requested-With' => 'XMLHttpRequest']);
        self::assertResponseIsSuccessful((string) $client->getResponse()->getContent());

        return json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function pessoa(Tenant $tenant, string $nome): User
    {
        $user = $this->criarUsuarioComPermissoes($tenant, self::QUEM_LE);
        $user->setFullName($nome);
        $this->em()->flush();

        return $user;
    }

    /** Pastas com um documento na lixeira e acesso de editar item a item (o caminho que consulta por pasta). */
    private function comPastas(Tenant $tenant, User $quem, int $n): void
    {
        for ($i = 0; $i < $n; ++$i) {
            $pasta = $this->criarPasta($tenant, 'Q-' . uniqid());
            $this->darEdicao($quem, $tenant, $pasta);
            $this->marcar('pasta_documento', $this->criarDocumento($pasta, $tenant, null, 'q.pdf')->getId(), $quem, '2026-10-01 09:00:00');
            $this->ocultarFinanceiro($pasta, $tenant, $quem);
        }
    }

    private function contarGrupos(KernelBrowser $client, User $quem, Tenant $tenant): int
    {
        return \count($this->pedir($client, $quem, $tenant)['grupos']);
    }

    private function consultasDoPedido(KernelBrowser $client, User $quem, Tenant $tenant): int
    {
        $this->logarComTenant($client, $quem, $tenant);
        $this->em()->clear();
        $client->enableProfiler();
        $client->request('GET', '/expediente/lixeira', [], [], ['HTTP_X-Requested-With' => 'XMLHttpRequest']);
        self::assertResponseIsSuccessful();
        $perfil = $client->getProfile();
        self::assertNotFalse($perfil);
        /** @var \Doctrine\Bundle\DoctrineBundle\DataCollector\DoctrineDataCollector $banco */
        $banco = $perfil->getCollector('db');

        return $banco->getQueryCount();
    }

    private function tokenDoLote(KernelBrowser $client, User $quem, Tenant $tenant, Pasta $pasta): string
    {
        return 'TOKEN_pex_lote_' . $pasta->getId();
    }

    /** @param array<string, list<string>> $ids */
    private function excluirLote(KernelBrowser $client, Pasta $pasta, string $token, array $ids): void
    {
        $this->em()->clear();
        $client->request('POST', "/pasta/{$pasta->getId()}/documentos/excluir-lote", ['_token' => $token] + $ids);
        self::assertResponseIsSuccessful('pré-condição: excluir-lote ' . (string) $client->getResponse()->getContent());
    }

    /** @param array<string, list<string>> $ids */
    private function restaurar(KernelBrowser $client, Pasta $pasta, string $token, array $ids): void
    {
        $this->em()->clear();
        $client->request('POST', "/pasta/{$pasta->getId()}/documentos/restaurar", ['_token' => $token] + $ids);
    }

    private function criarTenant(): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName('Lixeira do escritório ' . uniqid());
        $this->em()->persist($tenant);
        $this->em()->flush();

        return $tenant;
    }

    private function criarPasta(Tenant $tenant, string $nup, ?string $cliente = null): Pasta
    {
        $pasta = new Pasta();
        $pasta->setNup($nup);
        $pasta->setTenant($tenant);
        $pasta->setNomeCliente($cliente);
        $this->em()->persist($pasta);
        $this->em()->flush();

        return $pasta;
    }

    private function criarSecao(Pasta $pasta, Tenant $tenant, string $nome, ?PastaSecao $pai = null): PastaSecao
    {
        $secao = new PastaSecao();
        $secao->setPasta($pasta);
        $secao->setTenant($tenant);
        $secao->setNome($nome);
        $secao->setOrdem(1);
        if ($pai !== null) {
            $secao->setPai($pai);
        }
        $this->em()->persist($secao);
        $this->em()->flush();

        return $secao;
    }

    private function criarDocumento(Pasta $pasta, Tenant $tenant, ?PastaSecao $secao, string $nome, string $categoria = PastaDocumento::CATEGORIA_DEMAIS): PastaDocumento
    {
        $doc = new PastaDocumento();
        $doc->setTitulo($nome);
        $doc->setCategoria($categoria);
        $doc->setCaminhoArquivo('fake-' . bin2hex(random_bytes(6)) . '.pdf');
        $doc->setNomeOriginal($nome);
        $doc->setMimeType('application/pdf');
        $doc->setTamanhoBytes(10);
        $doc->setPasta($pasta);
        $doc->setTenant($tenant);
        $doc->setSecao($secao);
        $this->em()->persist($doc);
        $this->em()->flush();

        return $doc;
    }

    /** A lápide gravada por SQL, com a hora e o autor escolhidos (o caminho real é provado no subárvore). */
    private function marcar(string $tabela, ?int $id, User $por, string $em): void
    {
        $this->em()->getConnection()->executeStatement(
            "UPDATE {$tabela} SET excluido_em = :em, excluido_por_id = :por WHERE id = :id",
            ['em' => $em, 'por' => $por->getId(), 'id' => $id],
        );
    }

    private function darEdicao(User $user, Tenant $tenant, Pasta $pasta): void
    {
        $acesso = new ResourceAccess();
        $acesso->setUser($user);
        $acesso->setTenant($tenant);
        $acesso->setResourceType(ResourceAccess::RESOURCE_PASTA);
        $acesso->setResourceId((int) $pasta->getId());
        $acesso->setCanView(true);
        $acesso->setCanEdit(true);
        $this->em()->persist($acesso);
        $this->em()->flush();
    }

    private function ocultarFinanceiro(Pasta $pasta, Tenant $tenant, User $de): void
    {
        $this->em()->persist(AbaOcultaDaPasta::paraAlguns($tenant, $pasta, AbaDaPasta::Financeiro, [(int) $de->getId()], $de, new \DateTimeImmutable()));
        $this->em()->flush();
    }

    private static function atom(string $quando): string
    {
        return (new \DateTimeImmutable($quando))->format(\DateTimeInterface::ATOM);
    }
}
