<?php

declare(strict_types=1);

namespace App\Tests\Kanban\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Kanban\Controller\KanbanAnexoController;
use App\Kanban\Controller\KanbanCardController;
use App\Kanban\Controller\KanbanChecklistController;
use App\Kanban\Controller\KanbanComentarioController;
use App\Kanban\Controller\KanbanMarcadorController;
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
 * C4b — os endpoints AJAX/JSON mutantes do Kanban (criar/atualizar/mover card, criar checklist,
 * criar/toggle item, upload anexo, criar/editar/toggle marcador, criar/editar comentário) passam a
 * exigir o token CSRF no header X-CSRF-Token (token 'ajax'). Sem token → 403; com token válido →
 * sucesso (ou 422 quando falta payload, provando que o CSRF passou).
 *
 * O par discriminante (sem token → 403 / com token → sucesso) prova que o 403 vem do CSRF e não de
 * um guard de tenant/acesso: a mesma requisição autorizada (gestor isSystem, board do próprio user)
 * passa por todos os guards e só falha no CSRF. Os endpoints *_excluir já têm CSRF próprio (token
 * por-intenção) e o board criar/editar usa form CSRF — fora deste teste.
 */
#[CoversClass(KanbanCardController::class)]
#[CoversClass(KanbanChecklistController::class)]
#[CoversClass(KanbanAnexoController::class)]
#[CoversClass(KanbanMarcadorController::class)]
#[CoversClass(KanbanComentarioController::class)]
final class KanbanCsrfControllerTest extends JusPrimeWebTestCase
{
    #[TestDox('card criar: sem token CSRF 403, com token sucesso')]
    public function testCardCriar(): void
    {
        [$client, $c] = $this->preparar();
        $url = "/kanban/coluna/{$c['colunaId']}/card";

        $this->postJson($client, $url, ['titulo' => 'Novo card']);
        self::assertResponseStatusCodeSame(403, 'criar card sem CSRF deveria ser barrado');

        $this->postJson($client, $url, ['titulo' => 'Novo card'], 'TOKEN_ajax');
        $this->assertSucessoJson($client);
    }

    #[TestDox('card atualizar: sem token CSRF 403, com token sucesso')]
    public function testCardAtualizar(): void
    {
        [$client, $c] = $this->preparar();
        $url = "/kanban/card/{$c['cardId']}";

        $this->postJson($client, $url, ['titulo' => 'Editado']);
        self::assertResponseStatusCodeSame(403, 'atualizar card sem CSRF deveria ser barrado');

        $this->postJson($client, $url, ['titulo' => 'Editado'], 'TOKEN_ajax');
        $this->assertSucessoJson($client);
    }

    #[TestDox('card mover: sem token CSRF 403, com token sucesso')]
    public function testCardMover(): void
    {
        [$client, $c] = $this->preparar();
        $url     = "/kanban/card/{$c['cardId']}/mover";
        $payload = ['novaColunaId' => $c['colunaId'], 'novaPosicao' => 0];

        $this->postJson($client, $url, $payload);
        self::assertResponseStatusCodeSame(403, 'mover card sem CSRF deveria ser barrado');

        $this->postJson($client, $url, $payload, 'TOKEN_ajax');
        $this->assertSucessoJson($client);
    }

    #[TestDox('checklist criar: sem token CSRF 403, com token sucesso')]
    public function testChecklistCriar(): void
    {
        [$client, $c] = $this->preparar();
        $url = "/kanban/card/{$c['cardId']}/checklist";

        $this->postJson($client, $url, ['titulo' => 'CL']);
        self::assertResponseStatusCodeSame(403, 'criar checklist sem CSRF deveria ser barrado');

        $this->postJson($client, $url, ['titulo' => 'CL'], 'TOKEN_ajax');
        $this->assertSucessoJson($client);
    }

