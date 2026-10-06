<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Controller\PastaDocumentoController;
use App\Pasta\DTO\ExploradorDeDocumentosOutput;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Entity\PastaDocumentoFavorito;
use App\Pasta\Entity\PastaSecao;
use App\Pasta\Repository\PastaDocumentoFavoritoRepository;
use App\Pasta\UseCase\AlternarFavoritoDeDocumentoUseCase;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\DBAL\Exception\DriverException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * A estrela de arquivo/subpasta da aba Documentos (D2, DOC-23): `pasta_documentos_favorito` e o
 * que o explorador recebe (`favorito` por item, `urlFavorito`/`csrfFavorito` no topo).
 *
 * Isolamento pelos dois lados: pasta de OUTRO escritório → 404 (com SUPER_ADMIN, que passa em
 * qualquer `canAccessResource` — o 404 só pode vir da conferência de dono); alvo da PASTA IRMÃ do
 * mesmo escritório → 404. A permissão é provada pelo par "sem nada" (403) × "só ver" (200) no
 * mesmo escritório, para o 403 não ser outra barreira. Cada negativo confere no banco que nada
 * foi gravado.
 */
#[CoversClass(PastaDocumentoController::class)]
#[CoversClass(AlternarFavoritoDeDocumentoUseCase::class)]
#[CoversClass(PastaDocumentoFavoritoRepository::class)]
#[CoversClass(ExploradorDeDocumentosOutput::class)]
#[Group('pasta')]
final class PastaDocumentoFavoritoControllerTest extends JusPrimeWebTestCase
{
    use CriaFixturesPushDaPastaTrait;

    private const XHR = ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'];

    // ── marcar / desmarcar ──────────────────────────────────────────────────────

    #[TestDox('documento: marcar grava (tenant e usuário da sessão), marcar de novo não duplica, desmarcar apaga e desmarcar de novo não erra')]
    public function testMarcarEDesmarcarDocumentoEhIdempotente(): void
    {
        $client          = $this->cliente();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $doc             = $this->criarDocumento($pasta, $tenant, null, 'a.pdf');
        $this->logarComTenant($client, $user, $tenant);

        $this->favoritar($client, $pasta, 'documento', (int) $doc->getId(), '1');
        self::assertResponseIsSuccessful();
        $primeira = $this->json($client);
        $this->assertMarcadoComHora($primeira);

        $this->favoritar($client, $pasta, 'documento', (int) $doc->getId(), '1');
        self::assertResponseIsSuccessful();
        $segunda = $this->json($client);
        $this->assertMarcadoComHora($segunda);
        self::assertSame($primeira['favoritoEm'], $segunda['favoritoEm'], 'marcar de novo não muda a hora (o lugar no topo)');
        self::assertSame(1, $this->favoritosDoDocumento($doc), 'marcar duas vezes = uma linha');

        $linha = $this->em()->getConnection()->fetchAssociative(
            'SELECT tenant_id, user_id, secao_id FROM pasta_documento_favorito WHERE documento_id = :d',
            ['d' => $doc->getId()],
        );
        self::assertSame([$tenant->getId(), $user->getId()], [(int) $linha['tenant_id'], (int) $linha['user_id']]);
        self::assertNull($linha['secao_id']);

        $this->favoritar($client, $pasta, 'documento', (int) $doc->getId(), '0');
        self::assertResponseIsSuccessful();
        self::assertSame(['ok' => true, 'marcado' => false, 'favoritoEm' => null], $this->json($client));
        self::assertSame(0, $this->favoritosDoDocumento($doc));

        $this->favoritar($client, $pasta, 'documento', (int) $doc->getId(), '0');
        self::assertResponseIsSuccessful();
        self::assertSame(['ok' => true, 'marcado' => false, 'favoritoEm' => null], $this->json($client));
    }

