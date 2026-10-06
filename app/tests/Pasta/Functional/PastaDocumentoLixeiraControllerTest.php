<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Permission\ResourceAccess;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Pasta\Armazenamento\ChavesDePasta;
use App\Pasta\Controller\PastaDocumentoController;
use App\Pasta\DTO\LixeiraDaPastaOutput;
use App\Pasta\DTO\TimelineItemDTO;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Entity\PastaSecao;
use App\Pasta\Service\PastaTimelineAssembler;
use App\Pasta\UseCase\ListarLixeiraDaPastaUseCase;
use App\Pasta\UseCase\RestaurarItensDaPastaUseCase;
use App\Shared\Armazenamento\ArmazenamentoDeArquivos;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\FonteDeConteudo;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * As duas rotas da lixeira (D7): `pasta_documentos_restaurar` e `pasta_documentos_lixeira`.
 *
 * Tudo ponta a ponta: o item vai para a lixeira pelo `excluir-lote` real e volta pelo `restaurar`
 * real. Isolamento provado pelos dois lados (outro escritório → 404; pasta IRMÃ do mesmo
 * escritório → 404 sem efeito parcial), item vivo na seleção → 404 sem efeito, CSRF 400, sem
 * permissão 403, teto 422. Cada caso negativo confere no banco que NADA mudou.
 *
 * Com `disableReboot()` o EntityManager sobrevive entre requests e o filtro é SQL (não esconde o
 * que já está no identity map): `limpar()` antes de cada request reproduz o EM vazio de produção.
 */
#[CoversClass(PastaDocumentoController::class)]
#[CoversClass(RestaurarItensDaPastaUseCase::class)]
#[CoversClass(ListarLixeiraDaPastaUseCase::class)]
#[CoversClass(LixeiraDaPastaOutput::class)]
final class PastaDocumentoLixeiraControllerTest extends JusPrimeWebTestCase
{
    /** @var list<ChaveDeArquivo> */
    private array $arquivosGravados = [];

    protected function tearDown(): void
    {
        if ($this->arquivosGravados !== []) {
            $armazenamento = static::getContainer()->get(ArmazenamentoDeArquivos::class);
            foreach ($this->arquivosGravados as $chave) {
                if ($armazenamento->existe($chave)) {
                    $armazenamento->excluir($chave);
                }
            }
            $this->arquivosGravados = [];
        }

        parent::tearDown();
    }

    // ── restaurar ──────────────────────────────────────────────────────────────

    #[TestDox('restaurar um documento: volta vivo, para a seção de origem (viva), reaparece no explorador e na visualização; auditoria registra')]
    public function testRestauraDocumento(): void
    {
        $client          = $this->cliente();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $viva            = $this->criarSecao($pasta, $tenant, 'VIVA');
        $doc             = $this->criarDocumento($pasta, $tenant, $viva, 'volta.pdf', $this->gravarArquivo($tenant, 'volta'));
        $this->logarComTenant($client, $user, $tenant);
        $this->mandarParaALixeira($client, $pasta, [(int) $doc->getId()], []);

        self::assertNotNull($this->naLixeira('pasta_documento', (int) $doc->getId()), 'pré-condição');

        $this->limpar();
        $client->request('POST', "/pasta/{$pasta->getId()}/documentos/restaurar", [
            '_token'     => $this->csrf('pex_lote_' . $pasta->getId()),
            'documentos' => [(string) $doc->getId()],
        ]);

        self::assertResponseIsSuccessful((string) $client->getResponse()->getContent());
        $json = $this->json($client);
        self::assertTrue($json['ok']);
        self::assertSame(['documentos' => 1, 'secoes' => 0], $json['restaurados']);
        self::assertSame(0, $json['paraARaiz']);

        self::assertNull($this->naLixeira('pasta_documento', (int) $doc->getId()));
        self::assertNull($this->excluidoPor('pasta_documento', (int) $doc->getId()));
        self::assertSame((int) $viva->getId(), $this->secaoDoDocumento((int) $doc->getId()), 'a origem está viva: volta para ela');

        $this->limpar();
        $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();
        self::assertContains((int) $doc->getId(), $this->idsDosArquivosDoExplorador((string) $client->getResponse()->getContent()));

        $this->limpar();
        $client->request('GET', "/pasta/documento/{$doc->getId()}/visualizar");
        self::assertResponseIsSuccessful('restaurado: volta a se ver');

        self::assertSame(2, $this->auditorias('update', PastaDocumento::class, [(int) $doc->getId()]), 'ida e volta, as duas no histórico');
    }

