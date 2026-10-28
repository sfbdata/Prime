<?php

declare(strict_types=1);

namespace App\Tests\Shared\Functional;

use App\Controller\PastaController;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Pasta\Armazenamento\ChavesDePasta;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Shared\Armazenamento\ArmazenamentoDeArquivos;
use App\Shared\Armazenamento\ArmazenamentoLocal;
use App\Shared\Armazenamento\CategoriaDeArquivo;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\EscopoDeArquivo;
use App\Shared\Armazenamento\FonteDeConteudo;
use App\Shared\Armazenamento\NovoArquivo;
use App\Shared\EspelhoRemoto\ArmazenamentoEspelhado;
use App\Shared\EspelhoRemoto\FabricaDoEspelhoRemoto;
use App\Shared\EspelhoRemoto\LayoutDeChaveRemota;
use App\Shared\EspelhoRemoto\PoliticaDeEspelhamento;
use App\Shared\Http\EntregaDeArquivo;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Shared\Doubles\EspelhoRemotoEmMemoria;
use App\Tests\Shared\Doubles\ProvedorDoEspelhoFixo;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * A entrega pela rota de um documento da pasta que saiu do disco e está no R2 (spec
 * `leitura-pelo-r2-e-liberacao-de-disco.md`, §3.3, functional por categoria).
 *
 * Container de teste de verdade: o arquivo é gravado pelo armazenamento do container com a categoria
 * ligada, a linha da outbox é marcada `verificado` (o que o worker faria), o objeto vai para o espelho
 * dublê e o arquivo sai do disco pelo backend local. O espelho entra trocando a
 * {@see FabricaDoEspelhoRemoto} no container (o serviço privado aceita substituição antes da primeira
 * requisição); o `disableReboot()` mantém a troca entre as requisições do mesmo teste.
 */
#[CoversClass(EntregaDeArquivo::class)]
#[CoversClass(ArmazenamentoEspelhado::class)]
#[CoversClass(PastaController::class)]
final class EntregaPeloEspelhoTest extends JusPrimeWebTestCase
{
    private const PDF = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\nsó no R2\n";

    private EspelhoRemotoEmMemoria $espelho;
    private KernelBrowser $cliente;

    /** @var list<ChaveDeArquivo> */
    private array $gravados = [];

    protected function setUp(): void
    {
        $this->cliente = static::createClient();
        $this->cliente->disableReboot();
        $this->espelho = new EspelhoRemotoEmMemoria();

        $container = static::getContainer();
        $container->set(PoliticaDeEspelhamento::class, new PoliticaDeEspelhamento('pasta_documento'));
        $container->set(FabricaDoEspelhoRemoto::class, ProvedorDoEspelhoFixo::com($this->espelho));
    }

    protected function tearDown(): void
    {
        $local = static::getContainer()->get(ArmazenamentoLocal::class);
        foreach ($this->gravados as $chave) {
            $local->excluir($chave);
        }
        $this->gravados = [];
        $cache = static::getContainer()->getParameter('espelho_cache_dir');
        if (\is_string($cache) && is_file($cache . '/' . hash('sha256', self::PDF))) {
            unlink($cache . '/' . hash('sha256', self::PDF));
        }

        parent::tearDown();
    }

    #[TestDox('documento só no R2: 200 inline com o MESMO conteúdo (SHA igual), e a 2ª requisição com Range sai do cache como 206')]
    public function testEntregaPeloEspelhoComRange(): void
    {
        [$user, $tenant] = $this->criarSuperAdmin();
        $doc             = $this->documentoSoNoEspelho($tenant);
        $this->logarComTenant($this->cliente, $user, $tenant);

        $this->cliente->request('GET', '/pasta/documento/' . $doc->getId() . '/visualizar');

        self::assertResponseIsSuccessful();
        self::assertInstanceOf(BinaryFileResponse::class, $this->cliente->getResponse());
        self::assertResponseHeaderSame('Content-Disposition', 'inline; filename=Contrato.pdf');
        self::assertResponseHeaderSame('Content-Type', 'application/pdf');
        self::assertResponseHeaderSame('Accept-Ranges', 'bytes');
        self::assertSame(hash('sha256', self::PDF), hash('sha256', (string) $this->cliente->getInternalResponse()->getContent()));

        $this->cliente->request('GET', '/pasta/documento/' . $doc->getId() . '/visualizar', server: ['HTTP_RANGE' => 'bytes=0-3']);

        self::assertResponseStatusCodeSame(206);
        self::assertResponseHeaderSame('Content-Range', 'bytes 0-3/' . \strlen(self::PDF));
        self::assertSame('%PDF', $this->cliente->getInternalResponse()->getContent());
        self::assertSame(1, $this->espelho->downloads, 'o Range saiu do cache, sem baixar de novo');
    }

    #[TestDox('download do documento só no R2: 200 attachment, com o conteúdo inteiro')]
    public function testDownloadPeloEspelho(): void
    {
        [$user, $tenant] = $this->criarSuperAdmin();
        $doc             = $this->documentoSoNoEspelho($tenant);
        $this->logarComTenant($this->cliente, $user, $tenant);

        $this->cliente->request('GET', '/pasta/documento/' . $doc->getId() . '/download');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Disposition', 'attachment; filename=Contrato.pdf');
        self::assertSame(self::PDF, $this->cliente->getInternalResponse()->getContent());
    }

