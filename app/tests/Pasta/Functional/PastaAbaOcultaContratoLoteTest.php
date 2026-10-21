<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Controller\PastaAbaOcultaController;
use App\Pasta\Controller\PastaDocumentoController;
use App\Pasta\Controller\PeticionarController;
use App\Pasta\Entity\AbaDaPasta;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Contrato de honorários com o Financeiro oculto nas ações em lote, na lixeira, na estrela e no
 * Peticionar (re-revisão do ocultar aba): o id do contrato responde como id que não existe (404) e
 * ele não aparece nas listas. E a trava real da primeira regra (`comTravaDaPasta`) pelo controller.
 */
#[CoversClass(PastaDocumentoController::class)]
#[CoversClass(PeticionarController::class)]
#[CoversClass(PastaAbaOcultaController::class)]
#[Group('pasta')]
final class PastaAbaOcultaContratoLoteTest extends JusPrimeWebTestCase
{
    use CriaCenarioDeAbaOcultaTrait;

    private KernelBrowser $client;
    private Tenant $tenant;
    private User $primario;
    private User $ana;
    private Pasta $pasta;
    private PastaDocumento $contrato;

    private function cenario(): void
    {
        $this->client = $this->cliente();
        $this->instalarCsrfPrevisivel();
        $this->tenant = $this->criarTenant();
        $this->primario = $this->criarPrimario($this->tenant);
        $this->ana = $this->criarColaborador($this->tenant);
        $this->pasta = $this->criarPasta($this->tenant);
        $this->contrato = $this->documento(PastaDocumento::CATEGORIA_CONTRATO, 'contrato-honorarios');
        $this->ocultarAba($this->pasta, AbaDaPasta::Financeiro, $this->primario, [$this->ana]);
    }

    private function documento(string $categoria, string $nome): PastaDocumento
    {
        $doc = new PastaDocumento();
        $doc->setTenant($this->tenant);
        $doc->setPasta($this->pasta);
        $doc->setTitulo($nome);
        $doc->setCategoria($categoria);
        $doc->setCaminhoArquivo('teste/' . uniqid() . '.pdf');
        $doc->setNomeOriginal($nome . '.pdf');
        $doc->setMimeType('application/pdf');
        $doc->setTamanhoBytes(1234);
        $this->em()->persist($doc);
        $this->em()->flush();
        $this->em()->refresh($this->pasta); // a coleção `documentos` da pasta relê o banco

        return $doc;
    }

    private function pedir(User $quem, string $metodo, string $caminho, array $corpo = []): int
    {
        $this->logarComTenant($this->client, $quem, $this->tenant);
        $this->client->request($metodo, "/pasta/{$this->pasta->getId()}{$caminho}", $corpo, [], ['HTTP_X-Requested-With' => 'XMLHttpRequest']);

        return $this->client->getResponse()->getStatusCode();
    }

    /** @return iterable<string, array{string}> */
    public static function lotes(): iterable
    {
        yield 'mover em lote' => ['/documentos/mover-lote'];
        yield 'excluir em lote' => ['/documentos/excluir-lote'];
        yield 'copiar' => ['/documentos/copiar'];
        yield 'baixar em .zip' => ['/documentos/zip'];
    }

    #[DataProvider('lotes')]
    #[TestDox('Lote com o contrato ($caminho): 404 como id inexistente, nada muda; o primário passa da seleção')]
    public function testLoteComContrato(string $caminho): void
    {
        $this->cenario();
        $corpo = ['_token' => 'TOKEN_pex_lote_' . $this->pasta->getId(), 'documentos' => [(string) $this->contrato->getId()]];

        self::assertSame(404, $this->pedir($this->ana, 'POST', $caminho, $corpo));
        self::assertSame(['erro' => 'Documento não encontrado.'], json_decode((string) $this->client->getResponse()->getContent(), true));
        $this->em()->clear();
        $relido = $this->em()->getRepository(PastaDocumento::class)->find($this->contrato->getId());
        self::assertNotNull($relido);
        self::assertFalse($relido->estaNaLixeira());

        self::assertNotSame(404, $this->pedir($this->primario, 'POST', $caminho, $corpo));
    }