    #[TestDox('restaurar uma subpasta devolve a subárvore inteira (mesmo carimbo), com a estrutura intacta')]
    public function testRestauraSubarvore(): void
    {
        $client          = $this->cliente();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $a               = $this->criarSecao($pasta, $tenant, 'A');
        $b               = $this->criarSecao($pasta, $tenant, 'B', $a);
        $emA             = $this->criarDocumento($pasta, $tenant, $a, 'em-a.pdf');
        $emB             = $this->criarDocumento($pasta, $tenant, $b, 'em-b.pdf');
        $this->logarComTenant($client, $user, $tenant);
        $this->mandarParaALixeira($client, $pasta, [], [(int) $a->getId()]);

        foreach ([['pasta_secao', $a], ['pasta_secao', $b], ['pasta_documento', $emA], ['pasta_documento', $emB]] as [$tabela, $item]) {
            self::assertNotNull($this->naLixeira($tabela, (int) $item->getId()), 'pré-condição: a árvore inteira foi');
        }

        $this->limpar();
        $client->request('POST', "/pasta/{$pasta->getId()}/documentos/restaurar", [
            '_token' => $this->csrf('pex_lote_' . $pasta->getId()),
            'secoes' => [(string) $a->getId()],
        ]);

        self::assertResponseIsSuccessful((string) $client->getResponse()->getContent());
        $json = $this->json($client);
        self::assertSame(['documentos' => 2, 'secoes' => 2], $json['restaurados']);
        self::assertSame(0, $json['paraARaiz']);

        foreach ([['pasta_secao', $a], ['pasta_secao', $b], ['pasta_documento', $emA], ['pasta_documento', $emB]] as [$tabela, $item]) {
            self::assertNull($this->naLixeira($tabela, (int) $item->getId()), "{$tabela} #{$item->getId()} devia ter voltado");
        }
        self::assertSame((int) $a->getId(), $this->paiDaSecao((int) $b->getId()), 'B continua dentro de A');
        self::assertSame((int) $b->getId(), $this->secaoDoDocumento((int) $emB->getId()));
        self::assertSame((int) $a->getId(), $this->secaoDoDocumento((int) $emA->getId()));
    }

    #[TestDox('pai ausente → raiz: restaurar só a filha (ou só o documento) de uma subpasta que continua na lixeira devolve o item à raiz')]
    public function testPaiNaLixeiraDevolveParaARaiz(): void
    {
        $client          = $this->cliente();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $a               = $this->criarSecao($pasta, $tenant, 'A');
        $b               = $this->criarSecao($pasta, $tenant, 'B', $a);
        $emA             = $this->criarDocumento($pasta, $tenant, $a, 'em-a.pdf');
        $emB             = $this->criarDocumento($pasta, $tenant, $b, 'em-b.pdf');
        $this->logarComTenant($client, $user, $tenant);
        $this->mandarParaALixeira($client, $pasta, [], [(int) $a->getId()]);

        $this->limpar();
        $client->request('POST', "/pasta/{$pasta->getId()}/documentos/restaurar", [
            '_token'     => $this->csrf('pex_lote_' . $pasta->getId()),
            'documentos' => [(string) $emA->getId()],
            'secoes'     => [(string) $b->getId()],
        ]);

        self::assertResponseIsSuccessful((string) $client->getResponse()->getContent());
        $json = $this->json($client);
        self::assertSame(['documentos' => 2, 'secoes' => 1], $json['restaurados'], 'B volta com o em-b (mesmo carimbo); em-a volta sozinho');
        self::assertSame(2, $json['paraARaiz']);

        self::assertNull($this->naLixeira('pasta_secao', (int) $b->getId()));
        self::assertNull($this->paiDaSecao((int) $b->getId()), 'A continua na lixeira: B vira raiz');
        self::assertNull($this->naLixeira('pasta_documento', (int) $emA->getId()));
        self::assertNull($this->secaoDoDocumento((int) $emA->getId()), 'A continua na lixeira: em-a vai para a raiz');
        self::assertNull($this->naLixeira('pasta_documento', (int) $emB->getId()));
        self::assertSame((int) $b->getId(), $this->secaoDoDocumento((int) $emB->getId()), 'em-b fica em B, que voltou');
        self::assertNotNull($this->naLixeira('pasta_secao', (int) $a->getId()), 'A não foi pedida: fica na lixeira');

        // E a tela reflete: os três estão na lista, B na raiz.
        $this->limpar();
        $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();
        $dados = $this->explorador((string) $client->getResponse()->getContent());
        $pastasPorId = array_column($dados['pastas'], null, 'id');
        self::assertArrayHasKey((int) $b->getId(), $pastasPorId);
        self::assertNull($pastasPorId[(int) $b->getId()]['paiId']);
        self::assertArrayNotHasKey((int) $a->getId(), $pastasPorId);
    }

