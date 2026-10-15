<?php

declare(strict_types=1);

namespace App\Tests\TrabalhoExterno\Functional;

use App\Tests\Functional\JusPrimeWebTestCase;
use App\TrabalhoExterno\Controller\DemandaExternaController;
use App\TrabalhoExterno\Controller\TrabalhoExternoController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * O Trabalho Externo pela HTTP, com banco real (DAMA desfaz por teste): perfis, fluxo da spec §4
 * e as barreiras da §3 (outro escritório é 404, perfil errado é 403, CSRF é 403).
 */
#[CoversClass(TrabalhoExternoController::class)]
#[CoversClass(DemandaExternaController::class)]
final class TrabalhoExternoControllerTest extends JusPrimeWebTestCase
{
    use CriaFixturesTrabalhoExternoTrait;

    #[TestDox('sem a permissão do módulo: aviso amigável na própria tela (403), sem mandar para módulo que também está fechado; a API responde 403')]
    public function testSemPermissao(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        // Sem permissão NENHUMA: antes, o redirect para "/" levava ao Expediente, que também é
        // fechado para ele, e a pessoa via a página técnica 403 do Symfony.
        $user = $this->criarUsuario($tenant, []);
        $this->logarComTenant($client, $user, $tenant);

        $client->request('GET', '/trabalho-externo');
        self::assertResponseStatusCodeSame(403);
        self::assertSelectorTextContains('.te-sem-acesso h1', 'Você não tem acesso ao Trabalho Externo');
        self::assertSelectorTextContains('.te-sem-acesso', 'administrador do seu escritório');
        self::assertSelectorNotExists('#tePagina', 'a tela do módulo não é montada');
        self::assertStringNotContainsString('data-painel', (string) $client->getResponse()->getContent());

        $client->request('GET', '/trabalho-externo/api/painel');
        self::assertResponseStatusCodeSame(403);
        self::assertSame(false, $this->json($client)['ok'] ?? null, 'a API continua em JSON');
    }

    #[TestDox('sem escritório e sem convite: o mesmo aviso, com a dica do link do convite')]
    public function testSemEscritorioESemConvite(): void
    {
        $client = static::createClient();
        $avulso = new \App\Entity\Auth\User();
        $avulso->setEmail('avulso_' . uniqid() . '@test.com');
        $avulso->setFullName('Conta avulsa');
        $avulso->setRoles(['ROLE_USER']);
        $avulso->setIsActive(true);
        $avulso->setPassword('x');
        $this->em()->persist($avulso);
        $this->em()->flush();
        $client->loginUser($avulso);
        $this->marcarTermosAceitos($client);

        $client->request('GET', '/trabalho-externo');
        self::assertResponseStatusCodeSame(403);
        self::assertSelectorTextContains('.te-sem-acesso', 'abra o link do convite');
    }

    #[TestDox('o super admin é o Master em qualquer escritório, mesmo sem a permissão do módulo')]
    public function testMasterEhOSuperAdmin(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $master = $this->criarMaster($tenant);
        $this->logarComTenant($client, $master, $tenant);

        $client->request('GET', '/trabalho-externo');
        self::assertResponseIsSuccessful();

        $client->request('GET', '/trabalho-externo/api/painel');
        $painel = $this->json($client)['painel'];
        self::assertSame('master', $painel['perfil']['chave']);
        self::assertFalse($painel['perfil']['podeSolicitar']);
        self::assertArrayHasKey('equipe', $painel['configuracao'], 'o Master recebe a configuração completa');
    }

