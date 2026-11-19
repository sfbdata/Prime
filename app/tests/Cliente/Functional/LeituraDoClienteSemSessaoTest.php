<?php

declare(strict_types=1);

namespace App\Tests\Cliente\Functional;

use App\Cliente\Entity\ClienteDocumento;
use App\Cliente\Entity\ClientePF;
use App\Cliente\Exception\ClienteNaoEncontradoException;
use App\Cliente\Exception\SemPermissaoParaVerClienteException;
use App\Cliente\Service\LeituraDoCliente;
use App\Cliente\Service\PendenciasDoCadastro;
use App\Cliente\UseCase\ConferirDocumentoDoClienteUseCase;
use App\Cliente\UseCase\LerClienteUseCase;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Permission\Permission;
use App\Entity\Permission\ResourceAccess;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Entity\Tenant\TenantRolePermission;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Service\Tenant\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Leitura do cliente SEM sessão, sem `TenantContext` e sem TenantFilter, como o conector de I.A. chama (spec
 * mcp-remoto-oauth, 6.4, `ler_clientes`): pessoa e escritório vêm só por parâmetro. Cada guarda tem o seu teste, com o
 * controle que prova que o item recusado existe.
 */
#[CoversClass(LerClienteUseCase::class)]
#[CoversClass(LeituraDoCliente::class)]
#[CoversClass(ConferirDocumentoDoClienteUseCase::class)]
#[CoversClass(PendenciasDoCadastro::class)]
final class LeituraDoClienteSemSessaoTest extends KernelTestCase
{
    private int $seq = 0;

    protected function setUp(): void
    {
        self::bootKernel();
        self::assertNull(static::getContainer()->get(TenantContext::class)->getCurrentTenant(), 'o teste roda sem escritório na sessão');
        self::assertFalse($this->em()->getFilters()->isEnabled('tenant'), 'e sem o TenantFilter: o escritório vem só do parâmetro');
    }

    #[TestDox('ler: cliente de outro escritório e id inexistente dão a mesma recusa de não encontrado')]
    public function testOutroEscritorioNaoEncontrado(): void
    {
        $meu = $this->criarTenant();
        $outro = $this->criarTenant();
        $gestor = $this->criarGestor($meu);
        $alheio = $this->criarClientePF($outro, 'Cliente Alheio');
        $proprio = $this->criarClientePF($meu, 'Cliente Proprio');

        self::assertSame((int) $proprio->getId(), (int) $this->ler()->executar((int) $proprio->getId(), $gestor, $meu)->cliente->getId(), 'controle: o do escritório é lido');
        foreach ([(int) $alheio->getId(), 999_999_999] as $id) {
            try {
                $this->ler()->executar($id, $gestor, $meu);
                self::fail('cliente fora do escritório não pode ser lido: ' . $id);
            } catch (ClienteNaoEncontradoException) {
                self::addToAssertionCount(1);
            }
        }
    }

    #[TestDox('ler: sem permissão de ver o cliente, recusa')]
    public function testSemVerRecusa(): void
    {
        $tenant = $this->criarTenant();
        $cliente = $this->criarClientePF($tenant, 'Cliente Fechado');
        $semNada = $this->criarColaborador($tenant, []);

        $this->expectException(SemPermissaoParaVerClienteException::class);
        $this->ler()->executar((int) $cliente->getId(), $semNada, $tenant);
    }

