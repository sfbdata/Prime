<?php

declare(strict_types=1);

namespace App\Tests\ServiceDesk\Functional;

use App\Controller\ServiceDeskController;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Notificacao;
use App\Entity\ServiceDesk\Chamado;
use App\Entity\ServiceDesk\ChamadoAnexo;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\ServiceDesk\Armazenamento\ChavesDeServiceDesk;
use App\Shared\Armazenamento\CategoriaDeArquivo;
use App\Shared\Armazenamento\Exception\FalhaDeArmazenamento;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Shared\Doubles\ArmazenamentoEmMemoriaNoContainer;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[CoversClass(ServiceDeskController::class)]
final class CriarChamadoControllerTest extends JusPrimeWebTestCase
{
    private const PDF = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";

    /** @var list<string> arquivos criados no disco (o DAMA reverte o banco, não o disco) */
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

    #[TestDox('POST /servicedesk/novo abre o chamado e notifica os gestores, exceto o solicitante')]
    public function testCriaChamadoENotificaGestores(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $solicitante = $this->criarUsuario($tenant, 'solic_' . uniqid() . '@test.com');
        $gestor      = $this->criarUsuario($tenant, 'gestor_' . uniqid() . '@test.com');

        $this->logarComTenant($client, $solicitante, $tenant);

        $crawler = $client->request('GET', '/servicedesk/novo');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Abrir Chamado')->form([
            'chamado[titulo]'     => 'Não consigo acessar o sistema',
            'chamado[descricao]'  => 'Aparece erro ao fazer login',
            'chamado[categoria]'  => Chamado::CATEGORIA_SOFTWARE,
            'chamado[prioridade]' => Chamado::PRIORIDADE_ALTA,
        ]);
        $client->submit($form);

        self::assertResponseRedirects();

        $em = static::getContainer()->get(EntityManagerInterface::class);

        $chamados = $em->getRepository(Chamado::class)->findAll();
        self::assertCount(1, $chamados, 'o chamado deve ter sido criado');
        self::assertSame('Não consigo acessar o sistema', $chamados[0]->getTitulo());

        $notifsGestor = $em->getRepository(Notificacao::class)->findBy(['usuario' => $gestor]);
        self::assertCount(1, $notifsGestor, 'o gestor deve receber a notificação de novo chamado');
        self::assertSame(Notificacao::TIPO_SERVICEDESK_NOVO, $notifsGestor[0]->getTipo());

        $notifsSolicitante = $em->getRepository(Notificacao::class)->findBy(['usuario' => $solicitante]);
        self::assertCount(0, $notifsSolicitante, 'o solicitante não é notificado do próprio chamado');
    }

    /**
     * Até a E2.4A este fluxo SEMPRE quebrava: o tamanho era lido do `UploadedFile` depois do
     * `move()`, estourava "stat failed", a resposta era 500 e o arquivo ficava órfão no disco.
     */
    #[TestDox('POST /servicedesk/novo com anexo PDF grava o arquivo, registra o anexo com o tamanho real e redireciona')]
    public function testCriaChamadoComAnexoGravaArquivoERegistraTamanhoReal(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $solicitante = $this->criarUsuario($tenant, 'solic_' . uniqid() . '@test.com');

        $this->logarComTenant($client, $solicitante, $tenant);

        $crawler = $client->request('GET', '/servicedesk/novo');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Abrir Chamado')->form([
            'chamado[titulo]'     => 'Impressora não imprime',
            'chamado[descricao]'  => 'Segue o manual em anexo',
            'chamado[categoria]'  => Chamado::CATEGORIA_SOFTWARE,
            'chamado[prioridade]' => Chamado::PRIORIDADE_MEDIA,
        ]);

        $diretorio = (string) static::getContainer()->getParameter('chamados_uploads_dir');
        $antes     = $this->arquivosEm($diretorio);

        $origem = sys_get_temp_dir() . '/chamado_anexo_' . bin2hex(random_bytes(6));
        file_put_contents($origem, self::PDF);
        $this->arquivosCriados[] = $origem;

        $client->request('POST', $form->getUri(), $form->getPhpValues(), [
            'chamado' => ['anexos' => [new UploadedFile($origem, 'manual.pdf', 'application/pdf', null, true)]],
        ]);

        // Todo arquivo que surgir no diretório durante a requisição é deste teste — inclusive o
        // órfão que sobraria se a requisição quebrasse depois da gravação.
        foreach (array_diff($this->arquivosEm($diretorio), $antes) as $novo) {
            $this->arquivosCriados[] = $diretorio . '/' . $novo;
        }

        self::assertResponseRedirects();

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        $chamados = $em->getRepository(Chamado::class)->findAll();
        self::assertCount(1, $chamados, 'o chamado deve ter sido criado');

        $anexos = $em->getRepository(ChamadoAnexo::class)->findBy(['chamado' => $chamados[0]]);
        self::assertCount(1, $anexos, 'o anexo deve ter sido registrado');

        $anexo   = $anexos[0];
        $caminho = $diretorio . '/' . $anexo->getNomeArquivo();

        self::assertMatchesRegularExpression('/^[0-9a-f]{32}\.pdf$/', $anexo->getNomeArquivo());
        self::assertFileExists($caminho, 'o anexo tem de estar em %chamados_uploads_dir%');
        self::assertStringEqualsFile($caminho, self::PDF);
        self::assertSame(\strlen(self::PDF), $anexo->getTamanho(), 'o tamanho é o do arquivo gravado');
        self::assertSame('manual.pdf', $anexo->getNomeOriginal());
        self::assertSame('application/pdf', $anexo->getMimeType());
    }

    /**
     * R1: no disco o escopo da chave não aparece (a categoria é plana). Contra o dublê em memória,
     * a chave gravada tem de ser exatamente a que a leitura monta a partir do anexo persistido.
     */
    #[TestDox('R1: a chave gravada é a mesma que a leitura monta a partir do anexo do chamado')]
    public function testChaveGravadaEhAMesmaDaLeitura(): void
    {
        $client = static::createClient();
        $client->disableReboot(); // o formulário vem de um GET; o dublê tem de valer nas duas requisições
        $duble  = ArmazenamentoEmMemoriaNoContainer::instalarEm(static::getContainer());
        $tenant = $this->criarTenant();
        $this->logarComTenant($client, $this->criarUsuario($tenant, 'solic_' . uniqid() . '@test.com'), $tenant);

        $this->abrirChamadoComAnexo($client);

        self::assertResponseRedirects();

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $anexos = $em->getRepository(ChamadoAnexo::class)->findAll();
        self::assertCount(1, $anexos);

        $gravada = $duble->memoria->ultimaGravada();
        self::assertTrue($gravada->ehIgualA(ChavesDeServiceDesk::anexoDeChamado($anexos[0])), 'gravação e leitura divergem');
        self::assertSame(CategoriaDeArquivo::CHAMADO_ANEXO, $gravada->categoria);
        self::assertSame($tenant->getId(), $gravada->escopo->tenantIdOuNull());
    }

    #[TestDox('falha do storage: nem o chamado nem o anexo são registrados')]
    public function testFalhaDoStorageNaoRegistraChamado(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $duble                         = ArmazenamentoEmMemoriaNoContainer::instalarEm(static::getContainer());
        $duble->memoria->falhaAoGravar = new FalhaDeArmazenamento('disco cheio');
        $tenant                        = $this->criarTenant();
        $this->logarComTenant($client, $this->criarUsuario($tenant, 'solic_' . uniqid() . '@test.com'), $tenant);

        $this->abrirChamadoComAnexo($client);

        self::assertResponseStatusCodeSame(500);
        self::assertStringContainsString('disco cheio', (string) $client->getResponse()->getContent(), 'o 500 tem de ser a falha de gravação, não outra');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertSame([], $em->getRepository(Chamado::class)->findAll());
        self::assertSame([], $em->getRepository(ChamadoAnexo::class)->findAll());
    }

    private function abrirChamadoComAnexo(KernelBrowser $client): void
    {
        $crawler = $client->request('GET', '/servicedesk/novo');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Abrir Chamado')->form([
            'chamado[titulo]'     => 'Impressora não imprime',
            'chamado[descricao]'  => 'Segue o manual em anexo',
            'chamado[categoria]'  => Chamado::CATEGORIA_SOFTWARE,
            'chamado[prioridade]' => Chamado::PRIORIDADE_MEDIA,
        ]);

        $origem = sys_get_temp_dir() . '/chamado_anexo_' . bin2hex(random_bytes(6));
        file_put_contents($origem, self::PDF);
        $this->arquivosCriados[] = $origem;

        $client->request('POST', $form->getUri(), $form->getPhpValues(), [
            'chamado' => ['anexos' => [new UploadedFile($origem, 'manual.pdf', 'application/pdf', null, true)]],
        ]);
    }

    /** @return list<string> */
    private function arquivosEm(string $diretorio): array
    {
        if (!is_dir($diretorio)) {
            return [];
        }

        return array_values(array_diff(scandir($diretorio) ?: [], ['.', '..']));
    }

    private function criarTenant(): Tenant
    {
        $em     = static::getContainer()->get(EntityManagerInterface::class);
        $tenant = new Tenant();
        $tenant->setName('Tenant SD ' . uniqid());
        $em->persist($tenant);
        $em->flush();

        return $tenant;
    }

    private function criarUsuario(Tenant $tenant, string $email): User
    {
        $container = static::getContainer();
        $em        = $container->get(EntityManagerInterface::class);
        $hasher    = $container->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail($email);
        $user->setFullName('Usuário ' . uniqid());
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);

        // Papel de sistema → gestor do tenant (passa em canAccessModule e canAdminister).
        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName('Administrador ' . uniqid());
        $role->setIsSystem(true);
        $em->persist($role);

        $userTenant = new UserTenant($user, $tenant);
        $userTenant->setTenantRole($role);
        $em->persist($userTenant);
        $em->flush();

        return $user;
    }
}
