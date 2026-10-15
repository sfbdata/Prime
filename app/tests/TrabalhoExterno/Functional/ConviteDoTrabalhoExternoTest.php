<?php

declare(strict_types=1);

namespace App\Tests\TrabalhoExterno\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\TrabalhoExterno\Controller\ConviteController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Mime\Email;

/**
 * F3 (risco ALTO): o convite do profissional externo, do e-mail até o acesso. O que se prova:
 * o convite não cria vínculo com escritório; o convidado só enxerga a demanda do convite, nas
 * situações e com as permissões do convite; revogar corta na hora; o token não abre nada sozinho.
 */
#[CoversClass(ConviteController::class)]
final class ConviteDoTrabalhoExternoTest extends JusPrimeWebTestCase
{
    use CriaFixturesTrabalhoExternoTrait;

    #[TestDox('sem conta: cria a conta pelo link, entra direto na demanda e não ganha escritório nenhum')]
    public function testCriarContaPeloConvite(): void
    {
        [$client, $codigo] = $this->demandaEmExecucao();
        $token = $this->convidar($client, $codigo, 'novo.correspondente@test.com');

        $client->getCookieJar()->clear();
        $crawler = $client->request('GET', "/trabalho-externo/convite/$token");
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('button', 'Criar conta e aceitar');
        self::assertStringNotContainsString('Escritório Solicitante', (string) $client->getResponse()->getContent(), 'a página não revela o escritório');

        $csrf = $crawler->filter('form[action$="/criar-conta"] input[name="_token"]')->attr('value');
        $client->request('POST', "/trabalho-externo/convite/$token/criar-conta", ['full_name' => 'Novo Correspondente', 'senha' => 'curta', 'aceite_termos' => '1', '_token' => $csrf]);
        self::assertResponseRedirects("/trabalho-externo/convite/$token");

        $client->request('POST', "/trabalho-externo/convite/$token/criar-conta", ['full_name' => 'Novo Correspondente', 'senha' => 'senha-forte-1', 'aceite_termos' => '1', '_token' => $csrf]);
        self::assertResponseRedirects("/trabalho-externo?te=$codigo");

        $usuario = $this->em()->getRepository(User::class)->findOneBy(['email' => 'novo.correspondente@test.com']);
        self::assertNotNull($usuario);
        self::assertSame([], $this->em()->getRepository(UserTenant::class)->findBy(['user' => $usuario]), 'convite não cria vínculo com escritório');

        $client->request('GET', "/trabalho-externo?te=$codigo");
        self::assertResponseIsSuccessful();
        $client->request('GET', '/trabalho-externo/api/painel');
        $painel = $this->json($client)['painel'];
        self::assertSame('convidado', $painel['perfil']['chave']);
        self::assertSame([$codigo], array_column($painel['demandas'], 'codigo'));

        $client->request('GET', "/trabalho-externo/api/demandas/$codigo");
        $d = $this->json($client)['demanda'];
        self::assertSame(['resumo', 'exec', 'chat', 'hist'], $d['abas']);
        self::assertNull($d['whatsapp']);
        self::assertNotContains('CONTATO', array_column($d['campos'], 'r'), 'contato do solicitante escondido do convidado');
        self::assertSame(['validar', 'impedimento', 'prorrogar'], array_column($d['botoes'], 'k'));
        self::assertSame([], $d['propostas'], 'valor e condições da proposta não saem para o Convidado');
        foreach ($d['mensagens'] as $m) {
            self::assertStringNotContainsString('Proposta v1', $m['txt'], 'o chat de antes do aceite (com o valor) não aparece');
        }
        self::assertNotSame([], $d['textos'], 'com "ver", lê as instruções');

        foreach ([
            ['/trabalho-externo/api/solicitacoes', []],
            ['/trabalho-externo/api/configuracao', ['visibilidade' => 'oculta']],
            ["/trabalho-externo/api/demandas/$codigo/convites", ['nome' => 'X', 'email' => 'x@test.com']],
            ["/trabalho-externo/api/demandas/$codigo/whatsapp/contatos", ['nome' => 'X', 'telefone' => '61999990000', 'autorizou' => true]],
            ["/trabalho-externo/api/demandas/$codigo/propostas", ['valor' => '1']],
        ] as [$url, $corpo]) {
            $this->postarJson($client, $url, $corpo);
            self::assertResponseStatusCodeSame(403, "o Convidado não usa $url");
        }

        $client->request('GET', '/expediente');
        self::assertResponseRedirects('/escritorio/selecionar', null, 'fora do Trabalho Externo, o convidado é só uma conta sem escritório');
    }

