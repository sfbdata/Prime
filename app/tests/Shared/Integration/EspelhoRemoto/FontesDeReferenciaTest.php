<?php

declare(strict_types=1);

namespace App\Tests\Shared\Integration\EspelhoRemoto;

use App\Entity\Tarefa\TarefaMensagem;
use App\Pasta\Entity\PastaDocumento;
use App\Shared\Armazenamento\CategoriaDeArquivo;
use App\Shared\EspelhoRemoto\Reconciliacao\FontesDeReferencia;
use App\Shared\EspelhoRemoto\Reconciliacao\ReferenciaDeArquivo;
use App\Tests\Factory\Auth\UserFactory;
use App\Tests\Factory\Pasta\PastaFactory;
use App\Tests\Factory\Tarefa\TarefaFactory;
use App\Tests\Factory\Tenant\TenantFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * As fontes reais da reconciliação, contra linhas de verdade no banco: o escopo sai da coluna de
 * escritório da própria linha, o nome vai byte a byte, e o valor de tarefa passa pela MESMA regra
 * da `ChavesDeTarefa` (`admin/` e lixo não viram chave).
 */
#[CoversClass(FontesDeReferencia::class)]
final class FontesDeReferenciaTest extends KernelTestCase
{
    use Factories;

    #[TestDox('documento de pasta e anexo de tarefa viram chaves com o escritório da linha; valor fora do formato vira referência sem chave')]
    public function testReferenciasDasLinhas(): void
    {
        self::bootKernel();
        $em     = static::getContainer()->get(EntityManagerInterface::class);
        $tenant = TenantFactory::createOne();
        $pasta  = PastaFactory::createOne(['tenant' => $tenant]);
        $tarefa = TarefaFactory::createOne(['pasta' => $pasta, 'tenant' => $tenant]);
        $autor  = UserFactory::createOne();

        $nome = 'e36d9f9f37beec5e7463a93e755e10a2. AÇAÇ - 01 AAAAA 2025';
        $doc  = new PastaDocumento();
        $doc->setTitulo('t');
        $doc->setCategoria(PastaDocumento::CATEGORIA_DEMAIS);
        $doc->setCaminhoArquivo($nome);
        $doc->setNomeOriginal('t.pdf');
        $doc->setMimeType('application/pdf');
        $doc->setTamanhoBytes(1);
        $doc->setPasta($pasta->_real());
        $doc->setTenant($tenant->_real());
        $em->persist($doc);

        $valido = $this->mensagem($tarefa->_real(), $autor->_real(), '/uploads/tarefas/chat/arquivo_6abab5669fac90.42347212.pdf');
        $admin  = $this->mensagem($tarefa->_real(), $autor->_real(), '/uploads/tarefas/admin/legado.pdf');
        foreach ([$valido, $admin] as $mensagem) {
            $em->persist($mensagem);
        }
        $em->flush();

        $porLinha = [];
        foreach (static::getContainer()->get(FontesDeReferencia::class)->referencias() as $referencia) {
            /** @var ReferenciaDeArquivo $referencia */
            $porLinha[$referencia->fonte . '#' . $referencia->idDaLinha] = $referencia;
        }

        $refDoc = $porLinha['pasta_documento#' . $doc->getId()] ?? self::fail('documento não listado');
        self::assertSame(CategoriaDeArquivo::PASTA_DOCUMENTO, $refDoc->chave?->categoria);
        self::assertSame($tenant->getId(), $refDoc->chave?->escopo->tenantIdOuNull());
        self::assertSame($nome, $refDoc->chave?->nome, 'byte a byte');

        $refValido = $porLinha['tarefa_mensagem#' . $valido->getId()] ?? self::fail('anexo válido não listado');
        self::assertSame(CategoriaDeArquivo::TAREFA_ANEXO, $refValido->chave?->categoria);
        self::assertSame('arquivo_6abab5669fac90.42347212.pdf', $refValido->chave?->nome);
        self::assertSame($tenant->getId(), $refValido->chave?->escopo->tenantIdOuNull());

        $refAdmin = $porLinha['tarefa_mensagem#' . $admin->getId()] ?? self::fail('anexo admin não listado');
        self::assertNull($refAdmin->chave, 'admin/ não é endereçável (DP-9): sai como chave inválida, nunca como outra chave');
    }

    private function mensagem(\App\Entity\Tarefa\Tarefa $tarefa, \App\Entity\Auth\User $autor, string $valor): TarefaMensagem
    {
        $mensagem = new TarefaMensagem();
        $mensagem->setTarefa($tarefa);
        $mensagem->setUsuario($autor);
        $mensagem->setMensagem('m');
        $mensagem->setTenant($tarefa->getTenant() ?? self::fail('tarefa sem escritório'));
        $mensagem->setArquivoAnexo($valor);

        return $mensagem;
    }
}