    #[TestDox('fluxo completo: envio, análise, proposta, aceite, execução, validação, conclusão, pagamento e arquivo')]
    public function testFluxoCompleto(): void
    {
        $client = static::createClient();
        $this->instalarCsrf($client);
        $escritorio = $this->criarTenant('Solicitante');
        $solicitante = $this->criarUsuario($escritorio);
        $central = $this->criarTenant('Central');
        $master = $this->criarMaster($central);

        $this->logarComTenant($client, $solicitante, $escritorio);
        $resposta = $this->solicitar($client, self::solicitacaoValida());
        self::assertResponseIsSuccessful();
        $codigo = $resposta['codigo'];
        self::assertMatchesRegularExpression('/^TE-\d{4,}$/', $codigo);

        $this->logarComTenant($client, $master, $central);
        $this->postarJson($client, "/trabalho-externo/api/demandas/$codigo/acoes/analise");
        self::assertResponseIsSuccessful();
        $d = $this->postarJson($client, "/trabalho-externo/api/demandas/$codigo/propostas", ['valor' => '1.500,00', 'prazoEstimado' => '2 dias úteis'])['demanda'];
        self::assertSame('proposta', $d['situacao']);
        self::assertSame('R$ 1.500,00', $d['propostas'][0]['campos'][0]['v']);

        $this->logarComTenant($client, $solicitante, $escritorio);
        $this->postarJson($client, "/trabalho-externo/api/demandas/$codigo/acoes/aceitar");
        self::assertResponseIsSuccessful();

        $this->logarComTenant($client, $master, $central);
        foreach (['iniciar', 'validar', 'concluir', 'pagamento', 'arquivar'] as $acao) {
            $d = $this->postarJson($client, "/trabalho-externo/api/demandas/$codigo/acoes/$acao")['demanda'] ?? null;
            self::assertResponseIsSuccessful("ação $acao");
        }

        self::assertSame('arquivada', $d['situacao']);
        $marcos = array_column($d['etapas'], 'feito');
        self::assertSame([true, true, true, true, true, true, true, true], $marcos, 'os oito marcos do andamento foram alcançados');
        self::assertCount(9, $d['historico'], 'nova, análise, proposta, aceita, execução, validação, concluída, pago, arquivada');
    }

    #[TestDox('motivo é obrigatório onde o desenho pede, e a transição fora do estado é 409')]
    public function testMotivoETransicaoInvalida(): void
    {
        $client = static::createClient();
        $this->instalarCsrf($client);
        $escritorio = $this->criarTenant();
        $solicitante = $this->criarUsuario($escritorio);
        $master = $this->criarMaster($escritorio);

        $this->logarComTenant($client, $solicitante, $escritorio);
        $codigo = $this->solicitar($client, self::solicitacaoValida())['codigo'];

        $this->logarComTenant($client, $master, $escritorio);
        $this->postarJson($client, "/trabalho-externo/api/demandas/$codigo/acoes/recusar", ['motivo' => '  ']);
        self::assertResponseStatusCodeSame(409);

        $this->postarJson($client, "/trabalho-externo/api/demandas/$codigo/acoes/concluir");
        self::assertResponseStatusCodeSame(409, 'concluir não se aplica a uma demanda nova (estado, não permissão)');

        $d = $this->postarJson($client, "/trabalho-externo/api/demandas/$codigo/acoes/recusar", ['motivo' => 'Fora da área atendida'])['demanda'];
        self::assertSame('recusada', $d['situacao']);
        self::assertSame('Demanda recusada: Fora da área atendida', end($d['mensagens'])['txt']);
    }

