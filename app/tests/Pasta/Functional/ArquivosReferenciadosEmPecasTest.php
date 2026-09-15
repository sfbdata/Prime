<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Repository\PastaDocumentoRepository;
use App\Pasta\Service\ArquivosReferenciadosEmPecas;
use App\Pasta\Service\ReferenciasDePecaHtml;
use App\Shared\Service\ArquivoStorageInterface;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * A prova da regra que a E1 institui:
 *
 *   > "Arquivo sem linha própria no banco" NÃO significa "arquivo órfão".
 *
 * A imagem do editor usada aqui NÃO tem linha em `pasta_documento` — exatamente como em produção,
 * onde `UploadImagemEditorUseCase` devolve o nome e não persiste nada. Qualquer critério do tipo
 * `{disco} − {banco}` a classificaria como órfã e a apagaria.
 */
#[CoversClass(ArquivosReferenciadosEmPecas::class)]
final class ArquivosReferenciadosEmPecasTest extends JusPrimeWebTestCase
{
    #[TestDox('Imagem SEM linha no banco, citada no HTML da peça, é reconhecida como referenciada')]
    public function testImagemSemLinhaNoBancoEstaReferenciada(): void
    {
        self::bootKernel();
        [$tenant]   = $this->criarTenantComUsuario();
        $nomeImagem = 'imagem_' . bin2hex(random_bytes(8)) . '.png';

        $this->criarPecaHtml($tenant, '<p>Peça</p><img src="../../uploads/pastas/' . $nomeImagem . '">');

        $servico = $this->servico();

        self::assertContains($nomeImagem, $servico->doTenant($tenant));
        self::assertTrue(
            $servico->estaReferenciado($nomeImagem, $tenant),
            'a imagem está viva dentro do HTML — apagá-la quebraria a peça',
        );
    }

    #[TestDox('Imagem citada por URL absoluta também é reconhecida')]
    public function testUrlAbsolutaTambemEReconhecida(): void
    {
        self::bootKernel();
        [$tenant]   = $this->criarTenantComUsuario();
        $nomeImagem = 'abs_' . bin2hex(random_bytes(8)) . '.png';

        $this->criarPecaHtml($tenant, '<img src="/uploads/pastas/' . $nomeImagem . '">');

        $servico = $this->servico();

        self::assertContains($nomeImagem, $servico->doTenant($tenant));
    }

    #[TestDox('Arquivo que ninguém cita NÃO é reportado como referenciado')]
    public function testArquivoNaoCitadoNaoEReferenciado(): void
    {
        self::bootKernel();
        [$tenant] = $this->criarTenantComUsuario();

        $this->criarPecaHtml($tenant, '<p>Peça sem imagem nenhuma.</p>');

        $servico = $this->servico();

        self::assertFalse($servico->estaReferenciado('nunca_citado.png', $tenant));
    }

    #[TestDox('A busca é escopada por tenant: peça de outro escritório não vaza referência')]
    public function testNaoVazaEntreTenants(): void
    {
        self::bootKernel();
        [$tenantA] = $this->criarTenantComUsuario();
        [$tenantB] = $this->criarTenantComUsuario();

        $nomeImagem = 'doA_' . bin2hex(random_bytes(8)) . '.png';
        $this->criarPecaHtml($tenantA, '<img src="/uploads/pastas/' . $nomeImagem . '">');

        $servico = $this->servico();

        self::assertContains($nomeImagem, $servico->doTenant($tenantA));
        self::assertNotContains(
            $nomeImagem,
            $servico->doTenant($tenantB),
            'a referência do escritório A não pode aparecer para o escritório B',
        );
    }

    #[TestDox('Peça cujo arquivo sumiu do disco é ignorada, sem estourar')]
    public function testPecaComArquivoAusenteNaoEstoura(): void
    {
        self::bootKernel();
        [$tenant] = $this->criarTenantComUsuario();

        $em    = static::getContainer()->get(EntityManagerInterface::class);
        $pasta = $this->criarPasta($tenant);

        $doc = new PastaDocumento();
        $doc->setTitulo('peça fantasma');
        $doc->setCategoria(PastaDocumento::CATEGORIA_DEMAIS);
        $doc->setCaminhoArquivo('nao_existe_no_disco.html');
        $doc->setNomeOriginal('peca.html');
        $doc->setMimeType('text/html');
        $doc->setTamanhoBytes(10);
        $doc->setOrdem(1);
        $doc->setPasta($pasta);
        $doc->setTenant($tenant);
        $em->persist($doc);
        $em->flush();

        $servico = $this->servico();

        self::assertSame([], $servico->doTenant($tenant));
    }

    // ------------------------------------------------------------------ helpers

    /**
     * Montado à mão, e não pelo container, porque o serviço ainda NÃO tem consumidor em `src/`:
     * nenhuma rotina de limpeza foi escrita nesta E1 (decisão registrada na spec), então o
     * compilador do Symfony o remove como inlined. Quando a primeira rotina existir, ela o
     * injetará normalmente e este helper pode virar um `get()`.
     */
    private function servico(): ArquivosReferenciadosEmPecas
    {
        return new ArquivosReferenciadosEmPecas(
            static::getContainer()->get(PastaDocumentoRepository::class),
            static::getContainer()->get(ArquivoStorageInterface::class),
            new ReferenciasDePecaHtml(),
            (string) static::getContainer()->getParameter('uploads_dir'),
        );
    }

    private function criarPecaHtml(Tenant $tenant, string $html): PastaDocumento
    {
        $em        = static::getContainer()->get(EntityManagerInterface::class);
        $storage   = static::getContainer()->get(ArquivoStorageInterface::class);
        $uploadsDir = (string) static::getContainer()->getParameter('uploads_dir');

        $nomeArquivo = $storage->salvarConteudo($html, $uploadsDir, 'html');

        $doc = new PastaDocumento();
        $doc->setTitulo('Peça de teste');
        $doc->setCategoria(PastaDocumento::CATEGORIA_DEMAIS);
        $doc->setCaminhoArquivo($nomeArquivo);
        $doc->setNomeOriginal('peca.html');
        $doc->setMimeType('text/html');
        $doc->setTamanhoBytes(\strlen($html));
        $doc->setOrdem(1);
        $doc->setPasta($this->criarPasta($tenant));
        $doc->setTenant($tenant);
        $em->persist($doc);
        $em->flush();

        return $doc;
    }

    private function criarPasta(Tenant $tenant): Pasta
    {
        $em    = static::getContainer()->get(EntityManagerInterface::class);
        $pasta = new Pasta();
        $pasta->setNup('TEST-REF-' . uniqid());
        $pasta->setTenant($tenant);
        $em->persist($pasta);
        $em->flush();

        return $pasta;
    }

    /** @return array{0: Tenant, 1: User} */
    private function criarTenantComUsuario(): array
    {
        $em     = static::getContainer()->get(EntityManagerInterface::class);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $tenant = new Tenant();
        $tenant->setName('Tenant REF ' . uniqid());
        $em->persist($tenant);

        $user = new User();
        $user->setEmail('ref_' . uniqid() . '@test.com');
        $user->setFullName('Usuário Ref');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);

        $em->persist(new UserTenant($user, $tenant));
        $em->flush();

        return [$tenant, $user];
    }
}
