<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Functional;

use App\Cobranca\DTO\AbrirCasoInput;
use App\Cobranca\DTO\JudicializarCasoInput;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\CobrancaDocumento;
use App\Cobranca\Entity\CobrancaSecao;
use App\Cobranca\Entity\ObjetoCobranca;
use App\Cobranca\Entity\Pessoa;
use App\Cobranca\Enum\CategoriaDocumentoCobranca;
use App\Cobranca\Enum\ModoCarteira;
use App\Cobranca\Enum\StatusCaso;
use App\Cobranca\Exception\SecaoNaoEncontradaException;
use App\Cobranca\Repository\CasoCobrancaRepository;
use App\Cobranca\Repository\CobrancaDocumentoRepository;
use App\Cobranca\Repository\CobrancaSecaoRepository;
use App\Cobranca\Repository\EventoHistoricoRepository;
use App\Cobranca\Repository\ObjetoCobrancaRepository;
use App\Cobranca\Repository\PessoaRepository;
use App\Cobranca\Service\RegistrarEventoHistorico;
use App\Cobranca\UseCase\AbrirCasoUseCase;
use App\Cobranca\UseCase\CriarSecaoUseCase;
use App\Cobranca\UseCase\EnviarDocumentoUseCase;
use App\Cobranca\UseCase\ExcluirSecaoUseCase;
use App\Cobranca\UseCase\JudicializarCasoUseCase;
use App\Cobranca\UseCase\MoverDocumentoUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Repository\PastaRepository;
use App\Shared\Service\ArquivoStorageInterface;
use App\Shared\Service\CompressorArquivoInterface;
use App\Tests\Factory\Cliente\ClientePFFactory;
use App\Tests\Factory\Cobranca\CarteiraFactory;
use App\Tests\Factory\Cobranca\ObjetoCobrancaFactory;
use App\Tests\Factory\Cobranca\PessoaFactory;
use App\Tests\Factory\Pasta\PastaFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Zenstruck\Foundry\Test\Factories;

/**
 * Etapa 6 (documentos do Caso de Cobrança) contra o BANCO e o DISCO REAIS. Prova:
 * (1) documento existe vinculado ao Caso SEM Pasta (invariável 25); (2) ao judicializar, os
 * documentos PERMANECEM no Caso — não migram nem duplicam para a Pasta (SPEC §15/§16); (3)
 * isolamento FÍSICO por tenant no disco (arquivos em cobrancas/<tenantId>/, padrão M5); (4) IDOR
 * por tenant nos UseCases que cruzam Caso/Seção/Documento (guarda explícita, não a rede do
 * TenantFilter — KernelTestCase o desliga). Usa o storage e o diretório de uploads REAIS
 * (var/uploads-test/cobrancas no ambiente de teste); os arquivos criados são limpos no tearDown
 * (o DAMA reverte o banco, mas não o disco).
 */
#[CoversClass(EnviarDocumentoUseCase::class)]
#[CoversClass(MoverDocumentoUseCase::class)]
#[CoversClass(CriarSecaoUseCase::class)]
#[CoversClass(ExcluirSecaoUseCase::class)]
final class DocumentosCobrancaIsolamentoTenantTest extends KernelTestCase
{
    use Factories;

    private EntityManagerInterface $em;
    private ArquivoStorageInterface $storage;
    private string $cobrancasUploadsDir;
    private AbrirCasoUseCase $abrirCaso;
    private JudicializarCasoUseCase $judicializar;
    private EnviarDocumentoUseCase $enviarDocumento;
    private MoverDocumentoUseCase $moverDocumento;
    private CriarSecaoUseCase $criarSecao;
    private ExcluirSecaoUseCase $excluirSecao;

    /** @var list<string> caminhos físicos criados, para limpeza no tearDown */
    private array $arquivosCriados = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $c = static::getContainer();
        $this->em = $c->get(EntityManagerInterface::class);
        $this->storage = $c->get(ArquivoStorageInterface::class);
        $this->cobrancasUploadsDir = (string) $c->getParameter('cobrancas_uploads_dir');

        $compressor = $c->get(CompressorArquivoInterface::class);

        /** @var CasoCobrancaRepository $casoRepo */
        $casoRepo = $this->em->getRepository(CasoCobranca::class);
        /** @var ObjetoCobrancaRepository $objetoRepo */
        $objetoRepo = $this->em->getRepository(ObjetoCobranca::class);
        /** @var PessoaRepository $pessoaRepo */
        $pessoaRepo = $this->em->getRepository(Pessoa::class);
        /** @var EventoHistoricoRepository $eventoRepo */
        $eventoRepo = $this->em->getRepository(\App\Cobranca\Entity\EventoHistorico::class);
        /** @var PastaRepository $pastaRepo */
        $pastaRepo = $this->em->getRepository(Pasta::class);
        /** @var CobrancaDocumentoRepository $docRepo */
        $docRepo = $this->em->getRepository(CobrancaDocumento::class);
        /** @var CobrancaSecaoRepository $secaoRepo */
        $secaoRepo = $this->em->getRepository(CobrancaSecao::class);

