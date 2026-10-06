<?php

declare(strict_types=1);

namespace App\Tests\Sync\Functional;

use App\Entity\Auth\User;
use App\Pasta\Armazenamento\ChavesDePasta;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Entity\PastaSecao;
use App\Shared\Armazenamento\ArmazenamentoDeArquivos;
use App\Shared\Armazenamento\FonteDeConteudo;
use App\Sync\Enum\ModoSincronizacao;
use App\Sync\Service\ReconciliadorDePasta;
use App\Tests\Factory\Pasta\PastaFactory;
use App\Tests\Factory\Tenant\TenantFactory;
use App\Tests\Sync\Support\FakeGoogleDriveClient;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * O reconciliador do Drive lê `pasta_documento`/`pasta_secao` por SQL CRU — que não passa pelo
 * `LixeiraFilter`. Os dois pontos que precisavam do `excluido_em IS NULL` à mão (D7):
 *
 *  - Via A (sistema→Drive): um documento na lixeira NÃO sobe ao Drive — senão o cron publicaria o
 *    que o usuário acabou de excluir. O `drive_file_id` dele continua NULL; se ele tinha
 *    `drive_file_id`, continua "conhecido" (não é reimportado como novo);
 *  - Via B (Drive→sistema): a seção-espelho é achada por nome, e uma seção com esse nome NA
 *    LIXEIRA não é reaproveitada — o arquivo importado ganha uma seção viva nova, em vez de
 *    ficar pendurado numa pasta invisível.
 */
