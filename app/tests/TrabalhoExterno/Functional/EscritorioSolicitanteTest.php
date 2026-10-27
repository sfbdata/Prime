<?php

declare(strict_types=1);

namespace App\Tests\TrabalhoExterno\Functional;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\TrabalhoExterno\Controller\DemandaExternaController;
use App\TrabalhoExterno\Entity\EscritorioSolicitante;
use App\TrabalhoExterno\UseCase\MontarPainelUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;

/** "Novo escritório" e "Salvar edição" pela HTTP: dono, CSRF, teto, painel e o escritório da plataforma intocado. */
#[CoversClass(DemandaExternaController::class)]
#[CoversClass(MontarPainelUseCase::class)]
final class EscritorioSolicitanteTest extends JusPrimeWebTestCase
{
    use CriaFixturesTrabalhoExternoTrait;

    private const ROTA = '/trabalho-externo/api/escritorios-solicitantes';

    /** @return array{0: \Symfony\Bundle\FrameworkBundle\KernelBrowser, 1: User, 2: Tenant} */
    private function solicitanteLogado(): array
    {
        $client = static::createClient();
        $this->instalarCsrf($client);
        $sede = $this->criarTenant('Central');
        $this->definirSede($sede);
        $a = $this->criarTenant('Escritório A');
        $user = $this->criarUsuario($a);
        $this->logarComTenant($client, $user, $a);

        return [$client, $user, $a];
    }

    #[TestDox('cria, aparece no painel depois dos escritórios com id "s<n>", salvo e telefone real; o Tenant não muda')]
    public function testCriaEAparece(): void
    {
        [$client, , $a] = $this->solicitanteLogado();
        $antes = $this->em()->getConnection()->fetchAssociative('SELECT name, estado, cidade FROM tenant WHERE id = ?', [$a->getId()]);

        $r = $this->postarJson($client, self::ROTA, ['nome' => 'Silva & Souza', 'email' => 'c@silva.test', 'telefone' => '(61) 3000-0000', 'uf' => 'GO', 'cidade' => 'Goiânia']);
        self::assertResponseIsSuccessful();
        self::assertSame('Escritório salvo', $r['aviso']['titulo']);
        self::assertSame('Silva & Souza fica disponível nas próximas solicitações.', $r['aviso']['texto']);
        self::assertMatchesRegularExpression('/^s\d+$/', $r['escritorio']['id']);

        $client->request('GET', '/trabalho-externo/api/painel');
        $lista = $this->json($client)['painel']['novaSolicitacao']['escritorios'];
        self::assertCount(2, $lista);
        self::assertSame($a->getId(), $lista[0]['id'], 'o escritório (tenant) vem primeiro');
        self::assertArrayNotHasKey('salvo', $lista[0]);
        self::assertSame(['id' => $r['escritorio']['id'], 'salvo' => true, 'nome' => 'Silva & Souza', 'email' => 'c@silva.test', 'telefone' => '(61) 3000-0000', 'uf' => 'GO', 'cidade' => 'Goiânia'], $lista[1]);
        self::assertSame($antes, $this->em()->getConnection()->fetchAssociative('SELECT name, estado, cidade FROM tenant WHERE id = ?', [$a->getId()]), 'o Tenant nunca é alterado');
    }

