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
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
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
        self::assertResponseHeaderSame('Content-Disposition', 'inline; filename=documento.pdf');
        self::assertSame('%PDF-1.4 conteudo de teste', $client->getInternalResponse()->getContent());
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

    /** @return iterable<string, array{string}> */
    public static function exclusoes(): iterable
    {
        yield 'anexo' => ['anexo'];
        yield 'card'  => ['card'];
        yield 'mural' => ['mural'];
    }

    /**
     * E2.5 (INV-6): antes, os três UseCases apagavam o arquivo e só então davam `flush`. Um banco
     * que recusasse deixava o anexo de pé apontando para o vazio. A recusa é simulada no `onFlush`,
     * depois de o UseCase ter chamado o repositório e antes de qualquer SQL — exatamente onde o
     * arquivo antigo já teria sumido.
     */
    #[TestDox('Banco recusa excluir o $alvo: o anexo continua no banco e o arquivo continua no disco')]
    #[DataProvider('exclusoes')]
    public function testBancoQueRecusaNaoApagaOArquivo(string $alvo): void
    {
        [$client, $c] = $this->preparar();
        $anexoId = $this->subirAnexo($client, $c['cardId']);
        $caminho = $this->caminhoDoAnexo($anexoId);
        self::assertFileExists($caminho);

        $em     = static::getContainer()->get(EntityManagerInterface::class);
        $recusa = new class {
            public int $recusas = 0;

            public function onFlush(OnFlushEventArgs $args): void
            {
                if ($args->getObjectManager()->getUnitOfWork()->getScheduledEntityDeletions() !== []) {
                    ++$this->recusas;

                    throw new \LogicException('banco recusou a exclusão');
                }
            }
        };
        $em->getEventManager()->addEventListener([Events::onFlush], $recusa);

        try {
            match ($alvo) {
                'anexo' => $client->request('POST', "/kanban/anexo/{$anexoId}/excluir", [
                    '_token' => 'TOKEN_kanban_anexo_excluir_' . $anexoId,
                ]),
                'card' => $client->request('POST', "/kanban/card/{$c['cardId']}/excluir", [
                    '_token' => 'TOKEN_kanban_card_excluir_' . $c['cardId'],
                ]),
                'mural' => $client->request('POST', "/kanban/{$c['boardId']}/excluir", [
                    '_token' => 'TOKEN_kanban_board_excluir_' . $c['boardId'],
                ]),
            };
        } finally {
            $em->getEventManager()->removeEventListener([Events::onFlush], $recusa);
        }

        try {
            self::assertSame(1, $recusa->recusas, 'a exclusão chegou ao banco e foi recusada');
            self::assertFalse($client->getResponse()->isSuccessful());
            self::assertSame(
                1,
                (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM kanban_anexo WHERE id = ?', [$anexoId]),
            );
            self::assertFileExists($caminho, 'o arquivo não pode sair antes de o banco confirmar a exclusão');
        } finally {
            @unlink($caminho);
        }
    }

    /** COMMIT confirmado seguido de falha física: a exclusão vale e não há erro para quem chamou. */
    #[TestDox('Disco recusa depois de excluir o $alvo: a exclusão vale, o arquivo fica e não há erro')]
    #[DataProvider('exclusoes')]
    public function testDiscoQueFalhaDepoisDoCommitNaoDesfaz(string $alvo): void
    {
        if (\function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('root ignora permissão de diretório');
        }

        [$client, $c] = $this->preparar();
        $anexoId = $this->subirAnexo($client, $c['cardId']);
        $caminho = $this->caminhoDoAnexo($anexoId);
        $modo    = fileperms($this->diretorioConfigurado()) & 0o7777;
        chmod($this->diretorioConfigurado(), 0o555);

        try {
            match ($alvo) {
                'anexo' => $client->request('POST', "/kanban/anexo/{$anexoId}/excluir", [
                    '_token' => 'TOKEN_kanban_anexo_excluir_' . $anexoId,
                ]),
                'card' => $client->request('POST', "/kanban/card/{$c['cardId']}/excluir", [
                    '_token' => 'TOKEN_kanban_card_excluir_' . $c['cardId'],
                ]),
                'mural' => $client->request('POST', "/kanban/{$c['boardId']}/excluir", [
                    '_token' => 'TOKEN_kanban_board_excluir_' . $c['boardId'],
                ]),
            };
        } finally {
            chmod($this->diretorioConfigurado(), $modo);
        }

        try {
            self::assertResponseIsSuccessful();
            $em = static::getContainer()->get(EntityManagerInterface::class);
            self::assertSame(0, (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM kanban_anexo WHERE id = ?', [$anexoId]));
            self::assertFileExists($caminho, 'órfão recuperável');
        } finally {
            @unlink($caminho);
        }
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
