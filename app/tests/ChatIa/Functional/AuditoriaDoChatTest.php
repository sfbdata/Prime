<?php

declare(strict_types=1);

namespace App\Tests\ChatIa\Functional;

use App\ChatIa\Entity\AnexoDeChat;
use App\ChatIa\Entity\ConversaDeChat;
use App\Entity\Audit\AuditLog;
use App\Entity\Tenant\Tenant;
use App\Service\Audit\AuditLogSubscriber;
use App\Tests\Central\Support\CentralHttpTrait;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Inteligencia\Support\CriaFixturesInteligenciaTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * A conversa e o anexo do Chat I.A são auditados (quem criou, renomeou, fixou, trocou o modelo, excluiu, anexou e
 * removeu), mas o título, o nome do arquivo e o texto das mensagens NUNCA entram no audit_log.
 */
#[CoversClass(AuditLogSubscriber::class)]
final class AuditoriaDoChatTest extends JusPrimeWebTestCase
{
    use CriaFixturesInteligenciaTrait;
    use CentralHttpTrait;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';
    private const SEGREDO_TITULO = 'Divorcio litigioso de Maria Aparecida';
    private const SEGREDO_PERGUNTA = 'CPF 123.456.789-00 do cliente Joao';
    private const SEGREDO_RESPOSTA = 'Resposta reservada sobre o cliente';
    private const SEGREDO_ARQUIVO = 'rg-do-cliente-secreto.png';

    /** @return list<AuditLog> */
    private function logs(Tenant $tenant, ?string $classe = null): array
    {
        $this->em()->clear();
        $criterio = ['tenantId' => $tenant->getId()];
        if ($classe !== null) {
            $criterio['entityClass'] = $classe;
        }

        return $this->em()->getRepository(AuditLog::class)->findBy($criterio, ['id' => 'ASC']);
    }

    private function textoDosLogs(Tenant $tenant): string
    {
        return implode("\n", array_map(
            static fn (AuditLog $l): string => (string) json_encode([$l->getChanges(), $l->getEntityId()], JSON_UNESCAPED_UNICODE),
            $this->logs($tenant),
        ));
    }

    private function subir(KernelBrowser $client, int $conversaId): int
    {
        $arquivo = tempnam(sys_get_temp_dir(), 'anx');
        file_put_contents($arquivo, base64_decode(self::PNG));
        $client->request('POST', '/chat-ia/anexos', ['conversaId' => $conversaId], ['arquivo' => new UploadedFile($arquivo, self::SEGREDO_ARQUIVO, 'image/png', null, true)], ['HTTP_X-CSRF-Token' => 'TOKEN_ajax']);
        unlink($arquivo);
        self::assertResponseStatusCodeSame(201);

        return (int) $this->json($client)['id'];
    }

    #[TestDox('Criar, renomear, fixar, trocar modelo, anexar, remover e excluir ficam na trilha; título, nome do arquivo e texto não')]
    public function testTrilhaSemConteudo(): void
    {
        $client = $this->cliente();
        [$admin, $tenant] = $this->criarAdmin();
        $this->ligarIaNoTenant($tenant, $admin);
        $ana = $this->criarUsuarioComPermissoes($tenant, ['modules.inteligencia.view'], 'ana');
        $this->logarComTenant($client, $ana, $tenant);

        $this->post($client, '/chat-ia/conversas', []);
        $id = (int) $this->json($client)['id'];
        $client->request('PATCH', "/chat-ia/conversas/$id", [], [], self::CSRF, json_encode(['titulo' => self::SEGREDO_TITULO, 'fixada' => true]));
        self::assertResponseIsSuccessful();
        $client->request('PATCH', "/chat-ia/conversas/$id", [], [], self::CSRF, json_encode(['modelo' => 'claude-sonnet-5-5']));
        self::assertResponseIsSuccessful();
        $this->provedorFalso()->transmitirTrechos([self::SEGREDO_RESPOSTA], 5, 5);
        $this->provedorFalso()->responderCom('Titulo automatico secreto');
        $this->post($client, "/chat-ia/conversas/$id/mensagens", ['texto' => self::SEGREDO_PERGUNTA]);
        $anexoSolto = $this->subir($client, $id);
        $client->request('DELETE', '/chat-ia/anexos/' . $anexoSolto, [], [], self::CSRF);
        self::assertResponseIsSuccessful();
        $client->request('DELETE', "/chat-ia/conversas/$id", [], [], self::CSRF);
        self::assertResponseIsSuccessful();

        $conversas = $this->logs($tenant, ConversaDeChat::class);
        $acoes = array_map(static fn (AuditLog $l): string => $l->getAction(), $conversas);
        self::assertSame('create', $acoes[0]);
        self::assertSame('delete', end($acoes));
        self::assertCount(2, array_filter($acoes, static fn (string $a): bool => $a === 'update'), 'renomear e fixar, trocar o modelo (título automático e atividade não geram linha)');
        foreach ($conversas as $log) {
            self::assertSame($ana->getId(), $log->getActorUserId(), 'o ator é quem usou o chat');
            if ($log->getAction() !== 'create') {
                // No create o id ainda não existe no onFlush (vale para toda entidade auditada).
                self::assertSame((string) $id, $log->getEntityId());
            }
        }
        $anexos = array_map(static fn (AuditLog $l): string => $l->getAction(), $this->logs($tenant, AnexoDeChat::class));
        self::assertSame(['create', 'delete'], $anexos, 'anexou e removeu');

        $todo = $this->textoDosLogs($tenant);
        foreach ([self::SEGREDO_TITULO, self::SEGREDO_PERGUNTA, self::SEGREDO_RESPOSTA, self::SEGREDO_ARQUIVO, 'Titulo automatico secreto', 'Divorcio', 'Maria'] as $segredo) {
            self::assertStringNotContainsString($segredo, $todo, "o audit_log não pode conter: $segredo");
        }
        self::assertStringContainsString('tituloManual', $todo);
        self::assertStringContainsString('fixada', $todo);
        self::assertStringContainsString('modelo', $todo);
        self::assertSame([], $this->logs($tenant, \App\ChatIa\Entity\MensagemDeChat::class), 'mensagem nunca é auditada');
    }

    #[TestDox('A trilha do chat de um escritório não aparece no outro')]
    public function testIsolamentoPorEscritorio(): void
    {
        $client = $this->cliente();
        [$admin, $tenant] = $this->criarAdmin();
        $this->ligarIaNoTenant($tenant, $admin);
        $ana = $this->criarUsuarioComPermissoes($tenant, ['modules.inteligencia.view'], 'ana');
        [$admin2, $tenant2] = $this->criarAdmin();
        $this->ligarIaNoTenant($tenant2, $admin2);
        $this->logarComTenant($client, $ana, $tenant);
        $this->post($client, '/chat-ia/conversas', []);
        $id = (string) $this->json($client)['id'];

        self::assertNotSame([], $this->logs($tenant, ConversaDeChat::class));
        self::assertSame([], $this->logs($tenant2, ConversaDeChat::class));
        foreach ($this->logs($tenant) as $log) {
            self::assertSame($tenant->getId(), $log->getTenantId());
        }
        self::assertNotSame('', $id);
    }
}
