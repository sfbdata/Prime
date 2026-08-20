<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Entity\PastaSecao;
use App\Entity\Tenant\Tenant;
use App\Pasta\Controller\PastaSecaoController;
use App\Shared\Service\ArquivoStorageInterface;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

#[CoversClass(PastaSecaoController::class)]
final class PastaSecaoControllerTest extends JusPrimeWebTestCase
{
    private function criarUsuarioAdmin(): array
    {
        $container = static::getContainer();
        $em        = $container->get(EntityManagerInterface::class);
        $hasher    = $container->get(UserPasswordHasherInterface::class);

        $tenant = new Tenant();
        $tenant->setName('Tenant Secao ' . uniqid());
        $em->persist($tenant);

        $user = new User();
        $user->setEmail('test_secao_' . uniqid() . '@test.com');
        $user->setFullName('Admin Secao');
        $user->setRoles(['ROLE_SUPER_ADMIN']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);

        $userTenant = new UserTenant($user, $tenant);
        $em->persist($userTenant);

        $em->flush();

        return [$user, $tenant];
    }

    private function criarPasta(Tenant $tenant): Pasta
    {
        $em    = static::getContainer()->get(EntityManagerInterface::class);
        $pasta = new Pasta();
        $pasta->setNup('TEST-SEC-' . uniqid());
        $pasta->setTenant($tenant);
        $em->persist($pasta);
        $em->flush();

        return $pasta;
    }

    private function criarSecao(Pasta $pasta, Tenant $tenant): PastaSecao
    {
        $em    = static::getContainer()->get(EntityManagerInterface::class);
        $secao = new PastaSecao();
        $secao->setPasta($pasta);
        $secao->setTenant($tenant);
        $secao->setNome('Seção de Teste');
        $secao->setOrdem(1);
        $em->persist($secao);
        $em->flush();

        return $secao;
    }

    private function criarDocumento(Pasta $pasta, Tenant $tenant, int $ordem): PastaDocumento
    {
        $em  = static::getContainer()->get(EntityManagerInterface::class);
        $doc = new PastaDocumento();
        $doc->setTitulo('doc' . $ordem);
        $doc->setCategoria(PastaDocumento::CATEGORIA_DEMAIS);
        $doc->setCaminhoArquivo('uploads/fake/doc' . $ordem . '.pdf');
        $doc->setNomeOriginal('doc' . $ordem . '.pdf');
        $doc->setMimeType('application/pdf');
        $doc->setTamanhoBytes(1024);
        $doc->setOrdem($ordem);
        $doc->setPasta($pasta);
        $doc->setTenant($tenant);
        $em->persist($doc);
        $em->flush();

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

    private function csrf(string $tokenId): string
    {
        return 'TOKEN_' . $tokenId;
    }

    // ── Criar ──────────────────────────────────────────────────────────────────

    #[TestDox('POST criar seção sem autenticação redireciona para login')]
    public function testCriarSemAutenticacaoRedireciona(): void
    {
        $client = static::createClient();
        $client->request('POST', '/pasta/1/secao');

        self::assertResponseRedirects();
        self::assertStringContainsString('login', (string) $client->getResponse()->headers->get('Location'));
    }

    #[TestDox('POST criar seção com CSRF inválido retorna 400')]
    public function testCriarCsrfInvalidoRetorna400(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $client->loginUser($user);
        $this->marcarTermosAceitos($client);

        $client->request('POST', "/pasta/{$pasta->getId()}/secao", [
            '_token' => 'token_invalido',
            'nome'   => 'Petições',
        ]);

        self::assertResponseStatusCodeSame(400);
    }

    #[TestDox('POST criar seção com nome vazio retorna 422')]
    public function testCriarNomeVazioRetorna422(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);

        $client->request('POST', "/pasta/{$pasta->getId()}/secao", [
            '_token' => $this->csrf('pasta_secao_criar_' . $pasta->getId()),
            'nome'   => '   ',
        ]);

        self::assertResponseStatusCodeSame(422);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertArrayHasKey('erro', $data);
    }

    #[TestDox('POST criar seção com nome válido retorna 201 com id e nome')]
    public function testCriarComSucessoRetorna201(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);

        $client->request('POST', "/pasta/{$pasta->getId()}/secao", [
            '_token' => $this->csrf('pasta_secao_criar_' . $pasta->getId()),
            'nome'   => 'Petições',
        ]);