#[CoversClass(ReconciliadorDePasta::class)]
final class ReconciliadorIgnoraLixeiraTest extends KernelTestCase
{
    use Factories;

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }

    #[TestDox('Via A: o documento na lixeira não sobe ao Drive; o vivo sobe; o excluído continua sem drive_file_id')]
    public function testViaANaoSobeDocumentoDaLixeira(): void
    {
        self::bootKernel();
        $tenant = TenantFactory::createOne();
        $pasta  = PastaFactory::createOne(['tenant' => $tenant, 'nup' => '778', 'nomeCliente' => 'LIXEIRA']);
        $vivo   = $this->criarDocumento($pasta->getId(), 'vivo.pdf');
        $morto  = $this->criarDocumento($pasta->getId(), 'morto.pdf', naLixeira: true);

        $fake = new FakeGoogleDriveClient();
        $r    = self::getContainer()->get(ReconciliadorDePasta::class)->sincronizarPasta($pasta->getId(), 'RAIZ', $fake);

        self::assertSame(1, $r->arquivosEnviados, 'só o vivo');
        self::assertSame(0, $r->erros);
        self::assertSame(['conteudo-vivo.pdf'], array_values($fake->conteudosEnviados));

        $conn = $this->em()->getConnection();
        self::assertNotNull($conn->fetchOne('SELECT drive_file_id FROM pasta_documento WHERE id = ?', [$vivo]));
        self::assertNull($conn->fetchOne('SELECT drive_file_id FROM pasta_documento WHERE id = ?', [$morto]), 'na lixeira: nada foi publicado');

        // Rodar de novo não muda nada — e o da lixeira continua de fora.
        $r2 = self::getContainer()->get(ReconciliadorDePasta::class)->sincronizarPasta($pasta->getId(), 'RAIZ', $fake);
        self::assertSame(0, $r2->arquivosEnviados);
        self::assertCount(1, $fake->arquivos);
    }

    #[TestDox('Via A: um documento JÁ vinculado que foi para a lixeira continua conhecido — a importação não o reimporta como novo')]
    public function testDocumentoVinculadoNaLixeiraContinuaConhecido(): void
    {
        self::bootKernel();
        $tenant = TenantFactory::createOne();
        $pasta  = PastaFactory::createOne(['tenant' => $tenant, 'nup' => '779', 'nomeCliente' => 'CONHECIDO']);
        $this->criarDocumento($pasta->getId(), 'vinculado.pdf', naLixeira: true, driveFileId: 'F-VINCULADO');

        $fake = new FakeGoogleDriveClient();
        $fake->seedPasta('CASO', '779 - CONHECIDO', 'RAIZ');
        $fake->seedArquivo('F-VINCULADO', 'vinculado.pdf', 'CASO');
        $this->vincularAoFolder($pasta->getId(), 'CASO');

        $r = self::getContainer()->get(ReconciliadorDePasta::class)->sincronizarPasta($pasta->getId(), 'RAIZ', $fake, false, ModoSincronizacao::Importar);

        self::assertSame(0, $r->arquivosBaixados, 'o id está em $conhecidos: não vira documento novo');
        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM pasta_documento WHERE pasta_id = ?', [$pasta->getId()]));
    }

    #[TestDox('Via B: a seção-espelho com o mesmo nome NA LIXEIRA não é reaproveitada — o arquivo importado ganha uma seção viva nova')]
    public function testViaBNaoReaproveitaSecaoDaLixeira(): void
    {
        self::bootKernel();
        $tenant = TenantFactory::createOne();
        $pasta  = PastaFactory::createOne(['tenant' => $tenant, 'nup' => '780', 'nomeCliente' => 'SECAO']);
        $naLixeira = $this->criarSecao($pasta->getId(), 'PETICOES', naLixeira: true);

        $fake = new FakeGoogleDriveClient();
        $fake->seedPasta('CASO', '780 - SECAO', 'RAIZ');
        $fake->seedPasta('SUB', 'PETICOES', 'CASO');
        $fake->seedArquivo('F1', 'importado.pdf', 'SUB');
        $this->vincularAoFolder($pasta->getId(), 'CASO');

        $r = self::getContainer()->get(ReconciliadorDePasta::class)->sincronizarPasta($pasta->getId(), 'RAIZ', $fake, false, ModoSincronizacao::Importar);

        self::assertSame(1, $r->arquivosBaixados, $r->mensagens === [] ? '' : implode("\n", $r->mensagens));
        self::assertSame(1, $r->secoesArquivos, 'uma seção nova, viva');

        $conn   = $this->em()->getConnection();
        $secoes = $conn->fetchAllAssociative('SELECT id, excluido_em FROM pasta_secao WHERE pasta_id = ? AND nome = ? ORDER BY id', [$pasta->getId(), 'PETICOES']);
        self::assertCount(2, $secoes, 'a da lixeira continua lá; nasceu outra');
        self::assertSame($naLixeira, (int) $secoes[0]['id']);
        self::assertNotNull($secoes[0]['excluido_em']);
        self::assertNull($secoes[1]['excluido_em']);

        $secaoDoImportado = $conn->fetchOne('SELECT secao_id FROM pasta_documento WHERE drive_file_id = ?', ['F1']);
        self::assertSame((int) $secoes[1]['id'], (int) $secaoDoImportado, 'o importado está na seção VIVA, não na da lixeira');
    }

    // ----------------------------------------------------------------- helpers

    private function criarDocumento(int $pastaId, string $nomeOriginal, bool $naLixeira = false, ?string $driveFileId = null): int
    {
        $em    = $this->em();
        $pasta = $em->find(Pasta::class, $pastaId);
        self::assertNotNull($pasta);

        $nomeStorage = self::getContainer()->get(ArmazenamentoDeArquivos::class)->gravar(
            ChavesDePasta::novoDocumento((new PastaDocumento())->setTenant($pasta->getTenant()), 'pdf'),
            FonteDeConteudo::deTexto('conteudo-' . $nomeOriginal),
        )->chave->nome;

        $doc = (new PastaDocumento())
            ->setTitulo($nomeOriginal)
            ->setCategoria(PastaDocumento::CATEGORIA_DEMAIS)
            ->setCaminhoArquivo($nomeStorage)
            ->setNomeOriginal($nomeOriginal)
            ->setMimeType('application/pdf')
            ->setTamanhoBytes(10)
            ->setPasta($pasta)
            ->setTenant($pasta->getTenant())
            ->setDriveFileId($driveFileId);
        if ($naLixeira) {
            $doc->marcarExcluido($this->autor(), new \DateTimeImmutable('2026-10-07 10:00:00'));
        }
        $em->persist($doc);
        $em->flush();
        $id = (int) $doc->getId();
        $em->clear();

        return $id;
    }

    private function criarSecao(int $pastaId, string $nome, bool $naLixeira = false): int
    {
        $em    = $this->em();
        $pasta = $em->find(Pasta::class, $pastaId);
        self::assertNotNull($pasta);

        $secao = (new PastaSecao())->setNome($nome)->setPasta($pasta)->setTenant($pasta->getTenant())->setOrdem(1);
        if ($naLixeira) {
            $secao->marcarExcluido($this->autor(), new \DateTimeImmutable('2026-10-07 10:00:00'));
        }
        $em->persist($secao);
        $em->flush();
        $id = (int) $secao->getId();
        $em->clear();

        return $id;
    }

    private function vincularAoFolder(int $pastaId, string $folderId): void
    {
        $this->em()->getConnection()->executeStatement('UPDATE pasta SET drive_folder_id = :f WHERE id = :id', ['f' => $folderId, 'id' => $pastaId]);
        $this->em()->clear();
    }

    private function autor(): User
    {
        $em   = $this->em();
        $user = new User();
        $user->setEmail('sync_lixeira_' . uniqid() . '@test.com');
        $user->setFullName('Autor');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword('dummy');
        $em->persist($user);
        $em->flush();

        return $user;
    }
}