    #[TestDox('isolamento: outro escritório não lista, não abre, não age e não baixa documento (404)')]
    public function testIsolamentoEntreEscritorios(): void
    {
        $client = static::createClient();
        $this->instalarCsrf($client);
        $a = $this->criarTenant('A');
        $b = $this->criarTenant('B');
        $deA = $this->criarUsuario($a);
        $deB = $this->criarUsuario($b);

        $this->logarComTenant($client, $deA, $a);
        $arquivo = $this->arquivoTemporario('instrucoes.pdf', '%PDF-1.4 conteudo');
        $resposta = $this->solicitar($client, self::solicitacaoValida(), true, ['documentos' => [$arquivo]]);
        $codigo = $resposta['codigo'];
        $client->request('GET', "/trabalho-externo/api/demandas/$codigo");
        $docId = $this->json($client)['demanda']['documentos'][0]['id'];

        $client->request('GET', "/trabalho-externo/demandas/$codigo/documentos/$docId");
        self::assertResponseIsSuccessful('o próprio escritório baixa');

        $this->logarComTenant($client, $deB, $b);
        $client->request('GET', '/trabalho-externo/api/painel');
        self::assertSame([], $this->json($client)['painel']['demandas']);

        $client->request('GET', "/trabalho-externo/api/demandas/$codigo");
        self::assertResponseStatusCodeSame(404);
        $this->postarJson($client, "/trabalho-externo/api/demandas/$codigo/acoes/cancelar", ['motivo' => 'x']);
        self::assertResponseStatusCodeSame(404);
        $this->postarJson($client, "/trabalho-externo/api/demandas/$codigo/mensagens", ['texto' => 'olá']);
        self::assertResponseStatusCodeSame(404);
        $client->request('GET', "/trabalho-externo/demandas/$codigo/documentos/$docId");
        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('o mesmo usuário em outro escritório não vê a demanda do primeiro')]
    public function testMesmoUsuarioEmOutroEscritorio(): void
    {
        $client = static::createClient();
        $this->instalarCsrf($client);
        $a = $this->criarTenant('A');
        $b = $this->criarTenant('B');
        $user = $this->criarUsuario($a);
        $this->vincular($user, $b);

        $this->logarComTenant($client, $user, $a);
        $codigo = $this->solicitar($client, self::solicitacaoValida())['codigo'];

        $this->logarComTenant($client, $user, $b);
        $client->request('GET', "/trabalho-externo/api/demandas/$codigo");
        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('o Solicitante não executa ação da Central, e só o Master configura (403)')]
    public function testPerfilErradoEh403(): void
    {
        $client = static::createClient();
        $this->instalarCsrf($client);
        $escritorio = $this->criarTenant();
        $solicitante = $this->criarUsuario($escritorio);

        $this->logarComTenant($client, $solicitante, $escritorio);
        $codigo = $this->solicitar($client, self::solicitacaoValida())['codigo'];

        $this->postarJson($client, "/trabalho-externo/api/demandas/$codigo/acoes/analise");
        self::assertResponseStatusCodeSame(403);
        $this->postarJson($client, '/trabalho-externo/api/configuracao', ['visibilidade' => 'oculta']);
        self::assertResponseStatusCodeSame(403);
        $this->postarJson($client, "/trabalho-externo/api/demandas/$codigo/propostas", ['valor' => '10']);
        self::assertResponseStatusCodeSame(403);
    }

    #[TestDox('sem o token CSRF a escrita é recusada (403) e nada é gravado')]
    public function testCsrf(): void
    {
        $client = static::createClient();
        $this->instalarCsrf($client);
        $escritorio = $this->criarTenant();
        $solicitante = $this->criarUsuario($escritorio);
        $this->logarComTenant($client, $solicitante, $escritorio);

        $client->request('POST', '/trabalho-externo/api/solicitacoes', ['dados' => json_encode(self::solicitacaoValida()), 'enviar' => '1'], [], ['HTTP_X_CSRF_TOKEN' => 'errado']);
        self::assertResponseStatusCodeSame(403);

        $client->request('GET', '/trabalho-externo/api/painel');
        self::assertSame([], $this->json($client)['painel']['demandas']);
    }