    #[TestDox('ler: só as pastas que a pessoa abre, e o "pode editar" pela permissão de editar o cliente')]
    public function testSoAsPastasQueAPessoaAbre(): void
    {
        $tenant = $this->criarTenant();
        $gestor = $this->criarGestor($tenant);
        $cliente = $this->criarClientePF($tenant, 'Cliente Compartilhado');
        $liberada = $this->criarPasta($tenant, $gestor, $cliente, 'NUP-LER-LIB');
        $this->criarPasta($tenant, $gestor, $cliente, 'NUP-LER-PROIB');
        $colaborador = $this->criarColaborador($tenant, ['resources.cliente.view']);
        $this->conceder($colaborador, $tenant, (int) $liberada->getId());

        $doGestor = $this->ler()->executar((int) $cliente->getId(), $gestor, $tenant);
        self::assertCount(2, $doGestor->pastasVisiveis, 'controle: as duas pastas existem');
        self::assertTrue($doGestor->podeEditar);

        $doColaborador = $this->ler()->executar((int) $cliente->getId(), $colaborador, $tenant);
        self::assertSame([(int) $liberada->getId()], array_map(static fn (Pasta $p): int => (int) $p->getId(), $doColaborador->pastasVisiveis));
        self::assertFalse($doColaborador->podeEditar);
    }

    #[TestDox('ler: sem sessão, o documento vinculado resolve a pendência só para quem vê a pasta dele')]
    public function testVinculadoContaPorQuemOlha(): void
    {
        $tenant = $this->criarTenant();
        $gestor = $this->criarGestor($tenant);
        $cliente = $this->criarClientePF($tenant, 'Cliente Vinculado');
        $pasta = $this->criarPasta($tenant, $gestor, $cliente, 'NUP-LER-DOC');
        $origem = (new PastaDocumento())->setPasta($pasta)->setTenant($tenant)->setCategoria(PastaDocumento::CATEGORIA_IDENTIFICACAO)
            ->setTitulo('RG.pdf')->setNomeOriginal('RG.pdf')->setCaminhoArquivo('ler_' . bin2hex(random_bytes(6)) . '.pdf')
            ->setMimeType('application/pdf')->setTamanhoBytes(1234);
        $pasta->getDocumentos()->add($origem);
        $this->em()->persist($origem);
        $vinculo = ClienteDocumento::vinculadoA($origem, $cliente, $tenant);
        $cliente->getDocumentos()->add($vinculo);
        $this->em()->persist($vinculo);
        $this->em()->flush();
        $soCliente = $this->criarColaborador($tenant, ['resources.cliente.view']);

        $falta = 'Documento de identificação não anexado';
        self::assertNotContains($falta, array_column($this->ler()->executar((int) $cliente->getId(), $gestor, $tenant)->pendencias, 'texto'), 'quem vê a pasta: o vínculo conta');
        self::assertContains($falta, array_column($this->ler()->executar((int) $cliente->getId(), $soCliente, $tenant)->pendencias, 'texto'), 'quem não vê a pasta: não conta');
    }

    #[TestDox('conferir documento: acha com ou sem máscara, só no escritório, só se a pessoa vê, e nunca o próprio cliente')]
    public function testConferirDocumento(): void
    {
        $meu = $this->criarTenant();
        $outro = $this->criarTenant();
        $gestor = $this->criarGestor($meu);
        $cliente = $this->criarClientePF($meu, 'Cliente Documento');
        $cpf = (string) $cliente->getCpf();
        $mascarado = sprintf('%s.%s.%s-%s', substr($cpf, 0, 3), substr($cpf, 3, 3), substr($cpf, 6, 3), substr($cpf, 9, 2));
        $semVer = $this->criarColaborador($meu, []);
        $outroGestor = $this->criarGestor($outro);
        $conferir = static::getContainer()->get(ConferirDocumentoDoClienteUseCase::class);

        self::assertSame((int) $cliente->getId(), (int) $conferir->executar($cpf, $gestor, $meu)?->getId());
        self::assertSame((int) $cliente->getId(), (int) $conferir->executar($mascarado, $gestor, $meu)?->getId());
        self::assertNull($conferir->executar($cpf, $outroGestor, $outro), 'outro escritório não acha');
        self::assertNull($conferir->executar($cpf, $semVer, $meu), 'quem não vê o cliente não sabe que ele existe');
        self::assertNull($conferir->executar($cpf, $gestor, $meu, (int) $cliente->getId()), 'o próprio cliente não é "outro cadastro"');
        self::assertNull($conferir->executar(substr($cpf, 0, 10), $gestor, $meu), 'documento incompleto não procura');
    }

