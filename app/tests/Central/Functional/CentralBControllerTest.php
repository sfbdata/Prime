<?php

declare(strict_types=1);

namespace App\Tests\Central\Functional;

use App\Central\Controller\CentralController;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Notificacao;
use App\Entity\Tenant\Tenant;
use App\Tests\Central\Support\CentralHttpTrait;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Inteligencia\Support\CriaFixturesInteligenciaTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Central B (`docs/specs/central-comunicacao-b.md`): grupos e preferências individuais.
 *
 * O que se prova: só colega ativo do MESMO escritório entra; só participante ATIVO lê, escreve ou vê o grupo na lista
 * — quem sai ou é removido perde tudo; só o administrador do grupo adiciona e remove (403 ao participante, 404 a quem
 * está fora); a administração passa adiante quando o administrador sai; menção em grupo só avisa participante ativo;
 * os avisos não levam conteúdo; preferências são só de quem as define; silenciada sai do total; arquivada volta ao
 * enviar; o polling repete as recentes (janela) e nunca as antigas.
 */
#[CoversClass(CentralController::class)]
final class CentralBControllerTest extends JusPrimeWebTestCase
{
    use CriaFixturesInteligenciaTrait;
    use CentralHttpTrait;

    /**
     * @param list<User> $com
     *
     * @return array<string, mixed>
     */
    private function criarGrupo(KernelBrowser $client, string $nome, array $com): array
    {
        $this->post($client, '/central/grupos', ['nome' => $nome, 'participantes' => array_map(static fn (User $u): ?int => $u->getId(), $com)]);

        return $this->json($client);
    }

    /** @return array<string, mixed>|null a linha da conversa na MINHA lista */
    private function naLista(KernelBrowser $client, int $conversa): ?array
    {
        $client->request('GET', '/central/conversas');
        foreach ($this->conversasSemIa($client) as $linha) {
            if ($linha['id'] === $conversa) {
                return $linha;
            }
        }

        return null;
    }

    /** @return list<array<string, mixed>> */
    private function avisos(User $pessoa, string $tipo): array
    {
        return $this->em()->getConnection()->fetchAllAssociative(
            'SELECT titulo, mensagem, url, lida, tenant_id FROM notificacao WHERE usuario_id = ? AND tipo = ? ORDER BY id',
            [$pessoa->getId(), $tipo],
        );
    }

    /** @return array{0: Tenant, 1: User, 2: User, 3: User, 4: User} escritório, Ana, Bruno, Carla e Dora (fora do grupo) */
    private function equipe(): array
    {
        [, $tenant] = $this->criarAdmin();

        return [$tenant, $this->colega($tenant, 'ana'), $this->colega($tenant, 'bruno'), $this->colega($tenant, 'carla'), $this->colega($tenant, 'dora')];
    }

