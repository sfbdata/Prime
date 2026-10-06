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
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\FonteDeConteudo;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Shared\Doubles\ArmazenamentoEmMemoriaNoContainer;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * `POST /pasta/{id}/documentos/copiar` (D6): cópia = linha nova + arquivo novo com os mesmos
 * bytes, nome "(cópia)" único no destino, `driveFileId` nulo, `enviadoPor` = quem copiou, resposta
 * na forma do explorador; guardas do lote (irmã/outro escritório/lixeira 404, CSRF 400, sem
 * editar 403, subpasta na seleção 422) e — o que mais importa — falha do banco não deixa arquivo
 * órfão.
 */
#[CoversClass(PastaDocumentoController::class)]
final class PastaDocumentoCopiarControllerTest extends JusPrimeWebTestCase
{
    private const SHA = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

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

    #[TestDox('copiar: linha nova no destino com os metadados do original, arquivo novo com os mesmos bytes, driveFileId nulo, enviadoPor = quem copiou; JSON na forma do explorador; auditoria "create"')]
    public function testCopiaParaOutraSubpasta(): void
    {
        $client          = $this->cliente();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $a               = $this->criarSecao($pasta, $tenant, 'A');
        $destino         = $this->criarSecao($pasta, $tenant, 'DESTINO');
        $origem          = $this->criarDocumento($pasta, $tenant, $a, 'contrato.pdf', $this->gravarArquivo($tenant, 'bytes do contrato'));
        $origem->setSha256(self::SHA)->setPaginas(3)->setCategoria(PastaDocumento::CATEGORIA_PROCURACAO)->setDescricao('Assinado')->setNumero('12/2026')->setDriveFileId('drive-1');
        $this->em()->flush();
        $this->logarComTenant($client, $user, $tenant);
        $this->limpar();

        $client->request('POST', "/pasta/{$pasta->getId()}/documentos/copiar", [
            '_token'     => $this->csrf('pex_lote_' . $pasta->getId()),
            'documentos' => [(string) $origem->getId()],
            'destinoId'  => (string) $destino->getId(),
        ]);

        self::assertResponseIsSuccessful((string) $client->getResponse()->getContent());
        $json = $this->json($client);
        self::assertTrue($json['ok']);
        self::assertCount(1, $json['copiados']);
        $copia = $json['copiados'][0];
        self::assertSame('contrato (cópia).pdf', $copia['nome']);
        self::assertSame($destino->getId(), $copia['secaoId']);
        self::assertSame(self::SHA, $copia['sha256']);
        self::assertSame(3, $copia['paginas']);
        self::assertSame('Procuração', $copia['categoriaRotulo']);
        self::assertSame('Admin Documentos', $copia['enviadoPor']);
        self::assertFalse($copia['doDrive']);
        self::assertFalse($copia['favorito']);
        self::assertSame("/pasta/documento/{$copia['id']}/visualizar", $copia['viewUrl']);
        self::assertNotSame($origem->getId(), $copia['id']);

        $linha = $this->documento((int) $copia['id']);
        $this->arquivosGravados[] = ChavesDePasta::documentoPorNome((int) $tenant->getId(), $linha['caminho_arquivo']);
        self::assertSame($pasta->getId(), (int) $linha['pasta_id']);
        self::assertSame($destino->getId(), (int) $linha['secao_id']);
        self::assertSame('contrato (cópia).pdf', $linha['nome_original']);
        self::assertSame(PastaDocumento::CATEGORIA_PROCURACAO, $linha['categoria']);
        self::assertSame('Assinado', $linha['descricao']);
        self::assertSame('12/2026', $linha['numero']);
        self::assertSame(self::SHA, $linha['sha256']);
        self::assertSame(3, (int) $linha['paginas']);
        self::assertSame(strlen('bytes do contrato'), (int) $linha['tamanho_bytes']);
        self::assertNull($linha['drive_file_id'], 'para o Drive a cópia é documento novo');
        self::assertSame($user->getId(), (int) $linha['enviado_por_id']);
        self::assertNull($linha['modificado_em']);
        self::assertNull($linha['excluido_em']);
        self::assertNotSame($origem->getCaminhoArquivo(), $linha['caminho_arquivo'], 'arquivo NOVO');

        $armazenamento = static::getContainer()->get(ArmazenamentoDeArquivos::class);
        self::assertSame('bytes do contrato', $armazenamento->ler(ChavesDePasta::documentoPorNome((int) $tenant->getId(), $linha['caminho_arquivo'])));
        self::assertSame('bytes do contrato', $armazenamento->ler(ChavesDePasta::documentoPorNome((int) $tenant->getId(), $origem->getCaminhoArquivo())), 'o original fica');

        self::assertSame(1, $this->auditorias('create', [(int) $copia['id']]));
    }

