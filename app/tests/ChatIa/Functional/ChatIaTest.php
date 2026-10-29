<?php

declare(strict_types=1);

namespace App\Tests\ChatIa\Functional;

use App\ChatIa\Controller\ChatIaAnexosController;
use App\ChatIa\Controller\ChatIaConversasController;
use App\ChatIa\Controller\ChatIaMensagensController;
use App\ChatIa\Entity\ConversaDeChat;
use App\Entity\Auth\User;
use App\Inteligencia\Enum\ModeloDeIa;
use App\Entity\Permission\ResourceAccess;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\CadeadoDaPasta;
use App\Pasta\Entity\ModoDoCadeado;
use App\Pasta\Entity\Pasta;
use App\Tests\Auth\Doubles\RateLimiterFactoryEspiao;
use App\Tests\Central\Support\CentralHttpTrait;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Inteligencia\Support\CriaFixturesInteligenciaTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * O Chat I.A em página inteira pela HTTP: isolamento entre escritórios e entre pessoas, sem chave (503), CSRF, SSE com o
 * provedor falso, parar (parcial interrompido), pasta (aceite, segredo), teto diário e anexo alheio. Nenhuma chamada real.
 */
#[CoversClass(ChatIaConversasController::class)]
#[CoversClass(ChatIaMensagensController::class)]
#[CoversClass(ChatIaAnexosController::class)]
final class ChatIaTest extends JusPrimeWebTestCase
{
    use CriaFixturesInteligenciaTrait;
    use CentralHttpTrait;

    /** @return array{0: KernelBrowser, 1: Tenant, 2: User, 3: User} */
    private function cenario(): array
    {
        $client = $this->cliente();
        [$admin, $tenant] = $this->criarAdmin();
        $this->ligarIaNoTenant($tenant, $admin);
        $ana = $this->criarUsuarioComPermissoes($tenant, ['modules.inteligencia.view'], 'ana');
        $bruno = $this->criarUsuarioComPermissoes($tenant, ['modules.inteligencia.view'], 'bruno');

        return [$client, $tenant, $ana, $bruno];
    }

    private function novaConversa(KernelBrowser $client): int
    {
        $this->post($client, '/chat-ia/conversas', []);
        self::assertResponseStatusCodeSame(201);

        return (int) $this->json($client)['id'];
    }

    /** @param array<string, mixed> $corpo */
    private function enviarTexto(KernelBrowser $client, int $id, array $corpo): string
    {
        $this->post($client, "/chat-ia/conversas/$id/mensagens", $corpo);

        // Resposta em fluxo: o conteúdo escrito fica na resposta interna do navegador.
        return $client->getInternalResponse()->getContent();
    }

    private function darAcessoDeLeitura(User $user, Tenant $tenant, Pasta $pasta): void
    {
        $acesso = new ResourceAccess();
        $acesso->setUser($user);
        $acesso->setTenant($tenant);
        $acesso->setResourceType(ResourceAccess::RESOURCE_PASTA);
        $acesso->setResourceId((int) $pasta->getId());
        $acesso->setCanView(true);
        $this->em()->persist($acesso);
        $this->em()->flush();
    }

    /** @return list<array{evento: string, dados: array<string, mixed>}> */
    private function eventos(string $corpo): array
    {
        $eventos = [];
        foreach (array_filter(explode("\n\n", $corpo)) as $bloco) {
            preg_match('/^event: (\w+)\ndata: (.*)$/s', trim($bloco), $m);
            $eventos[] = ['evento' => $m[1], 'dados' => json_decode($m[2], true, flags: JSON_THROW_ON_ERROR)];
        }

        return $eventos;
    }