    #[TestDox('R2 fora do ar com o arquivo provado: 503, nunca 404, e o conteúdo não sai')]
    public function testR2ForaDoArEh503(): void
    {
        [$user, $tenant] = $this->criarSuperAdmin();
        $doc             = $this->documentoSoNoEspelho($tenant);
        $this->espelho->foraDoAr = true;
        $this->logarComTenant($this->cliente, $user, $tenant);

        $this->cliente->request('GET', '/pasta/documento/' . $doc->getId() . '/visualizar');

        self::assertResponseStatusCodeSame(503);
        self::assertStringNotContainsString('só no R2', (string) $this->cliente->getResponse()->getContent());
    }

    #[TestDox('documento só no R2 de OUTRO escritório: quem não é dele não chega à entrega (404 da posse), e o R2 não é chamado')]
    public function testOutroEscritorioNaoRecebe(): void
    {
        [, $tenantDono]     = $this->criarSuperAdmin();
        $doc                = $this->documentoSoNoEspelho($tenantDono);
        [$intruso, $outro]  = $this->criarUsuarioComum();
        $this->logarComTenant($this->cliente, $intruso, $outro);

        $this->cliente->request('GET', '/pasta/documento/' . $doc->getId() . '/visualizar');

        self::assertContains($this->cliente->getResponse()->getStatusCode(), [403, 404]);
        self::assertSame(0, $this->espelho->downloads);
    }

    /** Grava pela cadeia do container (categoria ligada), marca verificado, espelha e tira do disco. */
    private function documentoSoNoEspelho(Tenant $tenant): PastaDocumento
    {
        $container  = static::getContainer();
        $armazenado = $container->get(ArmazenamentoDeArquivos::class)->gravar(
            new NovoArquivo(EscopoDeArquivo::deTenant((int) $tenant->getId()), CategoriaDeArquivo::PASTA_DOCUMENTO, 'pdf'),
            FonteDeConteudo::deTexto(self::PDF),
        );
        $chave            = $armazenado->chave;
        $this->gravados[] = $chave;

        $conexao = $container->get(Connection::class);
        $linhas  = $conexao->executeStatement(
            "UPDATE armazenamento_replicacao SET estado = 'verificado' WHERE chave_remota = ? AND operacao = 'enviar'",
            [LayoutDeChaveRemota::chaveDe($chave)],
        );
        self::assertSame(1, $linhas, 'a gravação com a categoria ligada registrou o envio');

        $this->espelho->semear($chave, self::PDF, null, 'application/pdf');
        $container->get(ArmazenamentoLocal::class)->excluir($chave);
        self::assertFalse($container->get(ArmazenamentoLocal::class)->existe($chave), 'saiu do disco');

        $em    = $container->get(EntityManagerInterface::class);
        $pasta = new Pasta();
        $pasta->setNup('NUP-R2-' . uniqid());
        $pasta->setTenant($tenant);
        $em->persist($pasta);

        $doc = new PastaDocumento();
        $doc->setTitulo('Contrato.pdf');
        $doc->setCategoria(PastaDocumento::CATEGORIA_DEMAIS);
        $doc->setCaminhoArquivo($chave->nome);
        $doc->setNomeOriginal('Contrato.pdf');
        $doc->setMimeType('application/pdf');
        $doc->setTamanhoBytes(\strlen(self::PDF));
        $doc->setPasta($pasta);
        $doc->setTenant($tenant);
        $pasta->addDocumento($doc);
        $em->persist($doc);
        $em->flush();

        self::assertSame(LayoutDeChaveRemota::chaveDe($chave), LayoutDeChaveRemota::chaveDe(ChavesDePasta::documento($doc)), 'a rota monta a mesma chave');

        return $doc;
    }

    /** @return array{0: User, 1: Tenant} */
    private function criarSuperAdmin(): array
    {
        return $this->criarUsuarioNoEscritorio(['ROLE_SUPER_ADMIN']);
    }

    /** @return array{0: User, 1: Tenant} */
    private function criarUsuarioComum(): array
    {
        return $this->criarUsuarioNoEscritorio(['ROLE_USER']);
    }

    /**
     * @param list<string> $papeis
     *
     * @return array{0: User, 1: Tenant}
     */
    private function criarUsuarioNoEscritorio(array $papeis): array
    {
        $container = static::getContainer();
        $em        = $container->get(EntityManagerInterface::class);

        $tenant = new Tenant();
        $tenant->setName('Tenant R2 ' . uniqid());
        $em->persist($tenant);

        $user = new User();
        $user->setEmail('entrega_r2_' . uniqid() . '@test.com');
        $user->setFullName('Usuário Entrega R2');
        $user->setRoles($papeis);
        $user->setIsActive(true);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, 'senha123'));
        $em->persist($user);
        $em->persist(new UserTenant($user, $tenant));
        $em->flush();

        return [$user, $tenant];
    }
}