    #[TestDox('o convidado age dentro das permissões do convite e não vê outra demanda')]
    public function testPermissoesDoConvidado(): void
    {
        [$client, $codigo, $master, $central, $solicitante, $escritorio] = $this->demandaEmExecucao();
        $outra = $this->outraDemanda($client, $solicitante, $escritorio);
        $this->logarComTenant($client, $master, $central);
        $token = $this->convidar($client, $codigo, 'conv@test.com', ['ver' => true, 'baixar' => false, 'enviar' => false, 'chat' => false, 'andamento' => true, 'concluir' => false]);
        $convidado = $this->contaDoConvidado($client, $token, 'conv@test.com');

        $client->getCookieJar()->clear();
        $client->loginUser($convidado);
        $this->marcarTermosAceitos($client);

        $this->postarJson($client, "/trabalho-externo/api/demandas/$codigo/mensagens", ['texto' => 'olá']);
        self::assertResponseStatusCodeSame(403, 'sem "Conversar no chat"');
        $d = $this->postarJson($client, "/trabalho-externo/api/demandas/$codigo/andamentos", ['texto' => 'Cheguei'])['demanda'];
        self::assertSame('Cheguei', end($d['mensagens'])['txt']);
        self::assertSame(['impedimento', 'prorrogar'], array_column($d['botoes'], 'k'), 'sem "concluir", não marca como concluída');
        $this->postarJson($client, "/trabalho-externo/api/demandas/$codigo/acoes/validar");
        self::assertResponseStatusCodeSame(403);
        $this->postarJson($client, "/trabalho-externo/api/demandas/$codigo/acoes/impedimento", ['motivo' => 'Fórum fechado']);
        self::assertResponseIsSuccessful();
        $this->postarJson($client, "/trabalho-externo/api/demandas/$codigo/acoes/concluir");
        self::assertResponseStatusCodeSame(409, "concluir não se aplica em execução");

        $client->request('GET', "/trabalho-externo/api/demandas/$outra");
        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('revogar corta o acesso na hora e o reenvio invalida o link antigo')]
    public function testRevogarEReenviar(): void
    {
        [$client, $codigo, $master, $central] = $this->demandaEmExecucao();
        $token = $this->convidar($client, $codigo, 'rev@test.com');
        $convidado = $this->contaDoConvidado($client, $token, 'rev@test.com');

        $this->logarComTenant($client, $master, $central);
        $client->request('GET', "/trabalho-externo/api/demandas/$codigo");
        $convite = $this->json($client)['demanda']['convites'][0];
        self::assertSame('Convite aceito', $convite['st']);

        $d = $this->postarJson($client, "/trabalho-externo/api/demandas/$codigo/convites/{$convite['id']}/revogar")['demanda'];
        self::assertSame('Acesso revogado', $d['convites'][0]['st']);

        $client->getCookieJar()->clear();
        $client->loginUser($convidado);
        $this->marcarTermosAceitos($client);
        $client->request('GET', "/trabalho-externo/api/demandas/$codigo");
        self::assertResponseStatusCodeSame(403, 'sem convite vigente, a conta não tem perfil no módulo');

        $this->logarComTenant($client, $master, $central);
        $tokenB = $this->convidar($client, $codigo, 'reenvio@test.com');
        $client->request('GET', "/trabalho-externo/api/demandas/$codigo");
        $convites = $this->json($client)['demanda']['convites'];
        self::assertSame(['Acesso revogado', 'Convite enviado'], array_column($convites, 'st'), 'o convite novo não ressuscita o revogado');
        $id = end($convites)['id'];
        $this->postarJson($client, "/trabalho-externo/api/demandas/$codigo/convites/$id/reenviar");
        self::assertResponseIsSuccessful();
        $client->request('GET', "/trabalho-externo/convite/$tokenB");
        self::assertSelectorTextContains('h1', 'Convite indisponível');
    }

