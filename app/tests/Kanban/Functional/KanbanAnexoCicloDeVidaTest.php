<?php

declare(strict_types=1);

namespace App\Tests\Kanban\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Kanban\Entity\KanbanBoard;
use App\Kanban\Entity\KanbanCard;
use App\Kanban\Entity\KanbanColuna;
use App\Kanban\Service\ArquivosDeAnexoDoKanban;
use App\Kanban\UseCase\AdicionarAnexoUseCase;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * Ciclo de vida do arquivo físico de um anexo do Kanban (E1, Bloco C).
 *
 * Antes da E1 havia dois defeitos, nenhum coberto por teste:
 *  1. o diretório era o literal 'kanban', RELATIVO ao WORKDIR do PHP-FPM — o arquivo caía fora do
 *     volume persistido e sumia no deploy seguinte;
 *  2. `KanbanCard` cascateia `remove` + `orphanRemoval` sobre os anexos, e `KanbanColuna`/
 *     `KanbanBoard` cascateiam em cadeia até lá: excluir card ou mural apagava a LINHA e deixava
 *     o arquivo no disco.
 */
#[CoversClass(ArquivosDeAnexoDoKanban::class)]
#[CoversClass(AdicionarAnexoUseCase::class)]
final class KanbanAnexoCicloDeVidaTest extends JusPrimeWebTestCase
{
    #[TestDox('Upload grava o arquivo no diretório configurado, absoluto e persistido')]
    public function testUploadGravaNoDiretorioConfigurado(): void
    {
        [$client, $c] = $this->preparar();

        $anexoId = $this->subirAnexo($client, $c['cardId']);
        $caminho = $this->caminhoDoAnexo($anexoId);

        self::assertFileExists($caminho, 'o arquivo deveria existir no diretório configurado');
        self::assertStringStartsWith(
            $this->diretorioConfigurado(),
            $caminho,
            'o arquivo tem de nascer sob %kanban_uploads_dir%, não num caminho relativo',
        );
        self::assertStringStartsWith('/', $caminho, 'o diretório precisa ser absoluto');
    }

    #[TestDox('Servir devolve o conteúdo do arquivo recém-enviado')]
    public function testServirDevolveOConteudo(): void
    {
        [$client, $c] = $this->preparar();
        $anexoId = $this->subirAnexo($client, $c['cardId']);

        $client->request('GET', "/kanban/anexo/{$anexoId}");

        self::assertResponseIsSuccessful('o anexo recém-enviado deveria ser servível');
    }

    #[TestDox('Excluir o anexo remove o arquivo do disco')]
    public function testExcluirAnexoRemoveOArquivo(): void
    {
        [$client, $c] = $this->preparar();
        $anexoId = $this->subirAnexo($client, $c['cardId']);
        $caminho = $this->caminhoDoAnexo($anexoId);
        self::assertFileExists($caminho);

        $client->request('POST', "/kanban/anexo/{$anexoId}/excluir", [
            '_token' => 'TOKEN_kanban_anexo_excluir_' . $anexoId,
        ]);

        self::assertResponseIsSuccessful();
        self::assertFileDoesNotExist($caminho, 'excluir o anexo deveria apagar o arquivo');
    }

    #[TestDox('Excluir o CARD remove os arquivos dos anexos (o cascade sozinho não removia)')]
    public function testExcluirCardRemoveOsArquivosDosAnexos(): void
    {
        [$client, $c] = $this->preparar();
        $anexoId = $this->subirAnexo($client, $c['cardId']);
        $caminho = $this->caminhoDoAnexo($anexoId);
        self::assertFileExists($caminho);

        $client->request('POST', "/kanban/card/{$c['cardId']}/excluir", [
            '_token' => 'TOKEN_kanban_card_excluir_' . $c['cardId'],
        ]);

        self::assertResponseIsSuccessful();
        self::assertFileDoesNotExist($caminho, 'excluir o card deveria apagar o arquivo do anexo');
    }

    #[TestDox('Excluir o MURAL remove os arquivos dos anexos de todos os cards')]
    public function testExcluirBoardRemoveOsArquivosDosAnexos(): void
    {
        [$client, $c] = $this->preparar();
        $anexoId = $this->subirAnexo($client, $c['cardId']);
        $caminho = $this->caminhoDoAnexo($anexoId);
        self::assertFileExists($caminho);

        $client->request('POST', "/kanban/{$c['boardId']}/excluir", [
            '_token' => 'TOKEN_kanban_board_excluir_' . $c['boardId'],
        ]);

        self::assertResponseIsSuccessful();
        self::assertFileDoesNotExist($caminho, 'excluir o mural deveria apagar os arquivos dos anexos');
    }

    // ------------------------------------------------------------------ helpers

    private function diretorioConfigurado(): string
    {
        return (string) static::getContainer()->getParameter('kanban_uploads_dir');
    }

    private function caminhoDoAnexo(int $anexoId): string
    {
        $em    = static::getContainer()->get(EntityManagerInterface::class);
        $anexo = $em->find(\App\Kanban\Entity\KanbanAnexo::class, $anexoId);
        self::assertNotNull($anexo, 'o anexo deveria existir no banco');

        return $this->diretorioConfigurado() . '/' . $anexo->getCaminho();
    }

    private function subirAnexo(KernelBrowser $client, int $cardId): int
    {
        $origem = sys_get_temp_dir() . '/kanban-anexo-' . bin2hex(random_bytes(6)) . '.pdf';
        file_put_contents($origem, '%PDF-1.4 conteudo de teste');

        $arquivo = new UploadedFile($origem, 'documento.pdf', 'application/pdf', null, true);

        $client->request(
            'POST',
            "/kanban/card/{$cardId}/anexo",
            [],
            ['arquivo' => $arquivo],
            ['HTTP_X_CSRF_TOKEN' => 'TOKEN_ajax'],
        );

        self::assertResponseIsSuccessful('o upload do anexo deveria suceder');
        $dados = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($dados);
        self::assertTrue($dados['sucesso'] ?? false);

        return (int) $dados['anexo']['id'];
    }

    /** @return array{0: KernelBrowser, 1: array<string,mixed>} */
    private function preparar(): array
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->instalarCsrfStorage();

        $cenario = $this->criarCenario();
        $this->logarComTenant($client, $cenario['gestor'], $cenario['tenant']);

        return [$client, $cenario];
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
        $tenant->setName('Tenant KANBAN ANEXO ' . uniqid());
        $em->persist($tenant);

        $gestor = new User();
        $gestor->setEmail('gestor_anexo_' . uniqid() . '@test.com');
        $gestor->setFullName('Gestor Anexo Kanban');
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

        $em->flush();

        return [
            'tenant'   => $tenant,
            'gestor'   => $gestor,
            'boardId'  => $board->getId(),
            'colunaId' => $coluna->getId(),
            'cardId'   => $card->getId(),
        ];
    }
}
