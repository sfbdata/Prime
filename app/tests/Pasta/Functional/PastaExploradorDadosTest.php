<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Controller\PastaController;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Pasta\DTO\ExploradorDeDocumentosOutput;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Entity\PastaSecao;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * O que o servidor entrega ao explorador da aba Documentos — o JSON de `#pexDados`.
 *
 * O explorador renderiza a lista a partir deste JSON, não de linhas prontas no HTML. Cada fato
 * que o teste antigo (`PastaShowDocumentosControllerTest`) lia dos `data-*` das linhas passa a
 * ser lido daqui: seção de cada arquivo, pai de cada pasta, contagem recursiva, URLs e tokens
 * de cada ação, rótulo da categoria, ordem manual. Se um deles sumir do JSON, a tela "funciona"
 * e grava errado — falha silenciosa, a pior de todas aqui.
 */
#[CoversClass(PastaController::class)]
#[CoversClass(ExploradorDeDocumentosOutput::class)]
final class PastaExploradorDadosTest extends JusPrimeWebTestCase
{
    /** @return array{User, Tenant} */
    private function criarUsuarioAdmin(): array
    {
        $container = static::getContainer();
        $em        = $container->get(EntityManagerInterface::class);
        $hasher    = $container->get(UserPasswordHasherInterface::class);

        $tenant = new Tenant();
        $tenant->setName('Tenant Explorador ' . uniqid());
        $em->persist($tenant);

        $user = new User();
        $user->setEmail('test_pex_' . uniqid() . '@test.com');
        $user->setFullName('Admin Explorador');
        $user->setRoles(['ROLE_SUPER_ADMIN']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);
        $em->persist(new UserTenant($user, $tenant));
        $em->flush();

        return [$user, $tenant];
    }

    private function criarPasta(Tenant $tenant): Pasta
    {
        $em    = static::getContainer()->get(EntityManagerInterface::class);
        $pasta = new Pasta();
        $pasta->setNup('NUP-PEX-' . uniqid());
        $pasta->setTenant($tenant);
        $em->persist($pasta);
        $em->flush();

        return $pasta;
    }

    private function criarSecao(Pasta $pasta, Tenant $tenant, string $nome, ?PastaSecao $pai = null, int $ordem = 1): PastaSecao
    {
        $em    = static::getContainer()->get(EntityManagerInterface::class);
        $secao = new PastaSecao();
        $secao->setPasta($pasta);
        $secao->setTenant($tenant);
        $secao->setNome($nome);
        $secao->setOrdem($ordem);
        if ($pai !== null) {
            $secao->setPai($pai);
        }
        $em->persist($secao);
        $em->flush();

        return $secao;
    }

    private function criarDocumento(Pasta $pasta, Tenant $tenant, ?PastaSecao $secao, string $nomeOriginal, int $ordem = 0): PastaDocumento
    {
        $em  = static::getContainer()->get(EntityManagerInterface::class);
        $doc = new PastaDocumento();
        $doc->setTitulo($nomeOriginal);
        $doc->setCategoria(PastaDocumento::CATEGORIA_DEMAIS);
        $doc->setCaminhoArquivo('uploads/fake/' . $nomeOriginal);
        $doc->setNomeOriginal($nomeOriginal);
        $doc->setMimeType('application/pdf');
        $doc->setTamanhoBytes(1024);
        $doc->setPasta($pasta);
        $doc->setTenant($tenant);
        $doc->setSecao($secao);
        $doc->setOrdem($ordem);
        $pasta->addDocumento($doc);
        $em->persist($doc);
        $em->flush();

        return $doc;
    }

    /** Abre a pasta com o mapa de identidade limpo: o request lê o banco, não a memória do teste. */
    private function abrir(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client, Pasta $pasta): Crawler
    {
        static::getContainer()->get(EntityManagerInterface::class)->clear();
        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}");
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    /** @return array{pastas: list<array<string, mixed>>, arquivos: list<array<string, mixed>>, categorias: array<string, string>, totalArquivos: int, totalPastas: int} */
    private function dados(Crawler $crawler): array
    {
        $script = $crawler->filter('script#pexDados[type="application/json"]');
        self::assertCount(1, $script, 'o explorador lê os dados de UM <script type="application/json" id="pexDados">');

        /** @var array{pastas: list<array<string, mixed>>, arquivos: list<array<string, mixed>>, categorias: array<string, string>, totalArquivos: int, totalPastas: int} $dados */
        $dados = json_decode($script->text(null, false), true, 512, JSON_THROW_ON_ERROR);

        return $dados;
    }

    /** @return array<string, mixed> */
    private function arquivoChamado(array $dados, string $nome): array
    {
        foreach ($dados['arquivos'] as $arquivo) {
            if ($arquivo['nome'] === $nome) {
                return $arquivo;
            }
        }
        self::fail("arquivo \"{$nome}\" não está no JSON");
    }