    #[TestDox('com conta: o anônimo é mandado entrar; logado com outro e-mail, não aceita')]
    public function testContaExistenteEOutroEmail(): void
    {
        [$client, $codigo, $master, $central, $solicitante, $escritorio] = $this->demandaEmExecucao();
        $outroEscritorio = $this->criarTenant('Correspondente');
        $correspondente = $this->criarUsuario($outroEscritorio);
        $token = $this->convidar($client, $codigo, (string) $correspondente->getEmail());

        $client->getCookieJar()->clear();
        $client->request('GET', "/trabalho-externo/convite/$token");
        self::assertSelectorTextContains('.login-container', 'Já existe uma conta no BlueJus para este e-mail');

        $this->logarComTenant($client, $solicitante, $escritorio);
        $client->request('GET', "/trabalho-externo/convite/$token");
        self::assertSelectorTextContains('.login-container', 'Este convite é para outro e-mail');

        $this->logarComTenant($client, $correspondente, $outroEscritorio);
        $crawler = $client->request('GET', "/trabalho-externo/convite/$token");
        $client->request('POST', "/trabalho-externo/convite/$token/aceitar", ['_token' => $crawler->filter('form[action$="/aceitar"] input[name="_token"]')->attr('value')]);
        self::assertResponseRedirects("/trabalho-externo?te=$codigo");

        // Solicitante no próprio escritório E convidado nesta demanda: o perfil é por demanda.
        $client->request('GET', "/trabalho-externo/api/demandas/$codigo");
        self::assertResponseIsSuccessful();
        self::assertSame(['resumo', 'exec', 'chat', 'hist'], $this->json($client)['demanda']['abas']);
        $client->request('GET', '/trabalho-externo/api/painel');
        self::assertContains($codigo, array_column($this->json($client)['painel']['demandas'], 'codigo'));
    }

    #[TestDox('sem "ver" não lê instruções nem documentos; sem "baixar" o download é 404; vencido, perde o acesso')]
    public function testVerBaixarEValidade(): void
    {
        [$client, $codigo, $master, $central, $solicitante, $escritorio] = $this->demandaEmExecucao();
        $this->logarComTenant($client, $solicitante, $escritorio);
        $arquivo = tempnam(sys_get_temp_dir(), 'te');
        file_put_contents($arquivo, '%PDF-1.4 x');
        $client->request('POST', "/trabalho-externo/api/demandas/$codigo/documentos", [], ['documentos' => [new \Symfony\Component\HttpFoundation\File\UploadedFile($arquivo, 'mapa.pdf', 'application/pdf', null, true)]], ['HTTP_X_CSRF_TOKEN' => 'TOKEN_ajax']);
        self::assertResponseIsSuccessful();
        $docId = $this->json($client)['demanda']['documentos'][0]['id'];

        $this->logarComTenant($client, $master, $central);
        $token = $this->convidar($client, $codigo, 'semver@test.com', ['ver' => false, 'baixar' => false, 'enviar' => false, 'chat' => true, 'andamento' => false, 'concluir' => false]);
        $convidado = $this->contaDoConvidado($client, $token, 'semver@test.com');

        $client->request('GET', "/trabalho-externo/api/demandas/$codigo");
        $d = $this->json($client)['demanda'];
        self::assertSame([], $d['textos']);
        self::assertSame([], $d['documentos']);
        $client->request('GET', "/trabalho-externo/demandas/$codigo/documentos/$docId");
        self::assertResponseStatusCodeSame(404);

        $this->em()->getConnection()->executeStatement("UPDATE trabalho_externo_convite SET validade = CURRENT_DATE - 1 WHERE email = 'semver@test.com'");
        $this->em()->clear();
        $client->request('GET', '/trabalho-externo/api/painel');
        self::assertResponseStatusCodeSame(403, 'convite vencido: a conta perde o perfil');
        $client->request('GET', "/trabalho-externo/api/demandas/$codigo");
        self::assertResponseStatusCodeSame(403);
    }