    #[TestDox('Resposta em SSE com o falso: cabeçalhos, delta, fim com tokens reais, mensagens gravadas e título automático')]
    public function testSse(): void
    {
        [$client, $tenant, $ana] = $this->cenario();
        $this->logarComTenant($client, $ana, $tenant);
        $id = $this->novaConversa($client);
        $this->provedorFalso()->transmitirTrechos(['Olá', ', doutor.'], 31, 9);
        $this->provedorFalso()->responderCom('Cumprimento');

        $corpo = $this->enviarTexto($client, $id, ['texto' => 'Oi, tudo bem?']);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('text/event-stream', (string) $client->getResponse()->headers->get('Content-Type'));
        self::assertSame('no', $client->getResponse()->headers->get('X-Accel-Buffering'));
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
        $eventos = $this->eventos($corpo);
        self::assertSame(['delta', 'delta', 'fim'], array_column($eventos, 'evento'));
        self::assertSame('Olá', $eventos[0]['dados']['t']);
        self::assertSame(31, $eventos[2]['dados']['tokensEntrada']);
        self::assertSame(9, $eventos[2]['dados']['tokensSaida']);
        self::assertSame('Cumprimento', $eventos[2]['dados']['titulo']);

        $this->em()->clear();
        $this->post($client, '/chat-ia/conversas/' . $id, []);
        $client->request('GET', "/chat-ia/conversas/$id");
        $conversa = $this->json($client);
        self::assertSame(['usuario', 'assistente'], array_column($conversa['mensagens'], 'papel'));
        self::assertSame('Olá, doutor.', $conversa['mensagens'][1]['conteudo']);
        self::assertFalse($conversa['mensagens'][1]['interrompida']);
        self::assertSame('Cumprimento', $conversa['titulo']);
    }

    #[TestDox('Falha no meio do fluxo: o parcial fica gravado como interrompido e o cliente recebe evento erro')]
    public function testParcialInterrompido(): void
    {
        [$client, $tenant, $ana] = $this->cenario();
        $this->logarComTenant($client, $ana, $tenant);
        $id = $this->novaConversa($client);
        $this->provedorFalso()->transmitirTrechosEFalhar(['Começo de ']);

        $corpo = $this->enviarTexto($client, $id, ['texto' => 'Pergunta']);

        $eventos = $this->eventos($corpo);
        self::assertSame('erro', end($eventos)['evento']);
        $client->request('GET', "/chat-ia/conversas/$id");
        $mensagens = $this->json($client)['mensagens'];
        self::assertSame('Começo de ', $mensagens[1]['conteudo']);
        self::assertTrue($mensagens[1]['interrompida']);
    }

    #[TestDox('Conversa de outra pessoa do mesmo escritório e de outro escritório: 404 em ver, enviar, alterar e excluir; lista só a própria')]
    public function testIsolamento(): void
    {
        [$client, $tenant, $ana, $bruno] = $this->cenario();
        $this->logarComTenant($client, $ana, $tenant);
        $id = $this->novaConversa($client);

        // Outra pessoa, mesmo escritório.
        $this->logarComTenant($client, $bruno, $tenant);
        $this->afirmar404($client, $id);
        $client->request('GET', '/chat-ia/conversas');
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->json($client));

