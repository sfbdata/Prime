<?php

declare(strict_types=1);

namespace App\Tests\Djen\Functional;

use App\Djen\Controller\DiarioDaOabController;
use App\Djen\Entity\PublicacaoDoDiarioDaOab;
use App\Djen\Enum\TipoDeAtoDoDiario;
use App\Entity\Auth\User;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Pasta\Functional\CriaUsuarioComPermissoesTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(DiarioDaOabController::class)]
#[Group('djen')]
final class DiarioDaOabControllerTest extends JusPrimeWebTestCase
{
    use CriaFixturesDjenTrait;
    use CriaUsuarioComPermissoesTrait;

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function guardar(Tenant $tenant, User $usuario, string $ato, TipoDeAtoDoDiario $tipo = TipoDeAtoDoDiario::EticoDisciplinar, string $texto = 'Texto', ?int $prazoDias = null): int
    {
        $this->em()->persist($publicacao = new PublicacaoDoDiarioDaOab(
            $this->em()->getReference(Tenant::class, $tenant->getId()),
            $this->em()->getReference(User::class, $usuario->getId()),
            new \DateTimeImmutable('2026-10-20'),
            'OAB/DF',
            $tipo,
            $ato,
            'TED',
            $texto,
            'PED 1',
            $prazoDias,
        ));
        $this->em()->flush();

        return (int) $publicacao->getId();
    }

    private function criarPasta(Tenant $tenant, string $nup): Pasta
    {
        $pasta = new Pasta();
        $pasta->setNup($nup);
        $pasta->setNomeCliente('MARIA DA SILVA');
        $pasta->setTenant($this->em()->getReference(Tenant::class, $tenant->getId()));
        $this->em()->persist($pasta);
        $this->em()->flush();

        return $pasta;
    }

    /** @return array{0: \Symfony\Bundle\FrameworkBundle\KernelBrowser, 1: Tenant, 2: User} */
    private function entrar(): array
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $usuario = $this->criarGestor($tenant, 'g_' . uniqid() . '@test.com');
        $this->logarComTenant($client, $usuario, $tenant);
        $this->limparIdentityMap();