    #[TestDox('nome vazio: 422 com a mensagem do desenho')]
    public function testNomeVazio(): void
    {
        [$client] = $this->solicitanteLogado();

        $r = $this->postarJson($client, self::ROTA, ['nome' => '  ']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Informe o nome do escritório para salvar.', $r['mensagem']);
        self::assertSame(0, $this->contar('trabalho_externo_escritorio_solicitante'));
    }

    #[TestDox('sem o token CSRF: 403 e nada gravado, na criação e na edição')]
    public function testSemCsrf(): void
    {
        [$client, $user] = $this->solicitanteLogado();
        $e = new EscritorioSolicitante($this->em()->find(User::class, $user->getId()), \App\TrabalhoExterno\DTO\DadosDoEscritorioSolicitante::deArray(['nome' => 'Meu']));
        $this->em()->persist($e);
        $this->em()->flush();

        $this->postarJson($client, self::ROTA, ['nome' => 'X'], token: 'errado');
        self::assertResponseStatusCodeSame(403);
        $this->postarJson($client, self::ROTA . '/' . $e->getId(), ['nome' => 'Trocado'], token: 'errado');
        self::assertResponseStatusCodeSame(403);
        self::assertSame(1, $this->contar('trabalho_externo_escritorio_solicitante'));
        self::assertSame('Meu', $this->em()->getConnection()->fetchOne('SELECT nome FROM trabalho_externo_escritorio_solicitante'));
    }

    #[TestDox('atualiza o próprio: toast "Escritório atualizado"; id de outro usuário é 404 e o dele não muda')]
    public function testAtualizaEDono(): void
    {
        [$client, $user, $a] = $this->solicitanteLogado();
        $outro = $this->criarUsuario($a, nome: 'Outro');
        $alheio = new EscritorioSolicitante($this->em()->find(User::class, $outro->getId()), \App\TrabalhoExterno\DTO\DadosDoEscritorioSolicitante::deArray(['nome' => 'Do outro']));
        $this->em()->persist($alheio);
        $this->em()->flush();

        $r = $this->postarJson($client, self::ROTA, ['nome' => 'Meu']);
        $id = (int) substr($r['escritorio']['id'], 1);

        $r = $this->postarJson($client, self::ROTA . '/' . $id, ['nome' => 'Meu renomeado', 'email' => 'novo@meu.test', 'uf' => 'SP', 'cidade' => 'Santos']);
        self::assertResponseIsSuccessful();
        self::assertSame('Escritório atualizado', $r['aviso']['titulo']);
        self::assertSame('Meu renomeado', $r['aviso']['texto']);
        self::assertSame('novo@meu.test', $r['escritorio']['email']);

        $r = $this->postarJson($client, self::ROTA . '/' . $alheio->getId(), ['nome' => 'Invadido']);
        self::assertResponseStatusCodeSame(404);
        self::assertSame('Do outro', $this->em()->getConnection()->fetchOne('SELECT nome FROM trabalho_externo_escritorio_solicitante WHERE id = ?', [$alheio->getId()]));

        $this->postarJson($client, self::ROTA . '/' . $id, ['nome' => '']);
        self::assertResponseStatusCodeSame(422);
        $this->postarJson($client, self::ROTA . '/999999', ['nome' => 'X']);
        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('o painel só lista os escritórios guardados do próprio usuário')]
    public function testPainelSoDoDono(): void
    {
        [$client, , $a] = $this->solicitanteLogado();
        $outro = $this->criarUsuario($a, nome: 'Outro');
        $this->em()->persist(new EscritorioSolicitante($this->em()->find(User::class, $outro->getId()), \App\TrabalhoExterno\DTO\DadosDoEscritorioSolicitante::deArray(['nome' => 'Segredo do outro'])));
        $this->em()->flush();

        $client->request('GET', '/trabalho-externo/api/painel');
        $nomes = array_column($this->json($client)['painel']['novaSolicitacao']['escritorios'], 'nome');
        self::assertNotContains('Segredo do outro', $nomes);
    }

    #[TestDox('teto de 20 por usuário: o 21º é 409; outro usuário não é afetado')]
    public function testTeto(): void
    {
        [$client, $user, $a] = $this->solicitanteLogado();
        $em = $this->em();
        $dono = $em->find(User::class, $user->getId());
        for ($i = 1; $i <= EscritorioSolicitante::LIMITE_POR_USUARIO; ++$i) {
            $em->persist(new EscritorioSolicitante($dono, \App\TrabalhoExterno\DTO\DadosDoEscritorioSolicitante::deArray(['nome' => "E$i"])));
        }
        $em->flush();

        $r = $this->postarJson($client, self::ROTA, ['nome' => 'E21']);
        self::assertResponseStatusCodeSame(409);
        self::assertStringContainsString('limite', $r['mensagem']);
        self::assertSame(20, $this->contar('trabalho_externo_escritorio_solicitante'));

        $outro = $this->criarUsuario($a, nome: 'Outro');
        $this->logarComTenant($client, $outro, $a);
        $this->postarJson($client, self::ROTA, ['nome' => 'E1']);
        self::assertResponseIsSuccessful();
    }

    #[TestDox('o Master abre em nome de escritório da plataforma: os guardados não entram na lista dele; sem permissão do módulo: 403')]
    public function testMasterESemAcesso(): void
    {
        $client = static::createClient();
        $this->instalarCsrf($client);
        $central = $this->criarTenant('Central');
        $master = $this->criarMaster($central);
        $this->em()->persist(new EscritorioSolicitante($this->em()->find(User::class, $master->getId()), \App\TrabalhoExterno\DTO\DadosDoEscritorioSolicitante::deArray(['nome' => 'Guardado do Master'])));
        $this->em()->flush();
        $this->logarComTenant($client, $master, $central);

        $client->request('GET', '/trabalho-externo/api/painel');
        $lista = $this->json($client)['painel']['novaSolicitacao']['escritorios'];
        self::assertSame([$central->getId()], array_column($lista, 'id'), 'o Master escolhe só escritório da plataforma');

        $sem = $this->criarUsuario($central, []);
        $this->logarComTenant($client, $sem, $central);
        $this->postarJson($client, self::ROTA, ['nome' => 'X']);
        self::assertResponseStatusCodeSame(403);
    }

    #[TestDox('excluir o usuário leva os escritórios guardados junto (ON DELETE CASCADE)')]
    public function testSomeComOUsuario(): void
    {
        [$client, $user] = $this->solicitanteLogado();
        $this->postarJson($client, self::ROTA, ['nome' => 'Meu']);
        self::assertSame(1, $this->contar('trabalho_externo_escritorio_solicitante'));

        $c = $this->em()->getConnection();
        foreach (['user_tenant'] as $t) {
            $c->executeStatement("DELETE FROM $t WHERE user_id = ?", [$user->getId()]);
        }
        $c->executeStatement('DELETE FROM trabalho_externo_preferencia WHERE usuario_id = ?', [$user->getId()]);
        $c->executeStatement('DELETE FROM "user" WHERE id = ?', [$user->getId()]);
        self::assertSame(0, $this->contar('trabalho_externo_escritorio_solicitante'));
    }

    private function contar(string $tabelaEWhere): int
    {
        return (int) $this->em()->getConnection()->fetchOne("SELECT COUNT(*) FROM $tabelaEWhere");
    }
}