    #[TestDox('Equipe sem "Convidar e revogar profissionais" não convida; o link do reenvio funciona')]
    public function testEquipeSemConvidarEReenvio(): void
    {
        [$client, $codigo, $master, $central] = $this->demandaEmExecucao();
        $this->definirSede($central);
        $equipe = $this->criarUsuario($central, nome: 'Colaborador');
        $this->logarComTenant($client, $equipe, $central);
        $this->postarJson($client, "/trabalho-externo/api/demandas/$codigo/convites", ['nome' => 'X', 'email' => 'x@test.com']);
        self::assertResponseStatusCodeSame(403);

        $this->logarComTenant($client, $master, $central);
        $this->convidar($client, $codigo, 'reenvio2@test.com');
        $client->request('GET', "/trabalho-externo/api/demandas/$codigo");
        $id = $this->json($client)['demanda']['convites'][0]['id'];
        $antes = count($this->mensagensDeEmail());
        $this->postarJson($client, "/trabalho-externo/api/demandas/$codigo/convites/$id/reenviar");
        $mensagens = $this->mensagensDeEmail();
        self::assertCount($antes + 1, $mensagens);
        preg_match('#/trabalho-externo/convite/([a-f0-9]{64})#', (string) end($mensagens)->getHtmlBody(), $m);

        $client->getCookieJar()->clear();
        $client->request('GET', '/trabalho-externo/convite/' . $m[1]);
        self::assertSelectorTextContains('button', 'Criar conta e aceitar', 'o link novo vale');
    }

    #[TestDox('só quem pode convidar convida, e só em demanda aceita ou em execução')]
    public function testQuemConvida(): void
    {
        [$client, $codigo, , , $solicitante, $escritorio] = $this->demandaEmExecucao();

        $this->logarComTenant($client, $solicitante, $escritorio);
        $this->postarJson($client, "/trabalho-externo/api/demandas/$codigo/convites", ['nome' => 'X', 'email' => 'x@test.com']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, count($this->mensagensDeEmail()));
    }

    #[TestDox('A: o aviso "Abrir conversa" e as não lidas do convidado só contam o que ele pode ler no chat')]
    public function testAvisoENaoLidasRespeitamOCorteDoAceite(): void
    {
        [$client, $codigo, $master, $central, $solicitante, $escritorio, $token] = $this->demandaComNegociacao();
        $convidado = $this->contaDoConvidado($client, $token, 'negocio@test.com');

        $client->request('GET', '/trabalho-externo/api/painel');
        $conteudo = (string) $client->getResponse()->getContent();
        $painel = $this->json($client)['painel'];
        self::assertStringNotContainsString('SEGREDO', $conteudo, 'nada da conversa anterior ao aceite chega no painel');
        self::assertNull($painel['avisoDeMensagem'], 'sem mensagem visível nova, não há aviso');
        self::assertSame(0, $painel['demandas'][0]['naoLidas'], 'mensagens anteriores ao aceite não contam como não lidas');
        $client->request('GET', "/trabalho-externo/api/demandas/$codigo");
        self::assertSame(0, $this->json($client)['demanda']['naoLidas']);

        // Nota interna e registro de arquivo (sem "ver") depois do aceite também não contam.
        $this->logarComTenant($client, $master, $central);
        $this->postarJson($client, "/trabalho-externo/api/demandas/$codigo/mensagens", ['texto' => 'NOTA-INTERNA-POS', 'interna' => true]);
        $this->anexar($client, $codigo, 'laudo-pos.pdf');
        $this->logarComoConvidado($client, $convidado);
        $client->request('GET', '/trabalho-externo/api/painel');
        $painel = $this->json($client)['painel'];
        self::assertNull($painel['avisoDeMensagem']);
        self::assertSame(0, $painel['demandas'][0]['naoLidas']);
        self::assertStringNotContainsString('NOTA-INTERNA-POS', (string) $client->getResponse()->getContent());
        self::assertStringNotContainsString('laudo-pos.pdf', (string) $client->getResponse()->getContent());

        // Mensagem comum depois do aceite: conta e aparece no aviso.
        $this->logarComTenant($client, $solicitante, $escritorio);
        $this->postarJson($client, "/trabalho-externo/api/demandas/$codigo/mensagens", ['texto' => 'Pode confirmar o horário?']);
        // Depois dela, um registro de arquivo que ele não lê (sem "ver"): o aviso pula esse registro.
        $this->logarComTenant($client, $master, $central);
        $this->anexar($client, $codigo, 'laudo-final.pdf');
        $this->logarComoConvidado($client, $convidado);
        $client->request('GET', '/trabalho-externo/api/painel');
        $painel = $this->json($client)['painel'];
        self::assertSame(1, $painel['demandas'][0]['naoLidas']);
        self::assertSame('Pode confirmar o horário?', $painel['avisoDeMensagem']['texto'] ?? null);
    }

