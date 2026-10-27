<?php

declare(strict_types=1);

namespace App\Tests\Central\Functional;

use App\Central\Controller\CentralController;
use App\Central\Entity\Conversa;
use App\Central\Entity\Mensagem;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Central\Support\CentralHttpTrait;
use App\Tests\Inteligencia\Support\CriaFixturesInteligenciaTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Central A1 (`docs/specs/central-comunicacao-a1.md`): conversas 1:1 persistidas no servidor.
 *
 * O que se prova: só quem tem vínculo ativo usa; só participante lê e escreve — nem o super admin lê conversa
 * alheia; nada atravessa escritório; o par não duplica; não lidas contam só a mensagem do outro e o cursor não
 * volta; o polling entrega só o que é meu; escrita exige o CSRF `ajax`.
 */
#[CoversClass(CentralController::class)]
final class CentralControllerTest extends JusPrimeWebTestCase
{
    use CriaFixturesInteligenciaTrait;

    use CentralHttpTrait;

    #[TestDox('Ana abre conversa com Bruno, manda mensagem; Bruno vê 1 não lida e a mensagem')]
    public function testFluxoBasico(): void
    {
        $client = $this->cliente();
        [, $tenant] = $this->criarAdmin();
        $ana = $this->colega($tenant, 'ana');
        $bruno = $this->colega($tenant, 'bruno');

        $this->logarComTenant($client, $ana, $tenant);
        $conversa = $this->abrir($client, $bruno);
        $id = $this->enviar($client, $conversa, '  Bom dia, Bruno  ');

        $client->request('GET', '/central/conversas');
        $lista = $this->conversasSemIa($client);
        self::assertCount(1, $lista);
        self::assertSame(0, $lista[0]['naoLidas'], 'a própria mensagem não conta como não lida');
        self::assertSame($bruno->getId(), $lista[0]['outro']['id']);

        $this->logarComTenant($client, $bruno, $tenant);
        $client->request('GET', '/central/conversas');
        $lista = $this->conversasSemIa($client);
        self::assertSame(1, $lista[0]['naoLidas']);
        self::assertSame('Bom dia, Bruno', $lista[0]['ultima']['conteudo']);
        self::assertFalse($lista[0]['ultima']['minha']);

        $client->request('GET', "/central/conversas/$conversa/mensagens");
        $corpo = $this->json($client);
        self::assertSame([$id], array_column($corpo['mensagens'], 'id'));
        self::assertFalse($corpo['temMais']);
    }

    #[TestDox('Abrir de novo, de qualquer um dos lados, devolve a mesma conversa')]
    public function testParNaoDuplica(): void
    {
        $client = $this->cliente();
        [, $tenant] = $this->criarAdmin();
        $ana = $this->colega($tenant, 'ana');
        $bruno = $this->colega($tenant, 'bruno');

        $this->logarComTenant($client, $ana, $tenant);
        $primeira = $this->abrir($client, $bruno);
        $segunda = $this->abrir($client, $bruno);
        $this->logarComTenant($client, $bruno, $tenant);
        $doOutroLado = $this->abrir($client, $ana);

        self::assertSame($primeira, $segunda);
        self::assertSame($primeira, $doOutroLado);
    }

    #[TestDox('Marcar como lida zera as não lidas; o cursor não volta e não passa da última mensagem')]
    public function testLeitura(): void
    {
        $client = $this->cliente();
        [, $tenant] = $this->criarAdmin();
        $ana = $this->colega($tenant, 'ana');
        $bruno = $this->colega($tenant, 'bruno');
        $this->logarComTenant($client, $ana, $tenant);
        $conversa = $this->abrir($client, $bruno);
        $primeira = $this->enviar($client, $conversa, 'um');
        $segunda = $this->enviar($client, $conversa, 'dois');

        $this->logarComTenant($client, $bruno, $tenant);
        $this->post($client, "/central/conversas/$conversa/lida", ['ateId' => $segunda + 1000]);
        self::assertSame($segunda, $this->json($client)['ultimaLidaId'], 'não lê o que ainda não chegou');

        $this->post($client, "/central/conversas/$conversa/lida", ['ateId' => $primeira]);
        self::assertSame($segunda, $this->json($client)['ultimaLidaId'], 'o cursor não volta');

        $client->request('GET', '/central/conversas');
        self::assertSame(0, $this->conversasSemIa($client)[0]['naoLidas']);
    }