    #[TestDox('copiar para a raiz (destinoId ausente), duas vezes: "(cópia)" e depois "(cópia 2)" — único no destino; o leitor só vê')]
    public function testNomesUnicosNaRaiz(): void
    {
        $client          = $this->cliente();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $origem          = $this->criarDocumento($pasta, $tenant, null, 'rel.pdf', $this->gravarArquivo($tenant, 'rel'));
        $leitor          = $this->criarUsuarioSoLeitura($tenant, (int) $pasta->getId());
        $this->logarComTenant($client, $user, $tenant);
        $this->limpar();
        $corpo = ['_token' => $this->csrf('pex_lote_' . $pasta->getId()), 'documentos' => [(string) $origem->getId()]];

        $client->request('POST', "/pasta/{$pasta->getId()}/documentos/copiar", $corpo);
        self::assertResponseIsSuccessful((string) $client->getResponse()->getContent());
        $primeira = $this->json($client)['copiados'][0];
        self::assertSame('rel (cópia).pdf', $primeira['nome']);
        self::assertNull($primeira['secaoId']);
        $this->arquivosGravados[] = ChavesDePasta::documentoPorNome((int) $tenant->getId(), $this->documento((int) $primeira['id'])['caminho_arquivo']);

        $this->limpar();
        $client->request('POST', "/pasta/{$pasta->getId()}/documentos/copiar", $corpo + ['destinoId' => '']);
        self::assertResponseIsSuccessful((string) $client->getResponse()->getContent());
        $segunda = $this->json($client)['copiados'][0];
        self::assertSame('rel (cópia 2).pdf', $segunda['nome']);
        $this->arquivosGravados[] = ChavesDePasta::documentoPorNome((int) $tenant->getId(), $this->documento((int) $segunda['id'])['caminho_arquivo']);

        self::assertSame(3, $this->documentosDaPasta((int) $pasta->getId()));

        $this->logarComTenant($client, $leitor, $tenant);
        $client->request('POST', "/pasta/{$pasta->getId()}/documentos/copiar", $corpo);
        self::assertResponseStatusCodeSame(403, 'copiar exige EDITAR');
        self::assertSame(3, $this->documentosDaPasta((int) $pasta->getId()));
    }

    #[TestDox('copiar: destino de pasta irmã, destino na lixeira, documento na lixeira, documento de pasta irmã, pasta de outro escritório → 404, nada criado')]
    public function testNaoEncontrados(): void
    {
        $client          = $this->cliente();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        [, $outro]       = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $irma            = $this->criarPasta($tenant);
        $alheia          = $this->criarPasta($outro);
        $daIrma          = $this->criarSecao($irma, $tenant, 'DA IRMÃ');
        $apagada         = $this->criarSecao($pasta, $tenant, 'APAGADA');
        $meu             = $this->criarDocumento($pasta, $tenant, null, 'meu.pdf', $this->gravarArquivo($tenant, 'meu'));
        $naLixeira       = $this->criarDocumento($pasta, $tenant, null, 'apagado.pdf', $this->gravarArquivo($tenant, 'x'));
        $docDaIrma       = $this->criarDocumento($irma, $tenant, null, 'da-irma.pdf', $this->gravarArquivo($tenant, 'irmã'));
        $alheio          = $this->criarDocumento($alheia, $outro, null, 'alheio.pdf', $this->gravarArquivo($outro, 'alheio'));
        $conn            = $this->em()->getConnection();
        $conn->executeStatement('UPDATE pasta_documento SET excluido_em = :em, excluido_por_id = :por WHERE id = :id', ['em' => '2026-10-07 10:00:00', 'por' => $user->getId(), 'id' => (int) $naLixeira->getId()]);
        $conn->executeStatement('UPDATE pasta_secao SET excluido_em = :em, excluido_por_id = :por WHERE id = :id', ['em' => '2026-10-07 10:00:00', 'por' => $user->getId(), 'id' => (int) $apagada->getId()]);
        $this->logarComTenant($client, $user, $tenant);
        $this->limpar();
        $token = $this->csrf('pex_lote_' . $pasta->getId());
        $antes = $this->documentosDaPasta((int) $pasta->getId()) + $this->documentosDaPasta((int) $irma->getId()) + $this->documentosDaPasta((int) $alheia->getId());

        $client->request('POST', "/pasta/{$pasta->getId()}/documentos/copiar", ['_token' => $token, 'documentos' => [(string) $meu->getId()], 'destinoId' => (string) $daIrma->getId()]);
        self::assertResponseStatusCodeSame(404);

        $client->request('POST', "/pasta/{$pasta->getId()}/documentos/copiar", ['_token' => $token, 'documentos' => [(string) $meu->getId()], 'destinoId' => (string) $apagada->getId()]);
        self::assertResponseStatusCodeSame(404, 'destino na lixeira não existe para a cópia');

        $client->request('POST', "/pasta/{$pasta->getId()}/documentos/copiar", ['_token' => $token, 'documentos' => [(string) $naLixeira->getId()]]);
        self::assertResponseStatusCodeSame(404);

        $client->request('POST', "/pasta/{$pasta->getId()}/documentos/copiar", ['_token' => $token, 'documentos' => [(string) $meu->getId(), (string) $docDaIrma->getId()]]);
        self::assertResponseStatusCodeSame(404, 'sem efeito parcial: nem o meu é copiado');

        $client->request('POST', "/pasta/{$alheia->getId()}/documentos/copiar", ['_token' => $this->csrf('pex_lote_' . $alheia->getId()), 'documentos' => [(string) $alheio->getId()]]);
        self::assertResponseStatusCodeSame(404);

        $client->request('POST', "/pasta/{$pasta->getId()}/documentos/copiar", ['_token' => $token, 'documentos' => [(string) $alheio->getId()]]);
        self::assertResponseStatusCodeSame(404);

        self::assertSame($antes, $this->documentosDaPasta((int) $pasta->getId()) + $this->documentosDaPasta((int) $irma->getId()) + $this->documentosDaPasta((int) $alheia->getId()));
    }