    #[TestDox('B: no histórico e no andamento, o convidado vê só marcos administrativos de antes do aceite, sem motivo nem autor')]
    public function testHistoricoAnteriorAoAceiteNaoVazaNegociacao(): void
    {
        [$client, $codigo, $master, $central, , , $token] = $this->demandaComNegociacao();
        $convidado = $this->contaDoConvidado($client, $token, 'negocio@test.com');
        // Determinístico: todo o histórico no MESMO segundo do aceite (a coluna guarda segundos).
        // Com o corte inclusivo, isso é "anterior"; com '<', tudo vazaria.
        $this->em()->getConnection()->executeStatement(
            'UPDATE trabalho_externo_historico h SET em = c.aceito_em FROM trabalho_externo_convite c WHERE c.demanda_id = h.demanda_id AND h.demanda_id = ?',
            [\App\TrabalhoExterno\Entity\DemandaExterna::idDoCodigo($codigo)],
        );
        $this->em()->clear();

        $client->request('GET', "/trabalho-externo/api/demandas/$codigo");
        $conteudo = (string) $client->getResponse()->getContent();
        $d = $this->json($client)['demanda'];
        self::assertStringNotContainsString('SEGREDO', $conteudo, 'nenhum motivo de antes do aceite sai no JSON');
        self::assertStringNotContainsString('250,00', $conteudo, 'nenhum valor de proposta sai no JSON');
        self::assertSame(['Em execução', 'Aceita', 'Nova solicitação'], array_column($d['historico'], 'para'), 'só os marcos administrativos');
        foreach ($d['historico'] as $h) {
            self::assertSame('', $h['motivo']);
            self::assertSame('', $h['quem'], 'sem autor nos eventos anteriores ao aceite');
            self::assertSame('', $h['de']);
        }
        $datas = array_column($d['etapas'], 'dt', 'rot');
        foreach (['Em análise', 'Proposta', 'Pago'] as $reservado) {
            self::assertSame('', $datas[$reservado], "a data de \"$reservado\" é da Central com o cliente");
        }
        self::assertNotSame('', $datas['Aceite']);
        self::assertSame('', $d['propostaTitulo'], 'nem o número de versões da proposta');
        self::assertFalse($d['semDocumentos'], 'sem "ver", não afirma nem nega que haja documentos');

        // Até aqui tudo estava no mesmo segundo do aceite e foi tratado como anterior (a dúvida
        // esconde). Agora a negociação fica duas horas antes e o aceite uma hora antes: o que vier
        // depois é posterior ao aceite e volta a ter autor e motivo.
        $conexao = $this->em()->getConnection();
        $id = \App\TrabalhoExterno\Entity\DemandaExterna::idDoCodigo($codigo);
        $conexao->executeStatement("UPDATE trabalho_externo_historico SET em = em - INTERVAL '2 hours' WHERE demanda_id = ?", [$id]);
        $conexao->executeStatement("UPDATE trabalho_externo_convite SET aceito_em = aceito_em - INTERVAL '1 hour' WHERE demanda_id = ?", [$id]);
        $this->em()->clear();

        $this->logarComTenant($client, $master, $central);
        $this->postarJson($client, "/trabalho-externo/api/demandas/$codigo/acoes/validar");
        $this->postarJson($client, "/trabalho-externo/api/demandas/$codigo/acoes/devolver", ['motivo' => 'Faltou a ata assinada']);
        $this->logarComoConvidado($client, $convidado);
        $client->request('GET', "/trabalho-externo/api/demandas/$codigo");
        $conteudo = (string) $client->getResponse()->getContent();
        $topo = $this->json($client)['demanda']['historico'][0];
        self::assertSame('Faltou a ata assinada', $topo['motivo']);
        self::assertNotSame('', $topo['quem']);
        self::assertStringNotContainsString('SEGREDO', $conteudo, 'o passado continua reservado');

        // A Central continua vendo tudo.
        $this->logarComTenant($client, $master, $central);
        $client->request('GET', "/trabalho-externo/api/demandas/$codigo");
        self::assertStringContainsString('SEGREDO-AJUSTE', (string) $client->getResponse()->getContent());
    }

