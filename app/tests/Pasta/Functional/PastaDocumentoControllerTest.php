<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Permission\ResourceAccess;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Pasta\Armazenamento\ChavesDePasta;
use App\Pasta\Controller\PastaDocumentoController;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Entity\PastaSecao;
use App\Shared\Armazenamento\ArmazenamentoDeArquivos;
use App\Shared\Armazenamento\FonteDeConteudo;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * As três rotas do documento na aba Documentos: `pasta_documento_edit` migrada (D3) e as ações em
 * lote mover/excluir (D4).
 *
 * Isolamento provado pelos dois lados em cada rota: outro escritório → 404; e a PASTA IRMÃ do
 * mesmo escritório (um id de documento/subpasta de outra pasta na seleção ou no destino) → 404
 * sem efeito parcial — é o caso que um teste só de tenant não pega. Cada caso negativo confere
 * no banco que NADA mudou.
 */
#[CoversClass(PastaDocumentoController::class)]
final class PastaDocumentoControllerTest extends JusPrimeWebTestCase
{
    private const XHR = ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'];

    /** @var list<ChaveDeArquivo> arquivos reais gravados no disco de teste — o rollback do banco não os limpa */
    private array $arquivosGravados = [];

    protected function tearDown(): void
    {
        if ($this->arquivosGravados !== []) {
            $armazenamento = static::getContainer()->get(ArmazenamentoDeArquivos::class);
            foreach ($this->arquivosGravados as $chave) {
                if ($armazenamento->existe($chave)) {
                    $armazenamento->excluir($chave);
                }
            }
            $this->arquivosGravados = [];
        }

        parent::tearDown();
    }

    // ── pasta_documento_edit (D3) ───────────────────────────────────────────────

    #[TestDox('a rota pasta_documento_edit mantém caminho, método e nome — agora no PastaDocumentoController')]
    public function testRotaMantida(): void
    {
        $this->cliente();
        $rota = static::getContainer()->get('router')->getRouteCollection()->get('pasta_documento_edit');

        self::assertNotNull($rota);
        self::assertSame('/pasta/documento/{id}/editar', $rota->getPath());
        self::assertSame(['POST'], $rota->getMethods());
        self::assertSame(PastaDocumentoController::class . '::editar', $rota->getDefault('_controller'));
    }

    #[TestDox('editar sem XHR: grava (extensão preservada), marca modificado_em e redireciona para #documentos')]
    public function testEditarSemXhrGravaERedireciona(): void
    {
        $client          = $this->cliente();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $doc             = $this->criarDocumento($pasta, $tenant, null, 'contrato.PDF');
        $id              = (int) $doc->getId();
        $this->logarComTenant($client, $user, $tenant);
        $this->limpar();

        $client->request('POST', "/pasta/documento/{$id}/editar", [
            '_token'    => $this->csrf('edit_documento_' . $id),
            'nomeBase'  => 'Contrato final',
            'categoria' => 'procuracao',
            'descricao' => 'Assinado pelas partes',
            'numero'    => '12/2026',
        ]);

        self::assertResponseRedirects("/pasta/{$pasta->getId()}#documentos");
        $linha = $this->documento($id);
        self::assertSame('Contrato final.PDF', $linha['nome_original'], 'a extensão volta como estava');
        self::assertSame(PastaDocumento::CATEGORIA_PROCURACAO, $linha['categoria']);
        self::assertSame('Assinado pelas partes', $linha['descricao']);
        self::assertSame('12/2026', $linha['numero']);
        self::assertNotNull($linha['modificado_em'], 'D1: a edição grava modificado_em');
    }

    #[TestDox('editar com XHR: responde JSON com o documento atualizado na forma do explorador')]
    public function testEditarComXhrDevolveODocumento(): void
    {
        $client          = $this->cliente();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $doc             = $this->criarDocumento($pasta, $tenant, null, 'contrato.pdf');
        $id              = (int) $doc->getId();
        $this->logarComTenant($client, $user, $tenant);
        $this->limpar();

        $client->request('POST', "/pasta/documento/{$id}/editar", [
            '_token'    => $this->csrf('edit_documento_' . $id),
            'nomeBase'  => 'Procuração',
            'categoria' => PastaDocumento::CATEGORIA_PROCURACAO,
        ], [], self::XHR);

        self::assertResponseIsSuccessful();
        $json = $this->json($client);
        self::assertTrue($json['ok']);
        self::assertSame($id, $json['documento']['id']);
        self::assertSame('Procuração.pdf', $json['documento']['nome']);
        self::assertSame('Procuração', $json['documento']['categoriaRotulo']);
        self::assertNotNull($json['documento']['modificadoEm']);
        self::assertNull($json['documento']['descricao'], 'chave ausente no form = em branco = NULL, como sempre foi');
        self::assertTokenValido('edit_documento_' . $id, $json['documento']['csrfEditar']);
        self::assertSame("/pasta/documento/{$id}/visualizar", $json['documento']['viewUrl']);
        self::assertSame('Procuração.pdf', $this->documento($id)['nome_original']);
    }

    #[TestDox('editar com CSRF inválido: 403 sem XHR, 400 com XHR — e nada muda')]
    public function testEditarCsrfInvalido(): void
    {
        $client          = $this->cliente();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $id              = (int) $this->criarDocumento($pasta, $tenant, null, 'contrato.pdf')->getId();
        $this->logarComTenant($client, $user, $tenant);
        $this->limpar();

        $client->request('POST', "/pasta/documento/{$id}/editar", ['_token' => 'errado', 'nomeBase' => 'x']);
        self::assertResponseStatusCodeSame(403);

        $client->request('POST', "/pasta/documento/{$id}/editar", ['_token' => 'errado', 'nomeBase' => 'x'], [], self::XHR);
        self::assertResponseStatusCodeSame(400);

        self::assertSame('contrato.pdf', $this->documento($id)['nome_original']);
        self::assertNull($this->documento($id)['modificado_em']);
    }