    #[TestDox('rascunho: sem obrigatórios, só o escritório vê; o envio cobra os obrigatórios')]
    public function testRascunho(): void
    {
        $client = static::createClient();
        $this->instalarCsrf($client);
        $escritorio = $this->criarTenant();
        $solicitante = $this->criarUsuario($escritorio);
        $master = $this->criarMaster($escritorio);

        $this->logarComTenant($client, $solicitante, $escritorio);
        $codigo = $this->solicitar($client, ['descricao' => 'Só um começo'], false)['codigo'];
        self::assertResponseIsSuccessful();

        $this->solicitar($client, ['descricao' => 'Só um começo'], true, [], $codigo);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Preencha os campos obrigatórios marcados com *.', $this->json($client)['mensagem']);

        $this->logarComTenant($client, $master, $escritorio);
        $client->request('GET', "/trabalho-externo/api/demandas/$codigo");
        self::assertResponseStatusCodeSame(404, 'rascunho não sai do escritório');

        $this->logarComTenant($client, $solicitante, $escritorio);
        $this->solicitar($client, self::solicitacaoValida(), true, [], $codigo);
        self::assertResponseIsSuccessful();
        self::assertSame($codigo, $this->json($client)['codigo'], 'o envio usa o mesmo rascunho');

        $this->postarJson($client, "/trabalho-externo/api/demandas/$codigo/acoes/excluirRasc");
        self::assertResponseStatusCodeSame(409, 'enviada, não é mais rascunho');
    }

    #[TestDox('excluir rascunho apaga a demanda e, depois do COMMIT, o arquivo dela')]
    public function testExcluirRascunhoComDocumento(): void
    {
        $client = static::createClient();
        $this->instalarCsrf($client);
        $escritorio = $this->criarTenant();
        $solicitante = $this->criarUsuario($escritorio);
        $this->logarComTenant($client, $solicitante, $escritorio);

        $arquivo = $this->arquivoTemporario('rascunho.pdf', '%PDF-1.4 rascunho');
        $codigo = $this->solicitar($client, ['descricao' => 'esboço'], false, ['documentos' => [$arquivo]])['codigo'];
        $nome = (string) $this->em()->getConnection()->fetchOne('SELECT nome_armazenado FROM trabalho_externo_documento ORDER BY id DESC LIMIT 1');
        $caminho = static::getContainer()->getParameter('trabalho_externo_uploads_dir') . '/' . $nome;
        self::assertFileExists($caminho);

        $resposta = $this->postarJson($client, "/trabalho-externo/api/demandas/$codigo/acoes/excluirRasc");
        self::assertResponseIsSuccessful();
        self::assertNull($resposta['demanda']);
        self::assertFileDoesNotExist($caminho);
        $client->request('GET', "/trabalho-externo/api/demandas/$codigo");
        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('central suspensa com "aceitar só rascunhos": o envio é recusado, o rascunho passa')]
    public function testCentralSuspensa(): void
    {
        $client = static::createClient();
        $this->instalarCsrf($client);
        $escritorio = $this->criarTenant();
        $master = $this->criarMaster($escritorio);
        $solicitante = $this->criarUsuario($escritorio);

        $this->logarComTenant($client, $master, $escritorio);
        $this->postarJson($client, '/trabalho-externo/api/configuracao', ['disponibilidade' => 'off']);
        self::assertResponseIsSuccessful();

        $this->logarComTenant($client, $solicitante, $escritorio);
        $this->solicitar($client, self::solicitacaoValida());
        self::assertResponseStatusCodeSame(422);
        $this->solicitar($client, self::solicitacaoValida(), false);
        self::assertResponseIsSuccessful();
    }

    #[TestDox('aba só para a equipe: o Solicitante vê o bloqueio e não lista nada')]
    public function testVisibilidadeInterna(): void
    {
        $client = static::createClient();
        $this->instalarCsrf($client);
        $escritorio = $this->criarTenant();
        $master = $this->criarMaster($escritorio);
        $solicitante = $this->criarUsuario($escritorio);

        $this->logarComTenant($client, $solicitante, $escritorio);
        $codigo = $this->solicitar($client, self::solicitacaoValida())['codigo'];

        $this->logarComTenant($client, $master, $escritorio);
        $this->postarJson($client, '/trabalho-externo/api/configuracao', ['visibilidade' => 'interno']);

        $this->logarComTenant($client, $solicitante, $escritorio);
        $client->request('GET', '/trabalho-externo/api/painel');
        $painel = $this->json($client)['painel'];
        self::assertSame('Acesso restrito', $painel['bloqueio']['titulo']);
        self::assertSame([], $painel['demandas']);
        $client->request('GET', "/trabalho-externo/api/demandas/$codigo");
        self::assertResponseStatusCodeSame(404);

        $this->solicitar($client, self::solicitacaoValida());
        self::assertResponseStatusCodeSame(403, 'com a aba só para a equipe, o Solicitante não envia pelo servidor');
        $this->solicitar($client, self::solicitacaoValida(), false);
        self::assertResponseStatusCodeSame(403, 'nem salva rascunho');
    }

