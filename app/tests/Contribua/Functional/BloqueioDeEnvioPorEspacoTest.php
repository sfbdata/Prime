<?php

declare(strict_types=1);

namespace App\Tests\Contribua\Functional;

use App\Contribua\Armazenamento\ArmazenamentoComCota;
use App\Contribua\DTO\RetratoDoEspaco;
use App\Contribua\Entity\EspacoDoEscritorio;
use App\Contribua\EventListener\EspacoEsgotadoListener;
use App\Contribua\Exception\EspacoEsgotado;
use App\Contribua\Repository\ConsumoDeArmazenamentoRepository;
use App\Contribua\Repository\PlanoRepository;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Kanban\Entity\KanbanBoard;
use App\Kanban\Entity\KanbanCard;
use App\Kanban\Entity\KanbanColuna;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * O bloqueio de envio por espaço, ponta a ponta, por um upload real (anexo do Kanban, caminho JSON).
 *
 * O uso do escritório vem de um dublê da soma: com arquivos de teste de poucos bytes não dá para
 * encostar num limite de 1 GB. O que se prova aqui é o caminho todo depois da soma: decorador,
 * recusa, remoção do arquivo recém-gravado e resposta JSON.
 */
#[CoversClass(ArmazenamentoComCota::class)]
#[CoversClass(EspacoEsgotadoListener::class)]
#[CoversClass(ConsumoDeArmazenamentoRepository::class)]
final class BloqueioDeEnvioPorEspacoTest extends JusPrimeWebTestCase
{
    private const CONTEUDO = '%PDF-1.4 conteudo de teste';

    #[TestDox('Com o bloqueio desligado (padrão), envio passa mesmo acima do limite')]
    public function testBloqueioDesligadoNaoRecusa(): void
    {
        [$client, $c] = $this->preparar(usoSimulado: 5 * RetratoDoEspaco::BYTES_POR_GB, bloqueioAtivo: false);

        $this->subirAnexo($client, $c['cardId']);

        self::assertResponseIsSuccessful();
        self::assertSame(1, $this->anexosDoCard($c['cardId']));
    }

    #[TestDox('Escritório já no limite: envio recusado com 422 e a mensagem do desenho, nada gravado')]
    public function testEscritorioCheioRecusaAntesDeGravar(): void
    {
        [$client, $c] = $this->preparar(usoSimulado: RetratoDoEspaco::BYTES_POR_GB + 1, bloqueioAtivo: true);
        $arquivosAntes = $this->arquivosNoDisco();

        $this->subirAnexo($client, $c['cardId']);

        self::assertResponseStatusCodeSame(422);
        $dados = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame(EspacoEsgotado::MENSAGEM, $dados['mensagem'] ?? null);
        self::assertSame(EspacoEsgotado::MENSAGEM, $dados['erro'] ?? null);
        self::assertSame(EspacoEsgotado::MENSAGEM, $dados['error'] ?? null);
        self::assertFalse($dados['sucesso'] ?? true);
        self::assertSame(0, $this->anexosDoCard($c['cardId']));
        self::assertSame($arquivosAntes, $this->arquivosNoDisco());
    }

    #[TestDox('Arquivo que faria passar do limite: gravado, medido, removido do disco e recusado')]
    public function testArquivoQuePassaDoLimiteEhRemovido(): void
    {
        $quaseCheio = RetratoDoEspaco::BYTES_POR_GB - 5;
        [$client, $c] = $this->preparar(usoSimulado: $quaseCheio, bloqueioAtivo: true);
        $arquivosAntes = $this->arquivosNoDisco();

        $this->subirAnexo($client, $c['cardId']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->anexosDoCard($c['cardId']));
        self::assertSame($arquivosAntes, $this->arquivosNoDisco(), 'o arquivo recusado não pode ficar órfão no disco');
    }

    #[TestDox('Arquivo que cabe: passa com o bloqueio ligado')]
    public function testArquivoQueCabePassa(): void
    {
        [$client, $c] = $this->preparar(usoSimulado: 0, bloqueioAtivo: true);

        $this->subirAnexo($client, $c['cardId']);

        self::assertResponseIsSuccessful();
        self::assertSame(1, $this->anexosDoCard($c['cardId']));
    }

    #[TestDox('A soma do consumo conta os bytes do escritório e só dele')]
    public function testSomaDoConsumoIsolaPorEscritorio(): void
    {
        [$client, $c] = $this->preparar(usoSimulado: null, bloqueioAtivo: false);
        $this->subirAnexo($client, $c['cardId']);
        self::assertResponseIsSuccessful();

        $consumo = static::getContainer()->get(ConsumoDeArmazenamentoRepository::class);
        $outro = $this->criarCenario()['tenant'];

        self::assertSame(\strlen(self::CONTEUDO), $consumo->bytesDoEscritorio((int) $c['tenant']->getId()));
        self::assertSame(0, $consumo->bytesDoEscritorio((int) $outro->getId()));
    }

