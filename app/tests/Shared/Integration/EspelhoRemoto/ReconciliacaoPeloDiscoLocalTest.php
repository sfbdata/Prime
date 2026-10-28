<?php

declare(strict_types=1);

namespace App\Tests\Shared\Integration\EspelhoRemoto;

use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Shared\Armazenamento\ArmazenamentoDeArquivos;
use App\Shared\Armazenamento\ArmazenamentoLocal;
use App\Shared\Armazenamento\CategoriaDeArquivo;
use App\Shared\Armazenamento\EscopoDeArquivo;
use App\Shared\Armazenamento\FonteDeConteudo;
use App\Shared\Armazenamento\NovoArquivo;
use App\Shared\EspelhoRemoto\FabricaDoEspelhoRemoto;
use App\Shared\EspelhoRemoto\LayoutDeChaveRemota;
use App\Shared\EspelhoRemoto\PoliticaDeEspelhamento;
use App\Shared\EspelhoRemoto\Reconciliacao\ClasseDeReconciliacao;
use App\Shared\EspelhoRemoto\Reconciliacao\ReconciliadorDoEspelho;
use App\Tests\Shared\Doubles\EspelhoRemotoEmMemoria;
use App\Tests\Shared\Doubles\ProvedorDoEspelhoFixo;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A reconciliação do CONTAINER mede o disco local, não o decorador (spec
 * `leitura-pelo-r2-e-liberacao-de-disco.md`, Etapa A). Com a leitura de recuperação, o decorador
 * responderia pelo R2 o arquivo que saiu do disco: a reconciliação deixaria de ver o LOCAL_AUSENTE e,
 * na fatia do SHA, baixaria o arquivo do R2 para medi-lo.
 *
 * Serviço real do container, com a categoria ligada e o espelho dublê configurado: o mesmo cenário em
 * que o decorador hidrataria.
 */
#[CoversClass(ReconciliadorDoEspelho::class)]
final class ReconciliacaoPeloDiscoLocalTest extends KernelTestCase
{
    #[TestDox('arquivo ausente do disco com envio verificado sai como LOCAL_AUSENTE, e o R2 nunca é baixado')]
    public function testAusenteDoDiscoComVerificado(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $espelho   = new EspelhoRemotoEmMemoria();
        $politica  = new PoliticaDeEspelhamento('pasta_documento');
        $container->set(PoliticaDeEspelhamento::class, $politica);
        $container->set(FabricaDoEspelhoRemoto::class, ProvedorDoEspelhoFixo::com($espelho));

        $em     = $container->get(EntityManagerInterface::class);
        $tenant = (new Tenant())->setName('Tenant Reconciliação R2 ' . uniqid());
        $em->persist($tenant);
        $em->flush();

        $conteudo   = "%PDF-1.4\nsó no R2\n";
        $armazenado = $container->get(ArmazenamentoDeArquivos::class)->gravar(
            new NovoArquivo(EscopoDeArquivo::deTenant((int) $tenant->getId()), CategoriaDeArquivo::PASTA_DOCUMENTO, 'pdf'),
            FonteDeConteudo::deTexto($conteudo),
        );
        $chave = $armazenado->chave;
        $container->get(Connection::class)->executeStatement(
            "UPDATE armazenamento_replicacao SET estado = 'verificado' WHERE chave_remota = ?",
            [LayoutDeChaveRemota::chaveDe($chave)],
        );
        $espelho->semear($chave, $conteudo, null, 'application/pdf');
        $container->get(ArmazenamentoLocal::class)->excluir($chave);
        self::assertTrue($container->get(ArmazenamentoDeArquivos::class)->existe($chave), 'pelo decorador, o arquivo "existe" (outbox)');

        $pasta = (new Pasta())->setNup('NUP-REC-R2-' . uniqid())->setTenant($tenant);
        $em->persist($pasta);
        $doc = new PastaDocumento();
        $doc->setTitulo('x.pdf');
        $doc->setCategoria(PastaDocumento::CATEGORIA_DEMAIS);
        $doc->setCaminhoArquivo($chave->nome);
        $doc->setNomeOriginal('x.pdf');
        $doc->setMimeType('application/pdf');
        $doc->setTamanhoBytes(\strlen($conteudo));
        $doc->setPasta($pasta);
        $doc->setTenant($tenant);
        $em->persist($doc);
        $em->flush();

        $relatorio = $container->get(ReconciliadorDoEspelho::class)->reconciliar($espelho, true, null, $politica);

        $doDocumento = array_values(array_filter(
            $relatorio->divergencias(),
            static fn (array $d): bool => $d['id'] === $doc->getId() && $d['classe'] === ClasseDeReconciliacao::LOCAL_AUSENTE->value,
        ));
        self::assertCount(1, $doDocumento, 'o documento saiu como LOCAL_AUSENTE');
        self::assertSame(0, $espelho->downloads, 'a reconciliação nunca baixa do R2');
    }
}