    #[TestDox('Quem não participa — colega do mesmo escritório ou super admin — recebe 404 em ler, enviar e marcar')]
    public function testSoParticipante(): void
    {
        $client = $this->cliente();
        [$admin, $tenant] = $this->criarAdmin();
        $ana = $this->colega($tenant, 'ana');
        $bruno = $this->colega($tenant, 'bruno');
        $carla = $this->colega($tenant, 'carla');
        $this->logarComTenant($client, $ana, $tenant);
        $conversa = $this->abrir($client, $bruno);
        $this->enviar($client, $conversa, 'SEGREDO-DA-CONVERSA');

        foreach ([$carla, $admin] as $intruso) {
            $this->logarComTenant($client, $intruso, $tenant);
            $client->request('GET', "/central/conversas/$conversa/mensagens");
            self::assertResponseStatusCodeSame(404);
            self::assertStringNotContainsString('SEGREDO', (string) $client->getResponse()->getContent());

            $this->post($client, "/central/conversas/$conversa/mensagens", ['conteudo' => 'intrusão']);
            self::assertResponseStatusCodeSame(404);
            $this->post($client, "/central/conversas/$conversa/lida", ['ateId' => 1]);
            self::assertResponseStatusCodeSame(404);

            $client->request('GET', '/central/novidades?desde=1');
            self::assertStringNotContainsString('SEGREDO', (string) $client->getResponse()->getContent());
            $client->request('GET', '/central/conversas');
            self::assertSame([], $this->conversasSemIa($client));
        }
    }

    #[TestDox('Nada atravessa escritório: destinatário de fora é 422; a conversa de fora é 404')]
    public function testIsolamentoEntreEscritorios(): void
    {
        $client = $this->cliente();
        [, $tenant] = $this->criarAdmin();
        [, $outro] = $this->criarAdmin();
        $ana = $this->colega($tenant, 'ana');
        $bruno = $this->colega($tenant, 'bruno');
        $estranho = $this->colega($outro, 'estranho');
        $vizinho = $this->colega($outro, 'vizinho');

        $this->logarComTenant($client, $estranho, $outro);
        $conversaDeFora = $this->abrir($client, $vizinho);
        $this->enviar($client, $conversaDeFora, 'SEGREDO-DE-FORA');

        $this->logarComTenant($client, $ana, $tenant);
        $this->post($client, '/central/conversas', ['destinatarioId' => $estranho->getId()]);
        self::assertResponseStatusCodeSame(422);

        $client->request('GET', "/central/conversas/$conversaDeFora/mensagens");
        self::assertResponseStatusCodeSame(404);

        $this->abrir($client, $bruno);
        $client->request('GET', '/central/contatos');
        $ids = array_column($this->json($client)['contatos'], 'id');
        self::assertContains($bruno->getId(), $ids);
        self::assertNotContains($estranho->getId(), $ids);
        self::assertNotContains($ana->getId(), $ids, 'eu não sou meu contato');

        $client->request('GET', '/central/novidades?desde=1');
        self::assertStringNotContainsString('SEGREDO', (string) $client->getResponse()->getContent());
    }

    #[TestDox('Conversa consigo mesmo, mensagem vazia ou longa demais: 422')]
    public function testValidacoes(): void
    {
        $client = $this->cliente();
        [, $tenant] = $this->criarAdmin();
        $ana = $this->colega($tenant, 'ana');
        $bruno = $this->colega($tenant, 'bruno');
        $this->logarComTenant($client, $ana, $tenant);

        $this->post($client, '/central/conversas', ['destinatarioId' => $ana->getId()]);
        self::assertResponseStatusCodeSame(422);

        $conversa = $this->abrir($client, $bruno);
        foreach (['   ', str_repeat('a', 4001)] as $texto) {
            $this->post($client, "/central/conversas/$conversa/mensagens", ['conteudo' => $texto]);
            self::assertResponseStatusCodeSame(422);
        }
        $this->post($client, "/central/conversas/$conversa/mensagens", ['conteudo' => ['lista']]);
        self::assertResponseStatusCodeSame(422);
    }

    #[TestDox('Colega que saiu: a conversa segue legível, sem envio; e quem saiu não entra mais')]
    public function testColegaQueSaiu(): void
    {
        $client = $this->cliente();
        [, $tenant] = $this->criarAdmin();
        $ana = $this->colega($tenant, 'ana');
        $bruno = $this->colega($tenant, 'bruno');
        $this->logarComTenant($client, $ana, $tenant);
        $conversa = $this->abrir($client, $bruno);
        $this->enviar($client, $conversa, 'antes de sair');
        $this->sairDoEscritorio($bruno, $tenant);

        $client->request('GET', "/central/conversas/$conversa/mensagens");
        self::assertResponseIsSuccessful();
        $client->request('GET', '/central/conversas');
        self::assertFalse($this->conversasSemIa($client)[0]['outroAtivo']);
        $this->post($client, "/central/conversas/$conversa/mensagens", ['conteudo' => 'depois']);
        self::assertResponseStatusCodeSame(422);

        // Quem saiu nem chega à Central: a plataforma manda escolher escritório antes (barreira de fora — a
        // guarda da própria Central é provada em AcessoACentralTest).
        $this->logarComTenant($client, $bruno, $tenant);
        foreach (['/central/conversas', "/central/conversas/$conversa/mensagens"] as $url) {
            $client->request('GET', $url);
            self::assertResponseRedirects('/escritorio/selecionar');
            self::assertStringNotContainsString('antes de sair', (string) $client->getResponse()->getContent());
        }
    }