    #[TestDox('subpasta (tipo pasta), em corpo JSON: marcar duas vezes = uma linha; desmarcar apaga')]
    public function testMarcarSubpastaEmJson(): void
    {
        $client          = $this->cliente();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $secao           = $this->criarSecao($pasta, $tenant, 'PROCURAÇÕES');
        $this->logarComTenant($client, $user, $tenant);

        foreach ([true, true] as $marcado) {
            $this->favoritarJson($client, $pasta, ['tipo' => 'pasta', 'alvoId' => $secao->getId(), 'marcado' => $marcado]);
            self::assertResponseIsSuccessful();
            $this->assertMarcadoComHora($this->json($client));
        }
        self::assertSame(1, $this->favoritosDaSecao($secao));

        $this->favoritarJson($client, $pasta, ['tipo' => 'pasta', 'alvoId' => $secao->getId(), 'marcado' => 0]);
        self::assertResponseIsSuccessful();
        self::assertSame(['ok' => true, 'marcado' => false, 'favoritoEm' => null], $this->json($client));
        self::assertSame(0, $this->favoritosDaSecao($secao));
    }

    #[TestDox('desmarcar só apaga a MINHA estrela: a do colega no mesmo documento fica')]
    public function testDesmarcarNaoApagaODoColega(): void
    {
        $client          = $this->cliente();
        [$user, $tenant] = $this->criarAdmin();
        $colega          = $this->criarUsuarioSemPermissaoDoModulo($tenant);
        $pasta           = $this->criarPasta($tenant);
        $doc             = $this->criarDocumento($pasta, $tenant, null, 'a.pdf');
        $this->marcar($tenant, $colega, $doc);
        $this->marcar($tenant, $user, $doc);
        $this->logarComTenant($client, $user, $tenant);

        $this->favoritar($client, $pasta, 'documento', (int) $doc->getId(), '0');
        self::assertResponseIsSuccessful();
        self::assertSame(1, $this->favoritosDoDocumento($doc));
        self::assertSame(1, $this->favoritosDoDocumento($doc, $colega));
    }

    // ── isolamento e guardas ────────────────────────────────────────────────────

    #[TestDox('pasta de OUTRO escritório dá 404 (mesmo para SUPER_ADMIN) e não grava nada')]
    public function testOutroEscritorioDa404(): void
    {
        $client            = $this->cliente();
        [$userA, $tenantA] = $this->criarAdmin();
        [, $tenantB]       = $this->criarAdmin();
        $pastaB            = $this->criarPasta($tenantB);
        $docB              = $this->criarDocumento($pastaB, $tenantB, null, 'b.pdf');
        $this->logarComTenant($client, $userA, $tenantA);

        $this->favoritar($client, $pastaB, 'documento', (int) $docB->getId(), '1');

        self::assertResponseStatusCodeSame(404);
        self::assertSame(0, $this->favoritosDoDocumento($docB));
    }

    #[TestDox('alvo da PASTA IRMÃ (documento ou subpasta) na rota desta pasta dá 404 e não grava nada')]
    public function testAlvoDePastaIrmaDa404(): void
    {
        $client          = $this->cliente();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $irma            = $this->criarPasta($tenant);
        $docDaIrma       = $this->criarDocumento($irma, $tenant, null, 'irma.pdf');
        $secaoDaIrma     = $this->criarSecao($irma, $tenant, 'DA IRMÃ');
        $this->logarComTenant($client, $user, $tenant);

        $this->favoritar($client, $pasta, 'documento', (int) $docDaIrma->getId(), '1');
        self::assertResponseStatusCodeSame(404);
        self::assertSame(0, $this->favoritosDoDocumento($docDaIrma));

        $this->favoritar($client, $pasta, 'pasta', (int) $secaoDaIrma->getId(), '1');
        self::assertResponseStatusCodeSame(404);
        self::assertSame(0, $this->favoritosDaSecao($secaoDaIrma));
    }

    #[TestDox('o tipo decide onde procurar: id de documento enviado como "pasta" não acha a subpasta (404)')]
    public function testTipoTrocadoDa404(): void
    {
        $client          = $this->cliente();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $doc             = $this->criarDocumento($pasta, $tenant, null, 'a.pdf');
        $this->logarComTenant($client, $user, $tenant);

        // A pasta não tem subpasta nenhuma: o id do documento, procurado como seção, não acha nada.
        $this->favoritar($client, $pasta, 'pasta', (int) $doc->getId(), '1');

        self::assertResponseStatusCodeSame(404);
        self::assertSame(0, $this->totalDeFavoritos());
    }