    #[TestDox('Lixeira e restaurar: o contrato na lixeira não aparece para quem tem o Financeiro oculto e restaurá-lo é 404')]
    public function testLixeiraERestaurar(): void
    {
        $this->cenario();
        $this->contrato->marcarExcluido($this->primario, new \DateTimeImmutable());
        $this->em()->flush();

        self::assertSame(200, $this->pedir($this->ana, 'GET', '/documentos/lixeira'));
        self::assertStringNotContainsString('contrato-honorarios', (string) $this->client->getResponse()->getContent());
        self::assertSame(200, $this->pedir($this->primario, 'GET', '/documentos/lixeira'));
        self::assertStringContainsString('contrato-honorarios', (string) $this->client->getResponse()->getContent());

        $corpo = ['_token' => 'TOKEN_pex_lote_' . $this->pasta->getId(), 'documentos' => [(string) $this->contrato->getId()]];
        self::assertSame(404, $this->pedir($this->ana, 'POST', '/documentos/restaurar', $corpo));
    }

    #[TestDox('Estrela no contrato: 404 para quem tem o Financeiro oculto; o primário marca')]
    public function testFavorito(): void
    {
        $this->cenario();
        $corpo = ['_token' => 'TOKEN_pex_favorito_' . $this->pasta->getId(), 'tipo' => 'documento', 'alvoId' => (string) $this->contrato->getId(), 'marcado' => '1'];

        self::assertSame(404, $this->pedir($this->ana, 'POST', '/documentos/favorito', $corpo));
        self::assertSame(200, $this->pedir($this->primario, 'POST', '/documentos/favorito', $corpo));
    }

    #[TestDox('Peticionar: a lista de documentos não traz o contrato para quem tem o Financeiro oculto')]
    public function testPeticionar(): void
    {
        $this->cenario();
        $procuracao = $this->documento(PastaDocumento::CATEGORIA_DEMAIS, 'procuracao');
        $linha = static fn (PastaDocumento $d): string => 'data-doc-id="' . $d->getId() . '"';

        self::assertSame(200, $this->pedir($this->ana, 'GET', '/peticionar'));
        $daAna = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString($linha($procuracao), $daAna);
        self::assertStringNotContainsString($linha($this->contrato), $daAna);

        self::assertSame(200, $this->pedir($this->primario, 'GET', '/peticionar'));
        self::assertStringContainsString($linha($this->contrato), (string) $this->client->getResponse()->getContent());
    }

    #[TestDox('Trava real da primeira regra: com a regra já gravada por outra conexão, o controller relê sob a trava e atualiza (1 linha, sem 500)')]
    public function testTravaRealPeloController(): void
    {
        $this->cenario();
        // A "outra pessoa" gravou a primeira regra de Push direto no banco, fora do EntityManager.
        $this->em()->getConnection()->insert('pasta_aba_oculta', [
            'tenant_id' => $this->tenant->getId(), 'pasta_id' => $this->pasta->getId(), 'aba' => 'push',
            'para_todos' => 'false', 'usuarios' => json_encode([(int) $this->ana->getId()]),
            'atualizado_por_id' => $this->primario->getId(), 'atualizado_em' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        self::assertSame(200, $this->pedir($this->primario, 'POST', '/abas/push/ocultar-todos', ['_token' => 'TOKEN_pasta_aba_oculta_' . $this->pasta->getId()]));

        $linhas = $this->em()->getConnection()->fetchAllAssociative(
            'SELECT para_todos FROM pasta_aba_oculta WHERE pasta_id = :p AND aba = :a',
            ['p' => $this->pasta->getId(), 'a' => 'push'],
        );
        self::assertCount(1, $linhas);
        self::assertTrue((bool) $linhas[0]['para_todos']);
    }
}