    #[TestDox('Criar grupo: quem cria administra; os escolhidos o veem na lista e ganham aviso sem conteúdo; quem não foi escolhido não vê nada')]
    public function testCriarGrupo(): void
    {
        $client = $this->cliente();
        [$tenant, $ana, $bruno, $carla, $dora] = $this->equipe();

        $this->logarComTenant($client, $ana, $tenant);
        $grupo = $this->criarGrupo($client, '  Equipe   Cível ', [$bruno, $carla, $ana]);
        self::assertResponseStatusCodeSame(201);
        $id = $grupo['grupo']['id'];
        self::assertSame('Equipe Cível', $grupo['grupo']['nome']);

        $linha = $this->naLista($client, $id);
        self::assertSame('grupo', $linha['tipo']);
        self::assertNull($linha['outro']);
        self::assertSame(3, $linha['participantes'], 'eu, Bruno e Carla — "eu" repetido não conta duas vezes');
        self::assertTrue($linha['souAdministrador']);
        self::assertTrue($linha['ultima']['sistema']);
        self::assertStringContainsString('criou o grupo', $linha['ultima']['conteudo']);

        $this->logarComTenant($client, $bruno, $tenant);
        $linha = $this->naLista($client, $id);
        self::assertNotNull($linha);
        self::assertFalse($linha['souAdministrador']);
        self::assertSame(0, $linha['naoLidas'], 'mensagem de sistema não conta como não lida');

        $avisos = $this->avisos($bruno, Notificacao::TIPO_CENTRAL_GRUPO_ADICIONADO);
        self::assertCount(1, $avisos);
        self::assertStringContainsString('adicionou você ao grupo Equipe Cível', $avisos[0]['titulo']);
        self::assertNull($avisos[0]['mensagem']);
        self::assertSame('/central?conversa=' . $id, $avisos[0]['url']);
        self::assertSame([], $this->avisos($ana, Notificacao::TIPO_CENTRAL_GRUPO_ADICIONADO), 'quem cria não se avisa');

        $this->logarComTenant($client, $dora, $tenant);
        self::assertNull($this->naLista($client, $id));
        $client->request('GET', "/central/conversas/$id/mensagens");
        self::assertResponseStatusCodeSame(404);
        $client->request('GET', "/central/conversas/$id/participantes");
        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('Criar grupo: gente de outro escritório, nome vazio ou longo, ninguém escolhido ou sem CSRF — recusado e nada gravado')]
    public function testCriarGrupoRecusas(): void
    {
        $client = $this->cliente();
        [$tenant, $ana, $bruno] = $this->equipe();
        [, $outro] = $this->criarAdmin();
        $estranho = $this->colega($outro, 'estranho');

        $this->logarComTenant($client, $ana, $tenant);
        foreach ([
            ['Grupo', [$bruno, $estranho]],
            ['   ', [$bruno]],
            [str_repeat('x', 61), [$bruno]],
            ['Grupo', [$ana]],
            ['Grupo', []],
        ] as [$nome, $com]) {
            $this->criarGrupo($client, $nome, $com);
            self::assertResponseStatusCodeSame(422);
        }
        foreach ([['abc', 99999999999], [$bruno->getId(), 'abc'], [$bruno->getId(), -3]] as $ids) {
            $this->post($client, '/central/grupos', ['nome' => 'Grupo', 'participantes' => $ids]);
            self::assertResponseStatusCodeSame(422, 'id torto recusa o pedido inteiro, não some calado');
        }

        $client->request('POST', '/central/grupos', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['nome' => 'G', 'participantes' => [$bruno->getId()]]));
        self::assertResponseStatusCodeSame(403);