    #[TestDox('checklist item criar: sem token CSRF 403, com token sucesso')]
    public function testChecklistItemCriar(): void
    {
        [$client, $c] = $this->preparar();
        $url = "/kanban/checklist/{$c['checklistId']}/item";

        $this->postJson($client, $url, ['texto' => 'Item']);
        self::assertResponseStatusCodeSame(403, 'criar item sem CSRF deveria ser barrado');

        $this->postJson($client, $url, ['texto' => 'Item'], 'TOKEN_ajax');
        $this->assertSucessoJson($client);
    }

    #[TestDox('checklist item toggle: sem token CSRF 403, com token sucesso')]
    public function testChecklistItemToggle(): void
    {
        [$client, $c] = $this->preparar();
        $url = "/kanban/checklist/item/{$c['itemId']}/toggle";

        $this->postJson($client, $url, []);
        self::assertResponseStatusCodeSame(403, 'toggle item sem CSRF deveria ser barrado');

        $this->postJson($client, $url, [], 'TOKEN_ajax');
        $this->assertSucessoJson($client);
    }

    #[TestDox('anexo upload: sem token CSRF 403, com token (sem arquivo) 422')]
    public function testAnexoUpload(): void
    {
        [$client, $c] = $this->preparar();
        $url = "/kanban/card/{$c['cardId']}/anexo";

        $client->request('POST', $url);
        self::assertResponseStatusCodeSame(403, 'upload sem CSRF deveria ser barrado');

        // Com token válido mas sem arquivo: 422 (a validação do arquivo roda DEPOIS do CSRF,
        // provando que o CSRF passou). Mesmo padrão do BatidaCsrfControllerTest no C4a.
        $client->request('POST', $url, [], [], ['HTTP_X_CSRF_TOKEN' => 'TOKEN_ajax']);
        self::assertResponseStatusCodeSame(422, 'upload com CSRF válido deveria passar o CSRF e cair na validação de arquivo');
    }

    #[TestDox('marcador criar: sem token CSRF 403, com token sucesso')]
    public function testMarcadorCriar(): void
    {
        [$client, $c] = $this->preparar();
        $url = "/kanban/board/{$c['boardId']}/marcador";

        $this->postJson($client, $url, ['nome' => 'Urgente', 'cor' => '#ff0000']);
        self::assertResponseStatusCodeSame(403, 'criar marcador sem CSRF deveria ser barrado');

        $this->postJson($client, $url, ['nome' => 'Urgente', 'cor' => '#ff0000'], 'TOKEN_ajax');
        $this->assertSucessoJson($client);
    }

    #[TestDox('marcador editar: sem token CSRF 403, com token sucesso')]
    public function testMarcadorEditar(): void
    {
        [$client, $c] = $this->preparar();
        $url = "/kanban/marcador/{$c['marcadorId']}";

        $this->postJson($client, $url, ['nome' => 'Renomeado', 'cor' => '#00ff00']);
        self::assertResponseStatusCodeSame(403, 'editar marcador sem CSRF deveria ser barrado');

        $this->postJson($client, $url, ['nome' => 'Renomeado', 'cor' => '#00ff00'], 'TOKEN_ajax');
        $this->assertSucessoJson($client);
    }

    #[TestDox('marcador toggle no card: sem token CSRF 403, com token sucesso')]
    public function testMarcadorToggle(): void
    {
        [$client, $c] = $this->preparar();
        $url = "/kanban/card/{$c['cardId']}/marcador/{$c['marcadorId']}/toggle";

        $this->postJson($client, $url, []);
        self::assertResponseStatusCodeSame(403, 'toggle marcador sem CSRF deveria ser barrado');

        $this->postJson($client, $url, [], 'TOKEN_ajax');
        $this->assertSucessoJson($client);
    }

    #[TestDox('comentário criar: sem token CSRF 403, com token sucesso')]
    public function testComentarioCriar(): void
    {
        [$client, $c] = $this->preparar();
        $url = "/kanban/card/{$c['cardId']}/comentario";

        $this->postJson($client, $url, ['conteudo' => 'Olá']);
        self::assertResponseStatusCodeSame(403, 'criar comentário sem CSRF deveria ser barrado');

        $this->postJson($client, $url, ['conteudo' => 'Olá'], 'TOKEN_ajax');
        $this->assertSucessoJson($client);
    }