    #[TestDox('Equipe: colaborador da sede vê a Central, mas só muda a situação se o Master liberar')]
    public function testEquipe(): void
    {
        $client = static::createClient();
        $this->instalarCsrf($client);
        $sede = $this->criarTenant('Sede');
        $escritorio = $this->criarTenant('Cliente');
        $master = $this->criarMaster($sede);
        $equipe = $this->criarUsuario($sede, nome: 'Colaborador');
        $solicitante = $this->criarUsuario($escritorio);

        $this->logarComTenant($client, $solicitante, $escritorio);
        $codigo = $this->solicitar($client, self::solicitacaoValida())['codigo'];

        // Sem sede definida, o colaborador do escritório da Central é só mais um Solicitante.
        $this->logarComTenant($client, $equipe, $sede);
        $client->request('GET', '/trabalho-externo/api/painel');
        self::assertSame('solicitante', $this->json($client)['painel']['perfil']['chave']);

        $this->definirSede($sede);
        $client->request('GET', '/trabalho-externo/api/painel');
        $painel = $this->json($client)['painel'];
        self::assertSame('equipe', $painel['perfil']['chave']);
        self::assertSame([$codigo], array_column($painel['demandas'], 'codigo'));
        self::assertSame([], $painel['demandas'][0]['botoes'], 'por padrão a Equipe não altera a situação');
        self::assertArrayNotHasKey('equipe', $painel['configuracao'], 'só o Master vê a configuração completa');

        $this->postarJson($client, "/trabalho-externo/api/demandas/$codigo/acoes/analise");
        self::assertResponseStatusCodeSame(403);

        $this->logarComTenant($client, $master, $sede);
        $this->postarJson($client, '/trabalho-externo/api/configuracao', ['equipe' => ['situacao' => true]]);

        $this->logarComTenant($client, $equipe, $sede);
        $this->postarJson($client, "/trabalho-externo/api/demandas/$codigo/acoes/analise");
        self::assertResponseIsSuccessful();
        $this->postarJson($client, "/trabalho-externo/api/demandas/$codigo/propostas", ['valor' => '100']);
        self::assertResponseStatusCodeSame(403, 'proposta continua dependendo de "Elaborar e enviar propostas"');
    }