    #[TestDox('Polling: a primeira chamada só dá o cursor; depois vêm só as mensagens novas das minhas conversas')]
    public function testNovidades(): void
    {
        $client = $this->cliente();
        [, $tenant] = $this->criarAdmin();
        $ana = $this->colega($tenant, 'ana');
        $bruno = $this->colega($tenant, 'bruno');
        $this->logarComTenant($client, $ana, $tenant);
        $conversa = $this->abrir($client, $bruno);
        $antiga = $this->enviar($client, $conversa, 'antiga');

        $this->logarComTenant($client, $bruno, $tenant);
        $client->request('GET', '/central/novidades');
        $inicio = $this->json($client);
        self::assertSame([], $inicio['mensagens']);
        self::assertSame($antiga, $inicio['cursor']);
        self::assertSame(1, $inicio['naoLidas']);

        $this->logarComTenant($client, $ana, $tenant);
        $nova = $this->enviar($client, $conversa, 'nova');

        $this->logarComTenant($client, $bruno, $tenant);
        $client->request('GET', '/central/novidades?desde=' . $inicio['cursor']);
        $corpo = $this->json($client);
        // B (spec §6): a janela de 30 s repete as recentes (aqui, a "antiga" também) — a tela descarta as já vistas.
        $ids = array_column($corpo['mensagens'], 'id');
        self::assertContains($nova, $ids);
        self::assertSame($nova, max($ids));
        self::assertSame($nova, $corpo['cursor']);
        self::assertSame(2, $corpo['naoLidas']);
        self::assertTrue($corpo['completo']);
    }

    #[TestDox('Primeiro uso, sem mensagem nenhuma: o cursor começa em 0 e a primeira mensagem chega pelo polling')]
    public function testPrimeiraMensagemDeQuemNaoTinhaNenhuma(): void
    {
        $client = $this->cliente();
        [, $tenant] = $this->criarAdmin();
        $ana = $this->colega($tenant, 'ana');
        $bruno = $this->colega($tenant, 'bruno');
        $this->logarComTenant($client, $ana, $tenant);
        $conversa = $this->abrir($client, $bruno);

        $client->request('GET', '/central/novidades');
        self::assertSame(0, $this->json($client)['cursor']);

        $this->logarComTenant($client, $bruno, $tenant);
        $primeira = $this->enviar($client, $conversa, 'a primeira de todas');

        $this->logarComTenant($client, $ana, $tenant);
        $client->request('GET', '/central/novidades?desde=0');
        self::assertSame([$primeira], array_column($this->json($client)['mensagens'], 'id'));
    }

    #[TestDox('Histórico em páginas de 50: a mais nova está na primeira página; "antesDe" traz as anteriores')]
    public function testPaginacao(): void
    {
        $client = $this->cliente();
        [, $tenant] = $this->criarAdmin();
        $ana = $this->colega($tenant, 'ana');
        $bruno = $this->colega($tenant, 'bruno');
        $this->logarComTenant($client, $ana, $tenant);
        $id = $this->abrir($client, $bruno);
        $conversa = $this->em()->find(Conversa::class, $id);
        $ids = [];
        for ($i = 1; $i <= 52; ++$i) {
            $m = new Mensagem($tenant, $conversa, $ana, 'msg ' . $i, new \DateTimeImmutable());
            $this->em()->persist($m);
            $this->em()->flush();
            $ids[] = (int) $m->getId();
        }

        $client->request('GET', "/central/conversas/$id/mensagens");
        $pagina = $this->json($client);
        self::assertCount(50, $pagina['mensagens']);
        self::assertTrue($pagina['temMais']);
        self::assertSame(array_slice($ids, 2), array_column($pagina['mensagens'], 'id'), 'as 50 mais novas, em ordem');

        $client->request('GET', "/central/conversas/$id/mensagens?antesDe=" . $ids[2]);
        $anteriores = $this->json($client);
        self::assertSame(array_slice($ids, 0, 2), array_column($anteriores['mensagens'], 'id'));
        self::assertFalse($anteriores['temMais']);
    }

