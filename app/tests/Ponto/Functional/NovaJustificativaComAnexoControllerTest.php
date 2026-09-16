<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Functional;

use App\Controller\TenantController;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Ponto\Armazenamento\ChavesDePonto;
use App\Ponto\Controller\PontoController;
use App\Ponto\Entity\JustificativaPonto;
use App\Shared\Armazenamento\CategoriaDeArquivo;
use App\Shared\Armazenamento\Exception\FalhaDeArmazenamento;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Shared\Doubles\ArmazenamentoEmMemoriaNoContainer;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * As duas portas que CRIAM justificativa com atestado (E2.4A, risco ALTO): a do colaborador
 * (`PontoController::novaJustificativa`) e a do administrador
 * (`TenantController::novaJustificativaAdmin`).
 *
 * Nas duas, um arquivo serve ao lote inteiro e é gravado ANTES do banco; se o flush falhar, o
 * arquivo tem de sair do disco (ordem da E1). Nenhuma das duas tinha teste com anexo, e o
 * cleanup do catch nunca tinha sido exercitado.
 *
 * Contra o storage e o diretório REAIS (`var/uploads-test/justificativas`): o DAMA reverte o
 * banco, não o disco, e todo arquivo que surgir durante a requisição é apagado no tearDown. Os
 * casos de R1 e de falha de gravação trocam o storage pelo dublê em memória — é o único jeito de
 * ver o escopo da chave e de provocar a falha.
 */
#[CoversClass(PontoController::class)]
#[CoversClass(TenantController::class)]
final class NovaJustificativaComAnexoControllerTest extends JusPrimeWebTestCase
{
    private const PDF = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";

    /** @var list<string> */
    private array $arquivosCriados = [];

    protected function tearDown(): void
    {
        foreach ($this->arquivosCriados as $caminho) {
            if (is_file($caminho)) {
                @unlink($caminho);
            }
        }

        $this->arquivosCriados = [];

        parent::tearDown();
    }

    #[TestDox('colaborador: um atestado para dois dias vira um arquivo só, apontado pelas duas justificativas')]
    public function testColaboradorGravaUmArquivoParaOLote(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $tenant = $this->criarTenant();
        $user   = $this->criarUsuario();
        $this->vincular($user, $tenant);
        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);

        $novos = $this->enviarComoColaborador($client, $this->doisDiasUteisDoMesAnterior());

        self::assertResponseRedirects();
        self::assertCount(1, $novos, 'o lote inteiro compartilha UM arquivo');