        $registrarEvento = new RegistrarEventoHistorico($eventoRepo);

        $this->abrirCaso = new AbrirCasoUseCase($casoRepo, $objetoRepo, $pessoaRepo, $registrarEvento);
        // Este arquivo só usa o modo `vincular` (pasta EXISTENTE); os dois serviços do modo `criar`
        // entram reais e não chegam a ser chamados. O repositório de ClientePF vem do CONTAINER —
        // `ClientePF` não declara repositoryClass, então `getRepository()` daria o genérico.
        /** @var \App\Cliente\Repository\ClientePFRepository $clientePFRepo */
        $clientePFRepo = $c->get(\App\Cliente\Repository\ClientePFRepository::class);
        $this->judicializar = new JudicializarCasoUseCase(
            $casoRepo,
            $pastaRepo,
            $registrarEvento,
            new \App\Pasta\UseCase\CriarPastaUseCase($this->em, $c->get(\App\Pasta\UseCase\GerarNumeroDePasta::class)),
            new \App\Cobranca\Service\ResolvedorClienteDoResponsavel($clientePFRepo),
            new \App\Cobranca\Service\ComporNomeDaPastaJudicial(),
        );
        $this->enviarDocumento = new EnviarDocumentoUseCase($docRepo, $this->storage, $compressor, $this->cobrancasUploadsDir);
        $this->moverDocumento = new MoverDocumentoUseCase($docRepo);
        $this->criarSecao = new CriarSecaoUseCase($secaoRepo);
        $this->excluirSecao = new ExcluirSecaoUseCase($secaoRepo, $this->storage, $this->cobrancasUploadsDir);
    }

    protected function tearDown(): void
    {
        foreach ($this->arquivosCriados as $caminho) {
            if (is_file($caminho)) {
                @unlink($caminho);
            }
        }

        $this->arquivosCriados = [];

        parent::tearDown();
    }

    #[TestDox('Documento existe vinculado ao Caso SEM Pasta (invariável 25) e o arquivo isola por tenant no disco')]
    public function testDocumentoExisteSemPastaEIsolaNoDiscoPorTenant(): void
    {
        $tenant = $this->criarTenant();
        $user = $this->criarUser();
        $caso = $this->abrirCasoDe($tenant, $user);

        self::assertNull($caso->getPastaJudicial(), 'Pré-condição: caso ainda sem Pasta.');

        $doc = $this->enviarDocumento->executar(
            $caso,
            null,
            $this->arquivoDeTeste('boleto.txt'),
            CategoriaDocumentoCobranca::Boleto,
            null,
            $tenant,
        );

        // Documento persistido, ligado ao caso, sem depender de Pasta.
        self::assertNotNull($doc->getId());
        self::assertSame($caso, $doc->getCaso());
        self::assertNull($caso->getPastaJudicial());

        // Isolamento físico: o arquivo mora em cobrancas/<tenantId>/<hash>.
        $caminhoEsperado = $this->cobrancasUploadsDir . '/' . $tenant->getId() . '/' . $doc->getCaminhoArquivo();
        $this->arquivosCriados[] = $caminhoEsperado;
        self::assertFileExists($caminhoEsperado, 'Arquivo deveria estar isolado na subpasta do tenant.');
    }

    #[TestDox('Ao judicializar, os documentos PERMANECEM no Caso — não migram nem duplicam para a Pasta (§15/§16)')]
    public function testDocumentosPermanecemNoCasoAposJudicializacao(): void
    {
        $tenant = $this->criarTenant();
        $user = $this->criarUser();
        $caso = $this->abrirCasoDe($tenant, $user);

        $doc = $this->enviarDocumento->executar(
            $caso,
            null,
            $this->arquivoDeTeste('termo.txt'),
            CategoriaDocumentoCobranca::TermoAcordo,
            null,
            $tenant,
        );
        $this->arquivosCriados[] = $this->cobrancasUploadsDir . '/' . $tenant->getId() . '/' . $doc->getCaminhoArquivo();

        // Judicializa o caso (vincula uma Pasta do mesmo tenant).
        $pasta = PastaFactory::createOne(['tenant' => $tenant]);
        $jInput = new JudicializarCasoInput();
        $jInput->casoId = (int) $caso->getId();
        $jInput->modo = JudicializarCasoInput::MODO_VINCULAR;
        $jInput->pastaId = (int) $pasta->getId();
        $casoJudicializado = $this->judicializar->executar($jInput, $tenant, $user);

        self::assertSame(StatusCaso::Judicializado, $casoJudicializado->getStatus());

        // O documento continua no Caso, com a mesma FK; nada migrou para a Pasta.
        $this->em->clear();
        /** @var CobrancaDocumentoRepository $docRepo */
        $docRepo = $this->em->getRepository(CobrancaDocumento::class);
        /** @var CobrancaDocumento|null $recarregado */
        $recarregado = $docRepo->find($doc->getId());
        self::assertNotNull($recarregado);
        self::assertSame((int) $caso->getId(), (int) $recarregado->getCaso()->getId());

        $docsDoCaso = $docRepo->documentosDoCaso($casoJudicializado);
        self::assertCount(1, $docsDoCaso, 'O documento deve permanecer único e no Caso após judicializar.');
    }

    #[TestDox('EnviarDocumento não alcança caso de outro escritório (IDOR por tenant)')]
    public function testEnviarDocumentoRejeitaCasoDeOutroTenant(): void
    {
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $user = $this->criarUser();
        $casoA = $this->abrirCasoDe($tenantA, $user);

        $this->expectException(AccessDeniedException::class);

        // Operando como tenant B sobre um caso do tenant A.
        $this->enviarDocumento->executar(
            $casoA,
            null,
            $this->arquivoDeTeste('intruso.txt'),
            CategoriaDocumentoCobranca::Outro,
            null,
            $tenantB,
        );
    }

    #[TestDox('EnviarDocumento rejeita seção de outro caso (SecaoNaoEncontrada)')]
    public function testEnviarDocumentoRejeitaSecaoDeOutroCaso(): void
    {
        $tenant = $this->criarTenant();
        $user = $this->criarUser();
        $casoUm = $this->abrirCasoDe($tenant, $user);
        $casoDois = $this->abrirCasoDe($tenant, $user);

        // Seção pertence ao casoDois; tentamos usá-la ao enviar para o casoUm.
        $secaoDoCasoDois = $this->criarSecao->executar($casoDois, 'Comprovantes', $tenant);

        $this->expectException(SecaoNaoEncontradaException::class);

        $this->enviarDocumento->executar(
            $casoUm,
            $secaoDoCasoDois,
            $this->arquivoDeTeste('cruzado.txt'),
            CategoriaDocumentoCobranca::Outro,
            null,
            $tenant,
        );
    }

    #[TestDox('MoverDocumento não alcança documento de outro escritório (IDOR por tenant)')]
    public function testMoverDocumentoRejeitaDocumentoDeOutroTenant(): void
    {
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $user = $this->criarUser();
        $casoA = $this->abrirCasoDe($tenantA, $user);

        $doc = $this->enviarDocumento->executar(
            $casoA,
            null,
            $this->arquivoDeTeste('doc.txt'),
            CategoriaDocumentoCobranca::Outro,
            null,
            $tenantA,
        );
        $this->arquivosCriados[] = $this->cobrancasUploadsDir . '/' . $tenantA->getId() . '/' . $doc->getCaminhoArquivo();

        $this->expectException(AccessDeniedException::class);

        $this->moverDocumento->executar($doc, null, $tenantB);
    }

    #[TestDox('ExcluirSecao não alcança seção de outro escritório (IDOR por tenant)')]
    public function testExcluirSecaoRejeitaSecaoDeOutroTenant(): void
    {
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $user = $this->criarUser();
        $casoA = $this->abrirCasoDe($tenantA, $user);
        $secaoA = $this->criarSecao->executar($casoA, 'Notificações', $tenantA);

        $this->expectException(AccessDeniedException::class);

        $this->excluirSecao->executar($secaoA, $tenantB);
    }

    // ----------------------------------------------------------------- helpers

    private function arquivoDeTeste(string $nome): UploadedFile
    {
        $caminho = sys_get_temp_dir() . '/cobr_doc_' . uniqid() . '.txt';
        file_put_contents($caminho, 'conteudo de teste do documento de cobranca');
        $this->arquivosCriados[] = $caminho;

        // test mode (último arg true): pula a checagem is_uploaded_file.
        return new UploadedFile($caminho, $nome, 'text/plain', null, true);
    }

    private function abrirCasoDe(Tenant $tenant, User $user): CasoCobranca
    {
        $objeto = $this->criarObjeto($tenant);
        $pessoa = PessoaFactory::createOne(['tenant' => $tenant]);
        $input = new AbrirCasoInput();
        $input->objetoId = (int) $objeto->getId();
        $input->pessoaCobradaId = (int) $pessoa->getId();

        return $this->abrirCaso->executar($input, $tenant, $user);
    }

    private function criarTenant(): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName('Tenant DOC ' . uniqid());
        $this->em->persist($tenant);
        $this->em->flush();

        return $tenant;
    }

    private function criarUser(): User
    {
        $user = new User();
        $user->setEmail('doc_' . uniqid() . '@test.com');
        $user->setFullName('User Doc');
        $user->setIsActive(true);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function criarObjeto(Tenant $tenant): object
    {
        $carteira = CarteiraFactory::createOne([
            'tenant' => $tenant,
            'cliente' => ClientePFFactory::createOne(['tenant' => $tenant]),
            'modo' => ModoCarteira::Multiplo,
        ]);

        return ObjetoCobrancaFactory::createOne(['tenant' => $tenant, 'carteira' => $carteira]);
    }
}