    #[TestDox('restaurar com item de PASTA IRMÃ do mesmo escritório na seleção: 404, nada volta')]
    public function testRestaurarItemDePastaIrma(): void
    {
        $client          = $this->cliente();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $irma            = $this->criarPasta($tenant);
        $meu             = $this->criarDocumento($pasta, $tenant, null, 'meu.pdf');
        $daIrma          = $this->criarDocumento($irma, $tenant, null, 'da-irma.pdf');
        $this->logarComTenant($client, $user, $tenant);
        $this->mandarParaALixeira($client, $pasta, [(int) $meu->getId()], []);
        $this->mandarParaALixeira($client, $irma, [(int) $daIrma->getId()], []);

        $this->limpar();
        $client->request('POST', "/pasta/{$pasta->getId()}/documentos/restaurar", [
            '_token'     => $this->csrf('pex_lote_' . $pasta->getId()),
            'documentos' => [(string) $meu->getId(), (string) $daIrma->getId()],
        ]);

        self::assertResponseStatusCodeSame(404);
        self::assertNotNull($this->naLixeira('pasta_documento', (int) $meu->getId()), 'sem efeito parcial');
        self::assertNotNull($this->naLixeira('pasta_documento', (int) $daIrma->getId()));
    }

    #[TestDox('restaurar: outro escritório 404; item VIVO 404 sem efeito; CSRF 400; sem permissão 403; teto 422')]
    public function testRestaurarRecusas(): void
    {
        $client          = $this->cliente();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        [, $outro]       = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $alheia          = $this->criarPasta($outro);
        $naLixeira       = $this->criarDocumento($pasta, $tenant, null, 'na-lixeira.pdf');
        $vivo            = $this->criarDocumento($pasta, $tenant, null, 'vivo.pdf');
        $docAlheio       = $this->criarDocumento($alheia, $outro, null, 'alheio.pdf');
        $leitor          = $this->criarUsuarioSoLeitura($tenant, (int) $pasta->getId());
        $this->logarComTenant($client, $user, $tenant);
        $this->mandarParaALixeira($client, $pasta, [(int) $naLixeira->getId()], []);
        $this->marcarDireto($docAlheio, $user);

        $this->limpar();
        $client->request('POST', "/pasta/{$alheia->getId()}/documentos/restaurar", [
            '_token'     => $this->csrf('pex_lote_' . $alheia->getId()),
            'documentos' => [(string) $docAlheio->getId()],
        ]);
        self::assertResponseStatusCodeSame(404, 'pasta de outro escritório');

        $this->limpar();
        $client->request('POST', "/pasta/{$pasta->getId()}/documentos/restaurar", [
            '_token'     => $this->csrf('pex_lote_' . $pasta->getId()),
            'documentos' => [(string) $docAlheio->getId()],
        ]);
        self::assertResponseStatusCodeSame(404, 'documento de outro escritório na seleção');

        $this->limpar();
        $client->request('POST', "/pasta/{$pasta->getId()}/documentos/restaurar", [
            '_token'     => $this->csrf('pex_lote_' . $pasta->getId()),
            'documentos' => [(string) $naLixeira->getId(), (string) $vivo->getId()],
        ]);
        self::assertResponseStatusCodeSame(404, 'item vivo não está na lixeira: não há o que restaurar');
        self::assertNotNull($this->naLixeira('pasta_documento', (int) $naLixeira->getId()), 'sem efeito parcial');

        $this->limpar();
        $client->request('POST', "/pasta/{$pasta->getId()}/documentos/restaurar", [
            '_token'     => 'errado',
            'documentos' => [(string) $naLixeira->getId()],
        ]);
        self::assertResponseStatusCodeSame(400);

        $this->limpar();
        $client->request('POST', "/pasta/{$pasta->getId()}/documentos/restaurar", [
            '_token'     => $this->csrf('pex_lote_' . $pasta->getId()),
            'documentos' => array_fill(0, PastaDocumentoController::TETO_DE_ITENS_POR_LOTE + 1, (string) $naLixeira->getId()),
        ]);
        self::assertResponseStatusCodeSame(422, 'teto antes de qualquer consulta');

        $this->logarComTenant($client, $leitor, $tenant);
        $this->limpar();
        $client->request('POST', "/pasta/{$pasta->getId()}/documentos/restaurar", [
            '_token'     => $this->csrf('pex_lote_' . $pasta->getId()),
            'documentos' => [(string) $naLixeira->getId()],
        ]);
        self::assertResponseStatusCodeSame(403, 'quem só vê não restaura (restaura quem pode excluir)');

        self::assertNotNull($this->naLixeira('pasta_documento', (int) $naLixeira->getId()));
        self::assertNotNull($this->naLixeira('pasta_documento', (int) $docAlheio->getId()));
        self::assertNull($this->naLixeira('pasta_documento', (int) $vivo->getId()));
    }

