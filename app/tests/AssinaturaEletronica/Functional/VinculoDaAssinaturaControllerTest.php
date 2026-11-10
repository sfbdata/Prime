<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Functional;

use App\AssinaturaEletronica\Armazenamento\ChavesDeAssinaturaEletronica;
use App\AssinaturaEletronica\Controller\VinculoDaAssinaturaController;
use App\AssinaturaEletronica\Entity\DocumentoParaAssinar;
use App\AssinaturaEletronica\Event\PdfFinalGuardado;
use App\AssinaturaEletronica\EventListener\VinculoPendenteListener;
use App\AssinaturaEletronica\Service\PdfFinalDoDocumento;
use App\AssinaturaEletronica\Service\PdfFinalProvisorio;
use App\AssinaturaEletronica\Service\VinculoNaTela;
use App\AssinaturaEletronica\UseCase\BuscarPastasParaVincularUseCase;
use App\AssinaturaEletronica\UseCase\VincularAPastaUseCase;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Permission\Permission;
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
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * "Vincular à pasta de Expediente" pela HTTP (spec assinatura-documentos-etapa-3, E3-10 a E3-12): a busca da janela e o
 * vínculo. Porta do módulo, CSRF, a pasta do escritório que a pessoa edita (404 sem dizer qual, 403 de quem só vê),
 * aguardando fica pendente, concluído vai na hora para a pasta e o PDF final guardado aplica o pendente.
 *
 * O PDF final com selo é do bloco 1: aqui ele é dublado no serviço por trás de {@see PdfFinalDoDocumento}, grava no
 * disco em memória como o gerador real.
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
        $pasta = $this->criarPasta($tenant, 'CARLOS CONCLUIDO');

        $this->vincular($id, (int) $pasta->getId());

        self::assertResponseIsSuccessful();
        $json = $this->json();
        self::assertTrue($json['aplicado']);
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
        $this->situacao($id, 'concluido');

        static::getContainer()->get(EventDispatcherInterface::class)->dispatch(new PdfFinalGuardado($id));

        self::assertCount(1, $this->arquivosDaPasta($pasta));
        self::assertSame(['vinculo_definido', 'vinculo_feito'], array_values(array_filter($this->trilha($id), static fn (string $t): bool => str_starts_with($t, 'vinculo_'))));
        self::assertTrue($this->linha($id)['vinculo']['feito']);
    }

    private function vincular(int $id, int $pasta): void
    {
        $this->postarJson('/assinatura/documentos/' . $id . '/vincular', ['pasta' => $pasta]);
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
     * o alvo sai do mapa do container) por um que grava o PDF final no disco em memória uma vez por documento.
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

        $container->set($alvo, new class ($this->disco, self::PDF_FINAL) implements PdfFinalDoDocumento {
            /** @var array<int, ChaveDeArquivo> */
            private array $guardados = [];

            public function __construct(private readonly ArmazenamentoEmMemoriaNoContainer $disco, private readonly string $conteudo)
            {
            }

            public function garantir(DocumentoParaAssinar $doc): ChaveDeArquivo
            {
                return $this->guardados[(int) $doc->getId()] ??= $this->disco->gravar(ChavesDeAssinaturaEletronica::novoPdfFinal($doc), FonteDeConteudo::deTexto($this->conteudo))->chave;
            }
        });
    }
}