    #[TestDox('editar sem permissão de EDITAR a pasta (só ver): 403, nada muda')]
    public function testEditarSemPermissaoDeEdicao(): void
    {
        $client          = $this->cliente();
        [, $tenant]      = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $id              = (int) $this->criarDocumento($pasta, $tenant, null, 'contrato.pdf')->getId();
        $leitor          = $this->criarUsuarioSoLeitura($tenant, (int) $pasta->getId());
        $this->logarComTenant($client, $leitor, $tenant);
        $this->limpar();

        $client->request('POST', "/pasta/documento/{$id}/editar", ['_token' => $this->csrf('edit_documento_' . $id), 'nomeBase' => 'x'], [], self::XHR);
        self::assertResponseStatusCodeSame(403);
        self::assertArrayHasKey('erro', $this->json($client));

        $client->request('POST', "/pasta/documento/{$id}/editar", ['_token' => $this->csrf('edit_documento_' . $id), 'nomeBase' => 'x']);
        self::assertResponseStatusCodeSame(403);

        self::assertSame('contrato.pdf', $this->documento($id)['nome_original']);
    }

    #[TestDox('editar documento de OUTRO escritório: 404 (não 403), nada muda')]
    public function testEditarDocumentoDeOutroEscritorio(): void
    {
        $client          = $this->cliente();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        [, $outro]       = $this->criarUsuarioAdmin();
        $alheia          = $this->criarPasta($outro);
        $id              = (int) $this->criarDocumento($alheia, $outro, null, 'alheio.pdf')->getId();
        $this->logarComTenant($client, $user, $tenant);
        $this->limpar();

        $client->request('POST', "/pasta/documento/{$id}/editar", ['_token' => $this->csrf('edit_documento_' . $id), 'nomeBase' => 'invadido'], [], self::XHR);
        self::assertResponseStatusCodeSame(404);

        $client->request('POST', "/pasta/documento/{$id}/editar", ['_token' => $this->csrf('edit_documento_' . $id), 'nomeBase' => 'invadido']);
        self::assertResponseStatusCodeSame(404);

        self::assertSame('alheio.pdf', $this->documento($id)['nome_original']);
    }

    // ── mover-lote (D4) ────────────────────────────────────────────────────────

    #[TestDox('mover-lote: documentos (raiz e de outra subpasta) e uma subpasta vão para o destino numa transação só')]
    public function testMoverLoteParaODestino(): void
    {
        $client          = $this->cliente();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $a               = $this->criarSecao($pasta, $tenant, 'A');
        $b               = $this->criarSecao($pasta, $tenant, 'B');
        $destino         = $this->criarSecao($pasta, $tenant, 'DESTINO');
        $naRaiz          = $this->criarDocumento($pasta, $tenant, null, 'raiz.pdf');
        $emA             = $this->criarDocumento($pasta, $tenant, $a, 'em-a.pdf');
        $this->logarComTenant($client, $user, $tenant);
        $this->limpar();

        $client->request('POST', "/pasta/{$pasta->getId()}/documentos/mover-lote", [
            '_token'     => $this->csrf('pex_lote_' . $pasta->getId()),
            'documentos' => [(string) $naRaiz->getId(), (string) $emA->getId()],
            'secoes'     => [(string) $b->getId()],
            'destinoId'  => (string) $destino->getId(),
        ]);

        self::assertResponseIsSuccessful((string) $client->getResponse()->getContent());
        $json = $this->json($client);
        self::assertTrue($json['ok']);
        self::assertSame(['documentos' => 2, 'secoes' => 1], $json['movidos']);
        self::assertSame($destino->getId(), $json['destinoId']);

        self::assertSame($destino->getId(), $this->secaoDoDocumento((int) $naRaiz->getId()));
        self::assertSame($destino->getId(), $this->secaoDoDocumento((int) $emA->getId()));
        self::assertSame($destino->getId(), $this->paiDaSecao((int) $b->getId()));
        self::assertNull($this->paiDaSecao((int) $a->getId()), 'A não estava na seleção');
        self::assertNull($this->documento((int) $naRaiz->getId())['modificado_em'], 'mover não é modificação do documento');
    }

    #[TestDox('mover-lote sem destinoId devolve tudo para a raiz; o corpo também pode vir em JSON')]
    public function testMoverLoteParaARaizEmJson(): void
    {
        $client          = $this->cliente();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $a               = $this->criarSecao($pasta, $tenant, 'A');
        $filha           = $this->criarSecao($pasta, $tenant, 'FILHA', $a);
        $emA             = $this->criarDocumento($pasta, $tenant, $a, 'em-a.pdf');
        $this->logarComTenant($client, $user, $tenant);
        $this->limpar();

        $client->request(
            'POST',
            "/pasta/{$pasta->getId()}/documentos/mover-lote",
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            (string) json_encode([
                '_token'     => $this->csrf('pex_lote_' . $pasta->getId()),
                'documentos' => [$emA->getId()],
                'secoes'     => [$filha->getId()],
                'destinoId'  => null,
            ]),
        );

        self::assertResponseIsSuccessful((string) $client->getResponse()->getContent());
        self::assertNull($this->json($client)['destinoId']);
        self::assertNull($this->secaoDoDocumento((int) $emA->getId()));
        self::assertNull($this->paiDaSecao((int) $filha->getId()));
    }