        self::assertResponseStatusCodeSame(201);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('PETIÇÕES', $data['nome']);
        self::assertArrayHasKey('id', $data);
        self::assertGreaterThan(0, $data['id']);
    }

    #[TestDox('criar aceita paiId e aninha a pasta')]
    public function testCriarComPaiAninha(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $pai             = $this->criarSecao($pasta, $tenant);
        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);

        $client->request('POST', '/pasta/' . $pasta->getId() . '/secao', [
            '_token' => $this->csrf('pasta_secao_criar_' . $pasta->getId()),
            'nome'   => 'Filha',
            'paiId'  => (string) $pai->getId(),
        ]);

        self::assertResponseStatusCodeSame(201);
        $json = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame($pai->getId(), $json['paiId']);
    }

    #[TestDox('criar acima do 10º nível é recusado mesmo com a requisição forjada')]
    public function testCriarAcimaDoTetoRecusadoPelaRota(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);

        $em = static::getContainer()->get(EntityManagerInterface::class);

        // Cadeia de 10: a última fica no nível 10, então criar dentro dela daria 11.
        $anterior = null;
        for ($i = 0; $i < 10; ++$i) {
            $s = new PastaSecao();
            $s->setPasta($pasta);
            $s->setTenant($tenant);
            $s->setNome('N' . $i);
            $s->setOrdem(1);
            $s->setPai($anterior);
            $em->persist($s);
            $anterior = $s;
        }
        $em->flush();
        $paiId = $anterior->getId();

        // O teto mora SÓ no back — a tela nem sabe qual é o número. Mandamos direto.
        $client->request('POST', '/pasta/' . $pasta->getId() . '/secao', [
            '_token' => $this->csrf('pasta_secao_criar_' . $pasta->getId()),
            'nome'   => 'Decima primeira',
            'paiId'  => (string) $paiId,
        ]);

        self::assertResponseStatusCodeSame(422);

        $em->clear();
        $criadas = $em->getRepository(PastaSecao::class)->count(['pasta' => $pasta->getId()]);
        self::assertSame(10, $criadas, 'nenhuma seção a mais foi gravada');
    }

    // ── Renomear ───────────────────────────────────────────────────────────────

    #[TestDox('POST renomear seção com nome válido retorna 200')]
    public function testRenomearComSucessoRetorna200(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $secao           = $this->criarSecao($pasta, $tenant);
        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);

        $client->request('POST', "/pasta/secao/{$secao->getId()}/renomear", [
            '_token' => $this->csrf('pasta_secao_renomear_' . $secao->getId()),
            'nome'   => 'Contratos',
        ]);

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertTrue($data['ok']);
        self::assertSame('CONTRATOS', $data['nome']);
    }

    #[TestDox('POST renomear seção inexistente retorna 404')]
    public function testRenomearSecaoInexistenteRetorna404(): void
    {
        $client  = static::createClient();
        [$user]  = $this->criarUsuarioAdmin();
        $this->instalarCsrfStorage();
        $client->loginUser($user);
        $this->marcarTermosAceitos($client);

        $client->request('POST', '/pasta/secao/999999/renomear', [
            '_token' => $this->csrf('pasta_secao_renomear_999999'),
            'nome'   => 'Teste',
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    // ── Excluir ────────────────────────────────────────────────────────────────

    #[TestDox('POST excluir seção com sucesso retorna 200')]
    public function testExcluirComSucessoRetorna200(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $secao           = $this->criarSecao($pasta, $tenant);
        $secaoId         = $secao->getId();
        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);

        $client->request('POST', "/pasta/secao/{$secaoId}/excluir", [
            '_token' => $this->csrf('pasta_secao_excluir_' . $secaoId),
        ]);

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertTrue($data['ok']);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertNull($em->find(PastaSecao::class, $secaoId));
    }

    #[TestDox('POST excluir seção com CSRF inválido retorna 400')]
    public function testExcluirCsrfInvalidoRetorna400(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $secao           = $this->criarSecao($pasta, $tenant);
        $client->loginUser($user);
        $this->marcarTermosAceitos($client);

        $client->request('POST', "/pasta/secao/{$secao->getId()}/excluir", [
            '_token' => 'token_invalido',
        ]);

        self::assertResponseStatusCodeSame(400);
    }

    #[TestDox('excluir informa quanto conteúdo foi apagado junto')]
    public function testExcluirDevolveAContagem(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $pai             = $this->criarSecao($pasta, $tenant);
        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);

        $em    = static::getContainer()->get(EntityManagerInterface::class);
        $filha = new PastaSecao();
        $filha->setPasta($pasta);
        $filha->setTenant($tenant);
        $filha->setNome('FILHA');
        $filha->setOrdem(1);
        $filha->setPai($pai);
        $em->persist($filha);
        $em->flush();

        $client->request('POST', '/pasta/secao/' . $pai->getId() . '/excluir', [
            '_token' => $this->csrf('pasta_secao_excluir_' . $pai->getId()),
        ]);

        self::assertResponseIsSuccessful();
        $json = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame(1, $json['subpastasRemovidas']);
    }

    #[TestDox('excluir remove do disco os arquivos de TODA a árvore — mãe, filha e neta')]
    public function testExcluirRemoveArquivosDaArvoreInteira(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);

        $em         = static::getContainer()->get(EntityManagerInterface::class);
        $storage    = static::getContainer()->get(ArquivoStorageInterface::class);
        $uploadsDir = (string) static::getContainer()->getParameter('uploads_dir');

        $mae = $this->criarSecao($pasta, $tenant);

        $filha = new PastaSecao();
        $filha->setPasta($pasta);
        $filha->setTenant($tenant);
        $filha->setNome('FILHA');
        $filha->setOrdem(1);
        $filha->setPai($mae);
        $em->persist($filha);

        $neta = new PastaSecao();
        $neta->setPasta($pasta);
        $neta->setTenant($tenant);
        $neta->setNome('NETA');
        $neta->setOrdem(1);
        $neta->setPai($filha);
        $em->persist($neta);
        $em->flush();

        // Um arquivo DE VERDADE em cada nível, associado à respectiva seção via setSecao() — sem
        // isso a coleção documentos() da seção fica vazia e o teste não prova nada.
        $caminhos = [];
        foreach ([$mae, $filha, $neta] as $secao) {
            $nomeStorage = $storage->salvarConteudo('conteudo-' . $secao->getNome(), $uploadsDir, 'pdf');

            $doc = new PastaDocumento();
            $doc->setTitulo('doc-' . $secao->getNome());
            $doc->setCategoria(PastaDocumento::CATEGORIA_DEMAIS);
            $doc->setCaminhoArquivo($nomeStorage);
            $doc->setNomeOriginal('doc-' . $secao->getNome() . '.pdf');
            $doc->setMimeType('application/pdf');
            $doc->setTamanhoBytes(10);
            $doc->setOrdem(1);
            $doc->setPasta($pasta);
            $doc->setTenant($tenant);
            $doc->setSecao($secao);
            $em->persist($doc);

            $caminhos[] = $storage->caminho($uploadsDir, $nomeStorage);
        }
        $em->flush();

        foreach ($caminhos as $caminho) {
            self::assertTrue($storage->existe($caminho), 'pré-condição: o arquivo precisa existir antes da exclusão');
        }

        $maeId = $mae->getId();

        // setSecao() só grava a FK no lado dono, sem sincronizar PastaSecao::$documentos (ver o
        // docblock de contarConteudoRecursivo()). Sem o clear(), a coleção em memória continua
        // "presa" vazia mesmo com a FK gravada no banco — o clear() força o controller a carregar
        // a árvore de verdade, exatamente como aconteceria numa requisição HTTP real.
        $em->clear();

        $client->request('POST', '/pasta/secao/' . $maeId . '/excluir', [
            '_token' => $this->csrf('pasta_secao_excluir_' . $maeId),
        ]);

        self::assertResponseIsSuccessful();
        $json = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame(3, $json['arquivosRemovidos']);

        [$caminhoMae, $caminhoFilha, $caminhoNeta] = $caminhos;
        self::assertFalse($storage->existe($caminhoMae), 'o arquivo da MÃE devia ter sido removido do disco');
        self::assertFalse($storage->existe($caminhoFilha), 'o arquivo da FILHA devia ter sido removido do disco');
        self::assertFalse($storage->existe($caminhoNeta), 'o arquivo da NETA devia ter sido removido do disco — é o que a recursão prova');
    }

    #[TestDox('excluir com ciclo gravado por fora dos guards (ex.: desfazer da auditoria) não estoura a memória')]
    public function testExcluirComCicloNoPaiNaoEstouraAMemoria(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);

        $em = static::getContainer()->get(EntityManagerInterface::class);

        $a = new PastaSecao();
        $a->setPasta($pasta);
        $a->setTenant($tenant);
        $a->setNome('A');
        $a->setOrdem(1);
        $em->persist($a);
        $em->flush();

        $b = new PastaSecao();
        $b->setPasta($pasta);
        $b->setTenant($tenant);
        $b->setNome('B');
        $b->setOrdem(1);
        $b->setPai($a);
        $em->persist($b);
        $em->flush();

        // O ciclo NUNCA deveria existir — nenhum UseCase grava isto. É exatamente o que
        // DesfazerAlteracaoAuditLogUseCase faz na auditoria: chama setPai() direto na entidade,
        // sem passar pelo MoverPastaSecaoUseCase e sem o guard de ciclo dele (descendeDe()).
        // Reproduzido aqui do mesmo jeito, por fora do UseCase.
        $a->setPai($b);
        $em->flush();

        $aId = $a->getId();
        $em->clear();

        $client->request('POST', '/pasta/secao/' . $aId . '/excluir', [
            '_token' => $this->csrf('pasta_secao_excluir_' . $aId),
        ]);

        // O ponto do teste é NÃO estourar a memória (contarConteudoRecursivo() e
        // coletarCaminhosDaArvore() têm de RETORNAR, não a resposta em si).
        self::assertResponseIsSuccessful();
    }

    // ── Mover ──────────────────────────────────────────────────────────────────

    #[TestDox('mover recusa destino de outro tenant')]
    public function testMoverRecusaDestinoDeOutroTenant(): void
    {
        $client            = static::createClient();
        [$user, $tenant]   = $this->criarUsuarioAdmin();
        [, $outroTenant]   = $this->criarUsuarioAdmin();
        $pasta      = $this->criarPasta($tenant);
        $secao      = $this->criarSecao($pasta, $tenant);
        $outraPasta = $this->criarPasta($outroTenant);
        $alheia     = $this->criarSecao($outraPasta, $outroTenant);
        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);

        $client->request('POST', '/pasta/secao/' . $secao->getId() . '/mover', [
            '_token'    => $this->csrf('pasta_secao_mover_' . $secao->getId()),
            'destinoId' => (string) $alheia->getId(),
        ]);

        // 404, não 403: mesmo padrão do criar() — não confirma a existência de uma pasta de outro
        // escritório.
        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('mover sem destinoId devolve a pasta para a raiz')]
    public function testMoverParaRaiz(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $pai             = $this->criarSecao($pasta, $tenant);
        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);

        $em     = static::getContainer()->get(EntityManagerInterface::class);
        $filha  = new PastaSecao();
        $filha->setPasta($pasta);
        $filha->setTenant($tenant);
        $filha->setNome('FILHA');
        $filha->setOrdem(1);
        $filha->setPai($pai);
        $em->persist($filha);
        $em->flush();

        $client->request('POST', '/pasta/secao/' . $filha->getId() . '/mover', [
            '_token'    => $this->csrf('pasta_secao_mover_' . $filha->getId()),
            'destinoId' => '',
        ]);

        self::assertResponseIsSuccessful();
        $em->refresh($filha);
        self::assertNull($filha->getPai());
    }

    #[TestDox('mover para dentro da própria filha é recusado mesmo com a requisição forjada')]
    public function testMoverParaDescendenteRecusadoPelaRota(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);

        $em  = static::getContainer()->get(EntityManagerInterface::class);
        $mae = $this->criarSecao($pasta, $tenant);

        $filha = new PastaSecao();
        $filha->setPasta($pasta);
        $filha->setTenant($tenant);
        $filha->setNome('FILHA');
        $filha->setOrdem(1);
        $filha->setPai($mae);
        $em->persist($filha);
        $em->flush();

        // A tela esconderia a filha da lista de destinos. Aqui mandamos direto, como faria
        // qualquer pessoa com o DevTools aberto — o back tem de recusar sozinho.
        $client->request('POST', '/pasta/secao/' . $mae->getId() . '/mover', [
            '_token'    => $this->csrf('pasta_secao_mover_' . $mae->getId()),
            'destinoId' => (string) $filha->getId(),
        ]);

        self::assertResponseStatusCodeSame(422);

        $maeId = $mae->getId();
        $em->clear();
        self::assertNull(
            $em->find(PastaSecao::class, $maeId)->getPai(),
            'a mãe continua na raiz — a recusa não pode ter gravado nada',
        );
    }

    // ── Reordenar seções ─────────────────────────────────────────────────────────

    #[TestDox('POST reordenar seções atualiza a ordem e retorna 200')]
    public function testReordenarSecoesComSucessoRetorna200(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $secaoA          = $this->criarSecao($pasta, $tenant);
        $secaoB          = $this->criarSecao($pasta, $tenant);
        $idA             = $secaoA->getId();
        $idB             = $secaoB->getId();
        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);

        // Inverte a ordem: B primeiro, A depois.
        $client->request(
            'POST',
            "/pasta/{$pasta->getId()}/secoes/reordenar",
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                '_token' => $this->csrf('reordenar_secoes_pasta_' . $pasta->getId()),
                'ids'    => [$idB, $idA],
            ], JSON_THROW_ON_ERROR),
        );

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertTrue($data['ok']);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertSame(1, $em->find(PastaSecao::class, $idB)->getOrdem());
        self::assertSame(2, $em->find(PastaSecao::class, $idA)->getOrdem());
    }

    #[TestDox('POST reordenar seções com CSRF inválido retorna 400')]
    public function testReordenarSecoesCsrfInvalidoRetorna400(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $client->loginUser($user);
        $this->marcarTermosAceitos($client);

        $client->request(
            'POST',
            "/pasta/{$pasta->getId()}/secoes/reordenar",
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['_token' => 'token_invalido', 'ids' => [1, 2]], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(400);
    }

    // ── Isolamento multi-tenant ──────────────────────────────────────────────────

    #[TestDox('POST reordenar seções de OUTRO tenant não altera a ordem (isolamento)')]
    public function testReordenarSecoesCrossTenantNaoAltera(): void
    {
        $client            = static::createClient();
        [$userA, $tenantA] = $this->criarUsuarioAdmin();
        [, $tenantB]       = $this->criarUsuarioAdmin();
        $pastaB            = $this->criarPasta($tenantB);
        $secaoB1           = $this->criarSecao($pastaB, $tenantB);
        $secaoB2           = $this->criarSecao($pastaB, $tenantB);

        // Ordens distintas para provar que nada muda.
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $secaoB2->setOrdem(2);
        $em->flush();

        $idB1 = $secaoB1->getId();
        $idB2 = $secaoB2->getId();
        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $userA, $tenantA);

        // Usuário do tenant A tenta reordenar seções da pasta do tenant B.
        $client->request(
            'POST',
            "/pasta/{$pastaB->getId()}/secoes/reordenar",
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                '_token' => $this->csrf('reordenar_secoes_pasta_' . $pastaB->getId()),
                'ids'    => [$idB2, $idB1],
            ], JSON_THROW_ON_ERROR),
        );

        // O UseCase filtra por tenant → nenhuma seção do tenant B é tocada.
        // Lê direto do banco (o filtro de tenant esconderia a linha via ORM — o que já é prova de isolamento).
        $conn = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
        self::assertSame(1, (int) $conn->fetchOne('SELECT ordem FROM pasta_secao WHERE id = ?', [$idB1]), 'A ordem da seção de outro escritório não pode mudar.');
        self::assertSame(2, (int) $conn->fetchOne('SELECT ordem FROM pasta_secao WHERE id = ?', [$idB2]));
    }

    #[TestDox('POST reordenar documentos de OUTRO tenant não altera a ordem (isolamento)')]
    public function testReordenarDocumentosCrossTenantNaoAltera(): void
    {
        $client            = static::createClient();
        [$userA, $tenantA] = $this->criarUsuarioAdmin();
        [, $tenantB]       = $this->criarUsuarioAdmin();
        $pastaB            = $this->criarPasta($tenantB);
        $docB1             = $this->criarDocumento($pastaB, $tenantB, 1);
        $docB2             = $this->criarDocumento($pastaB, $tenantB, 2);
        $idB1              = $docB1->getId();
        $idB2              = $docB2->getId();
        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $userA, $tenantA);

        // Usuário do tenant A tenta reordenar documentos da pasta do tenant B.
        $client->request(
            'POST',
            "/pasta/{$pastaB->getId()}/documentos/reordenar",
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                '_token' => $this->csrf('reordenar_docs_pasta_' . $pastaB->getId()),
                'ids'    => [$idB2, $idB1],
            ], JSON_THROW_ON_ERROR),
        );

        // O UseCase filtra por tenant → nenhum documento do tenant B é tocado.
        // Lê direto do banco (o filtro de tenant esconderia a linha via ORM — o que já é prova de isolamento).
        $conn = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
        self::assertSame(1, (int) $conn->fetchOne('SELECT ordem FROM pasta_documento WHERE id = ?', [$idB1]), 'A ordem do documento de outro escritório não pode mudar.');
        self::assertSame(2, (int) $conn->fetchOne('SELECT ordem FROM pasta_documento WHERE id = ?', [$idB2]));
    }
}