        $client->request('GET', '/central/conversas');
        self::assertSame([], $this->conversasSemIa($client));
    }

    #[TestDox('Em grupo: a mensagem chega a todos como não lida; a menção só avisa participante ativo, com o nome do grupo e sem o texto')]
    public function testMensagemEMencaoEmGrupo(): void
    {
        $client = $this->cliente();
        [$tenant, $ana, $bruno, $carla, $dora] = $this->equipe();
        $this->logarComTenant($client, $ana, $tenant);
        $id = $this->criarGrupo($client, 'Equipe', [$bruno, $carla])['grupo']['id'];

        $this->post($client, "/central/conversas/$id/mensagens", ['conteudo' => sprintf('SEGREDO-B @[C](user:%d) @[D](user:%d)', $carla->getId(), $dora->getId())]);
        self::assertResponseStatusCodeSame(201);
        self::assertStringContainsString('@D', $this->json($client)['mensagem']['conteudo'], 'Dora não participa: vira texto');

        foreach ([$bruno, $carla] as $pessoa) {
            $this->logarComTenant($client, $pessoa, $tenant);
            self::assertSame(1, $this->naLista($client, $id)['naoLidas']);
        }
        $mencao = $this->avisos($carla, Notificacao::TIPO_CENTRAL_MENCAO);
        self::assertCount(1, $mencao);
        self::assertStringContainsString('mencionou você no grupo Equipe', $mencao[0]['titulo']);
        self::assertNull($mencao[0]['mensagem']);
        self::assertSame([], $this->avisos($dora, Notificacao::TIPO_CENTRAL_MENCAO));
        self::assertSame([], $this->avisos($bruno, Notificacao::TIPO_CENTRAL_MENCAO), 'mensagem comum de grupo não vai ao sino');
    }

    #[TestDox('Adicionar: só o administrador (403 ao participante, 404 a quem está fora); quem entra vê o histórico sem não lidas e ganha aviso')]
    public function testAdicionar(): void
    {
        $client = $this->cliente();
        [$tenant, $ana, $bruno, $carla, $dora] = $this->equipe();
        [, $outro] = $this->criarAdmin();
        $estranho = $this->colega($outro, 'estranho');
        $this->logarComTenant($client, $ana, $tenant);
        $id = $this->criarGrupo($client, 'Equipe', [$bruno])['grupo']['id'];
        $antiga = $this->enviar($client, $id, 'mensagem de antes');

        $this->logarComTenant($client, $bruno, $tenant);
        $this->post($client, "/central/conversas/$id/participantes", ['usuarioId' => $dora->getId()]);
        self::assertResponseStatusCodeSame(403);
        self::assertSame('sem_permissao_no_grupo', $this->json($client)['codigo']);

        $this->logarComTenant($client, $carla, $tenant);
        $this->post($client, "/central/conversas/$id/participantes", ['usuarioId' => $dora->getId()]);
        self::assertResponseStatusCodeSame(404);

        $this->logarComTenant($client, $ana, $tenant);
        foreach ([$estranho, $bruno, $ana] as $invalido) {
            $this->post($client, "/central/conversas/$id/participantes", ['usuarioId' => $invalido->getId()]);
            self::assertResponseStatusCodeSame(422);
        }
        $this->post($client, "/central/conversas/$id/participantes", ['usuarioId' => $dora->getId()]);
        self::assertResponseStatusCodeSame(201);

        $this->logarComTenant($client, $dora, $tenant);
        $linha = $this->naLista($client, $id);
        self::assertSame(0, $linha['naoLidas'], 'o histórico de antes não chega como não lido');
        $client->request('GET', "/central/conversas/$id/mensagens");
        self::assertContains($antiga, array_column($this->json($client)['mensagens'], 'id'), 'quem entra vê o histórico (desenho)');
        self::assertCount(1, $this->avisos($dora, Notificacao::TIPO_CENTRAL_GRUPO_ADICIONADO));
    }

    #[TestDox('Remover: só o administrador, nunca a si; quem é removido perde lista, histórico, polling e menção — e volta se readmitido')]
    public function testRemover(): void
    {
        $client = $this->cliente();
        [$tenant, $ana, $bruno, $carla] = $this->equipe();
        $this->logarComTenant($client, $ana, $tenant);
        $id = $this->criarGrupo($client, 'Equipe', [$bruno, $carla])['grupo']['id'];
        $this->post($client, "/central/conversas/$id/mensagens", ['conteudo' => sprintf('oi @[C](user:%d)', $carla->getId())]);
        $this->logarComTenant($client, $carla, $tenant);
        $this->post($client, "/central/conversas/$id/preferencias", ['arquivada' => true]);

        $this->logarComTenant($client, $bruno, $tenant);
        $client->request('DELETE', "/central/conversas/$id/participantes/{$carla->getId()}", [], [], self::CSRF);
        self::assertResponseStatusCodeSame(403);

        $this->logarComTenant($client, $ana, $tenant);
        $client->request('DELETE', "/central/conversas/$id/participantes/{$ana->getId()}", [], [], self::CSRF);
        self::assertResponseStatusCodeSame(422);
        $client->request('DELETE', "/central/conversas/$id/participantes/{$carla->getId()}", [], [], ['CONTENT_TYPE' => 'application/json']);
        self::assertResponseStatusCodeSame(403, 'sem CSRF');
        $client->request('DELETE', "/central/conversas/$id/participantes/{$carla->getId()}", [], [], self::CSRF);
        self::assertResponseIsSuccessful();
        $this->post($client, "/central/conversas/$id/mensagens", ['conteudo' => sprintf('SEGREDO-DEPOIS @[C](user:%d)', $carla->getId())]);

        $this->logarComTenant($client, $carla, $tenant);
        self::assertNull($this->naLista($client, $id));
        $client->request('GET', "/central/conversas/$id/mensagens");
        self::assertResponseStatusCodeSame(404);
        $client->request('GET', '/central/novidades?desde=0');
        self::assertStringNotContainsString('SEGREDO-DEPOIS', (string) $client->getResponse()->getContent());
        $this->post($client, "/central/conversas/$id/mensagens", ['conteudo' => 'ainda estou aqui?']);
        self::assertResponseStatusCodeSame(404);
        $mencoes = $this->avisos($carla, Notificacao::TIPO_CENTRAL_MENCAO);
        self::assertCount(1, $mencoes, 'só a de antes da remoção — removida não é mencionável');
        self::assertTrue((bool) $mencoes[0]['lida'], 'o aviso que apontava para o grupo vira lido: ela não o abre mais');
        self::assertTrue((bool) $this->avisos($carla, Notificacao::TIPO_CENTRAL_GRUPO_ADICIONADO)[0]['lida']);

        $this->logarComTenant($client, $ana, $tenant);
        $this->post($client, "/central/conversas/$id/participantes", ['usuarioId' => $carla->getId()]);
        self::assertResponseStatusCodeSame(201, 'readmitida na mesma participação');
        $this->logarComTenant($client, $carla, $tenant);
        $linha = $this->naLista($client, $id);
        self::assertNotNull($linha);
        self::assertSame(0, $linha['naoLidas'], 'o que foi dito enquanto estava fora não chega como não lido');
        self::assertFalse($linha['arquivada'], 'readmitida recomeça sem as preferências de antes');
    }

    #[TestDox('Sair: quem sai perde o acesso; se é o administrador, a administração passa ao participante mais antigo; individual não tem "sair"')]
    public function testSair(): void
    {
        $client = $this->cliente();
        [$tenant, $ana, $bruno, $carla] = $this->equipe();
        $this->logarComTenant($client, $ana, $tenant);
        $id = $this->criarGrupo($client, 'Equipe', [$bruno, $carla])['grupo']['id'];
        $individual = $this->abrir($client, $bruno);

        $this->post($client, "/central/conversas/$individual/sair", []);
        self::assertResponseStatusCodeSame(422);

        $this->post($client, "/central/conversas/$id/sair", []);
        self::assertResponseIsSuccessful();
        self::assertNull($this->naLista($client, $id));
        $client->request('GET', "/central/conversas/$id/participantes");
        self::assertResponseStatusCodeSame(404);

        $this->logarComTenant($client, $bruno, $tenant);
        $client->request('GET', "/central/conversas/$id/participantes");
        $corpo = $this->json($client);
        self::assertTrue($corpo['souAdministrador'], 'a administração passou adiante');
        self::assertSame([$bruno->getId(), $carla->getId()], array_column($corpo['participantes'], 'id'));
        self::assertStringContainsString('saiu do grupo', $this->naLista($client, $id)['ultima']['conteudo']);
    }

    #[TestDox('Renomear: qualquer participante (desenho), com linha de sistema; nome inválido ou conversa individual é 422; de fora é 404')]
    public function testRenomear(): void
    {
        $client = $this->cliente();
        [$tenant, $ana, $bruno, , $dora] = $this->equipe();
        $this->logarComTenant($client, $ana, $tenant);
        $id = $this->criarGrupo($client, 'Equipe', [$bruno])['grupo']['id'];
        $individual = $this->abrir($client, $bruno);
        $this->post($client, "/central/conversas/$individual/renomear", ['nome' => 'X']);
        self::assertResponseStatusCodeSame(422);

        $this->logarComTenant($client, $bruno, $tenant);
        foreach (['', str_repeat('y', 61)] as $invalido) {
            $this->post($client, "/central/conversas/$id/renomear", ['nome' => $invalido]);
            self::assertResponseStatusCodeSame(422);
        }
        $this->post($client, "/central/conversas/$id/renomear", ['nome' => 'Equipe Trabalhista']);
        self::assertResponseIsSuccessful();
        $linha = $this->naLista($client, $id);
        self::assertSame('Equipe Trabalhista', $linha['nome']);
        self::assertStringContainsString('renomeou o grupo para "Equipe Trabalhista"', $linha['ultima']['conteudo']);

        $this->logarComTenant($client, $dora, $tenant);
        $this->post($client, "/central/conversas/$id/renomear", ['nome' => 'Invasão']);
        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('Preferências: só de quem define; fixada vai ao topo; silenciada sai do total sem perder as não lidas; arquivada volta ao enviar, não ao receber')]
    public function testPreferencias(): void
    {
        $client = $this->cliente();
        [$tenant, $ana, $bruno, $carla, $dora] = $this->equipe();
        $this->logarComTenant($client, $ana, $tenant);
        $grupo = $this->criarGrupo($client, 'Equipe', [$bruno])['grupo']['id'];
        $comCarla = $this->abrir($client, $carla);
        $this->enviar($client, $comCarla, 'mais recente');

        $this->post($client, "/central/conversas/$grupo/preferencias", ['fixada' => true]);
        self::assertResponseIsSuccessful();
        $client->request('GET', '/central/conversas');
        self::assertSame($grupo, $this->conversasSemIa($client)[0]['id'], 'a fixada vem antes da mais recente');

        $this->logarComTenant($client, $bruno, $tenant);
        self::assertFalse($this->naLista($client, $grupo)['fixada'], 'a preferência da Ana não é a do Bruno');
        $this->enviar($client, $grupo, 'oi Ana');

        $this->logarComTenant($client, $ana, $tenant);
        $this->post($client, "/central/conversas/$grupo/preferencias", ['silenciada' => true, 'arquivada' => true]);
        $client->request('GET', '/central/novidades');
        self::assertSame(0, $this->json($client)['naoLidas'], 'silenciada fica fora do total');
        $linha = $this->naLista($client, $grupo);
        self::assertSame(1, $linha['naoLidas'], 'mas a conversa continua com a não lida dela');
        self::assertTrue($linha['silenciada']);
        self::assertTrue($linha['arquivada']);

        $this->logarComTenant($client, $bruno, $tenant);
        $this->enviar($client, $grupo, 'chegou outra');
        $this->logarComTenant($client, $ana, $tenant);
        self::assertTrue($this->naLista($client, $grupo)['arquivada'], 'receber não desarquiva');
        $this->enviar($client, $grupo, 'respondi');
        self::assertFalse($this->naLista($client, $grupo)['arquivada'], 'enviar desarquiva');

        $this->post($client, "/central/conversas/$grupo/preferencias", []);
        self::assertResponseStatusCodeSame(422);
        $this->post($client, "/central/conversas/$grupo/preferencias", ['fixada' => 'sim']);
        self::assertResponseStatusCodeSame(422, 'só booleano');
        $client->request('POST', "/central/conversas/$grupo/preferencias", [], [], ['CONTENT_TYPE' => 'application/json'], '{"fixada":false}');
        self::assertResponseStatusCodeSame(403, 'sem CSRF');

        $this->logarComTenant($client, $dora, $tenant);
        $this->post($client, "/central/conversas/$grupo/preferencias", ['fixada' => true]);
        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('Nada atravessa escritório: quem está nos dois não vê, de um, o grupo do outro; super admin de fora é 404')]
    public function testIsolamento(): void
    {
        $client = $this->cliente();
        [$admin, $escritorioA] = $this->criarAdmin();
        [, $escritorioB] = $this->criarAdmin();
        $ana = $this->colega($escritorioA, 'ana');
        $this->em()->persist(new UserTenant($ana, $escritorioB));
        $this->em()->flush();
        $dora = $this->colega($escritorioB, 'dora');

        $this->logarComTenant($client, $dora, $escritorioB);
        $id = $this->criarGrupo($client, 'Grupo do B', [$ana])['grupo']['id'];
        $this->enviar($client, $id, 'SEGREDO-DO-B');

        $this->logarComTenant($client, $ana, $escritorioA);
        self::assertNull($this->naLista($client, $id));
        foreach (['participantes', 'mensagens'] as $rota) {
            $client->request('GET', "/central/conversas/$id/$rota");
            self::assertResponseStatusCodeSame(404);
        }
        $this->post($client, "/central/conversas/$id/renomear", ['nome' => 'do A']);
        self::assertResponseStatusCodeSame(404);
        $client->request('GET', '/central/novidades?desde=0');
        self::assertStringNotContainsString('SEGREDO', (string) $client->getResponse()->getContent());

        $this->logarComTenant($client, $admin, $escritorioA);
        $this->post($client, "/central/conversas/$id/participantes", ['usuarioId' => $admin->getId()]);
        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('Mensagem de sistema não se responde (422)')]
    public function testNaoRespondeSistema(): void
    {
        $client = $this->cliente();
        [$tenant, $ana, $bruno] = $this->equipe();
        $this->logarComTenant($client, $ana, $tenant);
        $id = $this->criarGrupo($client, 'Equipe', [$bruno])['grupo']['id'];
        $client->request('GET', "/central/conversas/$id/mensagens");
        $sistema = $this->json($client)['mensagens'][0];
        self::assertTrue($sistema['sistema']);

        $this->post($client, "/central/conversas/$id/mensagens", ['conteudo' => 'resposta', 'respostaAId' => $sistema['id']]);
        self::assertResponseStatusCodeSame(422);
    }

    #[TestDox('Polling: a janela de 30 s repete as recentes abaixo do cursor (a confirmada fora de ordem chega); as antigas, nunca')]
    public function testJanelaDoPolling(): void
    {
        $client = $this->cliente();
        [$tenant, $ana, $bruno] = $this->equipe();
        $this->logarComTenant($client, $ana, $tenant);
        $id = $this->criarGrupo($client, 'Equipe', [$bruno])['grupo']['id'];
        $antiga = $this->enviar($client, $id, 'antiga');
        $recente = $this->enviar($client, $id, 'recente');
        $depois = $this->enviar($client, $id, 'depois');
        // A coluna é sem fuso, gravada na hora do APP: "10 min atrás" tem de vir do mesmo relógio (o NOW() do banco é UTC).
        $this->em()->getConnection()->executeStatement(
            'UPDATE central_mensagem SET criada_em = ? WHERE id = ?',
            [(new \DateTimeImmutable('-10 minutes'))->format('Y-m-d H:i:s'), $antiga],
        );

        $this->logarComTenant($client, $bruno, $tenant);
        // Cursor já em `depois`: a `recente` (abaixo dele, mas dos últimos 30 s) volta; a `antiga`, não.
        $client->request('GET', '/central/novidades?desde=' . $depois);
        $ids = array_column($this->json($client)['mensagens'], 'id');
        self::assertContains($recente, $ids);
        self::assertNotContains($antiga, $ids);
        self::assertSame($depois, $this->json($client)['cursor']);
    }

    #[TestDox('A janela traz o ⋮ (novo grupo, arquivadas), o botão de informações e as telas de grupo, escondidas')]
    public function testArranjoDaJanela(): void
    {
        $client = $this->cliente();
        [$tenant, $ana] = $this->equipe();
        $this->logarComTenant($client, $ana, $tenant);
        $client->request('GET', '/central');

        self::assertSelectorExists('[data-central][data-url-grupos="/central/grupos"]');
        self::assertSelectorExists('.bj-central-cab > [data-central-mais]');
        self::assertSelectorExists('.bj-central-cab > [data-central-info][hidden]');
        self::assertSelectorExists('[data-central-tela-lista] > [data-central-faixa-arquivadas][hidden] + [data-central-lista]');
        self::assertSelectorExists('[data-central] > [data-central-tela-novo-grupo][hidden]');
        self::assertSelectorExists('[data-central] > [data-central-tela-info][hidden]');
    }

    #[TestDox('Administração vaga: se o administrador perde o vínculo, o participante mais antigo COM vínculo administra; quem sai passa a administração a quem tem vínculo')]
    public function testAdministracaoNaoTrava(): void
    {
        $client = $this->cliente();
        [$tenant, $ana, $bruno, $carla, $dora] = $this->equipe();
        $this->logarComTenant($client, $ana, $tenant);
        $id = $this->criarGrupo($client, 'Equipe', [$bruno, $carla])['grupo']['id'];
        $this->sairDoEscritorio($ana, $tenant);

        $this->logarComTenant($client, $carla, $tenant);
        $this->post($client, "/central/conversas/$id/participantes", ['usuarioId' => $dora->getId()]);
        self::assertResponseStatusCodeSame(403, 'Carla não é a mais antiga');

        $this->logarComTenant($client, $bruno, $tenant);
        $client->request('GET', "/central/conversas/$id/participantes");
        self::assertTrue($this->json($client)['souAdministrador']);
        $this->post($client, "/central/conversas/$id/participantes", ['usuarioId' => $dora->getId()]);
        self::assertResponseStatusCodeSame(201, 'Bruno assume a administração vaga');

        // Agora o administrador é o Bruno; o mais antigo depois dele é a Carla — mas ela sai do escritório.
        $this->sairDoEscritorio($carla, $tenant);
        $this->post($client, "/central/conversas/$id/sair", []);
        $this->logarComTenant($client, $dora, $tenant);
        $client->request('GET', "/central/conversas/$id/participantes");
        self::assertTrue($this->json($client)['souAdministrador'], 'o sucessor precisa ter vínculo: passou da Carla para a Dora');
    }

    #[TestDox('O administrador do ESCRITÓRIO que não participa do grupo não lê nem administra (404)')]
    public function testAdminDoEscritorioNaoAdministraGrupo(): void
    {
        $client = $this->cliente();
        [$admin, $tenant] = $this->criarAdmin();
        $ana = $this->colega($tenant, 'ana');
        $bruno = $this->colega($tenant, 'bruno');
        $this->logarComTenant($client, $ana, $tenant);
        $id = $this->criarGrupo($client, 'Equipe', [$bruno])['grupo']['id'];

        $this->logarComTenant($client, $admin, $tenant);
        $client->request('GET', "/central/conversas/$id/participantes");
        self::assertResponseStatusCodeSame(404);
        $this->post($client, "/central/conversas/$id/participantes", ['usuarioId' => $admin->getId()]);
        self::assertResponseStatusCodeSame(404);
        $client->request('DELETE', "/central/conversas/$id/participantes/{$bruno->getId()}", [], [], self::CSRF);
        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('Lançador: as conversas com menção por ver vêm no polling — só do mencionado, somem ao ler, não atravessam escritório')]
    public function testConversasComMencaoNoPolling(): void
    {
        $client = $this->cliente();
        [$tenant, $ana, $bruno, $carla] = $this->equipe();
        [, $outro] = $this->criarAdmin();
        $this->em()->persist(new UserTenant($bruno, $outro));
        $this->em()->flush();
        $dora = $this->colega($outro, 'dora');

        $this->logarComTenant($client, $ana, $tenant);
        $id = $this->criarGrupo($client, 'Equipe', [$bruno, $carla])['grupo']['id'];
        $this->post($client, "/central/conversas/$id/mensagens", ['conteudo' => sprintf('oi @[B](user:%d)', $bruno->getId())]);
        $ultima = $this->json($client)['mensagem']['id'];

        // No OUTRO escritório também há menção ao Bruno: cada escritório só vê a sua (prova o filtro de tenant).
        $this->logarComTenant($client, $dora, $outro);
        $idOutro = $this->criarGrupo($client, 'Do outro', [$bruno])['grupo']['id'];
        $this->post($client, "/central/conversas/$idOutro/mensagens", ['conteudo' => sprintf('ei @[B](user:%d)', $bruno->getId())]);

        $this->logarComTenant($client, $bruno, $tenant);
        $client->request('GET', '/central/novidades');
        self::assertSame([$id], $this->json($client)['conversasComMencao']);

        $this->logarComTenant($client, $carla, $tenant);
        $client->request('GET', '/central/novidades');
        self::assertSame([], $this->json($client)['conversasComMencao'], 'quem não foi mencionado não vê marcador');

        $this->logarComTenant($client, $bruno, $outro);
        $client->request('GET', '/central/novidades');
        self::assertSame([$idOutro], $this->json($client)['conversasComMencao'], 'no outro escritório, só a menção de lá');

        $this->logarComTenant($client, $bruno, $tenant);
        $this->post($client, "/central/conversas/$id/lida", ['ateId' => $ultima]);
        $client->request('GET', '/central/novidades?desde=0');
        self::assertSame([], $this->json($client)['conversasComMencao'], 'ler a conversa lê a menção');
    }
}
