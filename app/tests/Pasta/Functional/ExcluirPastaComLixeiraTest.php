<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Pasta\Armazenamento\ChavesDePasta;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Entity\PastaSecao;
use App\Pasta\UseCase\ExcluirPastaUseCase;
use App\Shared\Armazenamento\ArmazenamentoDeArquivos;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\FonteDeConteudo;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * Apagar DE VERDADE uma pasta (a última da sequência) que tem itens na LIXEIRA da aba Documentos
 * (D7): o documento solto na raiz e o documento dentro de uma subpasta excluída.
 *
 * O defeito que isto fecha: com o `LixeiraFilter` ligado, o cascade do ORM não enxergava o
 * documento na lixeira, e a FK `pasta_documento.pasta_id` (sem ON DELETE CASCADE) derrubava o
 * DELETE da pasta — um 500 na tela. Agora as linhas (vivas e da lixeira) e os arquivos saem, pela
 * rota real, sem erro.
 */
#[CoversClass(ExcluirPastaUseCase::class)]
final class ExcluirPastaComLixeiraTest extends JusPrimeWebTestCase
{
    /** @var list<ChaveDeArquivo> */
    private array $arquivosGravados = [];

    protected function tearDown(): void
    {
        $armazenamento = static::getContainer()->get(ArmazenamentoDeArquivos::class);
        foreach ($this->arquivosGravados as $chave) {
            if ($armazenamento->existe($chave)) {
                $armazenamento->excluir($chave);
            }
        }
        $this->arquivosGravados = [];

        parent::tearDown();
    }

    #[TestDox('última da sequência com documento na lixeira (na raiz e dentro de subpasta excluída): linhas e arquivos saem, sem 500')]
    public function testApagaDeVerdadeComItensNaLixeira(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->instalarCsrfStorage();

        $em     = static::getContainer()->get(EntityManagerInterface::class);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $tenant = new Tenant();
        $tenant->setName('Tenant Lixeira x Pasta ' . uniqid());
        $em->persist($tenant);

        $user = new User();
        $user->setEmail('lixpasta_' . uniqid() . '@test.com');
        $user->setFullName('Admin');
        $user->setRoles(['ROLE_SUPER_ADMIN']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);
        $em->persist(new UserTenant($user, $tenant));

        // Única pasta do escritório: é a última da sequência → remoção real, não lápide.
        $pasta = new Pasta();
        $pasta->setNup('77');
        $pasta->setTenant($tenant);
        $em->persist($pasta);

        $secaoNaLixeira = new PastaSecao();
        $secaoNaLixeira->setPasta($pasta);
        $secaoNaLixeira->setTenant($tenant);
        $secaoNaLixeira->setNome('EXCLUIDA');
        $secaoNaLixeira->setOrdem(1);
        $em->persist($secaoNaLixeira);
        $em->flush();

        $vivo        = $this->documento($pasta, $tenant, null, 'vivo.pdf');
        $naRaiz      = $this->documento($pasta, $tenant, null, 'na-raiz.pdf');
        $dentro      = $this->documento($pasta, $tenant, $secaoNaLixeira, 'dentro.pdf');
        $carimbo     = new \DateTimeImmutable('2026-10-01 10:00:00');
        $naRaiz->marcarExcluido($user, $carimbo);
        $secaoNaLixeira->marcarArvoreExcluida($user, $carimbo);
        $em->flush();

        $ids = ['pasta' => (int) $pasta->getId(), 'secao' => (int) $secaoNaLixeira->getId(), 'docs' => [(int) $vivo->getId(), (int) $naRaiz->getId(), (int) $dentro->getId()]];
        $chaves = array_map(fn (PastaDocumento $d) => ChavesDePasta::documentoPorNome((int) $tenant->getId(), $d->getCaminhoArquivo()), [$vivo, $naRaiz, $dentro]);
        $armazenamento = static::getContainer()->get(ArmazenamentoDeArquivos::class);

        $this->logarComTenant($client, $user, $tenant);
        $em->clear();

        $client->request('POST', "/pasta/{$ids['pasta']}/deletar", [
            '_token'        => 'TOKEN_delete_pasta_' . $ids['pasta'],
            'confirmar_nup' => '77',
        ]);

        self::assertTrue($client->getResponse()->isRedirect(), 'sem 500: ' . $client->getResponse()->getStatusCode());
        $conn = $em->getConnection();
        self::assertSame(0, (int) $conn->fetchOne('SELECT COUNT(*) FROM pasta WHERE id = ?', [$ids['pasta']]), 'a pasta saiu de verdade');
        self::assertSame(0, (int) $conn->fetchOne('SELECT COUNT(*) FROM pasta_secao WHERE id = ?', [$ids['secao']]));
        foreach ($ids['docs'] as $docId) {
            self::assertSame(0, (int) $conn->fetchOne('SELECT COUNT(*) FROM pasta_documento WHERE id = ?', [$docId]), "documento #{$docId} devia ter saído (vivo ou na lixeira)");
        }
        foreach ($chaves as $i => $chave) {
            self::assertFalse($armazenamento->existe($chave), "arquivo #{$i} ficou órfão no disco");
        }
    }

    private function documento(Pasta $pasta, Tenant $tenant, ?PastaSecao $secao, string $nome): PastaDocumento
    {
        $em    = static::getContainer()->get(EntityManagerInterface::class);
        $chave = static::getContainer()->get(ArmazenamentoDeArquivos::class)->gravar(
            ChavesDePasta::novoDocumento((new PastaDocumento())->setTenant($tenant), 'pdf'),
            FonteDeConteudo::deTexto('conteudo-' . $nome),
        )->chave;
        $this->arquivosGravados[] = $chave;

        $doc = (new PastaDocumento())
            ->setTitulo($nome)
            ->setCategoria(PastaDocumento::CATEGORIA_DEMAIS)
            ->setCaminhoArquivo($chave->nome)
            ->setNomeOriginal($nome)
            ->setMimeType('application/pdf')
            ->setTamanhoBytes(10)
            ->setPasta($pasta)
            ->setTenant($tenant)
            ->setSecao($secao);
        $secao?->getDocumentos()->add($doc);
        $em->persist($doc);
        $em->flush();

        return $doc;
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
