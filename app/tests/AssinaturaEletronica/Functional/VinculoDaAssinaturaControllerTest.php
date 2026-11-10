<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Functional;

use App\AssinaturaEletronica\Armazenamento\ChavesDeAssinaturaEletronica;
use App\AssinaturaEletronica\Controller\VinculoDaAssinaturaController;
use App\AssinaturaEletronica\Entity\DocumentoParaAssinar;
use App\AssinaturaEletronica\Event\PdfFinalGuardado;
use App\AssinaturaEletronica\EventListener\VinculoPendenteListener;
use App\AssinaturaEletronica\Message\GerarPdfFinalDoDocumento;
use App\AssinaturaEletronica\MessageHandler\GerarPdfFinalDoDocumentoHandler;
use App\AssinaturaEletronica\Service\PdfFinalDoDocumento;
use App\AssinaturaEletronica\Service\PdfFinalProvisorio;
use App\AssinaturaEletronica\Service\VinculoNaTela;
use App\AssinaturaEletronica\UseCase\BuscarPastasParaVincularUseCase;
use App\AssinaturaEletronica\UseCase\VincularAPastaUseCase;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Permission\Permission;
use App\Entity\Permission\ResourceAccess;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRolePermission;
use App\Pasta\Armazenamento\ChavesDePasta;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Entity\PastaMensagem;
use App\Pasta\Enum\OrigemDaAssinatura;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\FonteDeConteudo;
use App\Tests\AssinaturaEletronica\Support\NaAssinaturaEnviada;
use App\Tests\AssinaturaEletronica\Support\NaTelaDeAssinatura;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Shared\Doubles\ArmazenamentoEmMemoriaNoContainer;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * "Vincular à pasta de Expediente" pela HTTP (spec assinatura-documentos-etapa-3, E3-10 a E3-12): a busca da janela e o
 * vínculo. Porta do módulo, CSRF, a pasta do escritório que a pessoa edita (404 sem dizer qual, 403 de quem só vê),
 * aguardando fica pendente, concluído com o PDF final guardado vai na hora para a pasta, concluído sem ele espera o
 * worker, e o PDF final guardado aplica o pendente.
 *
 * O PDF final com selo é do bloco 1: aqui ele é dublado no serviço por trás de {@see PdfFinalDoDocumento}, com o contrato
 * do gerador real (o guardado volta como está; senão grava no disco em memória, guarda a chave e avisa
 * {@see PdfFinalGuardado}).
 */
#[CoversClass(VinculoDaAssinaturaController::class)]
#[CoversClass(VincularAPastaUseCase::class)]
#[CoversClass(BuscarPastasParaVincularUseCase::class)]
#[CoversClass(VinculoNaTela::class)]
#[CoversClass(VinculoPendenteListener::class)]
final class VinculoDaAssinaturaControllerTest extends JusPrimeWebTestCase
{
    use NaTelaDeAssinatura;
    use NaAssinaturaEnviada;

    private const PDF_FINAL = "%PDF-1.4\n% PDF final com selo do teste\n%%EOF\n";

    private ArmazenamentoEmMemoriaNoContainer $disco;