    // ----------------------------------------------------------------- ajudantes

    private function ler(): LerClienteUseCase
    {
        return static::getContainer()->get(LerClienteUseCase::class);
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function criarTenant(): Tenant
    {
        $tenant = (new Tenant())->setName('Tenant Leitura ' . uniqid());
        $this->em()->persist($tenant);
        $this->em()->flush();

        return $tenant;
    }

    private function novoUsuario(string $prefixo): User
    {
        $user = new User();
        $user->setEmail($prefixo . '_' . uniqid() . '@test.com');
        $user->setFullName(ucfirst($prefixo) . ' ' . uniqid());
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword('x');
        $this->em()->persist($user);

        return $user;
    }

    private function criarGestor(Tenant $tenant): User
    {
        $user = $this->novoUsuario('gestor');
        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName('Gestor ' . uniqid());
        $role->setIsSystem(true);
        $this->em()->persist($role);
        $ut = new UserTenant($user, $tenant);
        $ut->setTenantRole($role);
        $this->em()->persist($ut);
        $this->em()->flush();

        return $user;
    }

    /** @param list<string> $codigos */
    private function criarColaborador(Tenant $tenant, array $codigos): User
    {
        $em = $this->em();
        $user = $this->novoUsuario('colab');
        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName('Colaborador ' . uniqid());
        $role->setIsSystem(false);
        $em->persist($role);
        foreach ($codigos as $codigo) {
            $perm = $em->getRepository(Permission::class)->findOneBy(['code' => $codigo]);
            if ($perm === null) {
                $perm = new Permission();
                $perm->setCode($codigo);
                $perm->setDescription($codigo);
                $perm->setGroup('resources');
                $em->persist($perm);
            }
            $vinculo = new TenantRolePermission();
            $vinculo->setTenantRole($role);
            $vinculo->setPermission($perm);
            $em->persist($vinculo);
            $role->getTenantRolePermissions()->add($vinculo);
        }
        $ut = new UserTenant($user, $tenant);
        $ut->setTenantRole($role);
        $em->persist($ut);
        $em->flush();

        return $user;
    }

    private function conceder(User $user, Tenant $tenant, int $pastaId): void
    {
        $ra = new ResourceAccess();
        $ra->setUser($user);
        $ra->setTenant($tenant);
        $ra->setResourceType(ResourceAccess::RESOURCE_PASTA);
        $ra->setResourceId($pastaId);
        $ra->setCanView(true);
        $this->em()->persist($ra);
        $this->em()->flush();
    }

    private function criarClientePF(Tenant $tenant, string $nome): ClientePF
    {
        $n = ++$this->seq;
        $cliente = new ClientePF();
        $cliente->setNomeCompleto($nome);
        $cliente->setCpf(sprintf('98765432%03d', $n));
        $cliente->setRg('654321' . $n);
        $cliente->setRgOrgaoExpedidor('SSP/DF');
        $cliente->setEmail('leitura_' . uniqid() . '@test.com');
        $cliente->setCep('70000-000');
        $cliente->setEndereco('SQS 110 Bloco A');
        $cliente->setCidade('Brasília');
        $cliente->setEstado('DF');
        $cliente->setTenant($tenant);
        $this->em()->persist($cliente);
        $this->em()->flush();

        return $cliente;
    }

    private function criarPasta(Tenant $tenant, User $criador, ClientePF $cliente, string $nup): Pasta
    {
        $pasta = new Pasta();
        $pasta->setNup($nup . '-' . strtoupper(substr(uniqid(), -5)));
        $pasta->setTenant($tenant);
        $pasta->setCriadoPor($criador);
        $pasta->setResponsavel($criador);
        $pasta->setNomeAcao('Ação de teste');
        $pasta->addCliente($cliente);
        $this->em()->persist($pasta);
        $this->em()->flush();

        return $pasta;
    }
}