        $anexos = $this->anexosDasJustificativasDo($user);
        self::assertSame([$novos[0], $novos[0]], $anexos);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}\.pdf$/', $novos[0]);

        self::assertStringEqualsFile($this->diretorio() . '/' . $novos[0], self::PDF);
    }

    #[TestDox('colaborador: se o banco recusar o lote, o atestado recém-gravado sai do disco')]
    public function testColaboradorFlushFalhoRemoveOArquivoNovo(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $tenant = $this->criarTenant();
        $user   = $this->criarUsuario();
        $this->vincular($user, $tenant);
        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);
        $gravacoes = $this->bancoRecusaJustificativas();

        $novos = $this->enviarComoColaborador($client, $this->doisDiasUteisDoMesAnterior());

        self::assertResponseStatusCodeSame(500);
        self::assertSame(1, $gravacoes->tentativas, 'o flush das justificativas chegou a ser tentado');
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}\.pdf$/', (string) $gravacoes->anexoNoFlush);
        self::assertTrue($gravacoes->arquivoExistiaNoFlush, 'o atestado tinha de estar no disco quando o banco recusou');
        self::assertFileDoesNotExist($this->diretorio() . '/' . $gravacoes->anexoNoFlush);
        self::assertSame([], $novos, 'o arquivo gravado antes do flush tinha de ter sido removido');
        self::assertSame([], $this->anexosDasJustificativasDo($user));
    }

    #[TestDox('administrador: lança justificativa com atestado, gravado e apontado pelo registro')]
    public function testAdministradorGravaOAtestado(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $tenant = $this->criarTenant();
        $admin  = $this->criarUsuario(['ROLE_SUPER_ADMIN']);
        $user   = $this->criarUsuario();
        $this->vincular($admin, $tenant); // sem vínculo, o listener de escritório desvia antes da rota
        $this->vincular($user, $tenant);
        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $admin, $tenant);

        $novos = $this->enviarComoAdministrador($client, $tenant, $user, $this->doisDiasUteisDoMesAnterior()[0]);

        self::assertResponseRedirects();
        self::assertCount(1, $novos);
        self::assertSame([$novos[0]], $this->anexosDasJustificativasDo($user));
        self::assertStringEqualsFile($this->diretorio() . '/' . $novos[0], self::PDF);
    }

    #[TestDox('administrador: se o banco recusar, o atestado recém-gravado sai do disco')]
    public function testAdministradorFlushFalhoRemoveOArquivoNovo(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $tenant = $this->criarTenant();
        $admin  = $this->criarUsuario(['ROLE_SUPER_ADMIN']);
        $user   = $this->criarUsuario();
        $this->vincular($admin, $tenant); // sem vínculo, o listener de escritório desvia antes da rota
        $this->vincular($user, $tenant);
        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $admin, $tenant);
        $gravacoes = $this->bancoRecusaJustificativas();

        $novos = $this->enviarComoAdministrador($client, $tenant, $user, $this->doisDiasUteisDoMesAnterior()[0]);

        self::assertResponseStatusCodeSame(500);
        self::assertSame(1, $gravacoes->tentativas);
        self::assertTrue($gravacoes->arquivoExistiaNoFlush, 'o atestado tinha de estar no disco quando o banco recusou');
        self::assertFileDoesNotExist($this->diretorio() . '/' . $gravacoes->anexoNoFlush);
        self::assertSame([], $novos, 'o arquivo gravado antes do flush tinha de ter sido removido');
        self::assertSame([], $this->anexosDasJustificativasDo($user));
    }

    /**
     * R1: no disco o escopo não aparece (a categoria é plana). Contra o dublê, a chave gravada
     * tem de ser a que a leitura monta a partir de CADA justificativa do lote.
     */
    #[TestDox('R1 colaborador: a chave gravada é a mesma que a leitura monta a partir de cada justificativa')]
    public function testColaboradorChaveGravadaEhAMesmaDaLeitura(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $duble  = ArmazenamentoEmMemoriaNoContainer::instalarEm(static::getContainer());
        $tenant = $this->criarTenant();
        $user   = $this->criarUsuario();
        $this->vincular($user, $tenant);
        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);

        $this->enviarComoColaborador($client, $this->doisDiasUteisDoMesAnterior());

        self::assertResponseRedirects();
        $this->assertChaveGravadaEhADaLeitura($duble, $user, $tenant, 2);
    }

    /**
     * O escritório da justificativa é o da URL, não o da sessão do administrador. Com os dois
     * diferentes, um controller que tirasse o escopo da sessão gravaria na chave errada — e a
     * suíte contra o disco plano não perceberia.
     */
    #[TestDox('R1 administrador: com a sessão em outro escritório, a chave usa o escritório da URL')]
    public function testAdministradorChaveUsaOEscritorioDaUrl(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $duble         = ArmazenamentoEmMemoriaNoContainer::instalarEm(static::getContainer());
        $tenantDaUrl   = $this->criarTenant();
        $tenantSessao  = $this->criarTenant();
        $admin         = $this->criarUsuario(['ROLE_SUPER_ADMIN']);
        $user          = $this->criarUsuario();
        $this->vincular($admin, $tenantSessao);
        $this->vincular($admin, $tenantDaUrl);
        $this->vincular($user, $tenantDaUrl);
        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $admin, $tenantSessao);

        $this->enviarComoAdministrador($client, $tenantDaUrl, $user, $this->doisDiasUteisDoMesAnterior()[0]);

        self::assertResponseRedirects();
        self::assertNotSame($tenantSessao->getId(), $duble->memoria->ultimaGravada()->escopo->tenantIdOuNull());
        $this->assertChaveGravadaEhADaLeitura($duble, $user, $tenantDaUrl, 1);
    }

    #[TestDox('falha do storage: nas duas portas, nenhuma justificativa é registrada')]
    public function testFalhaDoStorageNaoRegistraJustificativa(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $duble                         = ArmazenamentoEmMemoriaNoContainer::instalarEm(static::getContainer());
        $duble->memoria->falhaAoGravar = new FalhaDeArmazenamento('disco cheio');
        $tenant                        = $this->criarTenant();
        $admin                         = $this->criarUsuario(['ROLE_SUPER_ADMIN']);
        $user                          = $this->criarUsuario();
        $this->vincular($admin, $tenant);
        $this->vincular($user, $tenant);
        $this->instalarCsrfStorage();

        $this->logarComTenant($client, $user, $tenant);
        $this->enviarComoColaborador($client, $this->doisDiasUteisDoMesAnterior());
        self::assertResponseStatusCodeSame(500);
        self::assertStringContainsString('disco cheio', (string) $client->getResponse()->getContent(), 'o 500 tem de ser a falha de gravação, não outra');

        $this->logarComTenant($client, $admin, $tenant);
        $this->enviarComoAdministrador($client, $tenant, $user, $this->doisDiasUteisDoMesAnterior()[0]);
        self::assertResponseStatusCodeSame(500);
        self::assertStringContainsString('disco cheio', (string) $client->getResponse()->getContent(), 'o 500 tem de ser a falha de gravação, não outra');

        self::assertSame([], $this->anexosDasJustificativasDo($user));
    }

    // ----------------------------------------------------------------- helpers

    /**
     * @param list<\DateTimeImmutable> $dias
     *
     * @return list<string> nomes que surgiram no diretório durante a requisição
     */
    private function enviarComoColaborador(KernelBrowser $client, array $dias): array
    {
        return $this->observandoODiretorio(fn () => $client->request(
            'POST',
            '/ponto/justificativa/nova',
            ['justificativa_ponto' => [
                '_token' => 'TOKEN_justificativa_ponto',
                'tipo'   => 'atestado_medico',
                'datas'  => implode(',', array_map(static fn (\DateTimeImmutable $d): string => $d->format('Y-m-d'), $dias)),
            ]],
            ['justificativa_ponto' => ['anexo' => $this->upload()]],
        ));
    }

    /** @return list<string> */
    private function enviarComoAdministrador(KernelBrowser $client, Tenant $tenant, User $user, \DateTimeImmutable $dia): array
    {
        return $this->observandoODiretorio(fn () => $client->request(
            'POST',
            sprintf('/tenant/%d/user/%d/justificativa/nova', $tenant->getId(), $user->getId()),
            [
                '_token' => 'TOKEN_admin_nova_justificativa_' . $user->getId(),
                'tipo'   => 'atestado_medico',
                'datas'  => $dia->format('Y-m-d'),
            ],
            ['anexo' => $this->upload()],
        ));
    }

    /** @return list<string> */
    private function observandoODiretorio(callable $requisicao): array
    {
        $antes = $this->arquivosNoDiretorio();
        $requisicao();

        $novos = array_values(array_diff($this->arquivosNoDiretorio(), $antes));
        foreach ($novos as $novo) {
            $this->arquivosCriados[] = $this->diretorio() . '/' . $novo;
        }

        return $novos;
    }

    private function upload(): UploadedFile
    {
        $origem = sys_get_temp_dir() . '/atestado_' . bin2hex(random_bytes(6));
        file_put_contents($origem, self::PDF);
        $this->arquivosCriados[] = $origem;

        return new UploadedFile($origem, 'atestado.pdf', null, null, true);
    }

    /**
     * Faz o banco "recusar" o flush que insere justificativas — o equivalente, para o
     * controller, a uma violação de restrição ou a uma queda de conexão no meio do lote.
     */
    private function bancoRecusaJustificativas(): object
    {
        $listener = new class ($this->diretorio()) {
            public int $tentativas = 0;
            public ?string $anexoNoFlush = null;
            public bool $arquivoExistiaNoFlush = false;

            public function __construct(private readonly string $diretorio)
            {
            }

            public function onFlush(OnFlushEventArgs $args): void
            {
                foreach ($args->getObjectManager()->getUnitOfWork()->getScheduledEntityInsertions() as $entidade) {
                    if ($entidade instanceof JustificativaPonto) {
                        ++$this->tentativas;
                        // Fotografa o momento da recusa: sem isto, "nada sobrou no disco" passaria
                        // também se o anexo nunca tivesse chegado a ser gravado.
                        $this->anexoNoFlush          = $entidade->getAnexoPath();
                        $this->arquivoExistiaNoFlush = $this->anexoNoFlush !== null
                            && is_file($this->diretorio . '/' . $this->anexoNoFlush);

                        throw new \RuntimeException('banco recusou o lote');
                    }
                }
            }
        };

        static::getContainer()->get(EntityManagerInterface::class)
            ->getEventManager()
            ->addEventListener([Events::onFlush], $listener);

        return $listener;
    }

    private function assertChaveGravadaEhADaLeitura(ArmazenamentoEmMemoriaNoContainer $duble, User $user, Tenant $tenant, int $esperadas): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        if ($em->getFilters()->isEnabled('tenant')) {
            $em->getFilters()->disable('tenant');
        }
        $em->clear();

        $justificativas = $em->getRepository(JustificativaPonto::class)->findBy(['user' => $user]);
        self::assertCount($esperadas, $justificativas);
        self::assertCount(1, $duble->memoria->gravadas, 'o lote inteiro compartilha UM arquivo');

        $gravada = $duble->memoria->ultimaGravada();
        self::assertSame(CategoriaDeArquivo::JUSTIFICATIVA_ANEXO, $gravada->categoria);
        self::assertSame($tenant->getId(), $gravada->escopo->tenantIdOuNull());

        foreach ($justificativas as $justificativa) {
            self::assertTrue(
                $gravada->ehIgualA(ChavesDePonto::anexoDeJustificativa($justificativa)),
                'gravação e leitura divergem',
            );
        }
    }

    /** @return list<string|null> anexos das justificativas do usuário, pelo banco */
    private function anexosDasJustificativasDo(User $user): array
    {
        return static::getContainer()->get(EntityManagerInterface::class)->getConnection()->fetchFirstColumn(
            'SELECT anexo_path FROM justificativa_ponto WHERE user_id = ? ORDER BY data',
            [$user->getId()],
        );
    }

    /** @return list<\DateTimeImmutable> dois dias úteis do mês anterior: passados e sem feriado */
    private function doisDiasUteisDoMesAnterior(): array
    {
        $segunda = new \DateTimeImmutable('first monday of last month');

        return [$segunda, $segunda->modify('+1 day')];
    }

    private function diretorio(): string
    {
        return (string) static::getContainer()->getParameter('justificativas_uploads_dir');
    }

    /** @return list<string> */
    private function arquivosNoDiretorio(): array
    {
        $diretorio = $this->diretorio();

        if (!is_dir($diretorio)) {
            return [];
        }

        return array_values(array_diff(scandir($diretorio) ?: [], ['.', '..']));
    }

    private function criarTenant(): Tenant
    {
        $em     = static::getContainer()->get(EntityManagerInterface::class);
        $tenant = new Tenant();
        $tenant->setName('Tenant ATESTADO ' . uniqid());
        $em->persist($tenant);
        $em->flush();

        return $tenant;
    }

    /** @param list<string> $papeis */
    private function criarUsuario(array $papeis = ['ROLE_USER']): User
    {
        $container = static::getContainer();
        $em        = $container->get(EntityManagerInterface::class);
        $hasher    = $container->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail('atestado_' . uniqid() . '@test.com');
        $user->setFullName('User ' . uniqid());
        $user->setRoles($papeis);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);
        $em->flush();

        return $user;
    }

    /** Vínculo ativo com role isSystem → bypassa o PermissionChecker, isolando o que se quer testar. */
    private function vincular(User $user, Tenant $tenant): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName('Admin ' . uniqid());
        $role->setIsSystem(true);
        $em->persist($role);

        $userTenant = new UserTenant($user, $tenant);
        $userTenant->setTenantRole($role);
        $em->persist($userTenant);
        $em->flush();
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
}