    #[TestDox('copiar: subpasta na seleção 422 (só arquivos); CSRF inválido 400; seleção vazia 422 — nada criado')]
    public function testRecusas(): void
    {
        $client          = $this->cliente();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $a               = $this->criarSecao($pasta, $tenant, 'A');
        $meu             = $this->criarDocumento($pasta, $tenant, null, 'meu.pdf', $this->gravarArquivo($tenant, 'meu'));
        $this->logarComTenant($client, $user, $tenant);
        $this->limpar();
        $token = $this->csrf('pex_lote_' . $pasta->getId());

        $client->request('POST', "/pasta/{$pasta->getId()}/documentos/copiar", ['_token' => $token, 'documentos' => [(string) $meu->getId()], 'secoes' => [(string) $a->getId()]]);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Só arquivos', (string) $this->json($client)['erro']);

        $client->request('POST', "/pasta/{$pasta->getId()}/documentos/copiar", ['_token' => 'errado', 'documentos' => [(string) $meu->getId()]]);
        self::assertResponseStatusCodeSame(400);

        $client->request('POST', "/pasta/{$pasta->getId()}/documentos/copiar", ['_token' => $token]);
        self::assertResponseStatusCodeSame(422);

        self::assertSame(1, $this->documentosDaPasta((int) $pasta->getId()));
    }

    #[TestDox('copiar com original sem arquivo no armazenamento: 422 com o nome e "nada foi copiado" — nenhuma linha, nenhum arquivo')]
    public function testOriginalAusenteNoStorage(): void
    {
        $client          = $this->cliente();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $ok              = $this->criarDocumento($pasta, $tenant, null, 'ok.pdf', 'ok-' . bin2hex(random_bytes(4)) . '.pdf');
        $sumido          = $this->criarDocumento($pasta, $tenant, null, 'sumido.pdf', 'sumido-' . bin2hex(random_bytes(4)) . '.pdf');
        $this->logarComTenant($client, $user, $tenant);
        $this->limpar();

        // Só o "ok" existe no armazenamento (dublê em memória): o "sumido" tem linha e não tem arquivo.
        $duble = ArmazenamentoEmMemoriaNoContainer::instalarEm(static::getContainer());
        $duble->memoria->semear(ChavesDePasta::documentoPorNome((int) $tenant->getId(), $ok->getCaminhoArquivo()), 'bytes');

        $client->request('POST', "/pasta/{$pasta->getId()}/documentos/copiar", [
            '_token'     => $this->csrf('pex_lote_' . $pasta->getId()),
            'documentos' => [(string) $ok->getId(), (string) $sumido->getId()],
        ]);

        self::assertResponseStatusCodeSame(422);
        $erro = (string) $this->json($client)['erro'];
        self::assertStringContainsString('«sumido.pdf»', $erro);
        self::assertStringContainsString('nada foi copiado', $erro);
        self::assertSame(2, $this->documentosDaPasta((int) $pasta->getId()), 'nenhuma linha nova — nem a do "ok"');
        self::assertSame([], $duble->memoria->gravadas, 'nenhum arquivo foi gravado');
    }