    /** @return array<string, mixed> */
    private function pastaComId(array $dados, int $id): array
    {
        foreach ($dados['pastas'] as $pasta) {
            if ($pasta['id'] === $id) {
                return $pasta;
            }
        }
        self::fail("pasta #{$id} não está no JSON");
    }

    #[TestDox('cada arquivo diz em que seção está: null na raiz, o id da seção quando está nela')]
    public function testArquivoTrazASecao(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $secao           = $this->criarSecao($pasta, $tenant, 'Seção Teste');
        $this->criarDocumento($pasta, $tenant, $secao, 'doc-na-secao.pdf');
        $this->criarDocumento($pasta, $tenant, null, 'doc-geral.pdf');

        $this->logarComTenant($client, $user, $tenant);
        $dados = $this->dados($this->abrir($client, $pasta));

        self::assertNull($this->arquivoChamado($dados, 'doc-geral.pdf')['secaoId'], 'na raiz o secaoId é null');
        self::assertSame($secao->getId(), $this->arquivoChamado($dados, 'doc-na-secao.pdf')['secaoId']);
        self::assertSame(2, $dados['totalArquivos']);
        self::assertSame(1, $dados['totalPastas']);
    }

    #[TestDox('a subpasta chega ao JSON com o pai declarado (paiId); a de topo com null')]
    public function testSubpastaTrazPai(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $pai             = $this->criarSecao($pasta, $tenant, 'PAI');
        $filha           = $this->criarSecao($pasta, $tenant, 'FILHA', $pai);

        $this->logarComTenant($client, $user, $tenant);
        $dados = $this->dados($this->abrir($client, $pasta));

        self::assertNull($this->pastaComId($dados, (int) $pai->getId())['paiId'], 'a pasta de topo não tem pai');
        self::assertSame($pai->getId(), $this->pastaComId($dados, (int) $filha->getId())['paiId']);
        self::assertSame('FILHA', $this->pastaComId($dados, (int) $filha->getId())['nome']);
    }

    #[TestDox('D3: cada pasta traz subpastas/arquivos da árvore inteira, para o aviso de exclusão contar ANTES do clique')]
    public function testPastaTrazContagemDaArvore(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $pai             = $this->criarSecao($pasta, $tenant, 'PAI');
        $filha           = $this->criarSecao($pasta, $tenant, 'FILHA', $pai);
        $neta            = $this->criarSecao($pasta, $tenant, 'NETA', $filha);

        // Um arquivo em cada nível: o PAI acumula os 3 (recursivo), a FILHA 2, a NETA só o dela.
        $this->criarDocumento($pasta, $tenant, $pai, 'doc-pai.pdf');
        $this->criarDocumento($pasta, $tenant, $filha, 'doc-filha.pdf');
        $this->criarDocumento($pasta, $tenant, $neta, 'doc-neta.pdf');
        // Um na raiz: NUNCA entra na contagem de pasta nenhuma.
        $this->criarDocumento($pasta, $tenant, null, 'doc-raiz.pdf');

        $this->logarComTenant($client, $user, $tenant);
        $dados = $this->dados($this->abrir($client, $pasta));

        $noPai = $this->pastaComId($dados, (int) $pai->getId());
        self::assertSame(2, $noPai['subpastas'], 'FILHA e NETA');
        self::assertSame(3, $noPai['arquivos'], 'os 3 da árvore inteira do PAI');

        $noFilha = $this->pastaComId($dados, (int) $filha->getId());
        self::assertSame(1, $noFilha['subpastas'], 'só a NETA');
        self::assertSame(2, $noFilha['arquivos'], 'o dela mais o da NETA');

        $noNeta = $this->pastaComId($dados, (int) $neta->getId());
        self::assertSame(0, $noNeta['subpastas'], 'a NETA é folha');
        self::assertSame(1, $noNeta['arquivos']);
    }

    #[TestDox('o arquivo carrega as URLs de visualizar/baixar/mover e os tokens de mover/editar/excluir')]
    public function testArquivoTrazUrlsETokens(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $doc             = $this->criarDocumento($pasta, $tenant, null, 'contrato-assinado.pdf');

        $this->logarComTenant($client, $user, $tenant);
        $arquivo = $this->arquivoChamado($this->dados($this->abrir($client, $pasta)), 'contrato-assinado.pdf');

        /* O nome da linha é um <a> para a visualização (o preview tem o Baixar no rodapé);
           o menu da linha baixa direto. O arraste/“Mover para…” monta o POST a partir de
           urlMover + csrfMover: sem um deles o gesto acontece na tela e não grava nada. */
        self::assertSame('/pasta/documento/' . $doc->getId() . '/visualizar', $arquivo['viewUrl']);
        self::assertSame('/pasta/documento/' . $doc->getId() . '/download', $arquivo['downloadUrl']);
        self::assertSame('/pasta/documento/' . $doc->getId() . '/mover-secao', $arquivo['urlMover']);
        self::assertNotEmpty($arquivo['csrfMover'], 'token do pasta_doc_mover_<id>');
        self::assertNotEmpty($arquivo['csrfEditar'], 'token do edit_documento_<id> — o modal único de edição o usa');
        self::assertNotEmpty($arquivo['csrfExcluir'], 'token do delete_documento_<id>');
        self::assertSame('application/pdf', $arquivo['mime']);
        self::assertSame(1024, $arquivo['tamanho']);
        self::assertSame($doc->getId(), $arquivo['id']);
    }