    // ── lixeira (GET) ──────────────────────────────────────────────────────────

    #[TestDox('lixeira: lista nome, tipo, excluído em/por e o caminho original; só desta pasta; 404 fora do escritório; 403 para quem só vê')]
    public function testListaALixeiraDaPasta(): void
    {
        $client          = $this->cliente();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        [, $outro]       = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $irma            = $this->criarPasta($tenant);
        $alheia          = $this->criarPasta($outro);
        $a               = $this->criarSecao($pasta, $tenant, 'A');
        $b               = $this->criarSecao($pasta, $tenant, 'B', $a);
        $emB             = $this->criarDocumento($pasta, $tenant, $b, 'em-b.pdf');
        $solto           = $this->criarDocumento($pasta, $tenant, null, 'solto.pdf');
        $vivo            = $this->criarDocumento($pasta, $tenant, null, 'vivo.pdf');
        $daIrma          = $this->criarDocumento($irma, $tenant, null, 'da-irma.pdf');
        $leitor          = $this->criarUsuarioSoLeitura($tenant, (int) $pasta->getId());
        $this->logarComTenant($client, $user, $tenant);
        $this->mandarParaALixeira($client, $pasta, [(int) $solto->getId()], [(int) $a->getId()]);
        $this->mandarParaALixeira($client, $irma, [(int) $daIrma->getId()], []);

        $this->limpar();
        $client->request('GET', "/pasta/{$pasta->getId()}/documentos/lixeira");

        self::assertResponseIsSuccessful((string) $client->getResponse()->getContent());
        $json = $this->json($client);
        self::assertTrue($json['ok']);
        self::assertSame(2, $json['totalArquivos']);
        self::assertSame(2, $json['totalPastas']);

        $porChave = [];
        foreach ($json['itens'] as $item) {
            $porChave[$item['tipo'] . ':' . $item['id']] = $item;
        }
        self::assertCount(4, $porChave);
        self::assertArrayNotHasKey('arquivo:' . $vivo->getId(), $porChave, 'o vivo não está na lixeira');
        self::assertArrayNotHasKey('arquivo:' . $daIrma->getId(), $porChave, 'a lixeira da pasta irmã é dela');

        $itemA = $porChave['pasta:' . $a->getId()];
        self::assertSame('A', $itemA['nome']);
        self::assertSame(LixeiraDaPastaOutput::RAIZ, $itemA['caminho']);
        self::assertNull($itemA['paiId']);
        self::assertSame($user->getId(), $itemA['excluidoPor']['id']);
        self::assertSame('Admin Documentos', $itemA['excluidoPor']['nome']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $itemA['excluidoEm']);

        $itemB = $porChave['pasta:' . $b->getId()];
        self::assertSame('A', $itemB['caminho']);
        self::assertSame((int) $a->getId(), $itemB['paiId']);
        self::assertTrue($itemB['paiNaLixeira'], 'a UI sabe que restaurar só B o manda para a raiz');

        $itemEmB = $porChave['arquivo:' . $emB->getId()];
        self::assertSame('em-b.pdf', $itemEmB['nome']);
        self::assertSame('A / B', $itemEmB['caminho'], 'o caminho original sobe pelos pais, mesmo os que estão na lixeira');
        self::assertSame((int) $b->getId(), $itemEmB['secaoId']);
        self::assertTrue($itemEmB['secaoNaLixeira']);
        self::assertSame(10, $itemEmB['tamanho']);
        self::assertSame('application/pdf', $itemEmB['mime']);

        $itemSolto = $porChave['arquivo:' . $solto->getId()];
        self::assertSame(LixeiraDaPastaOutput::RAIZ, $itemSolto['caminho']);
        self::assertNull($itemSolto['secaoId']);
        self::assertFalse($itemSolto['secaoNaLixeira']);

        $this->limpar();
        $client->request('GET', "/pasta/{$alheia->getId()}/documentos/lixeira");
        self::assertResponseStatusCodeSame(404);

        $this->logarComTenant($client, $leitor, $tenant);
        $this->limpar();
        $client->request('GET', "/pasta/{$pasta->getId()}/documentos/lixeira");
        self::assertResponseStatusCodeSame(403);
    }