    #[TestDox('copiar com o banco recusando a linha nova: 500, nenhuma linha fica e o arquivo novo que chegou a ser gravado SAI (nada de órfão)')]
    public function testBancoQueRecusaNaoDeixaArquivoOrfao(): void
    {
        $client          = $this->cliente();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $origem          = $this->criarDocumento($pasta, $tenant, null, 'meu.pdf', 'origem-' . bin2hex(random_bytes(4)) . '.pdf');
        $this->logarComTenant($client, $user, $tenant);
        $this->limpar();

        // O armazenamento do container vira o dublê em memória: é nele que se vê o que foi gravado
        // e o que foi removido depois. O original é semeado lá com a chave que a leitura monta.
        $duble = ArmazenamentoEmMemoriaNoContainer::instalarEm(static::getContainer());
        $duble->memoria->semear(ChavesDePasta::documentoPorNome((int) $tenant->getId(), $origem->getCaminhoArquivo()), 'bytes');

        $em     = $this->em();
        $recusa = new class {
            public int $recusas = 0;

            public function onFlush(OnFlushEventArgs $args): void
            {
                foreach ($args->getObjectManager()->getUnitOfWork()->getScheduledEntityInsertions() as $entidade) {
                    if ($entidade instanceof PastaDocumento) {
                        ++$this->recusas;

                        throw new \LogicException('banco recusou a cópia');
                    }
                }
            }
        };
        $em->getEventManager()->addEventListener([Events::onFlush], $recusa);

        try {
            $client->request('POST', "/pasta/{$pasta->getId()}/documentos/copiar", [
                '_token'     => $this->csrf('pex_lote_' . $pasta->getId()),
                'documentos' => [(string) $origem->getId()],
            ]);
        } finally {
            $em->getEventManager()->removeEventListener([Events::onFlush], $recusa);
        }

        self::assertSame(1, $recusa->recusas);
        self::assertResponseStatusCodeSame(500);
        self::assertSame(1, $this->documentosDaPasta((int) $pasta->getId()), 'nenhuma linha nova ficou');
        self::assertCount(1, $duble->memoria->gravadas, 'a cópia chegou a ser gravada…');
        self::assertCount(1, $duble->memoria->excluidas, '…e saiu, porque o banco provou que nada foi confirmado');
        self::assertTrue($duble->memoria->gravadas[0]->ehIgualA($duble->memoria->excluidas[0]));
        self::assertFalse($duble->memoria->existe($duble->memoria->gravadas[0]));
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
        $tenant->setName('Tenant Copiar ' . uniqid());
        $em->persist($tenant);

        $user = new User();
        $user->setEmail('copiar_' . uniqid() . '@test.com');
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
        $pasta->setNup('COP-' . uniqid());
        $pasta->setTenant($tenant);
        $this->em()->persist($pasta);
        $this->em()->flush();

        return $pasta;
    }

    private function criarSecao(Pasta $pasta, Tenant $tenant, string $nome): PastaSecao
    {
        $secao = new PastaSecao();
        $secao->setPasta($pasta);
        $secao->setTenant($tenant);
        $secao->setNome($nome);
        $secao->setOrdem(1);
        $this->em()->persist($secao);
        $this->em()->flush();

        return $secao;
    }

    private function criarDocumento(Pasta $pasta, Tenant $tenant, ?PastaSecao $secao, string $nome, string $caminho): PastaDocumento
    {
        $doc = new PastaDocumento();
        $doc->setTitulo($nome);
        $doc->setCategoria(PastaDocumento::CATEGORIA_DEMAIS);
        $doc->setCaminhoArquivo($caminho);
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
            'SELECT nome_original, categoria, descricao, numero, caminho_arquivo, sha256, paginas, tamanho_bytes, drive_file_id, enviado_por_id, modificado_em, excluido_em, secao_id, pasta_id FROM pasta_documento WHERE id = :id',
            ['id' => $id],
        );
        self::assertIsArray($linha, "documento #{$id} não está no banco");

        return $linha;
    }

    private function documentosDaPasta(int $pastaId): int
    {
        return (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM pasta_documento WHERE pasta_id = :id', ['id' => $pastaId]);
    }

    /** @param list<int> $ids */
    private function auditorias(string $acao, array $ids): int
    {
        return (int) $this->em()->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM audit_log WHERE action = :acao AND entity_class = :classe AND entity_id IN (:ids)',
            ['acao' => $acao, 'classe' => PastaDocumento::class, 'ids' => array_map('strval', $ids)],
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
}