    #[TestDox('a pasta carrega as URLs e os tokens de renomear/excluir/mover')]
    public function testPastaTrazUrlsETokens(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $secao           = $this->criarSecao($pasta, $tenant, 'Procurações');

        $this->logarComTenant($client, $user, $tenant);
        $p = $this->pastaComId($this->dados($this->abrir($client, $pasta)), (int) $secao->getId());

        self::assertSame('/pasta/secao/' . $secao->getId() . '/renomear', $p['urlRenomear']);
        self::assertSame('/pasta/secao/' . $secao->getId() . '/excluir', $p['urlExcluir']);
        self::assertSame('/pasta/secao/' . $secao->getId() . '/mover', $p['urlMover']);
        self::assertNotEmpty($p['csrfRenomear']);
        self::assertNotEmpty($p['csrfExcluir']);
        self::assertNotEmpty($p['csrfMover']);
    }

    #[TestDox('a categoria vem com o RÓTULO que a tela mostra, não só a chave do enum')]
    public function testCategoriaTrazORotuloExibido(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $this->criarDocumento($pasta, $tenant, null, 'x.pdf');

        $this->logarComTenant($client, $user, $tenant);
        $dados   = $this->dados($this->abrir($client, $pasta));
        $arquivo = $this->arquivoChamado($dados, 'x.pdf');

        /* Ordenar pela chave (`DEMAIS`) agruparia certo e listaria numa ordem que a tela não
           exibe. O JSON leva os dois: a chave (para o modal de edição marcar o <select>) e o
           rótulo (para a coluna e a ordenação). */
        self::assertSame(PastaDocumento::CATEGORIA_DEMAIS, $arquivo['categoria']);
        self::assertSame('Demais documentos', $arquivo['categoriaRotulo']);
        self::assertSame($arquivo['categoriaRotulo'], $dados['categorias'][$arquivo['categoria']], 'o mapa de categorias e o rótulo da linha dizem a mesma coisa');
        self::assertArrayHasKey(PastaDocumento::CATEGORIA_PROCURACAO, $dados['categorias']);
    }

    #[TestDox('a ordem manual (persistida por pasta_documentos_reordenar) chega no JSON e na sequência dos itens')]
    public function testOrdemManual(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $segundo         = $this->criarDocumento($pasta, $tenant, null, 'b-segundo.pdf', 2);
        $primeiro        = $this->criarDocumento($pasta, $tenant, null, 'a-primeiro.pdf', 1);

        $this->logarComTenant($client, $user, $tenant);
        $dados = $this->dados($this->abrir($client, $pasta));

        self::assertSame(1, $this->arquivoChamado($dados, 'a-primeiro.pdf')['ordem']);
        self::assertSame(2, $this->arquivoChamado($dados, 'b-segundo.pdf')['ordem']);
        self::assertSame(
            [$primeiro->getId(), $segundo->getId()],
            array_column($dados['arquivos'], 'id'),
            'a lista já vem na ordem manual — é o que o modo Manual mostra sem reordenar nada'
        );
    }

    #[TestDox('D4: o JSON traz as URLs de mover-lote/excluir-lote e o token único pex_lote_<pastaId>')]
    public function testJsonTrazAsAcoesEmLote(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);

        $this->logarComTenant($client, $user, $tenant);
        $dados = $this->dados($this->abrir($client, $pasta));