    #[TestDox('o histórico da pasta mostra a ida e a volta do documento E da subpasta, com nome próprio e ator')]
    public function testHistoricoMostraIdaEVolta(): void
    {
        $client          = $this->cliente();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $a               = $this->criarSecao($pasta, $tenant, 'A');
        $solto           = $this->criarDocumento($pasta, $tenant, null, 'solto.pdf');
        $this->logarComTenant($client, $user, $tenant);
        $this->mandarParaALixeira($client, $pasta, [(int) $solto->getId()], [(int) $a->getId()]);

        $this->limpar();
        $client->request('POST', "/pasta/{$pasta->getId()}/documentos/restaurar", [
            '_token'     => $this->csrf('pex_lote_' . $pasta->getId()),
            'documentos' => [(string) $solto->getId()],
            'secoes'     => [(string) $a->getId()],
        ]);
        self::assertResponseIsSuccessful((string) $client->getResponse()->getContent());

        $this->limpar();
        $pastaLida = $this->em()->find(Pasta::class, (int) $pasta->getId());
        self::assertNotNull($pastaLida);
        $itens = static::getContainer()->get(PastaTimelineAssembler::class)->montar($pastaLida, $pastaLida->getTenant(), (int) $tenant->getId(), null);

        $titulos = array_map(static fn (TimelineItemDTO $i): string => $i->titulo, $itens);
        foreach (['Documento movido para a lixeira', 'Documento restaurado', 'Pasta movida para a lixeira', 'Pasta restaurada'] as $esperado) {
            self::assertContains($esperado, $titulos, implode(' | ', $titulos));
        }
        foreach ($itens as $item) {
            if (str_contains($item->titulo, 'lixeira') || str_contains($item->titulo, 'restaurad')) {
                self::assertSame('Admin Documentos', $item->autorNome, 'o ator da linha do audit_log');
            }
        }
    }