    #[TestDox('chat: nota interna some para o Solicitante, nome de quem atende vira "Central BlueJus" e as não lidas zeram ao abrir')]
    public function testChat(): void
    {
        $client = static::createClient();
        $this->instalarCsrf($client);
        $escritorio = $this->criarTenant();
        $master = $this->criarMaster($escritorio);
        $solicitante = $this->criarUsuario($escritorio);

        $this->logarComTenant($client, $solicitante, $escritorio);
        $codigo = $this->solicitar($client, self::solicitacaoValida())['codigo'];
        $this->postarJson($client, "/trabalho-externo/api/demandas/$codigo/mensagens", ['texto' => 'segredo', 'interna' => true]);
        self::assertResponseStatusCodeSame(403, 'nota interna é só da Central');

        $this->logarComTenant($client, $master, $escritorio);
        $this->postarJson($client, "/trabalho-externo/api/demandas/$codigo/mensagens", ['texto' => 'Combinar com o cartório', 'interna' => true]);
        $this->postarJson($client, "/trabalho-externo/api/demandas/$codigo/mensagens", ['texto' => 'Recebemos o pedido']);
        self::assertResponseIsSuccessful();

        $this->logarComTenant($client, $solicitante, $escritorio);
        $client->request('GET', '/trabalho-externo/api/painel');
        $painel = $this->json($client)['painel'];
        self::assertSame(1, $painel['demandas'][0]['naoLidas'], 'a nota interna não conta para o Solicitante');
        self::assertSame('Central BlueJus · ' . $codigo, $painel['avisoDeMensagem']['titulo']);

        $client->request('GET', "/trabalho-externo/api/demandas/$codigo");
        $mensagens = $this->json($client)['demanda']['mensagens'];
        self::assertSame(['Recebemos o pedido'], array_column($mensagens, 'txt'));
        self::assertSame('Central BlueJus', $mensagens[0]['de']);

        $this->postarJson($client, "/trabalho-externo/api/demandas/$codigo/lida");
        $client->request('GET', '/trabalho-externo/api/painel');
        self::assertSame(0, $this->json($client)['painel']['demandas'][0]['naoLidas']);
    }

    #[TestDox('execução: checklist e andamento só em execução e deixam registro no chat')]
    public function testExecucao(): void
    {
        $client = static::createClient();
        $this->instalarCsrf($client);
        $escritorio = $this->criarTenant();
        $master = $this->criarMaster($escritorio);
        $solicitante = $this->criarUsuario($escritorio);

        $this->logarComTenant($client, $solicitante, $escritorio);
        $codigo = $this->solicitar($client, self::solicitacaoValida())['codigo'];

        $this->logarComTenant($client, $master, $escritorio);
        $this->postarJson($client, "/trabalho-externo/api/demandas/$codigo/checklist/0");
        self::assertResponseStatusCodeSame(409);

        $this->postarJson($client, "/trabalho-externo/api/demandas/$codigo/acoes/analise");
        $this->postarJson($client, "/trabalho-externo/api/demandas/$codigo/propostas", ['valor' => '200']);
        $this->logarComTenant($client, $solicitante, $escritorio);
        $this->postarJson($client, "/trabalho-externo/api/demandas/$codigo/acoes/aceitar");
        $this->logarComTenant($client, $master, $escritorio);
        $this->postarJson($client, "/trabalho-externo/api/demandas/$codigo/acoes/iniciar");

        $d = $this->postarJson($client, "/trabalho-externo/api/demandas/$codigo/checklist/0")['demanda'];
        self::assertTrue($d['checklist'][0]['ok']);
        $d = $this->postarJson($client, "/trabalho-externo/api/demandas/$codigo/andamentos", ['texto' => 'Cheguei ao fórum'])['demanda'];
        self::assertSame(['Etapa concluída: Conferir documentos e instruções', 'Cheguei ao fórum'], array_slice(array_column($d['mensagens'], 'txt'), -2));

        $this->logarComTenant($client, $solicitante, $escritorio);
        $this->postarJson($client, "/trabalho-externo/api/demandas/$codigo/andamentos", ['texto' => 'x']);
        self::assertResponseStatusCodeSame(403);
    }

    private function vincular(\App\Entity\Auth\User $user, \App\Entity\Tenant\Tenant $tenant): void
    {
        $outro = $this->criarUsuario($tenant);
        $role = $this->em()->getRepository(\App\Entity\Auth\UserTenant::class)->findOneBy(['user' => $outro])->getTenantRole();
        $ut = new \App\Entity\Auth\UserTenant($user, $tenant);
        $ut->setTenantRole($role);
        $this->em()->persist($ut);
        $this->em()->flush();
    }

    private function arquivoTemporario(string $nome, string $conteudo): UploadedFile
    {
        $caminho = tempnam(sys_get_temp_dir(), 'te');
        file_put_contents($caminho, $conteudo);

        return new UploadedFile($caminho, $nome, 'application/pdf', null, true);
    }
}