        self::assertSame("/pasta/{$pasta->getId()}/documentos/mover-lote", $dados['urlMoverLote']);
        self::assertSame("/pasta/{$pasta->getId()}/documentos/excluir-lote", $dados['urlExcluirLote']);
        self::assertNotEmpty($dados['csrfLote'], 'um token por pasta para as ações em lote; os ids vão no corpo');
        self::assertSame("/pasta/{$pasta->getId()}/documentos/zip", $dados['urlZip'], 'D5: o .zip usa o mesmo token do lote');
        self::assertSame("/pasta/{$pasta->getId()}/documentos/copiar", $dados['urlCopiar'], 'D6: copiar idem');
    }

    #[TestDox('D1: o arquivo traz quem enviou (nome), modificadoEm e paginas — NULL no acervo que não tem')]
    public function testArquivoTrazOsMetadadosDaD1(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $em              = static::getContainer()->get(EntityManagerInterface::class);

        $com = $this->criarDocumento($pasta, $tenant, null, 'com-metadados.pdf');
        $com->setEnviadoPor($user);
        $com->marcarModificadoEm(new \DateTimeImmutable('2026-10-06 14:30:00'));
        $com->setPaginas(12);
        $em->flush();
        $this->criarDocumento($pasta, $tenant, null, 'acervo-antigo.pdf');

        $this->logarComTenant($client, $user, $tenant);
        $dados = $this->dados($this->abrir($client, $pasta));

        $comMetadados = $this->arquivoChamado($dados, 'com-metadados.pdf');
        self::assertSame('Admin Explorador', $comMetadados['enviadoPor'], 'o nome do usuário, não o e-mail');
        self::assertSame('2026-10-06 14:30:00', $comMetadados['modificadoEm']);
        self::assertSame(12, $comMetadados['paginas']);

        $antigo = $this->arquivoChamado($dados, 'acervo-antigo.pdf');
        self::assertNull($antigo['enviadoPor']);
        self::assertNull($antigo['modificadoEm']);
        self::assertNull($antigo['paginas']);
    }

    #[TestDox('nome de arquivo com </script> não fecha o <script> dos dados (flags JSON_HEX_*)')]
    public function testJsonNaoDeixaNomeFecharOScript(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $nome            = '</script><b>ataque</b>&amp;"x\'.pdf';
        $this->criarDocumento($pasta, $tenant, null, $nome);

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $this->abrir($client, $pasta);

        $html = (string) $client->getResponse()->getContent();
        self::assertSame(1, preg_match('~<script type="application/json" id="pexDados">(.*?)</script>~s', $html, $m));

        /* O corpo do <script> não pode ter `<`, `>` nem `&` CRUS: é o que torna impossível
           fechar a tag ou abrir um comentário a partir de um nome de arquivo. Conferir só
           "não contém </script>" não bastaria — o json_encode padrão já escapa a barra
           (`<\/script>`) e o teste passaria sem os flags HEX_*, provando nada. */
        self::assertDoesNotMatchRegularExpression('/[<>&]/', $m[1], 'sem JSON_HEX_TAG|AMP o nome entra cru no <script>');

        $arquivo = $this->arquivoChamado($this->dados($crawler), $nome);
        self::assertSame($nome, $arquivo['nome'], 'escapado como \\uXXXX, o JSON decodifica para o nome original');
    }

    #[TestDox('sem JS a lista de arquivos ainda leva a cada um (noscript com links de visualização)')]
    public function testNoscriptListaOsArquivos(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $doc             = $this->criarDocumento($pasta, $tenant, null, 'peticao.pdf');

        $this->logarComTenant($client, $user, $tenant);
        $this->abrir($client, $pasta);
        $html = (string) $client->getResponse()->getContent();

        /* O parser trata <noscript> como texto quando "scripting" está ligado; por isso a
           asserção é sobre o HTML cru, dentro do bloco do explorador. */
        self::assertMatchesRegularExpression(
            '~<noscript>\s*<ul class="pex-noscript">.*?<a href="/pasta/documento/' . $doc->getId() . '/visualizar"[^>]*>peticao\.pdf</a>.*?</noscript>~s',
            $html
        );
    }

    /**
     * Medido por mutação (06/10): tirar o `d.tenant = :tenant` explícito do repositório NÃO derruba
     * este teste — quem segura a linha alheia é o `TenantFilter` do Doctrine (`PastaDocumento
     * implements TenantAware`). O filtro explícito é defesa em profundidade, como nos outros
     * métodos do repositório; este teste prova o COMPORTAMENTO (nada de outro escritório no
     * JSON), não qual das duas barreiras o garantiu.
     */
    #[TestDox('documento de OUTRO escritório preso à mesma pasta não entra no JSON')]
    public function testDocumentoDeOutroTenantNaoEntra(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $this->criarDocumento($pasta, $tenant, null, 'meu.pdf');

        $em      = static::getContainer()->get(EntityManagerInterface::class);
        $outro   = new Tenant();
        $outro->setName('Outro escritório ' . uniqid());
        $em->persist($outro);
        $em->flush();
        // Linha inconsistente de propósito (pasta do tenant A, documento do tenant B): se a
        // consulta filtrasse só pela pasta, ela vazaria para a tela do A.
        $this->criarDocumento($pasta, $outro, null, 'alheio.pdf');

        $this->logarComTenant($client, $user, $tenant);
        $dados = $this->dados($this->abrir($client, $pasta));

        self::assertSame(['meu.pdf'], array_column($dados['arquivos'], 'nome'));
        self::assertSame(1, $dados['totalArquivos']);
    }
}