    #[TestDox('comentário editar: sem token CSRF 403, com token sucesso')]
    public function testComentarioEditar(): void
    {
        [$client, $c] = $this->preparar();
        $url = "/kanban/comentario/{$c['comentarioId']}";

        $this->postJson($client, $url, ['conteudo' => 'Editado']);
        self::assertResponseStatusCodeSame(403, 'editar comentário sem CSRF deveria ser barrado');

        $this->postJson($client, $url, ['conteudo' => 'Editado'], 'TOKEN_ajax');
        $this->assertSucessoJson($client);
    }

    #[TestDox('board criar (form Symfony) já exige CSRF: sem _token retorna 422')]
    public function testBoardCriarFormExigeCsrf(): void
    {
        [$client] = $this->preparar();

        // O board criar/editar usa Symfony Form (KanbanBoardType, csrf_token_id) — o CSRF do form
        // barra a requisição sem _token via isValid() → 422. Confirma que NÃO precisa do trait.
        $client->request('POST', '/kanban', ['nome' => 'Mural sem token']);
        self::assertResponseStatusCodeSame(422, 'board criar sem _token do form deveria ser inválido');
    }

    // ----------------------------------------------------------------- helpers

    /**
     * Cria cliente (sem reboot, p/ o storage CSRF fake sobreviver às 2 requisições do par),
     * instala o storage fake, monta o cenário completo e loga o gestor com o tenant na sessão.
     *
     * @return array{0: KernelBrowser, 1: array<string,mixed>}
     */
    private function preparar(): array
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->instalarCsrfStorage();

        $cenario = $this->criarCenario();
        $this->logarComTenant($client, $cenario['gestor'], $cenario['tenant']);

        return [$client, $cenario];
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
        self::assertResponseIsSuccessful('com CSRF válido a requisição deveria suceder');
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
        $tenant->setName('Tenant KANBAN CSRF ' . uniqid());
        $em->persist($tenant);

        $gestor = new User();
        $gestor->setEmail('gestor_kanban_' . uniqid() . '@test.com');
        $gestor->setFullName('Gestor Kanban CSRF');
        $gestor->setRoles(['ROLE_USER']);
        $gestor->setIsActive(true);
        $gestor->setPassword($hasher->hashPassword($gestor, 'senha123'));
        $em->persist($gestor);

        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName('Gestor ' . uniqid());
        $role->setIsSystem(true);
        $em->persist($role);

        $userTenant = new UserTenant($gestor, $tenant);
        $userTenant->setTenantRole($role);
        $em->persist($userTenant);

        $board = new KanbanBoard('Mural ' . uniqid(), $tenant, $gestor);
        $em->persist($board);

        $coluna = new KanbanColuna('A Fazer', KanbanColuna::TIPO_A_FAZER, 0, $board);
        $em->persist($coluna);

        $card = new KanbanCard('Card ' . uniqid(), $coluna, $board, $gestor);
        $em->persist($card);

        $checklist = new KanbanChecklist('Checklist', $card);
        $em->persist($checklist);

        $item = new KanbanChecklistItem('Item', $checklist);
        $em->persist($item);

        $marcador = new KanbanMarcador('Marcador', '#3b82f6', $board);
        $em->persist($marcador);

        $comentario = new KanbanComentario('Comentário', $card, $gestor);
        $em->persist($comentario);

        $em->flush();

        return [
            'tenant'       => $tenant,
            'gestor'       => $gestor,
            'boardId'      => $board->getId(),
            'colunaId'     => $coluna->getId(),
            'cardId'       => $card->getId(),
            'checklistId'  => $checklist->getId(),
            'itemId'       => $item->getId(),
            'marcadorId'   => $marcador->getId(),
            'comentarioId' => $comentario->getId(),
        ];
    }
}
