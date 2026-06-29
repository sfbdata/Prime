<?php

declare(strict_types=1);

namespace App\Tests\Kanban\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Kanban\Controller\KanbanAnexoController;
use App\Kanban\Controller\KanbanChecklistController;
use App\Kanban\Controller\KanbanComentarioController;
use App\Kanban\Controller\KanbanMarcadorController;
use App\Kanban\Entity\KanbanAnexo;
use App\Kanban\Entity\KanbanBoard;
use App\Kanban\Entity\KanbanCard;
use App\Kanban\Entity\KanbanChecklist;
use App\Kanban\Entity\KanbanChecklistItem;
use App\Kanban\Entity\KanbanColuna;
use App\Kanban\Entity\KanbanComentario;
use App\Kanban\Entity\KanbanMarcador;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * M3 — BAC horizontal intra-tenant no Kanban. Os sub-recursos de board (checklist, item, anexo,
 * marcador, comentário) carregados por id checavam o TENANT do board mas NÃO a MEMBERSHIP
 * (temAcesso = criador OU participante). Logo, dentro do mesmo escritório, um NÃO-participante de um
 * board privado acessava/editava/excluía o sub-recurso só sabendo o id. O fix soma temAcesso à guarda.
 *
 * O `estranho` é isSystem (passa canAccessModule('kanban') e até canAdminister) mas NÃO participa do
 * board — exatamente como o board em si já o trata (view/editar/excluir do board usam temAcesso, sem
 * bypass de admin). Para cada gap: estranho → 404; dono (criador) → sucesso (controle positivo).
 *
 * Nota de comportamento (intencional): comentário excluir tinha ramo canAdminister('kanban') no
 * UseCase — um admin do módulo não-participante deixa de moderar comentário em board que não participa
 * (passa a 404 no controller, antes do UseCase). Alinha com o resto do Kanban.
 */
#[CoversClass(KanbanChecklistController::class)]
#[CoversClass(KanbanAnexoController::class)]
#[CoversClass(KanbanMarcadorController::class)]
#[CoversClass(KanbanComentarioController::class)]
final class KanbanMembershipControllerTest extends JusPrimeWebTestCase
{
    /** @var string[] */
    private array $arquivosTemp = [];

    protected function tearDown(): void
    {
        foreach ($this->arquivosTemp as $arquivo) {
            if (is_file($arquivo)) {
                @unlink($arquivo);
            }
        }
        $this->arquivosTemp = [];

        parent::tearDown();
    }

    #[TestDox('checklist excluir: estranho ao board privado leva 404; dono exclui')]
    public function testChecklistExcluir(): void
    {
        [$client, $c] = $this->preparar();
        $url = "/kanban/checklist/{$c['checklistId']}/excluir";
        $token = 'TOKEN_kanban_checklist_excluir_' . $c['checklistId'];

        $this->logar($client, $c['estranho'], $c['tenant']);
        $client->request('POST', $url, ['_token' => $token]);
        self::assertResponseStatusCodeSame(404, 'não-participante não pode excluir checklist de board privado');

        $this->logar($client, $c['dono'], $c['tenant']);
        $client->request('POST', $url, ['_token' => $token]);
        $this->assertSucessoJson($client);
    }

    #[TestDox('checklist adicionarItem: estranho leva 404; dono adiciona')]
    public function testChecklistAdicionarItem(): void
    {
        [$client, $c] = $this->preparar();
        $url = "/kanban/checklist/{$c['checklistId']}/item";

        $this->logar($client, $c['estranho'], $c['tenant']);
        $this->postJson($client, $url, ['texto' => 'Item X'], 'TOKEN_ajax');
        self::assertResponseStatusCodeSame(404, 'não-participante não pode adicionar item em board privado');

        $this->logar($client, $c['dono'], $c['tenant']);
        $this->postJson($client, $url, ['texto' => 'Item X'], 'TOKEN_ajax');
        $this->assertSucessoJson($client);
    }

    #[TestDox('checklist toggleItem: estranho leva 404; dono alterna')]
    public function testChecklistToggleItem(): void
    {
        [$client, $c] = $this->preparar();
        $url = "/kanban/checklist/item/{$c['itemId']}/toggle";

        $this->logar($client, $c['estranho'], $c['tenant']);
        $this->postJson($client, $url, [], 'TOKEN_ajax');
        self::assertResponseStatusCodeSame(404, 'não-participante não pode alternar item em board privado');

        $this->logar($client, $c['dono'], $c['tenant']);
        $this->postJson($client, $url, [], 'TOKEN_ajax');
        $this->assertSucessoJson($client);
    }