    #[TestDox('C: sem "ver", a lista não entrega a descrição da atividade ao convidado')]
    public function testListaSemVerNaoEntregaDescricao(): void
    {
        [$client, $codigo] = $this->demandaEmExecucao();
        $token = $this->convidar($client, $codigo, 'semdesc@test.com', ['ver' => false, 'baixar' => false, 'enviar' => false, 'chat' => true, 'andamento' => false, 'concluir' => false]);
        $this->contaDoConvidado($client, $token, 'semdesc@test.com');

        $client->request('GET', '/trabalho-externo/api/painel');
        self::assertSame('', $this->json($client)['painel']['demandas'][0]['descricao']);
        self::assertStringNotContainsString('Comparecer à audiência', (string) $client->getResponse()->getContent());

        [$client2, $codigo2] = [$client, $this->demandaEmExecucaoNoMesmoCliente($client)];
        $token2 = $this->convidar($client2, $codigo2, 'comdesc@test.com');
        $this->contaDoConvidado($client2, $token2, 'comdesc@test.com');
        $client2->request('GET', '/trabalho-externo/api/painel');
        self::assertSame('Comparecer à audiência de conciliação.', $this->json($client2)['painel']['demandas'][0]['descricao'], 'com "ver", a descrição vem');
    }

    #[TestDox('revogado ou sem convite: nada da demanda, nem prévia de mensagem')]
    public function testRevogadoNaoRecebeNada(): void
    {
        [$client, $codigo, $master, $central, , , $token] = $this->demandaComNegociacao();
        $convidado = $this->contaDoConvidado($client, $token, 'negocio@test.com');

        $this->logarComTenant($client, $master, $central);
        $client->request('GET', "/trabalho-externo/api/demandas/$codigo");
        $id = $this->json($client)['demanda']['convites'][0]['id'];
        $this->postarJson($client, "/trabalho-externo/api/demandas/$codigo/convites/$id/revogar");
        $this->postarJson($client, "/trabalho-externo/api/demandas/$codigo/mensagens", ['texto' => 'DEPOIS-DA-REVOGACAO']);

        $this->logarComoConvidado($client, $convidado);
        foreach (['/trabalho-externo/api/painel', "/trabalho-externo/api/demandas/$codigo"] as $url) {
            $client->request('GET', $url);
            self::assertResponseStatusCodeSame(403, $url);
            self::assertStringNotContainsString('DEPOIS-DA-REVOGACAO', (string) $client->getResponse()->getContent());
            self::assertStringNotContainsString('SEGREDO', (string) $client->getResponse()->getContent());
        }

        // Revogado que AINDA tem perfil (convite vigente em outra demanda): o painel abre, mas só
        // com a outra; a revogada some e o detalhe dela é 404. Aqui a barreira é o recorte por
        // demanda, não a conta sem perfil.
        $outra = $this->demandaEmExecucaoNoMesmoCliente($client);
        $tokenOutra = $this->convidar($client, $outra, 'negocio@test.com');
        $this->logarComoConvidado($client, $convidado);
        $crawler = $client->request('GET', "/trabalho-externo/convite/$tokenOutra");
        $client->request('POST', "/trabalho-externo/convite/$tokenOutra/aceitar", ['_token' => $crawler->filter('form[action$="/aceitar"] input[name="_token"]')->attr('value')]);
        self::assertResponseRedirects("/trabalho-externo?te=$outra");

        $client->request('GET', '/trabalho-externo/api/painel');
        self::assertResponseIsSuccessful();
        $conteudo = (string) $client->getResponse()->getContent();
        self::assertSame([$outra], array_column($this->json($client)['painel']['demandas'], 'codigo'));
        foreach (['SEGREDO', 'DEPOIS-DA-REVOGACAO', $codigo] as $proibido) {
            self::assertStringNotContainsString($proibido, $conteudo, $proibido);
        }
        $client->request('GET', "/trabalho-externo/api/demandas/$codigo");
        self::assertResponseStatusCodeSame(404, 'a demanda do convite revogado não abre, mesmo com perfil');
    }