    #[TestDox('CSRF inválido — ou o token de OUTRA pasta — dá 400 e não grava nada')]
    public function testCsrfInvalido(): void
    {
        $client          = $this->cliente();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $outra           = $this->criarPasta($tenant);
        $doc             = $this->criarDocumento($pasta, $tenant, null, 'a.pdf');
        $this->logarComTenant($client, $user, $tenant);

        $this->favoritar($client, $pasta, 'documento', (int) $doc->getId(), '1', 'token_invalido');
        self::assertResponseStatusCodeSame(400);

        $this->favoritar($client, $pasta, 'documento', (int) $doc->getId(), '1', 'TOKEN_pex_favorito_' . $outra->getId());
        self::assertResponseStatusCodeSame(400);

        $this->favoritar($client, $pasta, 'documento', (int) $doc->getId(), '1', 'TOKEN_pex_lote_' . $pasta->getId());
        self::assertResponseStatusCodeSame(400, 'o token do lote não serve para a estrela');

        self::assertSame(0, $this->favoritosDoDocumento($doc));
    }

    #[TestDox('usuário do MESMO escritório sem permissão de ver a pasta leva 403 e não grava nada')]
    public function testSemPermissaoDeVerDa403(): void
    {
        $client     = $this->cliente();
        [, $tenant] = $this->criarAdmin();
        $semNada    = $this->criarUsuarioSemNenhumaPermissao($tenant);
        $pasta      = $this->criarPasta($tenant);
        $doc        = $this->criarDocumento($pasta, $tenant, null, 'a.pdf');
        $this->logarComTenant($client, $semNada, $tenant);

        $this->favoritar($client, $pasta, 'documento', (int) $doc->getId(), '1');

        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->favoritosDoDocumento($doc));
    }

    #[TestDox('quem só pode VER a pasta consegue marcar — o 403 acima é a permissão, não outra barreira')]
    public function testQuemSoVeConsegueMarcar(): void
    {
        $client     = $this->cliente();
        [, $tenant] = $this->criarAdmin();
        $leitor     = $this->criarUsuarioSemPermissaoDoModulo($tenant); // tem resources.pasta.view
        $pasta      = $this->criarPasta($tenant);
        $doc        = $this->criarDocumento($pasta, $tenant, null, 'a.pdf');
        $this->logarComTenant($client, $leitor, $tenant);

        $this->favoritar($client, $pasta, 'documento', (int) $doc->getId(), '1');

        self::assertResponseIsSuccessful();
        self::assertSame(1, $this->favoritosDoDocumento($doc, $leitor));
    }

    /** @return iterable<string, array{array<string, mixed>, int}> */
    public static function corposInvalidos(): iterable
    {
        yield 'tipo desconhecido'     => [['tipo' => 'cliente', 'marcado' => '1'], 422];
        yield 'tipo ausente'          => [['marcado' => '1'], 422];
        yield 'marcado ausente'       => [['tipo' => 'documento'], 422];
        yield 'marcado fora de 1|0'   => [['tipo' => 'documento', 'marcado' => '2'], 422];
        yield 'marcado como texto'    => [['tipo' => 'documento', 'marcado' => 'sim'], 422];
        yield 'alvoId não numérico'   => [['tipo' => 'documento', 'marcado' => '1', 'alvoId' => 'abc'], 404];
        yield 'alvoId zero'           => [['tipo' => 'documento', 'marcado' => '1', 'alvoId' => '0'], 404];
        yield 'alvoId ausente'        => [['tipo' => 'documento', 'marcado' => '1', 'alvoId' => null], 404];
    }

    /** @param array<string, mixed> $corpo */
    #[DataProvider('corposInvalidos')]
    #[TestDox('corpo inválido ($_dataName) é recusado sem gravar nada')]
    public function testCorpoInvalido(array $corpo, int $status): void
    {
        $client          = $this->cliente();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $this->criarDocumento($pasta, $tenant, null, 'a.pdf');
        $this->logarComTenant($client, $user, $tenant);

        $client->request('POST', '/pasta/' . $pasta->getId() . '/documentos/favorito', ['_token' => $this->token($pasta)] + array_filter($corpo, fn ($v) => $v !== null), [], self::XHR);

        self::assertResponseStatusCodeSame($status);
        self::assertSame(0, $this->totalDeFavoritos());
    }

    // ── explorador, edição e cascata ────────────────────────────────────────────

    #[TestDox('explorador: favorito=true só no que EU marquei — o favorito do colega não aparece; topo traz urlFavorito e csrfFavorito')]
    public function testExploradorMostraSoOsMeus(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $colega          = $this->criarUsuarioSemPermissaoDoModulo($tenant);
        $pasta           = $this->criarPasta($tenant);
        $minhaSecao      = $this->criarSecao($pasta, $tenant, 'MINHA');
        $secaoDoColega   = $this->criarSecao($pasta, $tenant, 'DO COLEGA');
        $meuDoc          = $this->criarDocumento($pasta, $tenant, $minhaSecao, 'meu.pdf');
        $docDoColega     = $this->criarDocumento($pasta, $tenant, null, 'colega.pdf');
        $this->marcar($tenant, $user, $meuDoc);
        $this->marcar($tenant, $user, $minhaSecao);
        $this->marcar($tenant, $colega, $docDoColega);
        $this->marcar($tenant, $colega, $secaoDoColega);

        $this->logarComTenant($client, $user, $tenant);
        $this->em()->clear();
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();
        $dados = json_decode($crawler->filter('script#pexDados[type="application/json"]')->text(null, false), true, 512, JSON_THROW_ON_ERROR);

        $arquivos = array_column($dados['arquivos'], 'favorito', 'nome');
        self::assertSame(['meu.pdf' => true, 'colega.pdf' => false], [
            'meu.pdf'    => $arquivos['meu.pdf'],
            'colega.pdf' => $arquivos['colega.pdf'],
        ]);
        $horas = array_column($dados['arquivos'], 'favoritoEm', 'nome');
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/', (string) $horas['meu.pdf'], 'a hora em que EU marquei');
        self::assertNull($horas['colega.pdf'], 'a hora do colega não aparece');
        $pastas = array_column($dados['pastas'], 'favorito', 'nome');
        self::assertTrue($pastas['MINHA']);
        $horasPastas = array_column($dados['pastas'], 'favoritoEm', 'nome');
        self::assertIsString($horasPastas['MINHA']);
        self::assertNull($horasPastas['DO COLEGA']);
        self::assertFalse($pastas['DO COLEGA'], 'a estrela do colega não acende a minha');

        self::assertStringEndsWith('/pasta/' . $pasta->getId() . '/documentos/favorito', $dados['urlFavorito']);
        self::assertIsString($dados['csrfFavorito']);
        self::assertNotSame('', $dados['csrfFavorito']);
    }

    #[TestDox('explorador: estrela que marquei em OUTRA pasta não acende item desta')]
    public function testExploradorNaoMisturaPastas(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $outra           = $this->criarPasta($tenant);
        $this->criarDocumento($pasta, $tenant, null, 'daqui.pdf');
        $this->marcar($tenant, $user, $this->criarDocumento($outra, $tenant, null, 'de-la.pdf'));

        $this->logarComTenant($client, $user, $tenant);
        $this->em()->clear();
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();
        $dados = json_decode($crawler->filter('script#pexDados[type="application/json"]')->text(null, false), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame([false], array_column($dados['arquivos'], 'favorito'));
    }

    #[TestDox('editar com XHR devolve o documento com a estrela no estado real (não apaga a de quem marcou)')]
    public function testEditarDevolveOFavoritoReal(): void
    {
        $client          = $this->cliente();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $doc             = $this->criarDocumento($pasta, $tenant, null, 'a.pdf');
        $this->marcar($tenant, $user, $doc);
        $id = (int) $doc->getId();
        $this->logarComTenant($client, $user, $tenant);
        $this->em()->clear();

        $client->request('POST', "/pasta/documento/{$id}/editar", [
            '_token'   => 'TOKEN_edit_documento_' . $id,
            'nomeBase' => 'b',
        ], [], self::XHR);

        self::assertResponseIsSuccessful();
        $documento = $this->json($client)['documento'];
        self::assertTrue($documento['favorito']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/', (string) $documento['favoritoEm'], 'a hora vai junto: é ela que põe a linha no lugar do topo');
    }

    #[TestDox('excluir o documento ou a subpasta apaga a estrela (FK CASCADE)')]
    public function testExcluirAlvoApagaFavorito(): void
    {
        $this->cliente();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $secao           = $this->criarSecao($pasta, $tenant, 'S');
        $doc             = $this->criarDocumento($pasta, $tenant, null, 'a.pdf');
        $this->marcar($tenant, $user, $doc);
        $this->marcar($tenant, $user, $secao);
        self::assertSame(2, $this->totalDeFavoritos());

        $conn = $this->em()->getConnection();
        $conn->executeStatement('DELETE FROM pasta_documento WHERE id = :id', ['id' => $doc->getId()]);
        self::assertSame(1, $this->totalDeFavoritos());
        $conn->executeStatement('DELETE FROM pasta_secao WHERE id = :id', ['id' => $secao->getId()]);
        self::assertSame(0, $this->totalDeFavoritos());
    }

    // ── restrições do banco ─────────────────────────────────────────────────────

    #[TestDox('CHECK: exatamente um alvo — nenhum ou os dois são recusados pelo banco (23514)')]
    public function testCheckExatamenteUmAlvo(): void
    {
        $this->cliente();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $secao           = $this->criarSecao($pasta, $tenant, 'S');
        $doc             = $this->criarDocumento($pasta, $tenant, null, 'a.pdf');

        self::assertSame('23514', $this->sqlstateDoInsert($tenant, $user, null, null), 'sem alvo');
        self::assertSame('23514', $this->sqlstateDoInsert($tenant, $user, (int) $doc->getId(), (int) $secao->getId()), 'dois alvos');
        self::assertNull($this->sqlstateDoInsert($tenant, $user, (int) $doc->getId(), null), 'um alvo passa');
    }

    #[TestDox('UNIQUE: o mesmo usuário não marca duas vezes o mesmo documento nem a mesma subpasta (23505); outro usuário pode')]
    public function testUniquePorUsuarioEAlvo(): void
    {
        $this->cliente();
        [$user, $tenant] = $this->criarAdmin();
        $colega          = $this->criarUsuarioSemPermissaoDoModulo($tenant);
        $pasta           = $this->criarPasta($tenant);
        $secao           = $this->criarSecao($pasta, $tenant, 'S');
        $doc             = $this->criarDocumento($pasta, $tenant, null, 'a.pdf');

        self::assertNull($this->sqlstateDoInsert($tenant, $user, (int) $doc->getId(), null));
        self::assertSame('23505', $this->sqlstateDoInsert($tenant, $user, (int) $doc->getId(), null));
        self::assertNull($this->sqlstateDoInsert($tenant, $colega, (int) $doc->getId(), null), 'outro usuário, outra linha');

        self::assertNull($this->sqlstateDoInsert($tenant, $user, null, (int) $secao->getId()));
        self::assertSame('23505', $this->sqlstateDoInsert($tenant, $user, null, (int) $secao->getId()));
        self::assertNull($this->sqlstateDoInsert($tenant, $user, null, (int) $this->criarSecao($pasta, $tenant, 'T')->getId()), 'várias subpastas com documento_id NULL não colidem');
    }

    // ── helpers ────────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $resposta */
    private function assertMarcadoComHora(array $resposta): void
    {
        self::assertSame(['ok', 'marcado', 'favoritoEm'], array_keys($resposta));
        self::assertTrue($resposta['ok']);
        self::assertTrue($resposta['marcado']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/', (string) $resposta['favoritoEm'], 'a hora em que ficou marcado (ISO, sem fuso)');
    }

    private function cliente(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->instalarCsrfStorage();

        return $client;
    }

    private function token(Pasta $pasta): string
    {
        return 'TOKEN_pex_favorito_' . $pasta->getId();
    }

    private function favoritar(KernelBrowser $client, Pasta $pasta, string $tipo, int $alvoId, string $marcado, ?string $token = null): void
    {
        $client->request('POST', '/pasta/' . $pasta->getId() . '/documentos/favorito', [
            '_token'  => $token ?? $this->token($pasta),
            'tipo'    => $tipo,
            'alvoId'  => (string) $alvoId,
            'marcado' => $marcado,
        ], [], self::XHR);
    }

    /** @param array<string, mixed> $corpo */
    private function favoritarJson(KernelBrowser $client, Pasta $pasta, array $corpo): void
    {
        $client->request(
            'POST',
            '/pasta/' . $pasta->getId() . '/documentos/favorito',
            [],
            [],
            self::XHR + ['CONTENT_TYPE' => 'application/json'],
            json_encode(['_token' => $this->token($pasta)] + $corpo, JSON_THROW_ON_ERROR),
        );
    }

    /** @return array<string, mixed> */
    private function json(KernelBrowser $client): array
    {
        $json = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($json, (string) $client->getResponse()->getContent());

        return $json;
    }

    private function marcar(Tenant $tenant, User $usuario, PastaDocumento|PastaSecao $alvo): void
    {
        $this->em()->persist($alvo instanceof PastaDocumento
            ? PastaDocumentoFavorito::doDocumento($tenant, $usuario, $alvo)
            : PastaDocumentoFavorito::daSecao($tenant, $usuario, $alvo));
        $this->em()->flush();
    }

    private function favoritosDoDocumento(PastaDocumento $doc, ?User $usuario = null): int
    {
        return $this->contar('documento_id', (int) $doc->getId(), $usuario);
    }

    private function favoritosDaSecao(PastaSecao $secao, ?User $usuario = null): int
    {
        return $this->contar('secao_id', (int) $secao->getId(), $usuario);
    }

    private function contar(string $coluna, int $id, ?User $usuario): int
    {
        $sql    = "SELECT COUNT(*) FROM pasta_documento_favorito WHERE {$coluna} = :id";
        $params = ['id' => $id];
        if ($usuario !== null) {
            $sql .= ' AND user_id = :usuario';
            $params['usuario'] = $usuario->getId();
        }

        return (int) $this->em()->getConnection()->fetchOne($sql, $params);
    }

    private function totalDeFavoritos(): int
    {
        return (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM pasta_documento_favorito');
    }

    /**
     * INSERT direto, dentro de um SAVEPOINT: a violação aborta só o savepoint, não a transação do
     * DAMA — o teste segue usando a conexão. Devolve o SQLSTATE da recusa, ou NULL se gravou.
     */
    private function sqlstateDoInsert(Tenant $tenant, User $usuario, ?int $documentoId, ?int $secaoId): ?string
    {
        $conn = $this->em()->getConnection();
        $conn->executeStatement('SAVEPOINT favorito_restricao');
        try {
            $conn->executeStatement(
                'INSERT INTO pasta_documento_favorito (criado_em, tenant_id, user_id, documento_id, secao_id) VALUES (NOW(), :t, :u, :d, :s)',
                ['t' => $tenant->getId(), 'u' => $usuario->getId(), 'd' => $documentoId, 's' => $secaoId],
            );
            $conn->executeStatement('RELEASE SAVEPOINT favorito_restricao');

            return null;
        } catch (DriverException $e) {
            $conn->executeStatement('ROLLBACK TO SAVEPOINT favorito_restricao');

            return $e->getSQLState();
        }
    }

    private function criarSecao(Pasta $pasta, Tenant $tenant, string $nome): PastaSecao
    {
        $secao = new PastaSecao();
        $secao->setPasta($pasta);
        $secao->setTenant($tenant);
        $secao->setNome($nome);
        $secao->setOrdem(1);
        $this->em()->persist($secao);
        $this->em()->flush();

        return $secao;
    }

    private function criarDocumento(Pasta $pasta, Tenant $tenant, ?PastaSecao $secao, string $nome): PastaDocumento
    {
        $doc = new PastaDocumento();
        $doc->setTitulo($nome);
        $doc->setCategoria(PastaDocumento::CATEGORIA_DEMAIS);
        $doc->setCaminhoArquivo('fake-' . bin2hex(random_bytes(6)) . '.pdf');
        $doc->setNomeOriginal($nome);
        $doc->setMimeType('application/pdf');
        $doc->setTamanhoBytes(10);
        $doc->setPasta($pasta);
        $doc->setTenant($tenant);
        $doc->setSecao($secao);
        $pasta->addDocumento($doc);
        $this->em()->persist($doc);
        $this->em()->flush();

        return $doc;
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