    #[TestDox('checklist excluirItem: estranho leva 404; dono exclui')]
    public function testChecklistExcluirItem(): void
    {
        [$client, $c] = $this->preparar();
        $url = "/kanban/checklist/item/{$c['itemId']}/excluir";
        $token = 'TOKEN_kanban_item_excluir_' . $c['itemId'];

        $this->logar($client, $c['estranho'], $c['tenant']);
        $client->request('POST', $url, ['_token' => $token]);
        self::assertResponseStatusCodeSame(404, 'não-participante não pode excluir item de board privado');

        $this->logar($client, $c['dono'], $c['tenant']);
        $client->request('POST', $url, ['_token' => $token]);
        $this->assertSucessoJson($client);
    }

    #[TestDox('anexo servir: estranho leva 404; dono baixa o arquivo (200)')]
    public function testAnexoServir(): void
    {
        [$client, $c] = $this->preparar();
        $url = "/kanban/anexo/{$c['anexoId']}";

        $this->logar($client, $c['estranho'], $c['tenant']);
        $client->request('GET', $url);
        self::assertResponseStatusCodeSame(404, 'não-participante não pode baixar anexo de board privado');

        $this->logar($client, $c['dono'], $c['tenant']);
        $client->request('GET', $url);
        self::assertResponseIsSuccessful('o dono (participante) deveria baixar o anexo');
    }

    #[TestDox('anexo excluir: estranho leva 404; dono exclui')]
    public function testAnexoExcluir(): void
    {
        [$client, $c] = $this->preparar();
        $url = "/kanban/anexo/{$c['anexoId']}/excluir";
        $token = 'TOKEN_kanban_anexo_excluir_' . $c['anexoId'];

        $this->logar($client, $c['estranho'], $c['tenant']);
        $client->request('POST', $url, ['_token' => $token]);
        self::assertResponseStatusCodeSame(404, 'não-participante não pode excluir anexo de board privado');

        $this->logar($client, $c['dono'], $c['tenant']);
        $client->request('POST', $url, ['_token' => $token]);
        $this->assertSucessoJson($client);
    }

    #[TestDox('marcador editar: estranho leva 404; dono edita')]
    public function testMarcadorEditar(): void
    {
        [$client, $c] = $this->preparar();
        $url = "/kanban/marcador/{$c['marcadorId']}";

        $this->logar($client, $c['estranho'], $c['tenant']);
        $this->postJson($client, $url, ['nome' => 'Renomeado', 'cor' => '#00ff00'], 'TOKEN_ajax');
        self::assertResponseStatusCodeSame(404, 'não-participante não pode editar marcador de board privado');

        $this->logar($client, $c['dono'], $c['tenant']);
        $this->postJson($client, $url, ['nome' => 'Renomeado', 'cor' => '#00ff00'], 'TOKEN_ajax');
        $this->assertSucessoJson($client);
    }

    #[TestDox('marcador excluir: estranho leva 404; dono exclui')]
    public function testMarcadorExcluir(): void
    {
        [$client, $c] = $this->preparar();
        $url = "/kanban/marcador/{$c['marcadorId']}/excluir";
        $token = 'TOKEN_kanban_marcador_excluir_' . $c['marcadorId'];

        $this->logar($client, $c['estranho'], $c['tenant']);
        $client->request('POST', $url, ['_token' => $token]);
        self::assertResponseStatusCodeSame(404, 'não-participante não pode excluir marcador de board privado');

        $this->logar($client, $c['dono'], $c['tenant']);
        $client->request('POST', $url, ['_token' => $token]);
        $this->assertSucessoJson($client);
    }

    #[TestDox('comentário editar: estranho leva 404; dono (autor) edita')]
    public function testComentarioEditar(): void
    {
        [$client, $c] = $this->preparar();
        $url = "/kanban/comentario/{$c['comentarioId']}";

        $this->logar($client, $c['estranho'], $c['tenant']);
        $this->postJson($client, $url, ['conteudo' => 'Editado'], 'TOKEN_ajax');
        self::assertResponseStatusCodeSame(404, 'não-participante não pode editar comentário de board privado');

        $this->logar($client, $c['dono'], $c['tenant']);
        $this->postJson($client, $url, ['conteudo' => 'Editado'], 'TOKEN_ajax');
        $this->assertSucessoJson($client);
    }

    #[TestDox('comentário excluir: estranho (admin do módulo, não-participante) leva 404; dono exclui')]
    public function testComentarioExcluir(): void
    {
        [$client, $c] = $this->preparar();
        $url = "/kanban/comentario/{$c['comentarioId']}/excluir";
        $token = 'TOKEN_kanban_comentario_excluir_' . $c['comentarioId'];

        // estranho é isSystem → canAdminister('kanban') = true. Antes do M3, o ramo admin do
        // UseCase o deixava excluir. Agora o temAcesso no controller barra antes (404).
        $this->logar($client, $c['estranho'], $c['tenant']);
        $client->request('POST', $url, ['_token' => $token]);
        self::assertResponseStatusCodeSame(404, 'admin do módulo não-participante não modera comentário de board que não participa');

        $this->logar($client, $c['dono'], $c['tenant']);
        $client->request('POST', $url, ['_token' => $token]);
        $this->assertSucessoJson($client);
    }