    /**
     * Demanda em execução cuja história antes do convite tem negociação: pedido de informação,
     * resposta, ajuste com valor, duas propostas, pagamento, conversa e uma mensagem do cliente
     * DEPOIS do convite e ANTES do aceite (a última de outra pessoa que o convidado veria).
     *
     * @return array{0: KernelBrowser, 1: string, 2: User, 3: \App\Entity\Tenant\Tenant, 4: User, 5: \App\Entity\Tenant\Tenant, 6: string}
     */
    private function demandaComNegociacao(): array
    {
        $client = static::createClient();
        $this->instalarCsrf($client);
        $escritorio = $this->criarTenant('Solicitante');
        $central = $this->criarTenant('Central');
        $solicitante = $this->criarUsuario($escritorio);
        $master = $this->criarMaster($central);
        $url = static fn (string $acao): string => "/trabalho-externo/api/demandas/%s/acoes/$acao";

        $this->logarComTenant($client, $solicitante, $escritorio);
        $codigo = $this->solicitar($client, self::solicitacaoValida())['codigo'];
        $passos = [
            [$master, $central, sprintf($url('analise'), $codigo), []],
            [$master, $central, sprintf($url('info'), $codigo), ['motivo' => 'SEGREDO-INFO quanto o cliente paga?']],
            [$solicitante, $escritorio, sprintf($url('responderInfo'), $codigo), ['motivo' => 'SEGREDO-RESPOSTA orçamento apertado']],
            [$master, $central, "/trabalho-externo/api/demandas/$codigo/propostas", ['valor' => '300']],
            [$solicitante, $escritorio, sprintf($url('ajustes'), $codigo), ['motivo' => 'SEGREDO-AJUSTE baixar para 250,00']],
            [$master, $central, "/trabalho-externo/api/demandas/$codigo/propostas", ['valor' => '250']],
            [$solicitante, $escritorio, "/trabalho-externo/api/demandas/$codigo/mensagens", ['texto' => 'SEGREDO-CHAT fechamos em 250,00']],
            [$solicitante, $escritorio, sprintf($url('aceitar'), $codigo), []],
            [$master, $central, sprintf($url('pagamento'), $codigo), []],
            [$master, $central, sprintf($url('iniciar'), $codigo), []],
        ];
        foreach ($passos as [$quem, $onde, $endereco, $corpo]) {
            $this->logarComTenant($client, $quem, $onde);
            $this->postarJson($client, $endereco, $corpo);
            self::assertResponseIsSuccessful($endereco);
        }

        $this->logarComTenant($client, $master, $central);
        $token = $this->convidar($client, $codigo, 'negocio@test.com', ['ver' => false, 'baixar' => false, 'enviar' => false, 'chat' => true, 'andamento' => true, 'concluir' => false]);
        $this->logarComTenant($client, $solicitante, $escritorio);
        $this->postarJson($client, "/trabalho-externo/api/demandas/$codigo/mensagens", ['texto' => 'SEGREDO-ULTIMA o desconto fica entre nós']);
        self::assertResponseIsSuccessful();

        return [$client, $codigo, $master, $central, $solicitante, $escritorio, $token];
    }

    private function logarComoConvidado(KernelBrowser $client, User $convidado): void
    {
        $client->getCookieJar()->clear();
        $client->loginUser($convidado);
        $this->marcarTermosAceitos($client);
    }

    private function anexar(KernelBrowser $client, string $codigo, string $nome): void
    {
        $arquivo = tempnam(sys_get_temp_dir(), 'te');
        file_put_contents($arquivo, '%PDF-1.4 x');
        $client->request('POST', "/trabalho-externo/api/demandas/$codigo/documentos", [], ['documentos' => [new \Symfony\Component\HttpFoundation\File\UploadedFile($arquivo, $nome, 'application/pdf', null, true)]], ['HTTP_X_CSRF_TOKEN' => 'TOKEN_ajax']);
        self::assertResponseIsSuccessful();
    }

    /** Uma segunda demanda em execução, no mesmo cliente (o kernel não reinicia entre requisições). */
    private function demandaEmExecucaoNoMesmoCliente(KernelBrowser $client): string
    {
        $escritorio = $this->criarTenant('Solicitante 2');
        $central = $this->criarTenant('Central 2');
        $solicitante = $this->criarUsuario($escritorio);
        $master = $this->criarMaster($central);

        $this->logarComTenant($client, $solicitante, $escritorio);
        $codigo = $this->solicitar($client, self::solicitacaoValida())['codigo'];
        $this->logarComTenant($client, $master, $central);
        $this->postarJson($client, "/trabalho-externo/api/demandas/$codigo/acoes/analise");
        $this->postarJson($client, "/trabalho-externo/api/demandas/$codigo/propostas", ['valor' => '300']);
        $this->logarComTenant($client, $solicitante, $escritorio);
        $this->postarJson($client, "/trabalho-externo/api/demandas/$codigo/acoes/aceitar");
        $this->logarComTenant($client, $master, $central);
        $this->postarJson($client, "/trabalho-externo/api/demandas/$codigo/acoes/iniciar");

        return $codigo;
    }