        return [$client, $tenant, $usuario];
    }

    /** @return array<string,string> */
    private function formulario(string $token, array $troca = []): array
    {
        return $troca + ['_token' => $token, 'data' => '2026-10-20', 'seccional' => 'OAB/DF', 'tipo' => 'etico', 'ato' => 'Notificação de defesa', 'numero_processo' => 'PED 2026.1', 'orgao' => 'TED', 'prazo_dias' => '15', 'texto' => 'Fica notificado.'];
    }

    #[Test]
    public function registraEMostraNaLista(): void
    {
        [$client] = $this->entrar();
        $crawler = $client->request('GET', '/push-processual/diario');
        $token = $crawler->filter('[data-pp-dr-form] input[name="_token"]')->attr('value');

        $client->request('POST', '/push-processual/diario/registrar', $this->formulario($token));
        self::assertResponseRedirects('/push-processual/diario');
        $crawler = $client->followRedirect();

        self::assertCount(1, $crawler->filter('[data-pp-diario-item]'));
        self::assertStringContainsString('Notificação de defesa', $crawler->filter('[data-pp-diario-item]')->text());
        self::assertCount(1, $crawler->filter('.pp-sigilo-ico'));
        self::assertStringContainsString('Publicação registrada', $crawler->text());
    }

    #[Test]
    public function dadoInvalidoNaoGravaEAvisa(): void
    {
        [$client] = $this->entrar();
        $crawler = $client->request('GET', '/push-processual/diario');
        $token = $crawler->filter('[data-pp-dr-form] input[name="_token"]')->attr('value');

        $client->request('POST', '/push-processual/diario/registrar', $this->formulario($token, ['ato' => '']));
        $crawler = $client->followRedirect();

        self::assertCount(0, $crawler->filter('[data-pp-diario-item]'));
        self::assertStringContainsString('Preencha: ato', $crawler->text());
    }

    #[Test]
    public function tokenInvalidoNaoGrava(): void
    {
        [$client] = $this->entrar();

        $client->request('POST', '/push-processual/diario/registrar', $this->formulario('errado'));
        $crawler = $client->followRedirect();

        self::assertCount(0, $crawler->filter('[data-pp-diario-item]'));
    }

    #[Test]
    public function filtraPorTipoEBuscaNoTexto(): void
    {
        [$client, $tenant, $usuario] = $this->entrar();
        $this->guardar($tenant, $usuario, 'Ato ético', TipoDeAtoDoDiario::EticoDisciplinar, 'alfa');
        $this->guardar($tenant, $usuario, 'Ato comunicado', TipoDeAtoDoDiario::Comunicado, 'beta');
        $this->limparIdentityMap();

        $crawler = $client->request('GET', '/push-processual/diario');
        self::assertCount(2, $crawler->filter('[data-pp-diario-item]'));
        $crawler = $client->request('GET', '/push-processual/diario?filtro=eticas');
        self::assertCount(1, $crawler->filter('[data-pp-diario-item]'));
        self::assertStringContainsString('Ato ético', $crawler->filter('[data-pp-diario-item]')->text());
        $crawler = $client->request('GET', '/push-processual/diario?busca=BETA');
        self::assertCount(1, $crawler->filter('[data-pp-diario-item]'));
        self::assertStringContainsString('Ato comunicado', $crawler->filter('[data-pp-diario-item]')->text());
    }

    #[Test]
    public function naoEnxergaPublicacaoDeOutroUsuarioNemDeOutroEscritorio(): void
    {
        [$client, $tenant] = $this->entrar();
        $colega = $this->criarGestor($this->em()->getReference(Tenant::class, $tenant->getId()), 'c_' . uniqid() . '@test.com');
        $this->guardar($tenant, $colega, 'Do colega');
        $outro = $this->criarTenant();
        $this->guardar($outro, $this->criarGestor($outro, 'o_' . uniqid() . '@test.com'), 'De outro escritório');
        $this->limparIdentityMap();

        $crawler = $client->request('GET', '/push-processual/diario');

        self::assertCount(0, $crawler->filter('[data-pp-diario-item]'));
    }

    #[Test]
    public function abaMostraContagemDeNaoLidas(): void
    {
        [$client, $tenant, $usuario] = $this->entrar();
        $this->guardar($tenant, $usuario, 'Ato A');
        $this->guardar($tenant, $usuario, 'Ato B');
        $this->limparIdentityMap();

        $crawler = $client->request('GET', '/push-processual/diario');

        self::assertStringContainsString('2', $crawler->filter('nav.pp-abas a[data-pp-aba="diario"]')->text());
    }

    /** @return list<int> */
    private function idsNaTela(\Symfony\Component\DomCrawler\Crawler $crawler): array
    {
        return array_map('intval', $crawler->filter('[data-pp-diario-item]')->extract(['data-id']));
    }

    private function tokenDaTela(\Symfony\Component\DomCrawler\Crawler $crawler): string
    {
        return (string) $crawler->filter('[data-pp-diario-lote] input[name="_token"]')->attr('value');
    }

    #[Test]
    public function marcaComoLidaPorAjaxEAtualizaAContagem(): void
    {
        [$client, $tenant, $usuario] = $this->entrar();
        $this->guardar($tenant, $usuario, 'Ato A');
        $this->guardar($tenant, $usuario, 'Ato B');
        $this->limparIdentityMap();
        $crawler = $client->request('GET', '/push-processual/diario');
        $ids = $this->idsNaTela($crawler);

        $client->xmlHttpRequest('POST', '/push-processual/diario/leitura', ['_token' => $this->tokenDaTela($crawler), 'lida' => '1', 'ids' => [$ids[0]]]);

        self::assertResponseIsSuccessful();
        self::assertSame(['atualizadas' => 1, 'naoLidas' => 1], json_decode((string) $client->getResponse()->getContent(), true));
        $crawler = $client->request('GET', '/push-processual/diario');
        self::assertCount(1, $crawler->filter('[data-pp-diario-linha].is-nova'));
    }

    #[Test]
    public function excluiSelecionadasELimpaLidasSoDoProprioUsuario(): void
    {
        [$client, $tenant, $usuario] = $this->entrar();
        $colega = $this->criarGestor($this->em()->getReference(Tenant::class, $tenant->getId()), 'c_' . uniqid() . '@test.com');
        $this->guardar($tenant, $usuario, 'Ato A');
        $this->guardar($tenant, $usuario, 'Ato B');
        $this->guardar($tenant, $usuario, 'Ato C');
        $this->guardar($tenant, $colega, 'Do colega');
        $this->limparIdentityMap();
        $crawler = $client->request('GET', '/push-processual/diario');
        $ids = $this->idsNaTela($crawler);
        $token = $this->tokenDaTela($crawler);

        $client->request('POST', '/push-processual/diario/leitura', ['_token' => $token, 'lida' => '1', 'ids' => [$ids[0], $ids[1]]]);
        $client->request('POST', '/push-processual/diario/excluir', ['_token' => $token, 'ids' => [$ids[2]]]);
        $client->request('POST', '/push-processual/diario/limpar-lidas', ['_token' => $token]);

        $crawler = $client->request('GET', '/push-processual/diario');
        self::assertCount(0, $crawler->filter('[data-pp-diario-item]'));
        $colegaLista = $this->em()->getRepository(PublicacaoDoDiarioDaOab::class)->findBy(['ato' => 'Do colega', 'excluidaEm' => null]);
        self::assertCount(1, $colegaLista, 'a publicação do colega não é tocada');
    }

    #[Test]
    public function naoMexeEmPublicacaoDeOutroUsuarioPorId(): void
    {
        [$client, $tenant, $usuario] = $this->entrar();
        $colega = $this->criarGestor($this->em()->getReference(Tenant::class, $tenant->getId()), 'c_' . uniqid() . '@test.com');
        $this->guardar($tenant, $colega, 'Do colega');
        $alheia = $this->em()->getRepository(PublicacaoDoDiarioDaOab::class)->findOneBy(['ato' => 'Do colega'])->getId();
        $this->limparIdentityMap();
        $crawler = $client->request('GET', '/push-processual/diario');

        $client->request('POST', '/push-processual/diario/excluir', ['_token' => $this->tokenDaTela($crawler), 'ids' => [$alheia]]);

        self::assertCount(1, $this->em()->getRepository(PublicacaoDoDiarioDaOab::class)->findBy(['ato' => 'Do colega', 'excluidaEm' => null]));
    }

    #[Test]
    public function acoesComTokenInvalidoNaoMudamNada(): void
    {
        [$client, $tenant, $usuario] = $this->entrar();
        $this->guardar($tenant, $usuario, 'Ato A');
        $this->limparIdentityMap();
        $crawler = $client->request('GET', '/push-processual/diario');
        $ids = $this->idsNaTela($crawler);

        $client->request('POST', '/push-processual/diario/excluir', ['_token' => 'errado', 'ids' => $ids]);

        self::assertCount(1, $client->request('GET', '/push-processual/diario')->filter('[data-pp-diario-item]'));
    }

    #[Test]
    public function vinculaTrocaEDesvinculaDaPasta(): void
    {
        [$client, $tenant, $usuario] = $this->entrar();
        $id = $this->guardar($tenant, $usuario, 'Ato A');
        $pasta = $this->criarPasta($tenant, 'NUP-D1');
        $alheia = $this->criarPasta($this->criarTenant(), 'NUP-ALHEIA');
        $this->limparIdentityMap();
        $token = $this->tokenDaTela($client->request('GET', '/push-processual/diario'));

        $client->request('POST', sprintf('/push-processual/diario/%d/pasta', $id), ['_token' => $token, 'pasta_id' => $pasta->getId()]);
        $crawler = $client->request('GET', '/push-processual/diario');
        self::assertStringContainsString('Pasta NUP-D1', $crawler->filter('.pp-pill--pasta')->text());

        $client->request('POST', sprintf('/push-processual/diario/%d/pasta', $id), ['_token' => $token, 'pasta_id' => $alheia->getId()]);
        self::assertStringContainsString('Pasta NUP-D1', $client->request('GET', '/push-processual/diario')->filter('.pp-pill--pasta')->text(), 'pasta de outro escritório é recusada');

        $client->request('POST', sprintf('/push-processual/diario/%d/pasta', $id), ['_token' => $token, 'pasta_id' => '']);
        $crawler = $client->request('GET', '/push-processual/diario');
        self::assertCount(0, $crawler->filter('.pp-pill--pasta'));
        self::assertCount(1, $crawler->filter('.pp-pill--avulsa'));
    }

    #[Test]
    public function criaMetaNaPastaComOPrazoEMostraOSeloDeDias(): void
    {
        [$client, $tenant, $usuario] = $this->entrar();
        $id = $this->guardar($tenant, $usuario, 'Ato com prazo', TipoDeAtoDoDiario::EticoDisciplinar, 'Texto sigiloso', 15);
        $pasta = $this->criarPasta($tenant, 'NUP-D2');
        $this->limparIdentityMap();
        $crawler = $client->request('GET', '/push-processual/diario');
        $token = $this->tokenDaTela($crawler);
        self::assertCount(1, $crawler->filter('.pp-selo--prazo'));
        self::assertCount(0, $crawler->filter('[data-pp-diario-meta]'), 'sem pasta não há botão de meta');

        $client->request('POST', sprintf('/push-processual/diario/%d/pasta', $id), ['_token' => $token, 'pasta_id' => $pasta->getId()]);
        self::assertCount(1, $client->request('GET', '/push-processual/diario')->filter('[data-pp-diario-meta]'));
        $client->request('POST', sprintf('/push-processual/diario/%d/meta', $id), ['_token' => $token]);

        $tarefas = $this->em()->getRepository(Tarefa::class)->findBy(['pasta' => $pasta->getId()]);
        self::assertCount(1, $tarefas);
        self::assertStringNotContainsString('sigiloso', (string) $tarefas[0]->getDescricao());
        self::assertCount(0, $client->request('GET', '/push-processual/diario')->filter('[data-pp-diario-meta]'), 'uma meta por publicação');
    }

    #[Test]
    public function metaSemPastaOuSemPrazoNaoCria(): void
    {
        [$client, $tenant, $usuario] = $this->entrar();
        $semPasta = $this->guardar($tenant, $usuario, 'Sem pasta', TipoDeAtoDoDiario::Comunicado, 'x', 10);
        $this->limparIdentityMap();
        $token = $this->tokenDaTela($client->request('GET', '/push-processual/diario'));

        $client->request('POST', sprintf('/push-processual/diario/%d/meta', $semPasta), ['_token' => $token]);

        self::assertStringContainsString('Vincule a uma pasta', $client->followRedirect()->text());
        self::assertCount(0, $this->em()->getRepository(Tarefa::class)->findAll());
    }
}