    // ----------------------------------------------------------------- helpers

    /** @return array{0: KernelBrowser, 1: array<string,mixed>} */
    private function preparar(): array
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->instalarCsrfStorage();

        $cenario = $this->criarCenario();

        return [$client, $cenario];
    }

    private function logar(KernelBrowser $client, User $user, Tenant $tenant): void
    {
        $this->logarComTenant($client, $user, $tenant);
    }

    /** @param array<string,mixed> $payload */
    private function postJson(KernelBrowser $client, string $url, array $payload, ?string $csrf = null): void
    {
        $server = ['CONTENT_TYPE' => 'application/json'];
        if ($csrf !== null) {
            $server['HTTP_X_CSRF_TOKEN'] = $csrf;
        }

        $client->request('POST', $url, [], [], $server, (string) json_encode($payload));
    }

    private function assertSucessoJson(KernelBrowser $client): void
    {
        self::assertResponseIsSuccessful('a ação do dono (participante) deveria suceder');
        $dados = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($dados);
        self::assertTrue($dados['sucesso'] ?? false, 'a mutação deveria ter sido confirmada (sucesso=true)');
    }

    private function instalarCsrfStorage(): void
    {
        $storage = new class implements ClearableTokenStorageInterface {
            public function getToken(string $tokenId): string { return 'TOKEN_' . $tokenId; }
            public function setToken(string $tokenId, string $token): void {}
            public function removeToken(string $tokenId): ?string { return null; }
            public function hasToken(string $tokenId): bool { return true; }
            public function clear(): void {}
        };

        static::getContainer()->set('security.csrf.token_storage', $storage);
    }

    /** @return array<string,mixed> */
    private function criarCenario(): array
    {
        $em     = static::getContainer()->get(EntityManagerInterface::class);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $tenant = new Tenant();
        $tenant->setName('Tenant KANBAN M3 ' . uniqid());
        $em->persist($tenant);

        $dono     = $this->criarUsuarioIsSystem($em, $hasher, $tenant, 'dono');
        $estranho = $this->criarUsuarioIsSystem($em, $hasher, $tenant, 'estranho');

        // Board PRIVADO: dono é o criador (temAcesso); estranho NÃO é participante.
        $board = new KanbanBoard('Mural ' . uniqid(), $tenant, $dono);
        $em->persist($board);

        $coluna = new KanbanColuna('A Fazer', KanbanColuna::TIPO_A_FAZER, 0, $board);
        $em->persist($coluna);

        $card = new KanbanCard('Card ' . uniqid(), $coluna, $board, $dono);
        $em->persist($card);

        $checklist = new KanbanChecklist('Checklist', $card);
        $em->persist($checklist);

        $item = new KanbanChecklistItem('Item', $checklist);
        $em->persist($item);

        $marcador = new KanbanMarcador('Marcador', '#3b82f6', $board);
        $em->persist($marcador);

        $comentario = new KanbanComentario('Comentário', $card, $dono);
        $em->persist($comentario);

        // Anexo com arquivo real em disco (dentro do container) p/ o `servir` ter o que entregar
        // no controle positivo e na mutação.
        $arquivo = (string) tempnam(sys_get_temp_dir(), 'kanban_anexo_m3_');
        file_put_contents($arquivo, 'conteudo do anexo de teste');
        $this->arquivosTemp[] = $arquivo;

        $anexo = new KanbanAnexo('anexo.txt', $arquivo, 26, 'text/plain', $card, $dono);
        $em->persist($anexo);

        $em->flush();

        return [
            'tenant'       => $tenant,
            'dono'         => $dono,
            'estranho'     => $estranho,
            'boardId'      => $board->getId(),
            'cardId'       => $card->getId(),
            'checklistId'  => $checklist->getId(),
            'itemId'       => $item->getId(),
            'marcadorId'   => $marcador->getId(),
            'comentarioId' => $comentario->getId(),
            'anexoId'      => $anexo->getId(),
        ];
    }

    private function criarUsuarioIsSystem(
        EntityManagerInterface $em,
        UserPasswordHasherInterface $hasher,
        Tenant $tenant,
        string $prefixo,
    ): User {
        $user = new User();
        $user->setEmail($prefixo . '_' . uniqid() . '@test.com');
        $user->setFullName(ucfirst($prefixo) . ' Kanban M3');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);

        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName('Gestor ' . $prefixo . ' ' . uniqid());
        $role->setIsSystem(true);
        $em->persist($role);

        $userTenant = new UserTenant($user, $tenant);
        $userTenant->setTenantRole($role);
        $em->persist($userTenant);

        return $user;
    }
}
