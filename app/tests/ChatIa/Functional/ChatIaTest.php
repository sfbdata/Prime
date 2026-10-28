<?php

declare(strict_types=1);

namespace App\Tests\ChatIa\Functional;

use App\ChatIa\Controller\ChatIaAnexosController;
use App\ChatIa\Controller\ChatIaConversasController;
use App\ChatIa\Controller\ChatIaMensagensController;
use App\Entity\Auth\User;
use App\Entity\Permission\ResourceAccess;
use App\Entity\Tenant\Tenant;
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
        self::assertSame([], $this->json($client)['conversas'] ?? $this->json($client));

        // Outro escritório.
        [$admin2, $tenant2] = $this->criarAdmin();
        $this->ligarIaNoTenant($tenant2, $admin2);
        $carla = $this->criarUsuarioComPermissoes($tenant2, ['modules.inteligencia.view'], 'carla');
        $this->logarComTenant($client, $carla, $tenant2);
        $this->afirmar404($client, $id);
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

    #[TestDox('Anexo: sobe para a conversa da pessoa; outra pessoa não anexa na conversa alheia (404) nem usa o anexo alheio')]
    public function testAnexoAlheio(): void
    {
        [$client, $tenant, $ana, $bruno] = $this->cenario();
        $this->logarComTenant($client, $ana, $tenant);
        $id = $this->novaConversa($client);
        $arquivo = tempnam(sys_get_temp_dir(), 'png');
        file_put_contents($arquivo, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));
        $envio = fn () => $client->request('POST', '/chat-ia/anexos', ['conversaId' => $id], ['arquivo' => new UploadedFile($arquivo, 'foto.png', 'image/png', null, true)], ['HTTP_X-CSRF-Token' => 'TOKEN_ajax']);

        $envio();
        self::assertResponseStatusCodeSame(201);
        $anexoId = (int) $this->json($client)['id'];

        $this->logarComTenant($client, $bruno, $tenant);
        $envio();
        self::assertResponseStatusCodeSame(404);
        $idBruno = $this->novaConversa($client);
        $this->provedorFalso()->transmitirTrechos(['ok']);
        $this->enviarTexto($client, $idBruno, ['texto' => 'Veja', 'anexoIds' => [$anexoId]]);
        self::assertContains($client->getResponse()->getStatusCode(), [404, 422]);
        self::assertSame(0, $this->contarNoBanco('SELECT count(*) FROM chat_ia_mensagem'));
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
