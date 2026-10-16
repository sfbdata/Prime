<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Pasta\Controller\PastaPrazoController;
use App\Pasta\Entity\Pasta;
use App\Pasta\EventListener\PrazosDosRegistrosListener;
use App\Pasta\Service\PrazosDosRegistros;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * Datas escritas nos registros da pasta viram prazos (E12c, desenho 1.2.3 `pzLista` + `lerDatas`): "Prazo 16/10/2026"
 * no Registro aparece em "Próximos prazos" e no filtro "Expediente com prazo" da Lista; "audiência" vai para o filtro
 * Audiência. Editar ou excluir o registro relê a pasta.
 */
#[CoversClass(PrazosDosRegistros::class)]
#[CoversClass(PrazosDosRegistrosListener::class)]
final class PrazosDosRegistrosTest extends JusPrimeWebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->instalarCsrfStorage();
    }

    #[TestDox('"Prazo DD/MM/AAAA e prazo DD/MM/AAAA" no Registro cria dois prazos lidos, com o autor como responsável')]
    public function testRegistroComDuasDatasCriaDoisPrazos(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        $pasta = $this->criarPasta($tenant);
        $this->logarComTenant($this->client, $user, $tenant);
        [$d1, $d2] = [$this->dia('+5 days'), $this->dia('+6 days')];

        $this->registrar($pasta, '<p>Prazo ' . $d1->format('d/m/Y') . ' e prazo ' . $d2->format('d/m/Y') . '</p>');

        $linhas = $this->lidos($pasta);
        self::assertSame([[ 'prazo', $d1->format('Y-m-d')], ['prazo', $d2->format('Y-m-d')]], array_map(static fn (array $l): array => [$l['tipo'], $l['data']], $linhas));
        self::assertSame('Admin Leitura', $linhas[0]['responsavel']);
        self::assertStringStartsWith('expedientes:', $linhas[0]['registro_ref']);
    }

    #[TestDox('o trilho mostra o prazo lido com a origem "Registro dos expedientes" e sem cumprir, editar ou excluir')]
    public function testTrilhoMostraOPrazoLido(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        $pasta = $this->criarPasta($tenant);
        $this->logarComTenant($this->client, $user, $tenant);
        $this->registrar($pasta, 'Prazo ' . $this->dia('+2 days')->format('d/m/Y'));

        $c = $this->client->request('GET', '/pasta/' . $pasta->getId());

        $linha = $c->filter('[data-trilho="prazos"] .ps-prazo');
        self::assertCount(1, $linha);
        self::assertStringContainsString(' · Registro dos expedientes', $linha->filter('.ps-linha-meta')->text());
        self::assertSame('Faltam 2 dias', trim($linha->filter('.ps-selo')->text()));
        self::assertCount(0, $linha->filter('.js-prazo-cumprir, .js-prazo-editar, .js-prazo-excluir'));
        self::assertCount(0, $c->filter('.ps-prazo[data-prazo-manual]'));
    }

    #[TestDox('"audiência" com data vai para o filtro Audiência; "prazo" com data vai para Expediente com prazo')]
    public function testFiltrosDaLista(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        $comAudiencia = $this->criarPasta($tenant, 'RAUD');
        $comPrazo = $this->criarPasta($tenant, 'RPRZ');
        $semData = $this->criarPasta($tenant, 'RSEM');
        $this->logarComTenant($this->client, $user, $tenant);
        $this->registrar($comAudiencia, 'Audiência de instrução em ' . $this->dia('+10 days')->format('d/m/Y') . ' às 14h30.');
        $this->registrar($comPrazo, 'Prazo ' . $this->dia('+4 days')->format('d/m/Y'));
        $this->registrar($semData, 'Cliente ligou pedindo notícias.');

        $aud = $this->painel('?expediente=aud');
        self::assertStringContainsString($comAudiencia->getNup(), $aud);
        self::assertStringNotContainsString($comPrazo->getNup(), $aud);

        $prazo = $this->painel('?expediente=prazo');
        self::assertStringContainsString($comPrazo->getNup(), $prazo);
        self::assertStringContainsString($comAudiencia->getNup(), $prazo);
        self::assertStringNotContainsString($semData->getNup(), $prazo);

        self::assertSame('14:30', $this->lidos($comAudiencia)[0]['hora']);
        self::assertSame('audiencia', $this->lidos($comAudiencia)[0]['tipo']);
    }

    #[TestDox('editar o registro troca a data lida; excluir o registro apaga o prazo lido')]
    public function testEditarEExcluirORegistro(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        $pasta = $this->criarPasta($tenant);
        $this->logarComTenant($this->client, $user, $tenant);
        $id = $this->registrar($pasta, 'Prazo ' . $this->dia('+5 days')->format('d/m/Y'));

        $this->em()->clear();
        $this->client->xmlHttpRequest('POST', '/pasta/' . $pasta->getId() . '/mensagem/' . $id . '/editar', ['conteudo' => 'Prazo ' . $this->dia('+9 days')->format('d/m/Y'), '_token' => 'TOKEN_pasta_mensagem_editar_' . $id]);
        self::assertResponseIsSuccessful();
        self::assertSame([$this->dia('+9 days')->format('Y-m-d')], array_column($this->lidos($pasta), 'data'));

        $this->em()->clear();
        $this->client->xmlHttpRequest('POST', '/pasta/' . $pasta->getId() . '/mensagem/' . $id . '/excluir', ['_token' => 'TOKEN_pasta_mensagem_excluir_' . $id]);
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->lidos($pasta));
    }

    #[TestDox('Detalhes também é lido; o cartão do sistema ("Prazo adicionado: ...") não vira prazo de novo')]
    public function testDetalhesLidoECartaoDoSistemaNao(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        $pasta = $this->criarPasta($tenant);
        $this->logarComTenant($this->client, $user, $tenant);
        $this->em()->clear();
        $this->client->xmlHttpRequest('POST', '/pasta/' . $pasta->getId() . '/detalhes/observacao', ['conteudo' => 'Pagamento do boleto até ' . $this->dia('+7 days')->format('d/m/Y'), '_token' => 'TOKEN_pasta_detalhes_obs_' . $pasta->getId()]);
        self::assertResponseIsSuccessful();
        $this->em()->clear();
        $this->client->xmlHttpRequest('POST', '/pasta/' . $pasta->getId() . '/prazos', ['tipo' => 'prazo', 'titulo' => 'Manual', 'data' => $this->dia('+20 days')->format('Y-m-d'), 'hora' => '', 'responsavel' => '', '_token' => 'TOKEN_' . PastaPrazoController::idDoToken((int) $pasta->getId())]);
        self::assertResponseIsSuccessful();

        $lidos = $this->lidos($pasta);
        self::assertCount(1, $lidos, 'só o Detalhes; o cartão do sistema do prazo manual não é relido');
        self::assertStringStartsWith('detalhes:', $lidos[0]['registro_ref']);
        self::assertSame('Vencimento de pagamento', $lidos[0]['titulo']);
    }

    #[TestDox('prazo lido não se cumpre nem se edita pelo servidor')]
    public function testPrazoLidoRecusadoNasRotasDoManual(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        $pasta = $this->criarPasta($tenant);
        $this->logarComTenant($this->client, $user, $tenant);
        $this->registrar($pasta, 'Prazo ' . $this->dia('+5 days')->format('d/m/Y'));
        $id = (int) $this->lidos($pasta)[0]['id'];
        $token = 'TOKEN_' . PastaPrazoController::idDoToken((int) $pasta->getId());

        $this->client->xmlHttpRequest('POST', '/pasta/' . $pasta->getId() . '/prazos/' . $id . '/cumprir', ['_token' => $token]);
        self::assertGreaterThanOrEqual(400, $this->client->getResponse()->getStatusCode());
        $this->client->xmlHttpRequest('POST', '/pasta/' . $pasta->getId() . '/prazos', ['prazo_id' => (string) $id, 'tipo' => 'prazo', 'titulo' => 'X', 'data' => '2030-01-01', 'hora' => '', 'responsavel' => '', '_token' => $token]);
        self::assertGreaterThanOrEqual(400, $this->client->getResponse()->getStatusCode());
        self::assertNull($this->em()->getConnection()->fetchOne('SELECT cumprido_em FROM pasta_prazo WHERE id = ?', [$id]));
    }

    // ── helpers ─────────────────────────────────────────────────────────

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function dia(string $quando): \DateTimeImmutable
    {
        return new \DateTimeImmutable('today ' . $quando);
    }

    private function registrar(Pasta $pasta, string $texto): int
    {
        $this->em()->clear();
        $this->client->xmlHttpRequest('POST', '/pasta/' . $pasta->getId() . '/mensagem', ['conteudo' => $texto, '_token' => 'TOKEN_pasta_mensagem_' . $pasta->getId()]);
        self::assertResponseIsSuccessful();

        return (int) $this->em()->getConnection()->fetchOne('SELECT id FROM pasta_mensagem WHERE pasta_id = ? AND sistema IS NULL ORDER BY id DESC LIMIT 1', [$pasta->getId()]);
    }

    /** @return list<array<string, mixed>> */
    private function lidos(Pasta $pasta): array
    {
        return $this->em()->getConnection()->fetchAllAssociative(
            "SELECT id, tipo, titulo, data, hora, responsavel, registro_ref FROM pasta_prazo WHERE pasta_id = ? AND origem = 'registro' ORDER BY data",
            [$pasta->getId()],
        );
    }

    private function painel(string $query): string
    {
        $this->client->xmlHttpRequest('GET', '/expediente/painel/acervo-geral' . $query);
        self::assertResponseIsSuccessful();

        return (string) $this->client->getResponse()->getContent();
    }

    /** @return array{0: User, 1: Tenant} */
    private function criarAdmin(): array
    {
        $tenant = new Tenant();
        $tenant->setName('Tenant Prazo ' . uniqid());
        $this->em()->persist($tenant);
        $user = $this->novoUsuario('Admin Leitura');
        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName('Administrador ' . uniqid());
        $role->setIsSystem(true);
        $this->em()->persist($role);
        $ut = new UserTenant($user, $tenant);
        $ut->setTenantRole($role);
        $this->em()->persist($ut);
        $this->em()->flush();

        return [$user, $tenant];
    }

    private function novoUsuario(string $nome): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setEmail('prazo_' . uniqid() . '@test.com');
        $user->setFullName($nome);
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $this->em()->persist($user);

        return $user;
    }

    private function criarPasta(Tenant $tenant, string $rotulo = ''): Pasta
    {
        $pasta = new Pasta();
        $pasta->setNup('PZ' . $rotulo . strtoupper(substr(uniqid(), -6)));
        $pasta->setTenant($tenant);
        $this->em()->persist($pasta);
        $this->em()->flush();

        return $pasta;
    }

    private function instalarCsrfStorage(): void
    {
        $storage = new class implements ClearableTokenStorageInterface {
            public function getToken(string $tokenId): string
            {
                return 'TOKEN_' . $tokenId;
            }

            public function setToken(string $tokenId, string $token): void {}

            public function removeToken(string $tokenId): ?string
            {
                return null;
            }

            public function hasToken(string $tokenId): bool
            {
                return true;
            }

            public function clear(): void {}
        };

        static::getContainer()->set('security.csrf.token_storage', $storage);
    }
}