    #[TestDox('A lista vem pela mensagem mais recente; conversa sem mensagem conta pela criação')]
    public function testOrdemDaLista(): void
    {
        $client = $this->cliente();
        [, $tenant] = $this->criarAdmin();
        $ana = $this->colega($tenant, 'ana');
        $bruno = $this->colega($tenant, 'bruno');
        $carla = $this->colega($tenant, 'carla');
        $davi = $this->colega($tenant, 'davi');
        $this->logarComTenant($client, $ana, $tenant);
        // Ids em ordem bruno < carla < davi; a ordem certa não coincide com nenhuma ordem de id.
        $comBruno = $this->abrir($client, $bruno);
        $comCarla = $this->abrir($client, $carla);
        $comDavi = $this->abrir($client, $davi);

        $tenantGerenciado = $this->em()->find(Tenant::class, $tenant->getId());
        $anaGerenciada = $this->em()->find(User::class, $ana->getId());
        foreach ([[$comCarla, '+2 hours'], [$comBruno, '+1 hour']] as [$id, $quando]) {
            $conversa = $this->em()->find(Conversa::class, $id);
            $em = new \DateTimeImmutable($quando);
            $this->em()->persist(new Mensagem($tenantGerenciado, $conversa, $anaGerenciada, 'oi', $em));
            $conversa->registrarMensagem($em);
        }
        $this->em()->flush();

        $client->request('GET', '/central/conversas');
        self::assertSame([$comCarla, $comBruno, $comDavi], array_column($this->conversasSemIa($client), 'id'));
    }

    #[TestDox('Quem está em dois escritórios não vê, de um, a conversa do outro')]
    public function testPessoaEmDoisEscritorios(): void
    {
        $client = $this->cliente();
        [, $escritorioA] = $this->criarAdmin();
        [, $escritorioB] = $this->criarAdmin();
        $ana = $this->colega($escritorioA, 'ana');
        $this->em()->persist(new UserTenant($ana, $escritorioB));
        $this->em()->flush();
        $dora = $this->colega($escritorioB, 'dora');

        $this->logarComTenant($client, $ana, $escritorioB);
        $daB = $this->abrir($client, $dora);
        $this->enviar($client, $daB, 'SEGREDO-DO-B');

        $this->logarComTenant($client, $ana, $escritorioA);
        $client->request('GET', "/central/conversas/$daB/mensagens");
        self::assertResponseStatusCodeSame(404);
        $client->request('GET', '/central/conversas');
        self::assertSame([], $this->conversasSemIa($client));
        $client->request('GET', '/central/novidades?desde=0');
        self::assertStringNotContainsString('SEGREDO', (string) $client->getResponse()->getContent());
        $this->post($client, "/central/conversas/$daB/mensagens", ['conteudo' => 'do escritório errado']);
        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('Id gigante não chega ao banco: nada de 500')]
    public function testIdGigante(): void
    {
        $client = $this->cliente();
        [, $tenant] = $this->criarAdmin();
        $ana = $this->colega($tenant, 'ana');
        $this->logarComTenant($client, $ana, $tenant);

        $client->request('GET', '/central/conversas/9999999999/mensagens');
        self::assertResponseStatusCodeSame(404);
        $this->post($client, '/central/conversas', ['destinatarioId' => 99999999999]);
        self::assertResponseStatusCodeSame(422);
        foreach (['99999999999', 'abc'] as $desdeInvalido) {
            $client->request('GET', '/central/novidades?desde=' . $desdeInvalido);
            self::assertResponseIsSuccessful();
            self::assertSame([], $this->json($client)['mensagens'], 'desde inválido é a primeira chamada, não "tudo"');
        }
    }

    #[TestDox('Escrita sem o token CSRF: 403 e nada gravado')]
    public function testCsrf(): void
    {
        $client = $this->cliente();
        [, $tenant] = $this->criarAdmin();
        $ana = $this->colega($tenant, 'ana');
        $bruno = $this->colega($tenant, 'bruno');
        $this->logarComTenant($client, $ana, $tenant);

        $client->request('POST', '/central/conversas', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['destinatarioId' => $bruno->getId()]));
        self::assertResponseStatusCodeSame(403);

        $client->request('GET', '/central/conversas');
        self::assertSame([], $this->conversasSemIa($client));
    }

    #[TestDox('A página abre para quem tem vínculo; sem vínculo com o escritório, a plataforma manda escolher outro')]
    public function testPagina(): void
    {
        $client = $this->cliente();
        [, $tenant] = $this->criarAdmin();
        [, $outro] = $this->criarAdmin();
        $ana = $this->colega($tenant, 'ana');

        $this->logarComTenant($client, $ana, $tenant);
        $client->request('GET', '/central');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-central]');

        $this->logarComTenant($client, $ana, $outro);
        $client->request('GET', '/central');
        self::assertResponseRedirects('/escritorio/selecionar');
    }
}