        // Outro escritório.
        [$admin2, $tenant2] = $this->criarAdmin();
        $this->ligarIaNoTenant($tenant2, $admin2);
        $carla = $this->criarUsuarioComPermissoes($tenant2, ['modules.inteligencia.view'], 'carla');
        $this->logarComTenant($client, $carla, $tenant2);
        $this->afirmar404($client, $id);
        $client->request('GET', '/chat-ia/conversas');
        self::assertSame([], $this->json($client));
        self::assertSame(1, $this->contarNoBanco('SELECT count(*) FROM chat_ia_conversa'));
    }

    private function afirmar404(KernelBrowser $client, int $id): void
    {
        $client->request('GET', "/chat-ia/conversas/$id");
        self::assertResponseStatusCodeSame(404);
        $this->post($client, "/chat-ia/conversas/$id/mensagens", ['texto' => 'x']);
        self::assertResponseStatusCodeSame(404);
        $client->request('PATCH', "/chat-ia/conversas/$id", [], [], self::CSRF, '{"titulo":"x"}');
        self::assertResponseStatusCodeSame(404);
        $client->request('DELETE', "/chat-ia/conversas/$id", [], [], self::CSRF);
        self::assertResponseStatusCodeSame(404);
        self::assertSame(0, $this->contarNoBanco('SELECT count(*) FROM chat_ia_mensagem'));
    }

    #[TestDox('Sem chave de IA configurada: o envio devolve 503 sem_chave e nada é gravado')]
    public function testSemChave(): void
    {
        [$client, $tenant, $ana] = $this->cenario();
        $this->logarComTenant($client, $ana, $tenant);
        $id = $this->novaConversa($client);
        $this->provedorFalso()->desconfigurar();

        $this->enviarTexto($client, $id, ['texto' => 'Oi']);

        self::assertResponseStatusCodeSame(503);
        self::assertSame('sem_chave', $this->json($client)['codigo']);
        self::assertSame(0, $this->contarNoBanco('SELECT count(*) FROM chat_ia_mensagem'));
    }

    #[TestDox('Sem o token CSRF: 403 e nada gravado')]
    public function testSemCsrf(): void
    {
        [$client, $tenant, $ana] = $this->cenario();
        $this->logarComTenant($client, $ana, $tenant);
        $id = $this->novaConversa($client);

        $client->request('POST', "/chat-ia/conversas/$id/mensagens", [], [], ['CONTENT_TYPE' => 'application/json'], '{"texto":"Oi"}');

        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->contarNoBanco('SELECT count(*) FROM chat_ia_mensagem'));
    }

    #[TestDox('Texto vazio ou com mais de 8000 caracteres: 422 texto_invalido, antes do limitador')]
    public function testTextoInvalido(): void
    {
        [$client, $tenant, $ana] = $this->cenario();
        $this->logarComTenant($client, $ana, $tenant);
        $id = $this->novaConversa($client);
        $espiao = new RateLimiterFactoryEspiao();
        static::getContainer()->set('limiter.inteligencia_solicitar', $espiao);

        foreach (['', '   ', str_repeat('a', 8001)] as $texto) {
            $this->enviarTexto($client, $id, ['texto' => $texto]);
            self::assertResponseStatusCodeSame(422);
            self::assertSame('texto_invalido', $this->json($client)['codigo']);
        }
        self::assertSame(0, $this->contarNoBanco('SELECT count(*) FROM chat_ia_mensagem'));
    }

    #[TestDox('Teto diário estourado: 429 teto_diario')]
    public function testTetoDiario(): void
    {
        [$client, $tenant, $ana] = $this->cenario();
        $this->logarComTenant($client, $ana, $tenant);
        $id = $this->novaConversa($client);
        $this->provedorFalso()->transmitirTrechos(['ok'], 150000, 60000);
        $this->provedorFalso()->responderCom('Titulo');
        $this->enviarTexto($client, $id, ['texto' => 'Primeira']);
        self::assertResponseIsSuccessful();

        $this->enviarTexto($client, $id, ['texto' => 'Segunda']);

        self::assertResponseStatusCodeSame(429);
        self::assertSame('teto_diario', $this->json($client)['codigo']);
    }

    #[TestDox('Citar pasta sem o aceite do escritório: 409 sem_aceite; pasta em segredo de justiça: 409 contexto_bloqueado')]
    public function testPasta(): void
    {
        [$client, $tenant, $ana] = $this->cenario();
        [$pasta, $processo] = $this->criarPastaComPublicacao($tenant);
        $this->darAcessoDeLeitura($ana, $tenant, $pasta);
        $this->logarComTenant($client, $ana, $tenant);
        $id = $this->novaConversa($client);

        $processo->setNivelSigilo(1);
        $this->em()->flush();
        $this->enviarTexto($client, $id, ['texto' => 'Resuma', 'pastaId' => (int) $pasta->getId()]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('contexto_bloqueado', $this->json($client)['codigo']);

        $this->em()->getConnection()->executeStatement('UPDATE inteligencia_configuracao SET consentimento_envio_externo_em = NULL WHERE tenant_id = ?', [$tenant->getId()]);
        $this->em()->clear();
        $this->enviarTexto($client, $id, ['texto' => 'Resuma', 'pastaId' => (int) $pasta->getId()]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('sem_aceite', $this->json($client)['codigo']);
        self::assertSame(0, $this->contarNoBanco('SELECT count(*) FROM chat_ia_mensagem'));
    }

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    /** Sobe um arquivo para a conversa; devolve o id do anexo (ou 0 se recusou, com o status na resposta). */
    private function subirAnexo(KernelBrowser $client, int $conversaId, string $bytes, string $nome = 'foto.png', string $mime = 'image/png'): int
    {
        $arquivo = tempnam(sys_get_temp_dir(), 'anx');
        file_put_contents($arquivo, $bytes);
        $client->request('POST', '/chat-ia/anexos', ['conversaId' => $conversaId], ['arquivo' => new UploadedFile($arquivo, $nome, $mime, null, true)], ['HTTP_X-CSRF-Token' => 'TOKEN_ajax']);
        unlink($arquivo);

        return $client->getResponse()->getStatusCode() === 201 ? (int) $this->json($client)['id'] : 0;
    }

    private function png(): string
    {
        return (string) base64_decode(self::PNG);
    }

    private function afirmarAnexoInvalido(KernelBrowser $client): void
    {
        self::assertResponseStatusCodeSame(422);
        self::assertSame('anexo_invalido', $this->json($client)['codigo']);
    }

    #[TestDox('Anexo: outra pessoa não anexa na conversa alheia (404) nem usa o anexo alheio (422 anexo_invalido)')]
    public function testAnexoAlheio(): void
    {
        [$client, $tenant, $ana, $bruno] = $this->cenario();
        $this->logarComTenant($client, $ana, $tenant);
        $idAna = $this->novaConversa($client);
        $anexoId = $this->subirAnexo($client, $idAna, $this->png());
        self::assertResponseStatusCodeSame(201);
        self::assertGreaterThan(0, $anexoId);

        $this->logarComTenant($client, $bruno, $tenant);
        $this->subirAnexo($client, $idAna, $this->png());
        self::assertResponseStatusCodeSame(404);
        $idBruno = $this->novaConversa($client);
        $this->provedorFalso()->transmitirTrechos(['ok']);
        $this->enviarTexto($client, $idBruno, ['texto' => 'Veja', 'anexoIds' => [$anexoId]]);
        $this->afirmarAnexoInvalido($client);
        self::assertSame(0, $this->contarNoBanco('SELECT count(*) FROM chat_ia_mensagem'));
        self::assertFalse($this->provedorFalso()->foiChamado());
    }

    #[TestDox('Anexo de outro escritório: não vale em nenhuma conversa, e nem sobe na conversa dele (404)')]
    public function testAnexoDeOutroEscritorio(): void
    {
        [$client, $tenant, $ana] = $this->cenario();
        $this->logarComTenant($client, $ana, $tenant);
        $idAna = $this->novaConversa($client);
        $anexoId = $this->subirAnexo($client, $idAna, $this->png());

        [$admin2, $tenant2] = $this->criarAdmin();
        $this->ligarIaNoTenant($tenant2, $admin2);
        $carla = $this->criarUsuarioComPermissoes($tenant2, ['modules.inteligencia.view'], 'carla');
        $this->logarComTenant($client, $carla, $tenant2);
        $this->subirAnexo($client, $idAna, $this->png());
        self::assertResponseStatusCodeSame(404);
        $idCarla = $this->novaConversa($client);
        $this->enviarTexto($client, $idCarla, ['texto' => 'Veja', 'anexoIds' => [$anexoId]]);
        $this->afirmarAnexoInvalido($client);
        $client->request('DELETE', '/chat-ia/anexos/' . $anexoId, [], [], self::CSRF);
        $this->afirmarAnexoInvalido($client);
        self::assertSame(1, $this->contarNoBanco('SELECT count(*) FROM chat_ia_anexo'));
    }

    #[TestDox('Anexo da mesma pessoa em OUTRA conversa: 422 anexo_invalido; já amarrado a uma pergunta: 422 também')]
    public function testAnexoDeOutraConversaEJaUsado(): void
    {
        [$client, $tenant, $ana] = $this->cenario();
        $this->logarComTenant($client, $ana, $tenant);
        $a = $this->novaConversa($client);
        $b = $this->novaConversa($client);
        $anexoId = $this->subirAnexo($client, $a, $this->png());

        $this->enviarTexto($client, $b, ['texto' => 'Outra conversa', 'anexoIds' => [$anexoId]]);
        $this->afirmarAnexoInvalido($client);
        self::assertSame(0, $this->contarNoBanco('SELECT count(*) FROM chat_ia_mensagem'));

        $this->provedorFalso()->transmitirTrechos(['ok']);
        $this->provedorFalso()->responderCom('Titulo');
        $this->enviarTexto($client, $a, ['texto' => 'Aqui vale', 'anexoIds' => [$anexoId]]);
        self::assertResponseIsSuccessful();
        self::assertSame(1, $this->contarNoBanco('SELECT count(*) FROM chat_ia_anexo WHERE mensagem_id IS NOT NULL'));

        $this->enviarTexto($client, $a, ['texto' => 'De novo', 'anexoIds' => [$anexoId]]);
        $this->afirmarAnexoInvalido($client);
        $client->request('DELETE', '/chat-ia/anexos/' . $anexoId, [], [], self::CSRF);
        $this->afirmarAnexoInvalido($client);
        self::assertSame(2, $this->contarNoBanco('SELECT count(*) FROM chat_ia_mensagem'));
    }

    #[TestDox('DELETE de anexo alheio (mesmo escritório) é 422 anexo_invalido e o anexo fica; o dono descarta o solto')]
    public function testRemoverAnexoAlheio(): void
    {
        [$client, $tenant, $ana, $bruno] = $this->cenario();
        $this->logarComTenant($client, $ana, $tenant);
        $anexoId = $this->subirAnexo($client, $this->novaConversa($client), $this->png());

        $this->logarComTenant($client, $bruno, $tenant);
        $client->request('DELETE', '/chat-ia/anexos/' . $anexoId, [], [], self::CSRF);
        $this->afirmarAnexoInvalido($client);
        self::assertSame(1, $this->contarNoBanco('SELECT count(*) FROM chat_ia_anexo'));

        $this->logarComTenant($client, $ana, $tenant);
        $client->request('DELETE', '/chat-ia/anexos/' . $anexoId, [], [], self::CSRF);
        self::assertResponseIsSuccessful();
        self::assertSame(0, $this->contarNoBanco('SELECT count(*) FROM chat_ia_anexo'));
    }

    private function docx(string $texto): string
    {
        $caminho = tempnam(sys_get_temp_dir(), 'docx');
        $zip = new \ZipArchive();
        $zip->open($caminho, \ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<Types/>');
        $zip->addFromString('word/document.xml', '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>' . $texto . '</w:t></w:r></w:p></w:body></w:document>');
        $zip->close();
        $bytes = (string) file_get_contents($caminho);
        unlink($caminho);

        return $bytes;
    }

    #[TestDox('DOCX por HTTP: sobe (mime do conteúdo) e o texto vai ao provedor como DADO, não como arquivo')]
    public function testDocxPorHttp(): void
    {
        [$client, $tenant, $ana] = $this->cenario();
        $this->logarComTenant($client, $ana, $tenant);
        $id = $this->novaConversa($client);
        // Com título manual não há a chamada curta do título, e o último pedido do falso é o da resposta.
        $client->request('PATCH', "/chat-ia/conversas/$id", [], [], self::CSRF, '{"titulo":"Contrato"}');

        $anexoId = $this->subirAnexo($client, $id, $this->docx('CLAUSULA-DO-CONTRATO-XYZ'), 'contrato.docx', 'application/octet-stream');

        self::assertResponseStatusCodeSame(201);
        self::assertSame('contrato.docx', $this->json($client)['nome']);
        self::assertStringContainsString('wordprocessingml', $this->json($client)['mime']);
        $this->provedorFalso()->transmitirTrechos(['Li o contrato.']);

        $corpo = $this->enviarTexto($client, $id, ['texto' => 'Resuma', 'anexoIds' => [$anexoId]]);

        self::assertResponseIsSuccessful();
        $eventos = $this->eventos($corpo);
        self::assertSame('fim', end($eventos)['evento']);
        $pedido = $this->provedorFalso()->ultimoPedido();
        self::assertNotNull($pedido);
        self::assertStringContainsString('CLAUSULA-DO-CONTRATO-XYZ', $pedido->sistema . $pedido->textoDoUsuario());
        self::assertSame([], $pedido->anexos, 'DOCX vira texto no servidor, nunca bloco de arquivo');
    }

    #[TestDox('Anexo da pergunta anterior segue no pedido das perguntas seguintes da conversa')]
    public function testAnexoSegueNasPerguntasSeguintes(): void
    {
        [$client, $tenant, $ana] = $this->cenario();
        $this->logarComTenant($client, $ana, $tenant);
        $id = $this->novaConversa($client);
        $anexoId = $this->subirAnexo($client, $id, $this->png());
        $this->provedorFalso()->transmitirTrechos(['Primeira']);
        $this->provedorFalso()->responderCom('Titulo');
        $this->enviarTexto($client, $id, ['texto' => 'Resuma', 'anexoIds' => [$anexoId]]);
        self::assertResponseIsSuccessful();

        $this->provedorFalso()->transmitirTrechos(['Segunda']);
        $this->enviarTexto($client, $id, ['texto' => 'E a data?']);

        self::assertResponseIsSuccessful();
        self::assertCount(1, $this->provedorFalso()->ultimoPedido()?->anexos ?? [], 'o arquivo da 1ª pergunta continua no contexto da 2ª');
    }

    #[TestDox('Regenerar não apaga o custo: a resposta trocada continua somando no teto do dia')]
    public function testRegenerarGuardaOCusto(): void
    {
        [$client, $tenant, $ana] = $this->cenario();
        $this->logarComTenant($client, $ana, $tenant);
        $id = $this->novaConversa($client);
        $this->provedorFalso()->transmitirTrechos(['Primeira']);
        $this->provedorFalso()->responderCom('Titulo');
        $this->enviarTexto($client, $id, ['texto' => 'Pergunta']);
        $antes = $this->contarNoBanco('SELECT COALESCE(SUM(COALESCE(tokens_entrada, 0) + COALESCE(tokens_saida, 0)), 0) FROM chat_ia_mensagem');
        self::assertGreaterThan(0, $antes);

        $this->provedorFalso()->transmitirTrechos(['Outra']);
        $this->enviarTexto($client, $id, ['regenerar' => true]);

        self::assertResponseIsSuccessful();
        self::assertSame(2, $this->contarNoBanco("SELECT count(*) FROM chat_ia_mensagem WHERE papel = 'assistente'"), 'a trocada fica como registro');
        self::assertGreaterThan($antes, $this->contarNoBanco('SELECT COALESCE(SUM(COALESCE(tokens_entrada, 0) + COALESCE(tokens_saida, 0)), 0) FROM chat_ia_mensagem'), 'o custo da trocada soma ao da nova');
        self::assertSame(1, $this->contarNoBanco("SELECT count(*) FROM chat_ia_mensagem WHERE papel = 'assistente' AND NOT substituida"), 'só a nova aparece na conversa');
    }

    #[TestDox('Regenerar ignora o pastaId do corpo: a pasta é a da pergunta guardada')]
    public function testRegenerarIgnoraAPastaDoCorpo(): void
    {
        [$client, $tenant, $ana] = $this->cenario();
        [$pasta] = $this->criarPastaComPublicacao($tenant);
        $this->darAcessoDeLeitura($ana, $tenant, $pasta);
        $this->logarComTenant($client, $ana, $tenant);
        $id = $this->novaConversa($client);
        $this->provedorFalso()->transmitirTrechos(['Primeira']);
        $this->provedorFalso()->responderCom('Titulo');
        $this->enviarTexto($client, $id, ['texto' => 'Pergunta sem pasta']);

        $this->provedorFalso()->transmitirTrechos(['Outra']);
        $this->enviarTexto($client, $id, ['regenerar' => true, 'pastaId' => (int) $pasta->getId()]);

        self::assertResponseIsSuccessful();
        $ultima = $this->provedorFalso()->ultimoPedido()?->mensagens ?? [];
        self::assertStringNotContainsString('<pasta>', end($ultima)['conteudo'] ?? '', 'regenerar não puxa o contexto de uma pasta que a pergunta não tinha');
    }

    #[TestDox('Editar a última pergunta sem reenviar pasta nem anexos MANTÉM os dois; reenviar anexoIds vazio os remove')]
    public function testEditarUltimaMantemPastaEAnexos(): void
    {
        [$client, $tenant, $ana] = $this->cenario();
        [$pasta] = $this->criarPastaComPublicacao($tenant);
        $this->darAcessoDeLeitura($ana, $tenant, $pasta);
        $this->logarComTenant($client, $ana, $tenant);
        $id = $this->novaConversa($client);
        $anexoId = $this->subirAnexo($client, $id, $this->png());
        $this->provedorFalso()->transmitirTrechos(['Primeira']);
        $this->provedorFalso()->responderCom('Titulo');
        $this->enviarTexto($client, $id, ['texto' => 'Pergunta original', 'pastaId' => (int) $pasta->getId(), 'anexoIds' => [$anexoId]]);
        self::assertResponseIsSuccessful();

        $this->provedorFalso()->transmitirTrechos(['Editada']);
        $this->enviarTexto($client, $id, ['texto' => 'Pergunta editada', 'editarUltima' => true]);

        self::assertResponseIsSuccessful();
        $perguntas = $this->em()->getConnection()->fetchAllAssociative("SELECT id, conteudo, pasta_id FROM chat_ia_mensagem WHERE papel = 'usuario'");
        self::assertCount(1, $perguntas);
        self::assertSame('Pergunta editada', $perguntas[0]['conteudo']);
        self::assertSame((int) $pasta->getId(), (int) $perguntas[0]['pasta_id'], 'a pasta ficou');
        self::assertSame([['mensagem_id' => $perguntas[0]['id'], 'id' => $anexoId]], $this->em()->getConnection()->fetchAllAssociative('SELECT mensagem_id, id FROM chat_ia_anexo'), 'o anexo ficou, amarrado à pergunta nova');
        self::assertCount(1, $this->provedorFalso()->ultimoPedido()?->anexos ?? [], 'o provedor recebeu o anexo mantido');
        self::assertSame(1, $this->contarNoBanco("SELECT count(*) FROM chat_ia_mensagem WHERE papel = 'assistente' AND NOT substituida"));
        self::assertSame(1, $this->contarNoBanco("SELECT count(*) FROM chat_ia_mensagem WHERE papel = 'assistente' AND substituida AND conteudo = '' AND modelo IS NOT NULL AND tokens_saida IS NOT NULL"), 'a resposta trocada fica só como registro de custo');

        $this->provedorFalso()->transmitirTrechos(['Sem anexo']);
        $this->enviarTexto($client, $id, ['texto' => 'Sem o arquivo', 'editarUltima' => true, 'anexoIds' => []]);

        self::assertResponseIsSuccessful();
        self::assertSame(0, $this->contarNoBanco('SELECT count(*) FROM chat_ia_anexo'), 'anexoIds vazio explícito remove os antigos');
        self::assertSame((int) $pasta->getId(), (int) $this->em()->getConnection()->fetchOne("SELECT pasta_id FROM chat_ia_mensagem WHERE papel = 'usuario'"), 'a pasta continua');
    }

    #[TestDox('Regenerar mantém pasta e anexos e SUBSTITUI a última resposta (sem duplicar a pergunta)')]
    public function testRegenerar(): void
    {
        [$client, $tenant, $ana] = $this->cenario();
        [$pasta] = $this->criarPastaComPublicacao($tenant);
        $this->darAcessoDeLeitura($ana, $tenant, $pasta);
        $this->logarComTenant($client, $ana, $tenant);
        $id = $this->novaConversa($client);
        $anexoId = $this->subirAnexo($client, $id, $this->png());
        $this->provedorFalso()->transmitirTrechos(['Resposta velha']);
        $this->provedorFalso()->responderCom('Titulo');
        $this->enviarTexto($client, $id, ['texto' => 'Pergunta', 'pastaId' => (int) $pasta->getId(), 'anexoIds' => [$anexoId]]);

        $this->provedorFalso()->transmitirTrechos(['Resposta nova']);
        $this->enviarTexto($client, $id, ['regenerar' => true]);

        self::assertResponseIsSuccessful();
        $client->request('GET', "/chat-ia/conversas/$id");
        $mensagens = $this->json($client)['mensagens'];
        self::assertSame(['usuario', 'assistente'], array_column($mensagens, 'papel'));
        self::assertSame('Pergunta', $mensagens[0]['conteudo']);
        self::assertSame('Resposta nova', $mensagens[1]['conteudo']);
        self::assertSame($pasta->getId(), $mensagens[0]['pasta']['id']);
        self::assertSame([$anexoId], array_column($mensagens[0]['anexos'], 'id'));
        self::assertCount(1, $this->provedorFalso()->ultimoPedido()?->anexos ?? []);
    }

    #[TestDox('GET /chat-ia/pastas: só as que a pessoa vê, de seu escritório, fora da lixeira e do cadeado; no máximo 8')]
    public function testPastasCitaveis(): void
    {
        [$client, $tenant, $ana] = $this->cenario();
        $visiveis = [];
        for ($i = 1; $i <= 9; ++$i) {
            $visiveis[] = $this->pastaDeBusca($tenant, 'CITA-' . $i);
            $this->darAcessoDeLeitura($ana, $tenant, $visiveis[$i - 1]);
        }
        $trancada = $this->pastaDeBusca($tenant, 'CITA-TRANCADA');
        $this->darAcessoDeLeitura($ana, $tenant, $trancada);
        $this->em()->persist(new CadeadoDaPasta($tenant, $trancada, ModoDoCadeado::Todos, null, new \DateTimeImmutable()));
        $excluida = $this->pastaDeBusca($tenant, 'CITA-EXCLUIDA');
        $this->darAcessoDeLeitura($ana, $tenant, $excluida);
        $excluida->marcarExcluida($ana, new \DateTimeImmutable());
        [$admin2, $tenant2] = $this->criarAdmin();
        $this->pastaDeBusca($tenant2, 'CITA-OUTRO');
        $this->em()->flush();
        $this->logarComTenant($client, $ana, $tenant);

        $client->request('GET', '/chat-ia/pastas?q=CITA-');
        self::assertResponseIsSuccessful();
        $nups = array_column($this->json($client), 'nup');
        self::assertCount(8, $nups);
        foreach (['CITA-TRANCADA', 'CITA-EXCLUIDA', 'CITA-OUTRO'] as $proibida) {
            self::assertNotContains($proibida, $nups);
        }

        $client->request('GET', '/chat-ia/pastas?q=CITA-TRANCADA');
        self::assertSame([], $this->json($client));
        $client->request('GET', '/chat-ia/pastas?q=CITA-EXCLUIDA');
        self::assertSame([], $this->json($client));
        $client->request('GET', '/chat-ia/pastas?q=CITA-OUTRO');
        self::assertSame([], $this->json($client));
        $client->request('GET', '/chat-ia/pastas?q=');
        self::assertSame([], $this->json($client));
    }

    private function pastaDeBusca(Tenant $tenant, string $nup): Pasta
    {
        $pasta = new Pasta();
        $pasta->setNup($nup);
        $pasta->setTenant($tenant);
        $this->em()->persist($pasta);
        $this->em()->flush();

        return $pasta;
    }

    #[TestDox('Limitador chat_ia_escrita: criar conversa e subir anexo estourados são 429 muitas_solicitacoes e nada é gravado')]
    public function testLimiteDeEscrita(): void
    {
        [$client, $tenant, $ana] = $this->cenario();
        $this->logarComTenant($client, $ana, $tenant);
        $conversa = new ConversaDeChat($tenant, $ana, ModeloDeIa::PADRAO, new \DateTimeImmutable());
        $this->em()->persist($conversa);
        $this->em()->flush();
        $id = (int) $conversa->getId();
        // Antes de qualquer requisição: o serviço do limitador não pode ter sido inicializado.
        $espiao = new RateLimiterFactoryEspiao(false);
        static::getContainer()->set('limiter.chat_ia_escrita', $espiao);

        $this->post($client, '/chat-ia/conversas', []);
        self::assertResponseStatusCodeSame(429);
        self::assertSame('muitas_solicitacoes', $this->json($client)['codigo']);
        $this->subirAnexo($client, $id, $this->png());
        self::assertResponseStatusCodeSame(429);

        self::assertSame([(string) $ana->getId(), (string) $ana->getId()], $espiao->chavesConsumidas);
        self::assertSame(1, $this->contarNoBanco('SELECT count(*) FROM chat_ia_conversa'));
        self::assertSame(0, $this->contarNoBanco('SELECT count(*) FROM chat_ia_anexo'));
    }

    #[TestDox('Teto de conversas vazias: a 21ª criação devolve a vazia mais recente em vez de criar outra')]
    public function testTetoDeConversasVazias(): void
    {
        [$client, $tenant, $ana, $bruno] = $this->cenario();
        $this->logarComTenant($client, $ana, $tenant);
        $ultima = 0;
        for ($i = 0; $i < 20; ++$i) {
            $ultima = $this->novaConversa($client);
        }

        self::assertSame($ultima, $this->novaConversa($client));
        self::assertSame(20, $this->contarNoBanco('SELECT count(*) FROM chat_ia_conversa'));

        $this->logarComTenant($client, $bruno, $tenant);
        $doBruno = $this->novaConversa($client);
        self::assertNotSame($ultima, $doBruno, 'o teto é por pessoa');
        self::assertSame(21, $this->contarNoBanco('SELECT count(*) FROM chat_ia_conversa'));
    }

    #[TestDox('Arquivo que não é PDF, imagem nem DOCX (mesmo com extensão .pdf): 422 anexo_invalido')]
    public function testAnexoPeloConteudo(): void
    {
        [$client, $tenant, $ana] = $this->cenario();
        $this->logarComTenant($client, $ana, $tenant);
        $id = $this->novaConversa($client);
        $arquivo = tempnam(sys_get_temp_dir(), 'txt');
        file_put_contents($arquivo, 'isto é só texto');

        $client->request('POST', '/chat-ia/anexos', ['conversaId' => $id], ['arquivo' => new UploadedFile($arquivo, 'falso.pdf', 'application/pdf', null, true)], ['HTTP_X-CSRF-Token' => 'TOKEN_ajax']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('anexo_invalido', $this->json($client)['codigo']);
        self::assertSame(0, $this->contarNoBanco('SELECT count(*) FROM chat_ia_anexo'));
    }
}
