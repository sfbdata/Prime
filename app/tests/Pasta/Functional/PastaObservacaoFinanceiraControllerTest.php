<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Tests\Tenant\Support\DesligaEdicaoSemPrazo;
use App\Controller\PastaController;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Notificacao;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaObservacaoFinanceira;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

#[CoversClass(PastaController::class)]
final class PastaObservacaoFinanceiraControllerTest extends JusPrimeWebTestCase
{
    private function criarUsuario(Tenant $tenant, string $prefixo = 'obsfin'): User
    {
        $container = static::getContainer();
        $em        = $container->get(EntityManagerInterface::class);

        $user = new User();
        $user->setEmail($prefixo . '_' . uniqid() . '@test.com');
        $user->setFullName('Usuário ' . $prefixo);
        $user->setRoles(['ROLE_SUPER_ADMIN']);
        $user->setIsActive(true);
        $em->persist($user);
        $em->persist(new UserTenant($user, $tenant));
        $em->flush();

        return $user;
    }

    private function criarTenant(): Tenant
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $tenant = new Tenant();
        $tenant->setName('Tenant Obs Financeira ' . uniqid());
        $em->persist($tenant);
        $em->flush();

        return $tenant;
    }

    private function criarPasta(Tenant $tenant): Pasta
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $pasta = new Pasta();
        $pasta->setNup('TEST-' . uniqid());
        $pasta->setTenant($tenant);
        $em->persist($pasta);
        $em->flush();

        return $pasta;
    }

    private function criarObservacao(
        Pasta $pasta,
        User $autor,
        Tenant $tenant,
        ?\DateTimeImmutable $criadaEm = null,
    ): PastaObservacaoFinanceira {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $obs = (new PastaObservacaoFinanceira())
            ->setPasta($pasta)
            ->setAutor($autor)
            ->setTenant($tenant)
            ->setConteudo('Observação original');

        if ($criadaEm !== null) {
            $ref = new \ReflectionProperty(PastaObservacaoFinanceira::class, 'criadaEm');
            $ref->setValue($obs, $criadaEm);
        }

        $em->persist($obs);
        $em->flush();

        return $obs;
    }

    private function instalarCsrfStorage(): void
    {
        $storage = new class implements ClearableTokenStorageInterface {
            public function getToken(string $tokenId): string
            {
                return 'TOKEN_' . $tokenId;
            }

            public function setToken(string $tokenId, string $token): void {}

            public function removeToken(string $tokenId): ?string
            {
                return null;
            }

            public function hasToken(string $tokenId): bool
            {
                return true;
            }

            public function clear(): void {}
        };

        static::getContainer()->set('security.csrf.token_storage', $storage);
    }

    private function gerarCsrf(string $tokenId): string
    {
        return 'TOKEN_' . $tokenId;
    }

    // ── Sem autenticação ─────────────────────────────────────────────────────

    #[TestDox('POST financeiro/observacao/{obsId}/editar sem autenticação redireciona para login')]
    public function testEditarSemAutenticacaoRedireciona(): void
    {
        $client = static::createClient();
        $client->request('POST', '/pasta/1/financeiro/observacao/1/editar');

        self::assertResponseRedirects();
        self::assertStringContainsString('login', (string) $client->getResponse()->headers->get('Location'));
    }

    // ── Editar ───────────────────────────────────────────────────────────────

    #[TestDox('Autor edita a própria observação dentro de 24h e recebe 200')]
    public function testAutorEditaObservacao(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $autor  = $this->criarUsuario($tenant, 'autor');
        $pasta  = $this->criarPasta($tenant);
        $obs    = $this->criarObservacao($pasta, $autor, $tenant);

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $autor, $tenant);

        $client->request('POST', "/pasta/{$pasta->getId()}/financeiro/observacao/{$obs->getId()}/editar", [
            '_token'   => $this->gerarCsrf('pasta_financeiro_obs_editar_' . $obs->getId()),
            'conteudo' => 'Observação corrigida',
        ]);

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('Observação corrigida', $data['conteudo']);
        self::assertNotNull($data['editadaEm']);
    }

    #[TestDox('Não-autor recebe 403 ao tentar editar observação de outro')]
    public function testNaoAutorNaoEditaObservacao(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $autor  = $this->criarUsuario($tenant, 'autor');
        $outro  = $this->criarUsuario($tenant, 'outro');
        $pasta  = $this->criarPasta($tenant);
        $obs    = $this->criarObservacao($pasta, $autor, $tenant);

        $this->instalarCsrfStorage();
        DesligaEdicaoSemPrazo::no(static::getContainer()->get(EntityManagerInterface::class), $tenant, static::getContainer(), $outro);
        $this->logarComTenant($client, $outro, $tenant);

        $client->request('POST', "/pasta/{$pasta->getId()}/financeiro/observacao/{$obs->getId()}/editar", [
            '_token'   => $this->gerarCsrf('pasta_financeiro_obs_editar_' . $obs->getId()),
            'conteudo' => 'Tentando editar',
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    #[TestDox('Autor recebe 403 ao editar observação fora da janela de 24h')]
    public function testEditarForaDaJanelaRetorna403(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $autor  = $this->criarUsuario($tenant, 'autor');
        $pasta  = $this->criarPasta($tenant);
        $obs    = $this->criarObservacao($pasta, $autor, $tenant, new \DateTimeImmutable('-20 minutes'));

        $this->instalarCsrfStorage();
        DesligaEdicaoSemPrazo::no(static::getContainer()->get(EntityManagerInterface::class), $tenant, static::getContainer(), $autor);
        $this->logarComTenant($client, $autor, $tenant);

        $client->request('POST', "/pasta/{$pasta->getId()}/financeiro/observacao/{$obs->getId()}/editar", [
            '_token'   => $this->gerarCsrf('pasta_financeiro_obs_editar_' . $obs->getId()),
            'conteudo' => 'Tarde demais',
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    #[TestDox('Editar com CSRF inválido retorna 403')]
    public function testEditarCsrfInvalidoRetorna403(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $autor  = $this->criarUsuario($tenant, 'autor');
        $pasta  = $this->criarPasta($tenant);
        $obs    = $this->criarObservacao($pasta, $autor, $tenant);

        $this->logarComTenant($client, $autor, $tenant);

        $client->request('POST', "/pasta/{$pasta->getId()}/financeiro/observacao/{$obs->getId()}/editar", [
            '_token'   => 'token_invalido',
            'conteudo' => 'Qualquer coisa',
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    #[TestDox('Editar observação de outra pasta retorna 404')]
    public function testEditarObservacaoDeOutraPastaRetorna404(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $autor  = $this->criarUsuario($tenant, 'autor');
        $pastaA = $this->criarPasta($tenant);
        $pastaB = $this->criarPasta($tenant);
        $obs    = $this->criarObservacao($pastaA, $autor, $tenant);

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $autor, $tenant);

        $client->request('POST', "/pasta/{$pastaB->getId()}/financeiro/observacao/{$obs->getId()}/editar", [
            '_token'   => $this->gerarCsrf('pasta_financeiro_obs_editar_' . $obs->getId()),
            'conteudo' => 'Pasta errada',
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('Observação de outro tenant na própria pasta retorna 404 (IDOR bloqueado)')]
    public function testEditarObservacaoCrossTenantViaIdRetorna404(): void
    {
        $client  = static::createClient();
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $userA   = $this->criarUsuario($tenantA, 'usera');
        $userB   = $this->criarUsuario($tenantB, 'userb');
        $pastaA  = $this->criarPasta($tenantA);
        $pastaB  = $this->criarPasta($tenantB);
        $obsB    = $this->criarObservacao($pastaB, $userB, $tenantB);

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $userA, $tenantA);

        $client->request('POST', "/pasta/{$pastaA->getId()}/financeiro/observacao/{$obsB->getId()}/editar", [
            '_token'   => $this->gerarCsrf('pasta_financeiro_obs_editar_' . $obsB->getId()),
            'conteudo' => 'Invasão',
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    // ── Excluir ──────────────────────────────────────────────────────────────

    #[TestDox('Autor exclui a própria observação dentro de 24h e recebe sucesso')]
    public function testAutorExcluiObservacao(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $autor  = $this->criarUsuario($tenant, 'autor');
        $pasta  = $this->criarPasta($tenant);
        $obs    = $this->criarObservacao($pasta, $autor, $tenant);
        $obsId  = $obs->getId();

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $autor, $tenant);

        $client->request('POST', "/pasta/{$pasta->getId()}/financeiro/observacao/{$obsId}/excluir", [
            '_token' => $this->gerarCsrf('pasta_financeiro_obs_excluir_' . $obsId),
        ]);

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertTrue($data['sucesso']);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertNull($em->find(PastaObservacaoFinanceira::class, $obsId));
    }

    #[TestDox('Não-autor recebe 403 ao tentar excluir observação de outro')]
    public function testNaoAutorNaoExcluiObservacao(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $autor  = $this->criarUsuario($tenant, 'autor');
        $outro  = $this->criarUsuario($tenant, 'outro');
        $pasta  = $this->criarPasta($tenant);
        $obs    = $this->criarObservacao($pasta, $autor, $tenant);

        $this->instalarCsrfStorage();
        DesligaEdicaoSemPrazo::no(static::getContainer()->get(EntityManagerInterface::class), $tenant, static::getContainer(), $outro);
        $this->logarComTenant($client, $outro, $tenant);

        $client->request('POST', "/pasta/{$pasta->getId()}/financeiro/observacao/{$obs->getId()}/excluir", [
            '_token' => $this->gerarCsrf('pasta_financeiro_obs_excluir_' . $obs->getId()),
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    #[TestDox('Autor recebe 403 ao excluir observação fora da janela de 24h')]
    public function testExcluirForaDaJanelaRetorna403(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $autor  = $this->criarUsuario($tenant, 'autor');
        $pasta  = $this->criarPasta($tenant);
        $obs    = $this->criarObservacao($pasta, $autor, $tenant, new \DateTimeImmutable('-20 minutes'));

        $this->instalarCsrfStorage();
        DesligaEdicaoSemPrazo::no(static::getContainer()->get(EntityManagerInterface::class), $tenant, static::getContainer(), $autor);
        $this->logarComTenant($client, $autor, $tenant);

        $client->request('POST', "/pasta/{$pasta->getId()}/financeiro/observacao/{$obs->getId()}/excluir", [
            '_token' => $this->gerarCsrf('pasta_financeiro_obs_excluir_' . $obs->getId()),
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    // ── Enviar ───────────────────────────────────────────────────────────────

    #[TestDox('Enviar observação retorna conteudoHtml sanitizado para renderização imediata na lista')]
    public function testEnviarRetornaConteudoHtml(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $autor  = $this->criarUsuario($tenant, 'autor');
        $pasta  = $this->criarPasta($tenant);

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $autor, $tenant);

        $client->request('POST', "/pasta/{$pasta->getId()}/financeiro/observacao", [
            '_token'   => $this->gerarCsrf('pasta_financeiro_obs_' . $pasta->getId()),
            'conteudo' => '<p>Acordo <strong>fechado</strong></p>',
        ]);

        self::assertResponseStatusCodeSame(201);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        // Sem `conteudoHtml`, o JS grava innerHTML = '' e a observação nasce vazia
        // na lista até o usuário editá-la (regressão do commit 8612079).
        self::assertArrayHasKey('conteudoHtml', $data);
        self::assertStringContainsString('<strong>fechado</strong>', $data['conteudoHtml']);
    }

    // ── Responder (FPN-005) ──────────────────────────────────────────────────

    /** @return list<PastaObservacaoFinanceira> */
    private function registrosDaPasta(Pasta $pasta): array
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        return $em->getRepository(PastaObservacaoFinanceira::class)->findBy(['pasta' => $pasta->getId()], ['id' => 'ASC']);
    }

    #[TestDox('FPN-005: responder grava a resposta ligada à raiz e avisa o autor dela, com link para a resposta')]
    public function testResponderGravaEAvisa(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $dono   = $this->criarUsuario($tenant, 'dono');
        $autor  = $this->criarUsuario($tenant, 'autor');
        $pasta  = $this->criarPasta($tenant);
        $raiz   = $this->criarObservacao($pasta, $dono, $tenant);
        $raizId = $raiz->getId();

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $autor, $tenant);

        $client->request('POST', "/pasta/{$pasta->getId()}/financeiro/observacao", [
            '_token'     => $this->gerarCsrf('pasta_financeiro_obs_' . $pasta->getId()),
            'conteudo'   => '<p>Recibo emitido</p>',
            'resposta_a' => (string) $raizId,
        ]);

        self::assertResponseStatusCodeSame(201);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame($raizId, $data['respostaA']);
        self::assertSame('Usuário dono', $data['respostaANome']);

        $registros = $this->registrosDaPasta($pasta);
        self::assertCount(2, $registros);
        self::assertSame($raizId, $registros[1]->getRespostaA()?->getId());
        self::assertTrue($registros[1]->isResposta());

        $em     = static::getContainer()->get(EntityManagerInterface::class);
        $avisos = $em->getRepository(Notificacao::class)->findBy(['usuario' => $dono->getId(), 'tipo' => Notificacao::TIPO_PASTA_RESPOSTA_REGISTRO]);
        self::assertCount(1, $avisos);
        self::assertSame($tenant->getId(), $avisos[0]->getTenant()?->getId());
        self::assertStringEndsWith('/pasta/' . $pasta->getId() . '#financeiro-obs-' . $data['id'], (string) $avisos[0]->getUrl());
    }

    #[TestDox('FPN-005: responder a uma resposta liga à RAIZ (um nível só)')]
    public function testResponderARespostaLigaARaiz(): void
    {
        $client   = static::createClient();
        $tenant   = $this->criarTenant();
        $autor    = $this->criarUsuario($tenant, 'autor');
        $pasta    = $this->criarPasta($tenant);
        $raiz     = $this->criarObservacao($pasta, $autor, $tenant);
        $resposta = $this->criarObservacao($pasta, $autor, $tenant);
        $resposta->setRespostaA($raiz);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $autor, $tenant);

        $client->request('POST', "/pasta/{$pasta->getId()}/financeiro/observacao", [
            '_token'     => $this->gerarCsrf('pasta_financeiro_obs_' . $pasta->getId()),
            'conteudo'   => 'Tréplica',
            'resposta_a' => (string) $resposta->getId(),
        ]);

        self::assertResponseStatusCodeSame(201);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame($raiz->getId(), $data['respostaA']);
    }

    #[TestDox('FPN-005: resposta_a de OUTRA pasta do mesmo escritório → 422 e nada é gravado')]
    public function testResponderAOutraPastaRecusa(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $autor  = $this->criarUsuario($tenant, 'autor');
        $pasta  = $this->criarPasta($tenant);
        $outra  = $this->criarPasta($tenant);
        $alheio = $this->criarObservacao($outra, $autor, $tenant);

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $autor, $tenant);

        $client->request('POST', "/pasta/{$pasta->getId()}/financeiro/observacao", [
            '_token'     => $this->gerarCsrf('pasta_financeiro_obs_' . $pasta->getId()),
            'conteudo'   => 'Invasão',
            'resposta_a' => (string) $alheio->getId(),
        ]);

        self::assertResponseStatusCodeSame(422);
        // A busca do controller já é presa à pasta: o id alheio nem chega ao UseCase.
        self::assertSame('O registro respondido não foi encontrado.', json_decode((string) $client->getResponse()->getContent(), true)['erro']);
        self::assertCount(0, $this->registrosDaPasta($pasta));
        self::assertCount(1, $this->registrosDaPasta($outra));
    }

    #[TestDox('FPN-005: resposta_a de OUTRO escritório → 422 e nada é gravado')]
    public function testResponderAOutroEscritorioRecusa(): void
    {
        $client  = static::createClient();
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $userA   = $this->criarUsuario($tenantA, 'usera');
        $userB   = $this->criarUsuario($tenantB, 'userb');
        $pastaA  = $this->criarPasta($tenantA);
        $pastaB  = $this->criarPasta($tenantB);
        $obsB    = $this->criarObservacao($pastaB, $userB, $tenantB);

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $userA, $tenantA);

        $client->request('POST', "/pasta/{$pastaA->getId()}/financeiro/observacao", [
            '_token'     => $this->gerarCsrf('pasta_financeiro_obs_' . $pastaA->getId()),
            'conteudo'   => 'Invasão',
            'resposta_a' => (string) $obsB->getId(),
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('O registro respondido não foi encontrado.', json_decode((string) $client->getResponse()->getContent(), true)['erro']);
        self::assertCount(0, $this->registrosDaPasta($pastaA));
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertCount(0, $em->getRepository(Notificacao::class)->findBy(['usuario' => $userB->getId()]));
    }

    #[TestDox('FPN-005: responder na lápide é recusado e nada é gravado')]
    public function testResponderNaLapideRecusa(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $autor  = $this->criarUsuario($tenant, 'autor');
        $pasta  = $this->criarPasta($tenant);
        $raiz   = $this->criarObservacao($pasta, $autor, $tenant);
        $pasta->marcarExcluida($autor, new \DateTimeImmutable());
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $autor, $tenant);

        $client->request('POST', "/pasta/{$pasta->getId()}/financeiro/observacao", [
            '_token'     => $this->gerarCsrf('pasta_financeiro_obs_' . $pasta->getId()),
            'conteudo'   => 'Na lápide',
            'resposta_a' => (string) $raiz->getId(),
        ], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);

        // `PastaSomenteLeituraListener`: a recusa da lápide, antes do controller.
        self::assertResponseStatusCodeSame(403);
        self::assertCount(1, $this->registrosDaPasta($pasta));
    }

    // ── @menção (EDT-010r) ───────────────────────────────────────────────────

    #[TestDox('EDT-010r: mencionar um colega grava o token com o nome do banco, mostra em destaque e avisa com o link do cartão')]
    public function testMencaoGravaMostraEAvisa(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $autor  = $this->criarUsuario($tenant, 'autor');
        $colega = $this->criarUsuario($tenant, 'colega');
        $pasta  = $this->criarPasta($tenant);

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $autor, $tenant);

        $client->request('POST', "/pasta/{$pasta->getId()}/financeiro/observacao", [
            '_token'   => $this->gerarCsrf('pasta_financeiro_obs_' . $pasta->getId()),
            'conteudo' => '<p>Falar com @[Col](user:' . $colega->getId() . ')</p>',
        ]);

        self::assertResponseStatusCodeSame(201);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertStringContainsString('@[Usuário colega](user:' . $colega->getId() . ')', $data['conteudo']);
        self::assertStringContainsString('<span class="ps-mencao" data-user-id="' . $colega->getId() . '">@Usuário colega</span>', $data['conteudoHtml']);

        $em     = static::getContainer()->get(EntityManagerInterface::class);
        $avisos = $em->getRepository(Notificacao::class)->findBy(['usuario' => $colega->getId(), 'tipo' => Notificacao::TIPO_PASTA_MENCAO_REGISTRO]);
        self::assertCount(1, $avisos);
        self::assertStringEndsWith('/pasta/' . $pasta->getId() . '#financeiro-obs-' . $data['id'], (string) $avisos[0]->getUrl());
    }

    #[TestDox('EDT-010r: mencionar alguém de OUTRO escritório vira texto e não avisa ninguém')]
    public function testMencaoDeOutroEscritorioNaoAvisa(): void
    {
        $client  = static::createClient();
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $autor   = $this->criarUsuario($tenantA, 'autor');
        $alheio  = $this->criarUsuario($tenantB, 'alheio');
        $pasta   = $this->criarPasta($tenantA);

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $autor, $tenantA);

        $client->request('POST', "/pasta/{$pasta->getId()}/financeiro/observacao", [
            '_token'   => $this->gerarCsrf('pasta_financeiro_obs_' . $pasta->getId()),
            'conteudo' => '<p>Oi @[Alheio](user:' . $alheio->getId() . ')</p>',
        ]);

        self::assertResponseStatusCodeSame(201);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertStringNotContainsString('(user:' . $alheio->getId() . ')', $data['conteudo']);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertCount(0, $em->getRepository(Notificacao::class)->findBy(['usuario' => $alheio->getId()]));
    }

    #[TestDox('EDT-010r: editar e mencionar avisa quem passou a ser mencionado')]
    public function testEditarComMencaoAvisa(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $autor  = $this->criarUsuario($tenant, 'autor');
        $colega = $this->criarUsuario($tenant, 'colega');
        $pasta  = $this->criarPasta($tenant);
        $obs    = $this->criarObservacao($pasta, $autor, $tenant);

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $autor, $tenant);

        $client->request('POST', "/pasta/{$pasta->getId()}/financeiro/observacao/{$obs->getId()}/editar", [
            '_token'   => $this->gerarCsrf('pasta_financeiro_obs_editar_' . $obs->getId()),
            'conteudo' => '<p>Agora com @[C](user:' . $colega->getId() . ')</p>',
        ]);

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertStringContainsString('class="ps-mencao"', $data['conteudoHtml']);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertCount(1, $em->getRepository(Notificacao::class)->findBy(['usuario' => $colega->getId(), 'tipo' => Notificacao::TIPO_PASTA_MENCAO_REGISTRO]));
    }
}
