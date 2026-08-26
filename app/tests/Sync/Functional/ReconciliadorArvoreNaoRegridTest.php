<?php

declare(strict_types=1);

namespace App\Tests\Sync\Functional;

use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Entity\PastaSecao;
use App\Shared\Service\ArquivoStorageInterface;
use App\Sync\DTO\ResultadoReconciliacaoPasta;
use App\Sync\Enum\ModoSincronizacao;
use App\Sync\Service\ReconciliadorDePasta;
use App\Tests\Factory\Pasta\PastaFactory;
use App\Tests\Sync\Support\FakeGoogleDriveClient;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Guarda a promessa da §4 da spec de pastas aninhadas: a Entrega 1 NÃO mexe no sync.
 *
 * Uma árvore de 3 níveis no sistema continua subindo ao Drive achatada em UMA subpasta de 1º nível,
 * exatamente como antes de existir hierarquia. Quando a Entrega 2 for implementada, este teste vira
 * vermelho DE PROPÓSITO — é o sinal de que o achatamento saiu, e ele deve ser reescrito, não apagado.
 */
#[CoversClass(ReconciliadorDePasta::class)]
final class ReconciliadorArvoreNaoRegridTest extends KernelTestCase
{
    use Factories;

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }

    #[TestDox('árvore de 3 níveis continua subindo ao Drive achatada e sem erro')]
    public function testArvoreProfundaSobeAchatadaSemErro(): void
    {
        $em    = $this->em();
        $pasta = PastaFactory::createOne(['driveFolderId' => 'folder-caso'])->_real();
        $tenant = $pasta->getTenant();

        // A > B > C, três níveis
        $anterior = null;
        foreach (['A', 'B', 'C'] as $nome) {
            $secao = new PastaSecao();
            $secao->setPasta($pasta);
            $secao->setTenant($tenant);
            $secao->setNome($nome);
            $secao->setOrdem(1);
            $secao->setPai($anterior);
            $em->persist($secao);
            $anterior = $secao;
        }

        // documento na folha (C), sem drive_file_id → candidato a subir
        $storage     = self::getContainer()->get(ArquivoStorageInterface::class);
        $uploadsDir  = (string) self::getContainer()->getParameter('uploads_dir');
        $nomeStorage = $storage->salvarConteudo('conteudo', $uploadsDir, 'pdf');

        $doc = (new PastaDocumento())
            ->setTitulo('PECA')
            ->setCategoria(PastaDocumento::CATEGORIA_DEMAIS)
            ->setCaminhoArquivo($nomeStorage)
            ->setNomeOriginal('peca.pdf')
            ->setMimeType('application/pdf')
            ->setTamanhoBytes(10)
            ->setPasta($pasta)
            ->setTenant($tenant);
        $doc->setSecao($anterior);
        $em->persist($doc);
        $em->flush();

        $docId  = $doc->getId();
        $client = new FakeGoogleDriveClient();
        $r      = new ResultadoReconciliacaoPasta();

        self::getContainer()->get(ReconciliadorDePasta::class)->reconciliarArquivosDaPasta(
            (int) $pasta->getId(),
            $client,
            false,
            $r,
            ModoSincronizacao::Enviar,
        );

        self::assertSame(0, $r->erros, 'a árvore nova não pode gerar erro no envio');
        self::assertSame(1, $r->arquivosEnviados);

        // A asserção que importa: a subpasta criada no Drive é filha DIRETA da pasta do caso.
        // É isso que "achatado" quer dizer — nenhum A/B intermediário foi criado.
        $criadas = array_values(array_filter(
            $client->pastas,
            static fn (array $p): bool => $p['nome'] === 'C',
        ));
        self::assertCount(1, $criadas, 'exatamente uma subpasta-espelho da seção folha');
        self::assertSame('folder-caso', $criadas[0]['parent'], 'filha direta do caso: continua achatado');
        self::assertCount(1, $client->pastas, 'nenhuma pasta intermediária (A, B) foi criada');

        $em->clear();
        self::assertNotNull($em->find(PastaDocumento::class, $docId)->getDriveFileId());
    }
}