    /** @return array{0: KernelBrowser, 1: array{tenant: Tenant, cardId: int}} */
    private function preparar(?int $usoSimulado, bool $bloqueioAtivo): array
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->instalarCsrfStorage();

        if ($usoSimulado !== null) {
            $consumo = $this->createStub(ConsumoDeArmazenamentoRepository::class);
            $consumo->method('bytesDoEscritorio')->willReturn($usoSimulado);
            static::getContainer()->set(ConsumoDeArmazenamentoRepository::class, $consumo);
        }

        $cenario = $this->criarCenario();
        $this->darLimiteDeUmGb($cenario['tenant']);
        static::getContainer()->get(Connection::class)
            ->executeStatement('UPDATE contribua_configuracao SET bloqueio_ativo = :ligado', ['ligado' => $bloqueioAtivo], ['ligado' => 'boolean']);
        $this->logarComTenant($client, $cenario['gestor'], $cenario['tenant']);

        return [$client, $cenario];
    }

    private function darLimiteDeUmGb(Tenant $tenant): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $espaco = new EspacoDoEscritorio($tenant, static::getContainer()->get(PlanoRepository::class)->free());
        $espaco->definirLimite(1, false, 'teste do bloqueio', null);
        $em->persist($espaco);
        $em->flush();
    }

    private function subirAnexo(KernelBrowser $client, int $cardId): void
    {
        $origem = sys_get_temp_dir() . '/contribua-anexo-' . bin2hex(random_bytes(6)) . '.pdf';
        file_put_contents($origem, self::CONTEUDO);

        $client->request(
            'POST',
            "/kanban/card/{$cardId}/anexo",
            [],
            ['arquivo' => new UploadedFile($origem, 'documento.pdf', 'application/pdf', null, true)],
            ['HTTP_X_CSRF_TOKEN' => 'TOKEN_ajax'],
        );
    }

    private function anexosDoCard(int $cardId): int
    {
        return (int) static::getContainer()->get(Connection::class)
            ->fetchOne('SELECT COUNT(*) FROM kanban_anexo WHERE card_id = :card', ['card' => $cardId]);
    }

    private function arquivosNoDisco(): int
    {
        $dir = (string) static::getContainer()->getParameter('kanban_uploads_dir');

        return is_dir($dir) ? \count(array_diff((array) scandir($dir), ['.', '..'])) : 0;
    }

    /** @return array{tenant: Tenant, gestor: User, cardId: int} */
    private function criarCenario(): array
    {
        $em     = static::getContainer()->get(EntityManagerInterface::class);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $tenant = (new Tenant())->setName('Tenant CONTRIBUA COTA ' . uniqid());
        $em->persist($tenant);

        $gestor = new User();
        $gestor->setEmail('gestor_cota_' . uniqid() . '@test.com');
        $gestor->setFullName('Gestor Cota');
        $gestor->setRoles(['ROLE_USER']);
        $gestor->setIsActive(true);
        $gestor->setPassword($hasher->hashPassword($gestor, 'senha123'));
        $em->persist($gestor);

        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName('Gestor ' . uniqid());
        $role->setIsSystem(true);
        $em->persist($role);

        $vinculo = new UserTenant($gestor, $tenant);
        $vinculo->setTenantRole($role);
        $em->persist($vinculo);

        $board = new KanbanBoard('Mural ' . uniqid(), $tenant, $gestor);
        $coluna = new KanbanColuna('A Fazer', KanbanColuna::TIPO_A_FAZER, 0, $board);
        $card = new KanbanCard('Card ' . uniqid(), $coluna, $board, $gestor);
        $em->persist($board);
        $em->persist($coluna);
        $em->persist($card);
        $em->flush();

        return ['tenant' => $tenant, 'gestor' => $gestor, 'cardId' => (int) $card->getId()];
    }

    private function instalarCsrfStorage(): void
    {
        $storage = new class () implements ClearableTokenStorageInterface {
            public function getToken(string $tokenId): string
            {
                return 'TOKEN_' . $tokenId;
            }
            public function setToken(string $tokenId, string $token): void
            {
            }
            public function removeToken(string $tokenId): ?string
            {
                return null;
            }
            public function hasToken(string $tokenId): bool
            {
                return true;
            }
            public function clear(): void
            {
            }
        };

        static::getContainer()->set('security.csrf.token_storage', $storage);
    }
}