    #[TestDox('mover-lote com um documento de PASTA IRMÃ (mesmo escritório) na seleção: 404 e NADA é movido')]
    public function testMoverLoteDocumentoDePastaIrma(): void
    {
        $client          = $this->cliente();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $irma            = $this->criarPasta($tenant);
        $destino         = $this->criarSecao($pasta, $tenant, 'DESTINO');
        $meu             = $this->criarDocumento($pasta, $tenant, null, 'meu.pdf');
        $daIrma          = $this->criarDocumento($irma, $tenant, null, 'da-irma.pdf');
        $this->logarComTenant($client, $user, $tenant);
        $this->limpar();

        $client->request('POST', "/pasta/{$pasta->getId()}/documentos/mover-lote", [
            '_token'     => $this->csrf('pex_lote_' . $pasta->getId()),
            'documentos' => [(string) $meu->getId(), (string) $daIrma->getId()],
            'destinoId'  => (string) $destino->getId(),
        ]);

        self::assertResponseStatusCodeSame(404);
        self::assertNull($this->secaoDoDocumento((int) $meu->getId()), 'sem efeito parcial: o meu também não foi');
        self::assertNull($this->secaoDoDocumento((int) $daIrma->getId()));
        self::assertSame($irma->getId(), $this->pastaDoDocumento((int) $daIrma->getId()));
    }

    #[TestDox('mover-lote com subpasta de pasta irmã na seleção, ou como destino: 404 e nada muda')]
    public function testMoverLoteSecaoDePastaIrma(): void
    {
        $client          = $this->cliente();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $irma            = $this->criarPasta($tenant);
        $minha           = $this->criarSecao($pasta, $tenant, 'MINHA');
        $daIrma          = $this->criarSecao($irma, $tenant, 'DA IRMÃ');
        $meu             = $this->criarDocumento($pasta, $tenant, null, 'meu.pdf');
        $this->logarComTenant($client, $user, $tenant);
        $this->limpar();

        // ...na seleção
        $client->request('POST', "/pasta/{$pasta->getId()}/documentos/mover-lote", [
            '_token'    => $this->csrf('pex_lote_' . $pasta->getId()),
            'secoes'    => [(string) $daIrma->getId()],
            'destinoId' => (string) $minha->getId(),
        ]);
        self::assertResponseStatusCodeSame(404);
        self::assertNull($this->paiDaSecao((int) $daIrma->getId()));

        // ...como destino
        $client->request('POST', "/pasta/{$pasta->getId()}/documentos/mover-lote", [
            '_token'     => $this->csrf('pex_lote_' . $pasta->getId()),
            'documentos' => [(string) $meu->getId()],
            'destinoId'  => (string) $daIrma->getId(),
        ]);
        self::assertResponseStatusCodeSame(404);
        self::assertNull($this->secaoDoDocumento((int) $meu->getId()));
    }

    #[TestDox('mover-lote em pasta de OUTRO escritório, ou com documento de outro escritório: 404, nada muda')]
    public function testMoverLoteOutroEscritorio(): void
    {
        $client          = $this->cliente();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        [, $outro]       = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $alheia          = $this->criarPasta($outro);
        $docAlheio       = $this->criarDocumento($alheia, $outro, null, 'alheio.pdf');
        $destinoAlheio   = $this->criarSecao($alheia, $outro, 'ALHEIA');
        $this->logarComTenant($client, $user, $tenant);
        $this->limpar();

        $client->request('POST', "/pasta/{$alheia->getId()}/documentos/mover-lote", [
            '_token'     => $this->csrf('pex_lote_' . $alheia->getId()),
            'documentos' => [(string) $docAlheio->getId()],
            'destinoId'  => (string) $destinoAlheio->getId(),
        ]);
        self::assertResponseStatusCodeSame(404);

        $client->request('POST', "/pasta/{$pasta->getId()}/documentos/mover-lote", [
            '_token'     => $this->csrf('pex_lote_' . $pasta->getId()),
            'documentos' => [(string) $docAlheio->getId()],
            'destinoId'  => '',
        ]);
        self::assertResponseStatusCodeSame(404);

        self::assertNull($this->secaoDoDocumento((int) $docAlheio->getId()));
    }

    #[TestDox('mover-lote: CSRF inválido 400; sem permissão de edição 403; seleção vazia 422')]
    public function testMoverLoteRecusas(): void
    {
        $client          = $this->cliente();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $destino         = $this->criarSecao($pasta, $tenant, 'DESTINO');
        $doc             = $this->criarDocumento($pasta, $tenant, null, 'meu.pdf');
        $leitor          = $this->criarUsuarioSoLeitura($tenant, (int) $pasta->getId());
        $this->logarComTenant($client, $user, $tenant);
        $this->limpar();

        $client->request('POST', "/pasta/{$pasta->getId()}/documentos/mover-lote", [
            '_token'     => 'errado',
            'documentos' => [(string) $doc->getId()],
            'destinoId'  => (string) $destino->getId(),
        ]);
        self::assertResponseStatusCodeSame(400);

        $client->request('POST', "/pasta/{$pasta->getId()}/documentos/mover-lote", [
            '_token'    => $this->csrf('pex_lote_' . $pasta->getId()),
            'destinoId' => (string) $destino->getId(),
        ]);
        self::assertResponseStatusCodeSame(422);

        $this->logarComTenant($client, $leitor, $tenant);
        $client->request('POST', "/pasta/{$pasta->getId()}/documentos/mover-lote", [
            '_token'     => $this->csrf('pex_lote_' . $pasta->getId()),
            'documentos' => [(string) $doc->getId()],
            'destinoId'  => (string) $destino->getId(),
        ]);
        self::assertResponseStatusCodeSame(403);

        self::assertNull($this->secaoDoDocumento((int) $doc->getId()));
    }