    #[TestDox('depois de listar a lixeira, o filtro continua ligado no mesmo request: o explorador da mesma pasta não vê os itens')]
    public function testListarNaoDeixaOFiltroDesligado(): void
    {
        $client          = $this->cliente();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $some            = $this->criarDocumento($pasta, $tenant, null, 'some.pdf');
        $this->logarComTenant($client, $user, $tenant);
        $this->mandarParaALixeira($client, $pasta, [(int) $some->getId()], []);

        $this->limpar();
        $client->request('GET', "/pasta/{$pasta->getId()}/documentos/lixeira");
        self::assertResponseIsSuccessful();

        // Sem `limpar()`: mesmo EM, mesmo request simulado — se o escopo tivesse deixado o filtro
        // desligado, ou o item no identity map vazasse para o explorador, ele apareceria aqui.
        $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();
        self::assertNotContains((int) $some->getId(), $this->idsDosArquivosDoExplorador((string) $client->getResponse()->getContent()));
    }

    // ----------------------------------------------------------------- helpers

    private function cliente(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->instalarCsrfStorage();

        return $client;
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    /** O request lê o banco, não a memória do teste. */
    private function limpar(): void
    {
        $this->em()->clear();
    }

    /**
     * Pelo caminho real: o `excluir-lote` da aba. Deixa o EM limpo antes e depois.
     *
     * @param list<int> $documentos
     * @param list<int> $secoes
     */
    private function mandarParaALixeira(KernelBrowser $client, Pasta $pasta, array $documentos, array $secoes): void
    {
        $this->limpar();
        $client->request('POST', "/pasta/{$pasta->getId()}/documentos/excluir-lote", [
            '_token'     => $this->csrf('pex_lote_' . $pasta->getId()),
            'documentos' => array_map('strval', $documentos),
            'secoes'     => array_map('strval', $secoes),
        ]);
        self::assertResponseIsSuccessful('pré-condição: o excluir-lote precisa ter funcionado — ' . (string) $client->getResponse()->getContent());
        $this->limpar();
    }

    /**
     * Lápide gravada por SQL, para o documento de OUTRO escritório — o usuário logado não o alcança
     * pela rota, e o EM compartilhado ainda carrega o TenantFilter do último request (um `find()`
     * aqui devolveria null em silêncio).
     */
    private function marcarDireto(PastaDocumento $doc, User $por): void
    {
        $this->em()->getConnection()->executeStatement(
            'UPDATE pasta_documento SET excluido_em = :em, excluido_por_id = :por WHERE id = :id',
            ['em' => '2026-10-07 10:00:00', 'por' => $por->getId(), 'id' => (int) $doc->getId()],
        );
        $this->limpar();
    }

    /** @return array{User, Tenant} */
    private function criarUsuarioAdmin(): array
    {
        $em     = $this->em();
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $tenant = new Tenant();
        $tenant->setName('Tenant Lixeira ' . uniqid());
        $em->persist($tenant);

        $user = new User();
        $user->setEmail('lixeira_' . uniqid() . '@test.com');
        $user->setFullName('Admin Documentos');
        $user->setRoles(['ROLE_SUPER_ADMIN']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);
        $em->persist(new UserTenant($user, $tenant));
        $em->flush();

        return [$user, $tenant];
    }

    /** Papel comum (não-sistema) com acesso de VER a pasta, sem editar. */
    private function criarUsuarioSoLeitura(Tenant $tenant, int $pastaId): User
    {
        $em = $this->em();

        $user = new User();
        $user->setEmail('leitor_' . uniqid() . '@test.com');
        $user->setFullName('Leitor');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword('dummy_hash');
        $em->persist($user);

        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName('Papel ' . uniqid());
        $role->setIsSystem(false);
        $em->persist($role);

        $vinculo = new UserTenant($user, $tenant);
        $vinculo->setTenantRole($role);
        $em->persist($vinculo);

        $acesso = new ResourceAccess();
        $acesso->setUser($user);
        $acesso->setTenant($tenant);
        $acesso->setResourceType(ResourceAccess::RESOURCE_PASTA);
        $acesso->setResourceId($pastaId);
        $acesso->setCanView(true);
        $acesso->setCanEdit(false);
        $em->persist($acesso);
        $em->flush();

        return $user;
    }

    private function criarPasta(Tenant $tenant): Pasta
    {
        $pasta = new Pasta();
        $pasta->setNup('LIX-' . uniqid());
        $pasta->setTenant($tenant);
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

    private function criarDocumento(Pasta $pasta, Tenant $tenant, ?PastaSecao $secao, string $nome, ?string $caminho = null): PastaDocumento
    {
        $doc = new PastaDocumento();
        $doc->setTitulo($nome);
        $doc->setCategoria(PastaDocumento::CATEGORIA_DEMAIS);
        $doc->setCaminhoArquivo($caminho ?? 'fake-' . bin2hex(random_bytes(6)) . '.pdf');
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

    /** Grava um arquivo DE VERDADE pela chave e devolve o nome cunhado (vai em `caminho_arquivo`). */
    private function gravarArquivo(Tenant $tenant, string $conteudo): string
    {
        $chave = static::getContainer()->get(ArmazenamentoDeArquivos::class)->gravar(
            ChavesDePasta::novoDocumento((new PastaDocumento())->setTenant($tenant), 'pdf'),
            FonteDeConteudo::deTexto($conteudo),
        )->chave;
        $this->arquivosGravados[] = $chave;

        return $chave->nome;
    }

    /** @return array<string, mixed> */
    private function json(KernelBrowser $client): array
    {
        $json = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($json, (string) $client->getResponse()->getContent());

        return $json;
    }

    /** @return array<string, mixed> o JSON de `#pexDados` */
    private function explorador(string $html): array
    {
        self::assertSame(1, preg_match('~<script type="application/json" id="pexDados">(.*?)</script>~s', $html, $m), 'o explorador emite #pexDados');

        return json_decode($m[1], true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return list<int> */
    private function idsDosArquivosDoExplorador(string $html): array
    {
        return array_map(static fn (array $a): int => (int) $a['id'], $this->explorador($html)['arquivos']);
    }

    private function naLixeira(string $tabela, int $id): ?string
    {
        $linha = $this->em()->getConnection()->fetchAssociative("SELECT excluido_em FROM {$tabela} WHERE id = :id", ['id' => $id]);
        self::assertIsArray($linha, "{$tabela} #{$id} não está no banco");

        return $linha['excluido_em'];
    }

    private function excluidoPor(string $tabela, int $id): ?int
    {
        $valor = $this->em()->getConnection()->fetchOne("SELECT excluido_por_id FROM {$tabela} WHERE id = :id", ['id' => $id]);

        return $valor === null || $valor === false ? null : (int) $valor;
    }

    private function secaoDoDocumento(int $id): ?int
    {
        $valor = $this->em()->getConnection()->fetchOne('SELECT secao_id FROM pasta_documento WHERE id = :id', ['id' => $id]);
        self::assertNotFalse($valor, "documento #{$id} não está no banco");

        return $valor === null ? null : (int) $valor;
    }

    private function paiDaSecao(int $id): ?int
    {
        $valor = $this->em()->getConnection()->fetchOne('SELECT secao_pai_id FROM pasta_secao WHERE id = :id', ['id' => $id]);
        self::assertNotFalse($valor, "seção #{$id} não está no banco");

        return $valor === null ? null : (int) $valor;
    }

    /** @param list<int> $ids */
    private function auditorias(string $acao, string $classe, array $ids): int
    {
        return (int) $this->em()->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM audit_log WHERE action = :acao AND entity_class = :classe AND entity_id IN (:ids)',
            ['acao' => $acao, 'classe' => $classe, 'ids' => array_map('strval', $ids)],
            ['ids' => \Doctrine\DBAL\ArrayParameterType::STRING],
        );
    }

    private function csrf(string $id): string
    {
        return 'TOKEN_' . $id;
    }

    private function instalarCsrfStorage(): void
    {
        $storage = new class implements ClearableTokenStorageInterface {
            public function getToken(string $tokenId): string { return 'TOKEN_' . $tokenId; }
            public function setToken(string $tokenId, string $token): void {}
            public function removeToken(string $tokenId): ?string { return null; }
            public function hasToken(string $tokenId): bool { return true; }
            public function clear(): void {}
        };

        static::getContainer()->set('security.csrf.token_storage', $storage);
    }
}