    /** Ligado: o PDF final dublado falha, como o gerador que não conseguiu montar o PDF com selo. */
    private bool $pdfFalha = false;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->disco = ArmazenamentoEmMemoriaNoContainer::instalarEm(static::getContainer());
        $this->csrfPrevisivel();
        $this->dublarPdfFinal();
    }

    /** @return iterable<string, array{string, string}> */
    public static function rotas(): iterable
    {
        yield 'busca de pastas' => ['GET', '/assinatura/pastas?q=maria'];
        yield 'vincular' => ['POST', '/assinatura/documentos/1/vincular'];
    }

    #[DataProvider('rotas')]
    #[TestDox('sem o módulo: 403 em $_dataName')]
    public function testSemModulo(string $metodo, string $url): void
    {
        [$eu, $tenant] = $this->entrar(false);
        $this->conceder($eu, $tenant, 'resources.pasta.view', 'resources.pasta.edit');

        $this->client->request($metodo, $url, [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => self::CSRF], '{"pasta":1}');

        self::assertResponseStatusCodeSame(403);
    }

    #[TestDox('vincular sem o token CSRF: 403 e nada muda')]
    public function testSemCsrf(): void
    {
        [$eu, $tenant] = $this->entrar(true);
        $this->conceder($eu, $tenant, 'resources.pasta.view', 'resources.pasta.edit');
        [$id] = $this->documentoEnviado();
        $pasta = $this->criarPasta($tenant);

        $this->client->request('POST', '/assinatura/documentos/' . $id . '/vincular', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['pasta' => $pasta->getId()], \JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(403);
        self::assertNotContains('vinculo_definido', $this->trilha($id));
    }

    #[TestDox('busca: número, cliente e ação (em maiúsculas, como a pasta guarda) separados, só do escritório; quem não edita pasta recebe a lista vazia')]
    public function testBusca(): void
    {
        [$eu, $tenant] = $this->entrar(true);
        $this->conceder($eu, $tenant, 'resources.pasta.view', 'resources.pasta.edit');
        $minha = $this->criarPasta($tenant, 'MARIA VINCULO DA SILVA', 'Ação de alimentos');
        [, $outro] = $this->entrar(true);
        $this->criarPasta($outro, 'MARIA VINCULO DA SILVA');
        $this->logarComTenant($this->client, $eu, $tenant);

        $this->client->request('GET', '/assinatura/pastas?q=' . rawurlencode('maria vínculo'));

        self::assertResponseIsSuccessful();
        self::assertSame(['pastas' => [[
            'id' => (int) $minha->getId(),
            'nup' => (string) $minha->getNup(),
            'cliente' => 'MARIA VINCULO DA SILVA',
            'acao' => 'AÇÃO DE ALIMENTOS',
        ]]], $this->json());

        [$soVe, $mesmo] = $this->entrar(true);
        $this->conceder($soVe, $mesmo, 'resources.pasta.view');
        $this->criarPasta($mesmo, 'MARIA VINCULO DA SILVA');
        $this->client->request('GET', '/assinatura/pastas?q=maria');
        self::assertSame(['pastas' => []], $this->json(), 'sem editar pasta: nem número nem cliente');
    }

    #[TestDox('busca de quem só edita pasta item a item: o filtro vem antes do limite, e a pasta antiga liberada aparece mesmo com mais de 60 recentes')]
    public function testBuscaComPermissaoItemAItem(): void
    {
        [$eu, $tenant] = $this->entrar(true);
        $liberada = $this->criarPasta($tenant, 'LIBERADA ITEM A ITEM');
        $soVer = $this->criarPasta($tenant, 'SO VER ITEM A ITEM');
        $this->liberarItem($eu, $tenant, $liberada, editar: true);
        $this->liberarItem($eu, $tenant, $soVer, editar: false);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $escritorio = $em->find(Tenant::class, $tenant->getId()) ?? self::fail('escritório');
        for ($i = 0; $i <= BuscarPastasParaVincularUseCase::LIMITE; ++$i) {
            $recente = new Pasta();
            $recente->setNup('REC' . $i . strtoupper(substr(uniqid(), -5)));
            $recente->setNomeCliente('RECENTE ' . $i);
            $recente->setTenant($escritorio);
            $em->persist($recente);
        }
        $em->flush();

        $this->client->request('GET', '/assinatura/pastas?q=');

        self::assertResponseIsSuccessful();
        self::assertSame([(int) $liberada->getId()], array_column($this->json()['pastas'], 'id'), 'só a liberada para editar, mesmo fora das 60 mais recentes');

        $this->client->request('GET', '/assinatura/pastas?q=' . rawurlencode('item a item'));
        self::assertSame([(int) $liberada->getId()], array_column($this->json()['pastas'], 'id'));
    }

    #[TestDox('aguardando: 200, aplicado falso, a trilha diz "ao concluir" e a lista mostra "Irá para a pasta"')]
    public function testAguardandoFicaPendente(): void
    {
        [$eu, $tenant] = $this->entrar(true);
        $this->conceder($eu, $tenant, 'resources.pasta.view', 'resources.pasta.edit');
        [$id] = $this->documentoEnviado();
        $pasta = $this->criarPasta($tenant, 'JOANA PENDENTE');

        $this->vincular($id, (int) $pasta->getId());

        self::assertResponseIsSuccessful();
        $json = $this->json();
        self::assertTrue($json['ok']);
        self::assertFalse($json['aplicado']);
        self::assertTrue($json['documento']['vinculado']);
        self::assertSame((int) $pasta->getId(), $json['documento']['vinculo']['pasta']);
        self::assertSame((string) $pasta->getNup(), $json['documento']['vinculo']['nup']);
        self::assertSame('JOANA PENDENTE', $json['documento']['vinculo']['cliente']);
        self::assertFalse($json['documento']['vinculo']['feito']);
        self::assertStringEndsWith('/pasta/' . $pasta->getId(), $json['documento']['vinculo']['url']);
        self::assertContains('Definido o vínculo à pasta ' . $pasta->getNup() . ' ao concluir', array_column($this->detalhe($id)['eventos'], 'texto'));
        self::assertSame([], $this->arquivosDaPasta($pasta), 'nada vai para a pasta antes de concluir');

        $linha = $this->linha($id);
        self::assertTrue($linha['vinculado']);
        self::assertFalse($linha['vinculo']['feito']);
    }

    #[TestDox('pasta de outro escritório ou inexistente: 404 "Pasta não encontrada."; só visualizar: 403')]
    public function testPastaRecusada(): void
    {
        [, $outro] = $this->entrar(true);
        $alheia = $this->criarPasta($outro);
        [$eu, $tenant] = $this->entrar(true);
        $this->conceder($eu, $tenant, 'resources.pasta.view');
        [$id] = $this->documentoEnviado();
        $soVer = $this->criarPasta($tenant);

        foreach ([(int) $alheia->getId(), 999999999] as $pastaId) {
            $this->vincular($id, $pastaId);
            self::assertResponseStatusCodeSame(404);
            self::assertSame(['erro' => 'Pasta não encontrada.'], $this->json());
        }
        $this->vincular($id, (int) $soVer->getId());
        self::assertResponseStatusCodeSame(403);
        self::assertSame(['erro' => 'Você não pode editar esta pasta.'], $this->json());

        $this->client->request('POST', '/assinatura/documentos/' . $id . '/vincular', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => self::CSRF], '{"pasta":"12"}');
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['erro' => 'Escolha uma pasta.'], $this->json());
        self::assertNotContains('vinculo_definido', $this->trilha($id));
    }

    #[TestDox('documento de outro escritório: 404, mesmo com a pasta do próprio escritório')]
    public function testDocumentoDeOutroEscritorio(): void
    {
        $this->entrar(true);
        [$id] = $this->documentoEnviado();
        [$eu, $tenant] = $this->entrar(true);
        $this->conceder($eu, $tenant, 'resources.pasta.view', 'resources.pasta.edit');
        $pasta = $this->criarPasta($tenant);

        $this->vincular($id, (int) $pasta->getId());

        self::assertResponseStatusCodeSame(404);
        self::assertNotContains('vinculo_definido', $this->trilha($id));
    }

    #[TestDox('concluído: vai na hora, cópia do PDF final assinada na pasta, código DOC, histórico e "Na pasta" na lista')]
    public function testConcluidoAplicaNaHora(): void
    {
        [$eu, $tenant] = $this->entrar(true);
        $this->conceder($eu, $tenant, 'resources.pasta.view', 'resources.pasta.edit');
        [$id] = $this->documentoEnviado();
        $this->situacao($id, 'concluido');
        $this->guardarPdfFinal($id);
        $pasta = $this->criarPasta($tenant, 'CARLOS CONCLUIDO');

        $this->vincular($id, (int) $pasta->getId());

        self::assertResponseIsSuccessful();
        $json = $this->json();
        self::assertTrue($json['aplicado']);
        self::assertFalse($json['pendente']);
        self::assertSame([], $this->pedidosDoPdfFinal(), 'com o PDF final guardado, nada vai para a fila');
        self::assertTrue($json['documento']['vinculo']['feito']);
        $arquivos = $this->arquivosDaPasta($pasta);
        self::assertCount(1, $arquivos);
        $arquivo = $arquivos[0];
        self::assertSame('assinado_Contrato_de_honorarios.pdf', $arquivo->getNomeOriginal());
        self::assertSame(PastaDocumento::CATEGORIA_DEMAIS, $arquivo->getCategoria());
        self::assertSame(OrigemDaAssinatura::Eletronica, $arquivo->getAssinaturaOrigem());
        self::assertSame($eu->getId(), $arquivo->getEnviadoPor()?->getId());
        $codigo = (string) $arquivo->getArquivo()?->getCodigo();
        self::assertMatchesRegularExpression('/^DOC-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}$/', $codigo);
        self::assertSame(self::PDF_FINAL, $this->disco->ler(ChavesDePasta::documento($arquivo)));
        self::assertSame(['Assinatura: vinculou documento assinado: Contrato de honorários (' . $json['documento']['codigo'] . ')'], $this->historicoDaPasta($pasta), 'o código do documento assinado, como no desenho');
        self::assertSame(['vinculo_feito'], array_values(array_filter($this->trilha($id), static fn (string $t): bool => str_starts_with($t, 'vinculo_'))));
        self::assertContains('Documento assinado vinculado à pasta ' . $pasta->getNup(), array_column($this->detalhe($id)['eventos'], 'texto'));
        self::assertTrue($this->linha($id)['vinculo']['feito']);
    }

    #[TestDox('concluído com a aplicação falhando: o vínculo se desfaz, a trilha diz o motivo e dá para vincular de novo a outra pasta')]
    public function testFalhouEVinculaDeNovoAOutraPasta(): void
    {
        [$eu, $tenant] = $this->entrar(true);
        $this->conceder($eu, $tenant, 'resources.pasta.view', 'resources.pasta.edit');
        [$id] = $this->documentoEnviado();
        $this->situacao($id, 'concluido');
        $this->guardarPdfFinal($id);
        $primeira = $this->criarPasta($tenant, 'PRIMEIRA FALHOU');
        $segunda = $this->criarPasta($tenant, 'SEGUNDA DEU CERTO');
        $this->pdfFalha = true;

        $this->vincular($id, (int) $primeira->getId());

        self::assertResponseIsSuccessful();
        $json = $this->json();
        self::assertFalse($json['aplicado']);
        self::assertFalse($json['pendente']);
        self::assertFalse($json['documento']['vinculado'], 'sem vínculo: o "Vincular à pasta" volta');
        self::assertNull($json['documento']['vinculo']);
        self::assertContains('Vínculo à pasta ' . $primeira->getNup() . ' não aplicado: o PDF assinado não pôde ser gerado', array_column($json['documento']['eventos'], 'texto'));
        self::assertSame([], $this->arquivosDaPasta($primeira));
        self::assertFalse($this->linha($id)['vinculado']);

        $this->pdfFalha = false;
        $this->vincular($id, (int) $segunda->getId());

        self::assertResponseIsSuccessful();
        $json = $this->json();
        self::assertTrue($json['aplicado']);
        self::assertSame((int) $segunda->getId(), $json['documento']['vinculo']['pasta']);
        self::assertTrue($json['documento']['vinculo']['feito']);
        self::assertCount(1, $this->arquivosDaPasta($segunda));
        self::assertSame([], $this->arquivosDaPasta($primeira));
        self::assertSame(['vinculo_nao_aplicado', 'vinculo_feito'], array_values(array_filter($this->trilha($id), static fn (string $t): bool => str_starts_with($t, 'vinculo_'))));
        self::assertSame((string) $segunda->getNup(), $this->linha($id)['vinculo']['nup']);
    }

    #[TestDox('quem não vê a pasta: a linha diz que há vínculo, sem número, cliente nem link')]
    public function testQuemNaoVeAPasta(): void
    {
        [$eu, $tenant] = $this->entrar(true);
        [$id] = $this->documentoEnviado();
        $pasta = $this->criarPasta($tenant, 'PASTA ESCONDIDA');
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $documento = $em->find(DocumentoParaAssinar::class, $id) ?? self::fail('documento');
        $documento->definirVinculo($pasta, $em->find(User::class, $eu->getId()) ?? self::fail('usuário'), new \DateTimeImmutable());
        $em->flush();

        $linha = $this->linha($id);

        self::assertTrue($linha['vinculado']);
        self::assertNull($linha['vinculo']);
        self::assertNull($this->detalhe($id)['vinculo']);
        self::assertStringNotContainsString('PASTA ESCONDIDA', (string) $this->client->getResponse()->getContent());
    }

    #[TestDox('o PDF final guardado (evento do bloco 1) aplica o vínculo pendente do documento que concluiu')]
    public function testPdfFinalGuardadoAplicaOPendente(): void
    {
        [$eu, $tenant] = $this->entrar(true);
        $this->conceder($eu, $tenant, 'resources.pasta.view', 'resources.pasta.edit');
        [$id] = $this->documentoEnviado();
        $pasta = $this->criarPasta($tenant);
        $this->vincular($id, (int) $pasta->getId());
        self::assertFalse($this->json()['aplicado']);
        self::assertFalse($this->json()['pendente'], 'aguardando: a tela diz "quando concluído"');
        $this->situacao($id, 'concluido');
        $this->guardarPdfFinal($id);

        static::getContainer()->get(EventDispatcherInterface::class)->dispatch(new PdfFinalGuardado($id));

        self::assertCount(1, $this->arquivosDaPasta($pasta));
        self::assertSame(['vinculo_definido', 'vinculo_feito'], array_values(array_filter($this->trilha($id), static fn (string $t): bool => str_starts_with($t, 'vinculo_'))));
        self::assertTrue($this->linha($id)['vinculo']['feito']);
    }

    #[TestDox('concluído sem o PDF final guardado: 200 com pendente, o PDF vai para o worker e, guardado por ele, o vínculo vai para a pasta uma vez só')]
    public function testConcluidoSemPdfEsperaOWorker(): void
    {
        [$eu, $tenant] = $this->entrar(true);
        $this->conceder($eu, $tenant, 'resources.pasta.view', 'resources.pasta.edit');
        [$id] = $this->documentoEnviado();
        $this->situacao($id, 'concluido');
        $pasta = $this->criarPasta($tenant, 'PEDRO ESPERA O PDF');

        $this->vincular($id, (int) $pasta->getId());

        self::assertResponseIsSuccessful();
        $json = $this->json();
        self::assertFalse($json['aplicado']);
        self::assertTrue($json['pendente']);
        self::assertTrue($json['documento']['vinculado'], 'o vínculo fica: o "Vincular à pasta" não volta');
        self::assertFalse($json['documento']['vinculo']['feito']);
        self::assertSame((int) $pasta->getId(), $json['documento']['vinculo']['pasta']);
        $pedidos = $this->pedidosDoPdfFinal();
        self::assertEquals([new GerarPdfFinalDoDocumento($id, (int) $tenant->getId())], $pedidos);
        self::assertNull($this->conexao()->fetchOne('SELECT arquivo_final_chave FROM assinatura_eletronica_documento WHERE id = ?', [$id]), 'nada gerado na requisição');
        self::assertSame([], $this->vinculosDaTrilha($id), 'sem "não aplicado": o vínculo segue pendente');
        self::assertSame([], $this->arquivosDaPasta($pasta));

        static::getContainer()->get(GerarPdfFinalDoDocumentoHandler::class)($pedidos[0]);

        self::assertCount(1, $this->pedidosDoPdfFinal(), 'o evento achou a chave no documento: não pediu o PDF de novo');
        self::assertNotNull($this->conexao()->fetchOne('SELECT arquivo_final_chave FROM assinatura_eletronica_documento WHERE id = ?', [$id]));
        self::assertCount(1, $this->arquivosDaPasta($pasta));
        self::assertSame(['vinculo_feito'], $this->vinculosDaTrilha($id));
        self::assertTrue($this->linha($id)['vinculo']['feito']);
    }

    private function vincular(int $id, int $pasta): void
    {
        $this->postarJson('/assinatura/documentos/' . $id . '/vincular', ['pasta' => $pasta]);
    }

    /** O PDF final já guardado pelo worker: no disco em memória e com a chave no documento, sem o evento. */
    private function guardarPdfFinal(int $id): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $documento = $em->find(DocumentoParaAssinar::class, $id) ?? self::fail('documento');
        $chave = $this->disco->gravar(ChavesDeAssinaturaEletronica::novoPdfFinal($documento), FonteDeConteudo::deTexto(self::PDF_FINAL))->chave;
        $documento->guardarArquivoFinal($chave->nome, hash('sha256', self::PDF_FINAL));
        $em->flush();
        $em->clear();
    }

    /** @return list<GerarPdfFinalDoDocumento> os pedidos do PDF final ao worker, na fila em memória */
    private function pedidosDoPdfFinal(): array
    {
        $fila = static::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $fila);
        $pedidos = [];
        foreach ($fila->getSent() as $envelope) {
            $mensagem = $envelope->getMessage();
            if ($mensagem instanceof GerarPdfFinalDoDocumento) {
                $pedidos[] = $mensagem;
            }
        }

        return $pedidos;
    }

    /** @return list<string> os tipos de vínculo da trilha do documento, do mais antigo para o mais recente */
    private function vinculosDaTrilha(int $id): array
    {
        return array_values(array_filter($this->trilha($id), static fn (string $t): bool => str_starts_with($t, 'vinculo_')));
    }

    /** @return array<string, mixed> */
    private function linha(int $id): array
    {
        foreach ($this->listar()['documentos'] as $linha) {
            if ($linha['id'] === $id) {
                return $linha;
            }
        }

        self::fail('documento ' . $id . ' fora da lista');
    }

    /** O perfil do `entrar()` só tem o módulo: aqui ganha as permissões de pasta dadas. */
    private function conceder(User $user, Tenant $tenant, string ...$codigos): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $perfil = $em->getRepository(UserTenant::class)->findOneBy(['user' => $user, 'tenant' => $tenant])?->getTenantRole() ?? self::fail('perfil');
        foreach ($codigos as $codigo) {
            $concessao = new TenantRolePermission();
            $concessao->setTenantRole($perfil);
            $concessao->setPermission($em->getRepository(Permission::class)->findOneBy(['code' => $codigo]) ?? self::fail($codigo));
            $em->persist($concessao);
            $perfil->getTenantRolePermissions()->add($concessao);
        }
        $em->flush();
    }

    /** A permissão item a item (`resource_access`) de ver e, quando pedido, editar a pasta. */
    private function liberarItem(User $user, Tenant $tenant, Pasta $pasta, bool $editar): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $acesso = new ResourceAccess();
        $acesso->setUser($em->find(User::class, $user->getId()) ?? self::fail('usuário'));
        $acesso->setTenant($em->find(Tenant::class, $tenant->getId()) ?? self::fail('escritório'));
        $acesso->setResourceType(ResourceAccess::RESOURCE_PASTA);
        $acesso->setResourceId((int) $pasta->getId());
        $acesso->setCanView(true);
        $acesso->setCanEdit($editar);
        $em->persist($acesso);
        $em->flush();
    }

    private function criarPasta(Tenant $tenant, string $cliente = 'MARIA DA SILVA', ?string $acao = null): Pasta
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $pasta = new Pasta();
        $pasta->setNup('VIN' . strtoupper(substr(uniqid(), -6)));
        $pasta->setNomeCliente($cliente);
        $pasta->setNomeAcao($acao);
        $pasta->setTenant($em->find(Tenant::class, $tenant->getId()) ?? self::fail('escritório'));
        $em->persist($pasta);
        $em->flush();

        return $pasta;
    }

    /** @return list<PastaDocumento> */
    private function arquivosDaPasta(Pasta $pasta): array
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        return array_values($em->getRepository(PastaDocumento::class)->findBy(['pasta' => $pasta->getId()]));
    }

    /** @return list<string> o histórico escrito pelo sistema "assinatura", sem as marcas */
    private function historicoDaPasta(Pasta $pasta): array
    {
        $mensagens = static::getContainer()->get(EntityManagerInterface::class)->getRepository(PastaMensagem::class)->findBy(['pasta' => $pasta->getId(), 'sistema' => 'assinatura']);

        return array_map(static fn (PastaMensagem $m): string => trim(strip_tags($m->getConteudo())), array_values($mensagens));
    }

    /**
     * Troca o serviço por trás de {@see PdfFinalDoDocumento} (o provisório, ou o gerador real quando o bloco 1 entrar;
     * o alvo sai do mapa do container) por um com o contrato do gerador real: o guardado volta como está; senão grava no
     * disco em memória, guarda a chave no documento e, depois do flush, avisa {@see PdfFinalGuardado}.
     */
    private function dublarPdfFinal(): void
    {
        $container = static::getContainer();
        $alvo = PdfFinalProvisorio::class;
        $mapa = $container->getParameter('debug.container.dump');
        $xml = \is_string($mapa) && is_file($mapa) ? simplexml_load_file($mapa) : false;
        foreach ($xml !== false ? ($xml->xpath('//*[@id="' . PdfFinalDoDocumento::class . '"][@alias]') ?: []) : [] as $no) {
            $alvo = (string) $no['alias'];
        }

        $servicos = static fn (): array => [static::getContainer()->get(EntityManagerInterface::class), static::getContainer()->get(EventDispatcherInterface::class)];
        $container->set($alvo, new class ($this->disco, self::PDF_FINAL, fn (): bool => $this->pdfFalha, $servicos) implements PdfFinalDoDocumento {
            /** @param \Closure(): array{EntityManagerInterface, EventDispatcherInterface} $servicos */
            public function __construct(private readonly ArmazenamentoEmMemoriaNoContainer $disco, private readonly string $conteudo, private readonly \Closure $falha, private readonly \Closure $servicos)
            {
            }

            public function garantir(DocumentoParaAssinar $doc, ?\Closure $aCadaPedaco = null, bool $ultimaTentativa = false): ChaveDeArquivo
            {
                if (($this->falha)() === true) {
                    throw new \RuntimeException('PDF final indisponível no teste.');
                }
                $guardada = ChavesDeAssinaturaEletronica::pdfFinal($doc);
                if ($guardada !== null) {
                    return $guardada;
                }

                [$em, $eventos] = ($this->servicos)();
                $nova = $this->disco->gravar(ChavesDeAssinaturaEletronica::novoPdfFinal($doc), FonteDeConteudo::deTexto($this->conteudo))->chave;
                $doc->guardarArquivoFinal($nova->nome, hash('sha256', $this->conteudo));
                $em->flush();
                $eventos->dispatch(new PdfFinalGuardado((int) $doc->getId()));

                return $nova;
            }
        });
    }
}