    #[TestDox('mover-lote: ciclo (pasta para dentro da própria filha) é 422, e a outra subpasta da seleção também não vai')]
    public function testMoverLoteCiclo(): void
    {
        $client          = $this->cliente();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $a               = $this->criarSecao($pasta, $tenant, 'A');
        $filha           = $this->criarSecao($pasta, $tenant, 'FILHA', $a);
        $b               = $this->criarSecao($pasta, $tenant, 'B');
        $this->logarComTenant($client, $user, $tenant);
        $this->limpar();

        $client->request('POST', "/pasta/{$pasta->getId()}/documentos/mover-lote", [
            '_token'    => $this->csrf('pex_lote_' . $pasta->getId()),
            'secoes'    => [(string) $b->getId(), (string) $a->getId()],
            'destinoId' => (string) $filha->getId(),
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('dentro dela mesma', (string) $this->json($client)['erro']);
        self::assertNull($this->paiDaSecao((int) $a->getId()));
        self::assertNull($this->paiDaSecao((int) $b->getId()), 'tudo ou nada');
        self::assertSame($a->getId(), $this->paiDaSecao((int) $filha->getId()));
    }

    // ── excluir-lote (D4) → LIXEIRA (D7) ───────────────────────────────────────
    //
    // Desde o L7 nada sai do banco nem do disco aqui: cada item recebe a lápide, a subárvore de
    // cada subpasta inteira com o mesmo carimbo, e a resposta mantém as chaves de antes mais
    // `lixeira: true` e os ids (o "Desfazer"). Restaurar e a purga têm testes próprios
    // (`PastaDocumentoLixeiraControllerTest`, `PurgarLixeiraCommandTest`).

    #[TestDox('excluir-lote: as linhas FICAM com a lápide (inclusive as de dentro da subpasta, mesmo carimbo), os arquivos FICAM, a auditoria registra a marcação')]
    public function testExcluirLoteMandaParaALixeira(): void
    {
        $client          = $this->cliente();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $a               = $this->criarSecao($pasta, $tenant, 'A');
        $naRaiz          = $this->criarDocumento($pasta, $tenant, null, 'raiz.pdf', $this->gravarArquivo($tenant, 'raiz'));
        $emA             = $this->criarDocumento($pasta, $tenant, $a, 'em-a.pdf', $this->gravarArquivo($tenant, 'em-a'));
        $fica            = $this->criarDocumento($pasta, $tenant, null, 'fica.pdf', $this->gravarArquivo($tenant, 'fica'));
        $chaves          = array_map(fn (PastaDocumento $d) => ChavesDePasta::documentoPorNome((int) $tenant->getId(), $d->getCaminhoArquivo()), [$naRaiz, $emA, $fica]);
        $armazenamento   = static::getContainer()->get(ArmazenamentoDeArquivos::class);
        $this->logarComTenant($client, $user, $tenant);
        $this->limpar();

        $client->request('POST', "/pasta/{$pasta->getId()}/documentos/excluir-lote", [
            '_token'     => $this->csrf('pex_lote_' . $pasta->getId()),
            'documentos' => [(string) $naRaiz->getId()],
            'secoes'     => [(string) $a->getId()],
        ]);

        self::assertResponseIsSuccessful((string) $client->getResponse()->getContent());
        $json = $this->json($client);
        self::assertTrue($json['ok']);
        self::assertTrue($json['lixeira']);
        self::assertSame(1, $json['documentosRemovidos']);
        self::assertSame(1, $json['subpastasRemovidas']);
        self::assertSame(2, $json['arquivosRemovidos']);
        self::assertSame(['documentos' => [(int) $naRaiz->getId()], 'secoes' => [(int) $a->getId()]], $json['ids'], 'os ids voltam para o Desfazer');

        self::assertTrue($this->existeDocumento((int) $naRaiz->getId()), 'lixeira é lápide: a linha fica');
        self::assertTrue($this->existeDocumento((int) $emA->getId()));
        self::assertTrue($this->existeSecao((int) $a->getId()));

        $carimboRaiz = $this->naLixeira('pasta_documento', (int) $naRaiz->getId());
        $carimboEmA  = $this->naLixeira('pasta_documento', (int) $emA->getId());
        $carimboA    = $this->naLixeira('pasta_secao', (int) $a->getId());
        self::assertNotNull($carimboRaiz);
        self::assertNotNull($carimboEmA, 'o de dentro da subpasta vai junto');
        self::assertNotNull($carimboA);
        self::assertSame($carimboA, $carimboEmA, 'subpasta e conteúdo: o MESMO carimbo');
        self::assertSame($carimboA, $carimboRaiz, 'a ação inteira tem um carimbo só');
        self::assertNull($this->naLixeira('pasta_documento', (int) $fica->getId()));

        [$chaveRaiz, $chaveEmA, $chaveFica] = $chaves;
        self::assertTrue($armazenamento->existe($chaveRaiz), 'nada sai do disco: só a purga apaga');
        self::assertTrue($armazenamento->existe($chaveEmA));
        self::assertTrue($armazenamento->existe($chaveFica));

        self::assertSame(2, $this->auditorias('update', PastaDocumento::class, [(int) $naRaiz->getId(), (int) $emA->getId()]));
        self::assertSame(1, $this->auditorias('update', PastaSecao::class, [(int) $a->getId()]));
        self::assertSame(0, $this->auditorias('delete', PastaDocumento::class, [(int) $naRaiz->getId(), (int) $emA->getId()]));

        // O que foi para a lixeira some da prova de posse: um segundo excluir-lote com os mesmos
        // ids não os acha (404) — e não recarimba.
        $this->limpar();
        $client->request('POST', "/pasta/{$pasta->getId()}/documentos/excluir-lote", [
            '_token'     => $this->csrf('pex_lote_' . $pasta->getId()),
            'documentos' => [(string) $naRaiz->getId()],
        ]);
        self::assertResponseStatusCodeSame(404);
        self::assertSame($carimboRaiz, $this->naLixeira('pasta_documento', (int) $naRaiz->getId()));
    }

    #[TestDox('excluir-lote com documento de PASTA IRMÃ na seleção: 404, nada vai para a lixeira')]
    public function testExcluirLoteDocumentoDePastaIrma(): void
    {
        $client          = $this->cliente();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $irma            = $this->criarPasta($tenant);
        $meu             = $this->criarDocumento($pasta, $tenant, null, 'meu.pdf', $this->gravarArquivo($tenant, 'meu'));
        $daIrma          = $this->criarDocumento($irma, $tenant, null, 'da-irma.pdf', $this->gravarArquivo($tenant, 'da-irma'));
        $armazenamento   = static::getContainer()->get(ArmazenamentoDeArquivos::class);
        $this->logarComTenant($client, $user, $tenant);
        $this->limpar();

        $client->request('POST', "/pasta/{$pasta->getId()}/documentos/excluir-lote", [
            '_token'     => $this->csrf('pex_lote_' . $pasta->getId()),
            'documentos' => [(string) $meu->getId(), (string) $daIrma->getId()],
        ]);

        self::assertResponseStatusCodeSame(404);
        self::assertNull($this->naLixeira('pasta_documento', (int) $meu->getId()), 'sem efeito parcial');
        self::assertNull($this->naLixeira('pasta_documento', (int) $daIrma->getId()));
        self::assertTrue($armazenamento->existe(ChavesDePasta::documentoPorNome((int) $tenant->getId(), $meu->getCaminhoArquivo())));
        self::assertTrue($armazenamento->existe(ChavesDePasta::documentoPorNome((int) $tenant->getId(), $daIrma->getCaminhoArquivo())));
    }

    #[TestDox('excluir-lote: outro escritório 404; CSRF inválido 400; sem permissão 403 — nada vai para a lixeira')]
    public function testExcluirLoteRecusas(): void
    {
        $client          = $this->cliente();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        [, $outro]       = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $alheia          = $this->criarPasta($outro);
        $meu             = $this->criarDocumento($pasta, $tenant, null, 'meu.pdf');
        $docAlheio       = $this->criarDocumento($alheia, $outro, null, 'alheio.pdf');
        $leitor          = $this->criarUsuarioSoLeitura($tenant, (int) $pasta->getId());
        $this->logarComTenant($client, $user, $tenant);
        $this->limpar();

        $client->request('POST', "/pasta/{$alheia->getId()}/documentos/excluir-lote", [
            '_token'     => $this->csrf('pex_lote_' . $alheia->getId()),
            'documentos' => [(string) $docAlheio->getId()],
        ]);
        self::assertResponseStatusCodeSame(404);

        $client->request('POST', "/pasta/{$pasta->getId()}/documentos/excluir-lote", [
            '_token'     => $this->csrf('pex_lote_' . $pasta->getId()),
            'documentos' => [(string) $docAlheio->getId()],
        ]);
        self::assertResponseStatusCodeSame(404);

        $client->request('POST', "/pasta/{$pasta->getId()}/documentos/excluir-lote", [
            '_token'     => 'errado',
            'documentos' => [(string) $meu->getId()],
        ]);
        self::assertResponseStatusCodeSame(400);

        $this->logarComTenant($client, $leitor, $tenant);
        $client->request('POST', "/pasta/{$pasta->getId()}/documentos/excluir-lote", [
            '_token'     => $this->csrf('pex_lote_' . $pasta->getId()),
            'documentos' => [(string) $meu->getId()],
        ]);
        self::assertResponseStatusCodeSame(403);

        self::assertNull($this->naLixeira('pasta_documento', (int) $meu->getId()));
        self::assertNull($this->naLixeira('pasta_documento', (int) $docAlheio->getId()));
    }

    #[TestDox('excluir-lote com o banco recusando: nenhuma lápide fica meio gravada e nenhum arquivo é tocado')]
    public function testExcluirLoteBancoQueRecusaNaoMarcaNada(): void
    {
        $client          = $this->cliente();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $a               = $this->criarSecao($pasta, $tenant, 'A');
        $naRaiz          = $this->criarDocumento($pasta, $tenant, null, 'raiz.pdf', $this->gravarArquivo($tenant, 'raiz'));
        $emA             = $this->criarDocumento($pasta, $tenant, $a, 'em-a.pdf', $this->gravarArquivo($tenant, 'em-a'));
        $armazenamento   = static::getContainer()->get(ArmazenamentoDeArquivos::class);
        $this->logarComTenant($client, $user, $tenant);
        $this->limpar();

        $em     = $this->em();
        $recusa = new class {
            public int $recusas = 0;

            public function onFlush(OnFlushEventArgs $args): void
            {
                foreach ($args->getObjectManager()->getUnitOfWork()->getScheduledEntityUpdates() as $entidade) {
                    if ($entidade instanceof PastaDocumento) {
                        ++$this->recusas;

                        throw new \LogicException('banco recusou a lápide');
                    }
                }
            }
        };
        $em->getEventManager()->addEventListener([Events::onFlush], $recusa);

        try {
            $client->request('POST', "/pasta/{$pasta->getId()}/documentos/excluir-lote", [
                '_token'     => $this->csrf('pex_lote_' . $pasta->getId()),
                'documentos' => [(string) $naRaiz->getId()],
                'secoes'     => [(string) $a->getId()],
            ]);
        } finally {
            $em->getEventManager()->removeEventListener([Events::onFlush], $recusa);
        }

        self::assertSame(1, $recusa->recusas);
        self::assertResponseStatusCodeSame(500);
        self::assertNull($this->naLixeira('pasta_documento', (int) $naRaiz->getId()));
        self::assertNull($this->naLixeira('pasta_documento', (int) $emA->getId()));
        self::assertNull($this->naLixeira('pasta_secao', (int) $a->getId()));
        self::assertTrue($armazenamento->existe(ChavesDePasta::documentoPorNome((int) $tenant->getId(), $naRaiz->getCaminhoArquivo())));
        self::assertTrue($armazenamento->existe(ChavesDePasta::documentoPorNome((int) $tenant->getId(), $emA->getCaminhoArquivo())));
    }

    // ── forma do corpo do lote (ids, destinoId, teto) ──────────────────────────

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function selecoesInvalidas(): iterable
    {
        yield 'texto em documentos'       => [['documentos' => ['abc']]];
        yield 'aninhado em documentos'    => [['documentos' => [[1]]]];
        yield 'zero em documentos'        => [['documentos' => ['0']]];
        yield 'negativo em documentos'    => [['documentos' => ['-1']]];
        yield 'decimal em documentos'     => [['documentos' => ['1.5']]];
        yield 'documentos não é lista'    => [['documentos' => 'abc']];
        yield 'texto em secoes'           => [['secoes' => ['abc']]];
        yield 'aninhado em secoes'        => [['secoes' => [[1]]]];
        yield 'zero em secoes'            => [['secoes' => ['0']]];
        yield 'negativo em secoes'        => [['secoes' => ['-7']]];
    }

    /**
     * Cada caso leva, além do valor inválido, um documento e uma subpasta VÁLIDOS da pasta: a
     * resposta tem de ser 404 e os válidos têm de ficar onde estão — nada de efeito parcial.
     *
     * @param array<string, mixed> $invalidos
     */
    #[TestDox('lote com id que não é id ($_dataName): 404 em mover e em excluir, sem efeito')]
    #[DataProvider('selecoesInvalidas')]
    public function testLoteRecusaIdsInvalidos(array $invalidos): void
    {
        $client          = $this->cliente();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $destino         = $this->criarSecao($pasta, $tenant, 'DESTINO');
        $minha           = $this->criarSecao($pasta, $tenant, 'MINHA');
        $meu             = $this->criarDocumento($pasta, $tenant, null, 'meu.pdf');
        $this->logarComTenant($client, $user, $tenant);
        $this->limpar();

        $documentos = $invalidos['documentos'] ?? [];
        $secoes     = $invalidos['secoes'] ?? [];
        if (is_array($documentos)) {
            array_unshift($documentos, (string) $meu->getId());
        }
        if (is_array($secoes)) {
            array_unshift($secoes, (string) $minha->getId());
        }
        $corpo = ['_token' => $this->csrf('pex_lote_' . $pasta->getId()), 'documentos' => $documentos, 'secoes' => $secoes];

        $client->request('POST', "/pasta/{$pasta->getId()}/documentos/mover-lote", $corpo + ['destinoId' => (string) $destino->getId()]);
        self::assertResponseStatusCodeSame(404);

        $client->request('POST', "/pasta/{$pasta->getId()}/documentos/excluir-lote", $corpo);
        self::assertResponseStatusCodeSame(404);

        self::assertNull($this->secaoDoDocumento((int) $meu->getId()), 'o documento válido da seleção não se moveu');
        self::assertNull($this->paiDaSecao((int) $minha->getId()), 'a subpasta válida da seleção não se moveu');
        self::assertTrue($this->existeDocumento((int) $meu->getId()));
        self::assertTrue($this->existeSecao((int) $minha->getId()));
    }

    #[TestDox('ids repetidos na seleção contam UMA vez; destinoId ausente é a raiz')]
    public function testLoteComIdsRepetidos(): void
    {
        $client          = $this->cliente();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $destino         = $this->criarSecao($pasta, $tenant, 'DESTINO');
        $b               = $this->criarSecao($pasta, $tenant, 'B');
        $meu             = $this->criarDocumento($pasta, $tenant, null, 'meu.pdf');
        $outro           = $this->criarDocumento($pasta, $tenant, null, 'outro.pdf');
        $this->logarComTenant($client, $user, $tenant);
        $this->limpar();

        $client->request('POST', "/pasta/{$pasta->getId()}/documentos/mover-lote", [
            '_token'     => $this->csrf('pex_lote_' . $pasta->getId()),
            'documentos' => [(string) $meu->getId(), (string) $meu->getId(), (string) $meu->getId()],
            'secoes'     => [(string) $b->getId(), (string) $b->getId()],
            'destinoId'  => (string) $destino->getId(),
        ]);
        self::assertResponseIsSuccessful((string) $client->getResponse()->getContent());
        self::assertSame(['documentos' => 1, 'secoes' => 1], $this->json($client)['movidos']);
        self::assertSame($destino->getId(), $this->secaoDoDocumento((int) $meu->getId()));
        self::assertSame($destino->getId(), $this->paiDaSecao((int) $b->getId()));

        // Sem a chave `destinoId` no corpo: raiz.
        $client->request('POST', "/pasta/{$pasta->getId()}/documentos/mover-lote", [
            '_token'     => $this->csrf('pex_lote_' . $pasta->getId()),
            'documentos' => [(string) $meu->getId()],
            'secoes'     => [(string) $b->getId()],
        ]);
        self::assertResponseIsSuccessful((string) $client->getResponse()->getContent());
        self::assertNull($this->secaoDoDocumento((int) $meu->getId()));
        self::assertNull($this->paiDaSecao((int) $b->getId()));

        $client->request('POST', "/pasta/{$pasta->getId()}/documentos/excluir-lote", [
            '_token'     => $this->csrf('pex_lote_' . $pasta->getId()),
            'documentos' => [(string) $outro->getId(), (string) $outro->getId()],
        ]);
        self::assertResponseIsSuccessful((string) $client->getResponse()->getContent());
        self::assertSame(1, $this->json($client)['documentosRemovidos']);
        self::assertFalse($this->existeDocumento((int) $outro->getId()));
        self::assertTrue($this->existeDocumento((int) $meu->getId()));
    }

    /** @return iterable<string, array{mixed}> */
    public static function destinosInvalidos(): iterable
    {
        yield 'texto'    => ['abc'];
        yield 'zero'     => ['0'];
        yield 'negativo' => ['-3'];
        yield 'decimal'  => ['1.5'];
        yield 'lista'    => [['1']];
    }

    #[TestDox('mover-lote com destinoId que não é id nem raiz ($_dataName): 404, sem efeito')]
    #[DataProvider('destinosInvalidos')]
    public function testMoverLoteDestinoInvalido(mixed $destinoId): void
    {
        $client          = $this->cliente();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $a               = $this->criarSecao($pasta, $tenant, 'A');
        $meu             = $this->criarDocumento($pasta, $tenant, $a, 'meu.pdf');
        $this->logarComTenant($client, $user, $tenant);
        $this->limpar();

        $client->request('POST', "/pasta/{$pasta->getId()}/documentos/mover-lote", [
            '_token'     => $this->csrf('pex_lote_' . $pasta->getId()),
            'documentos' => [(string) $meu->getId()],
            'secoes'     => [(string) $a->getId()],
            'destinoId'  => $destinoId,
        ]);

        self::assertResponseStatusCodeSame(404);
        self::assertSame($a->getId(), $this->secaoDoDocumento((int) $meu->getId()), 'um (int) cego mandaria tudo para a raiz');
        self::assertNull($this->paiDaSecao((int) $a->getId()));
    }

    #[TestDox('lote com mais de 2.000 ids (documentos + subpastas somados): 422 em mover e em excluir, sem efeito')]
    public function testLoteAcimaDoTeto(): void
    {
        $client          = $this->cliente();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $destino         = $this->criarSecao($pasta, $tenant, 'DESTINO');
        $meu             = $this->criarDocumento($pasta, $tenant, null, 'meu.pdf');
        $this->logarComTenant($client, $user, $tenant);
        $this->limpar();

        $teto     = PastaDocumentoController::TETO_DE_ITENS_POR_LOTE;
        $forjados = array_map('strval', range(9_000_000, 9_000_000 + $teto - 2)); // teto − 1 ids
        $token    = $this->csrf('pex_lote_' . $pasta->getId());

        // teto + 1 no total, repartido entre as duas listas: é a SOMA que conta.
        $client->request('POST', "/pasta/{$pasta->getId()}/documentos/mover-lote", [
            '_token'     => $token,
            'documentos' => array_merge([(string) $meu->getId()], $forjados),
            'secoes'     => [(string) $destino->getId()],
            'destinoId'  => (string) $destino->getId(),
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('limite', (string) $this->json($client)['erro']);

        $client->request('POST', "/pasta/{$pasta->getId()}/documentos/excluir-lote", [
            '_token'     => $token,
            'documentos' => array_merge([(string) $meu->getId()], $forjados),
            'secoes'     => [(string) $destino->getId()],
        ]);
        self::assertResponseStatusCodeSame(422);

        // Exatamente no teto não é recusado pelo teto: os forjados não são desta pasta → 404.
        $client->request('POST', "/pasta/{$pasta->getId()}/documentos/excluir-lote", [
            '_token'     => $token,
            'documentos' => array_merge([(string) $meu->getId()], $forjados),
        ]);
        self::assertResponseStatusCodeSame(404);

        self::assertNull($this->secaoDoDocumento((int) $meu->getId()));
        self::assertTrue($this->existeDocumento((int) $meu->getId()));
        self::assertTrue($this->existeSecao((int) $destino->getId()));
    }

    // ----------------------------------------------------------------- helpers

    private function cliente(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->instalarCsrfStorage();

        return $client;
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    /** O request lê o banco, não a memória do teste (as coleções das seções precisam vir do banco). */
    private function limpar(): void
    {
        $this->em()->clear();
    }

    /** @return array{User, Tenant} */
    private function criarUsuarioAdmin(): array
    {
        $em     = $this->em();
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $tenant = new Tenant();
        $tenant->setName('Tenant Doc ' . uniqid());
        $em->persist($tenant);

        $user = new User();
        $user->setEmail('doc_' . uniqid() . '@test.com');
        $user->setFullName('Admin Documentos');
        $user->setRoles(['ROLE_SUPER_ADMIN']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);
        $em->persist(new UserTenant($user, $tenant));
        $em->flush();

        return [$user, $tenant];
    }

    /** Papel comum (não-sistema) com acesso de VER a pasta, sem editar. */
    private function criarUsuarioSoLeitura(Tenant $tenant, int $pastaId): User
    {
        $em = $this->em();

        $user = new User();
        $user->setEmail('leitor_' . uniqid() . '@test.com');
        $user->setFullName('Leitor');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword('dummy_hash');
        $em->persist($user);

        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName('Papel ' . uniqid());
        $role->setIsSystem(false);
        $em->persist($role);

        $vinculo = new UserTenant($user, $tenant);
        $vinculo->setTenantRole($role);
        $em->persist($vinculo);

        $acesso = new ResourceAccess();
        $acesso->setUser($user);
        $acesso->setTenant($tenant);
        $acesso->setResourceType(ResourceAccess::RESOURCE_PASTA);
        $acesso->setResourceId($pastaId);
        $acesso->setCanView(true);
        $acesso->setCanEdit(false);
        $em->persist($acesso);
        $em->flush();

        return $user;
    }

    private function criarPasta(Tenant $tenant): Pasta
    {
        $pasta = new Pasta();
        $pasta->setNup('DOC-' . uniqid());
        $pasta->setTenant($tenant);
        $this->em()->persist($pasta);
        $this->em()->flush();

        return $pasta;
    }

    private function criarSecao(Pasta $pasta, Tenant $tenant, string $nome, ?PastaSecao $pai = null): PastaSecao
    {
        $secao = new PastaSecao();
        $secao->setPasta($pasta);
        $secao->setTenant($tenant);
        $secao->setNome($nome);
        $secao->setOrdem(1);
        if ($pai !== null) {
            $secao->setPai($pai);
        }
        $this->em()->persist($secao);
        $this->em()->flush();

        return $secao;
    }

    private function criarDocumento(Pasta $pasta, Tenant $tenant, ?PastaSecao $secao, string $nome, ?string $caminho = null): PastaDocumento
    {
        $doc = new PastaDocumento();
        $doc->setTitulo($nome);
        $doc->setCategoria(PastaDocumento::CATEGORIA_DEMAIS);
        $doc->setCaminhoArquivo($caminho ?? 'fake-' . bin2hex(random_bytes(6)) . '.pdf');
        $doc->setNomeOriginal($nome);
        $doc->setMimeType('application/pdf');
        $doc->setTamanhoBytes(10);
        $doc->setPasta($pasta);
        $doc->setTenant($tenant);
        $doc->setSecao($secao);
        $this->em()->persist($doc);
        $this->em()->flush();

        return $doc;
    }

    /** Grava um arquivo DE VERDADE pela chave e devolve o nome cunhado (vai em `caminho_arquivo`). */
    private function gravarArquivo(Tenant $tenant, string $conteudo): string
    {
        $chave = static::getContainer()->get(ArmazenamentoDeArquivos::class)->gravar(
            ChavesDePasta::novoDocumento((new PastaDocumento())->setTenant($tenant), 'pdf'),
            FonteDeConteudo::deTexto($conteudo),
        )->chave;
        $this->arquivosGravados[] = $chave;

        return $chave->nome;
    }

    /** @return array<string, mixed> */
    private function json(KernelBrowser $client): array
    {
        $json = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($json, (string) $client->getResponse()->getContent());

        return $json;
    }

    /** @return array<string, mixed> a linha, direto do banco */
    private function documento(int $id): array
    {
        $linha = $this->em()->getConnection()->fetchAssociative(
            'SELECT nome_original, categoria, descricao, numero, modificado_em, secao_id, pasta_id FROM pasta_documento WHERE id = :id',
            ['id' => $id],
        );
        self::assertIsArray($linha, "documento #{$id} não está no banco");

        return $linha;
    }

    private function secaoDoDocumento(int $id): ?int
    {
        $valor = $this->documento($id)['secao_id'];

        return $valor === null ? null : (int) $valor;
    }

    private function pastaDoDocumento(int $id): int
    {
        return (int) $this->documento($id)['pasta_id'];
    }

    private function paiDaSecao(int $id): ?int
    {
        $valor = $this->em()->getConnection()->fetchOne('SELECT secao_pai_id FROM pasta_secao WHERE id = :id', ['id' => $id]);
        self::assertNotFalse($valor, "seção #{$id} não está no banco");

        return $valor === null ? null : (int) $valor;
    }

    private function existeDocumento(int $id): bool
    {
        return (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM pasta_documento WHERE id = :id', ['id' => $id]) === 1;
    }

    /** O carimbo da lixeira (`excluido_em`) da linha, ou null se ela está viva. Falha se a linha não existe. */
    private function naLixeira(string $tabela, int $id): ?string
    {
        $linha = $this->em()->getConnection()->fetchAssociative("SELECT excluido_em FROM {$tabela} WHERE id = :id", ['id' => $id]);
        self::assertIsArray($linha, "{$tabela} #{$id} não está no banco");

        return $linha['excluido_em'];
    }

    private function existeSecao(int $id): bool
    {
        return (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM pasta_secao WHERE id = :id', ['id' => $id]) === 1;
    }

    /** @param list<int> $ids */
    private function auditorias(string $acao, string $classe, array $ids): int
    {
        return (int) $this->em()->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM audit_log WHERE action = :acao AND entity_class = :classe AND entity_id IN (:ids)',
            ['acao' => $acao, 'classe' => $classe, 'ids' => array_map('strval', $ids)],
            ['ids' => ArrayParameterType::STRING],
        );
    }

    private function csrf(string $id): string
    {
        return 'TOKEN_' . $id;
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

    /** O gerenciador real mascara o valor (BREACH); a prova é ele aceitar o token para aquele id. */
    private static function assertTokenValido(string $idDoToken, mixed $valor): void
    {
        self::assertIsString($valor);
        self::assertNotSame('', $valor);
        self::assertTrue(
            static::getContainer()->get('security.csrf.token_manager')->isTokenValid(new CsrfToken($idDoToken, $valor)),
            "token devolvido não vale para '{$idDoToken}'",
        );
    }
}