    /** @return array{0: KernelBrowser, 1: string, 2: User, 3: \App\Entity\Tenant\Tenant, 4: User, 5: \App\Entity\Tenant\Tenant} */
    private function demandaEmExecucao(): array
    {
        $client = static::createClient();
        $this->instalarCsrf($client);
        $escritorio = $this->criarTenant('Solicitante');
        $central = $this->criarTenant('Central');
        $solicitante = $this->criarUsuario($escritorio);
        $master = $this->criarMaster($central);

        $this->logarComTenant($client, $solicitante, $escritorio);
        $codigo = $this->solicitar($client, self::solicitacaoValida())['codigo'];
        $this->logarComTenant($client, $master, $central);
        $this->postarJson($client, "/trabalho-externo/api/demandas/$codigo/acoes/analise");
        $this->postarJson($client, "/trabalho-externo/api/demandas/$codigo/propostas", ['valor' => '300']);
        $this->logarComTenant($client, $solicitante, $escritorio);
        $this->postarJson($client, "/trabalho-externo/api/demandas/$codigo/acoes/aceitar");
        $this->logarComTenant($client, $master, $central);
        $this->postarJson($client, "/trabalho-externo/api/demandas/$codigo/acoes/iniciar");

        return [$client, $codigo, $master, $central, $solicitante, $escritorio];
    }

    private function outraDemanda(KernelBrowser $client, User $solicitante, \App\Entity\Tenant\Tenant $escritorio): string
    {
        $this->logarComTenant($client, $solicitante, $escritorio);

        return $this->solicitar($client, self::solicitacaoValida())['codigo'];
    }

    /** Convida pelo endpoint real e devolve o token tirado do link do e-mail. */
    private function convidar(KernelBrowser $client, string $codigo, string $email, ?array $permissoes = null): string
    {
        $antes = count($this->mensagensDeEmail());
        $corpo = ['nome' => 'Profissional Convidado', 'email' => $email, 'funcao' => 'Advogado correspondente'];
        if ($permissoes !== null) {
            $corpo['permissoes'] = $permissoes;
        }
        $this->postarJson($client, "/trabalho-externo/api/demandas/$codigo/convites", $corpo);
        self::assertResponseIsSuccessful();

        $mensagens = $this->mensagensDeEmail();
        self::assertCount($antes + 1, $mensagens);
        $html = (string) end($mensagens)->getHtmlBody();
        self::assertMatchesRegularExpression('#/trabalho-externo/convite/([a-f0-9]{64})#', $html);
        preg_match('#/trabalho-externo/convite/([a-f0-9]{64})#', $html, $m);

        return $m[1];
    }

    private function contaDoConvidado(KernelBrowser $client, string $token, string $email): User
    {
        $client->getCookieJar()->clear();
        $crawler = $client->request('GET', "/trabalho-externo/convite/$token");
        $client->request('POST', "/trabalho-externo/convite/$token/criar-conta", ['full_name' => 'Convidado', 'senha' => 'senha-forte-1', 'aceite_termos' => '1', '_token' => $crawler->filter('form[action$="/criar-conta"] input[name="_token"]')->attr('value')]);
        self::assertResponseRedirects();

        return $this->em()->getRepository(User::class)->findOneBy(['email' => $email]);
    }

    /** @return list<Email> todas as mensagens enviadas neste teste (o kernel não reinicia) */
    private function mensagensDeEmail(): array
    {
        $enviadas = [];
        foreach (static::getContainer()->get('mailer.message_logger_listener')->getEvents()->getEvents() as $evento) {
            if (!$evento->isQueued() && $evento->getMessage() instanceof Email) {
                $enviadas[] = $evento->getMessage();
            }
        }

        return $enviadas;
    }
}
