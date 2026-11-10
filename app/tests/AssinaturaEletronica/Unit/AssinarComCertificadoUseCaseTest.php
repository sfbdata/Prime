<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Unit;

use App\AssinaturaEletronica\Armazenamento\ChavesDeAssinaturaEletronica;
use App\AssinaturaEletronica\DTO\DadosDoRascunho;
use App\AssinaturaEletronica\DTO\EntradaDoCertificado;
use App\AssinaturaEletronica\DTO\PdfAssinadoConfirmado;
use App\AssinaturaEletronica\DTO\PdfAssinadoEnviado;
use App\AssinaturaEletronica\Entity\DocumentoParaAssinar;
use App\AssinaturaEletronica\Entity\Signatario;
use App\AssinaturaEletronica\Entity\VersaoDoPdf;
use App\AssinaturaEletronica\Enum\OrigemDaVersao;
use App\AssinaturaEletronica\Enum\SituacaoDaCadeia;
use App\AssinaturaEletronica\Enum\SituacaoDoDocumento;
use App\AssinaturaEletronica\Enum\SituacaoDoSignatario;
use App\AssinaturaEletronica\Enum\TipoDeEvento;
use App\AssinaturaEletronica\Exception\AcaoRecusada;
use App\AssinaturaEletronica\Exception\LinkInvalido;
use App\AssinaturaEletronica\Message\GerarPdfFinalDoDocumento;
use App\AssinaturaEletronica\Repository\VersaoDoPdfRepository;
use App\AssinaturaEletronica\Service\Cadeia\ConferenciaDaCadeia;
use App\AssinaturaEletronica\Service\Cadeia\RegraDaCadeia;
use App\AssinaturaEletronica\Service\ChaveDaAssinatura;
use App\AssinaturaEletronica\Service\ConferenciaDePdf\ConferenciaDePdf;
use App\AssinaturaEletronica\Service\ConferenciaDePdf\LimitesDaConferencia;
use App\AssinaturaEletronica\Service\ConferenciaDoPdfAssinado;
use App\AssinaturaEletronica\Service\ResultadoDoPdfAssinado;
use App\AssinaturaEletronica\UseCase\AssinarComCertificadoUseCase;
use App\Shared\Armazenamento\CategoriaDeArquivo;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\EscopoDeArquivo;
use App\Tests\AssinaturaEletronica\Support\OficinaDosUseCasesDaAssinatura;
use App\Tests\AssinaturaEletronica\Support\ValidadorDeCadeiaQueResponde;
use App\Tests\AssinaturaEletronica\Unit\ConferenciaDePdf\OficinaDePdfAssinado;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Translation\IdentityTranslator;

/**
 * A assinatura com certificado da tela pública (etapa 5, bloco 5C) com os repositórios do domínio dublados (a oficina
 * dos UseCases), o motor, a regra e a conferência de verdade e o validador de cadeia dublado
 * ({@see ValidadorDeCadeiaQueResponde}, que responde pelas faixas do próprio arquivo). Cobre a ordem das conferências,
 * o que é gravado em cada desfecho (versão `devolvida` com a cadeia, trilha, arquivo) e o que não é.
 */
#[CoversClass(AssinarComCertificadoUseCase::class)]
#[CoversClass(EntradaDoCertificado::class)]
#[CoversClass(PdfAssinadoEnviado::class)]
#[CoversClass(PdfAssinadoConfirmado::class)]
final class AssinarComCertificadoUseCaseTest extends TestCase
{
    use OficinaDosUseCasesDaAssinatura;

    private const SHA = 'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';
    private const ANA = ['nome' => 'Ana Lima', 'email' => 'ana@exemplo.com'];
    private const BIA = ['nome' => 'Bia Souza', 'email' => 'bia@exemplo.com', 'nivel' => 'govbr', 'cpf' => OficinaDePdfAssinado::CPF];
    private const CARLA = ['nome' => 'Carla Dias', 'email' => 'carla@exemplo.com', 'nivel' => 'icp'];

    private ValidadorDeCadeiaQueResponde $validador;
    private RateLimiterFactory $limitador;
    private VersaoDoPdfRepository $versoes;
    /** @var list<VersaoDoPdf> */
    private array $versoesNaBase = [];
    /** @var list<VersaoDoPdf> as versões que o UseCase salvou */
    private array $salvas = [];

    protected function setUp(): void
    {
        $this->montarOficina();
        $this->quemEnviou = self::usuario('quem.enviou@escritorio.test', 'Dra. Remetente');
        $this->validador = new ValidadorDeCadeiaQueResponde();
        $this->limitador = new RateLimiterFactory(
            ['id' => 'assinatura_validador_conferencia', 'policy' => 'sliding_window', 'limit' => 6, 'interval' => '10 minutes'],
            new InMemoryStorage(),
        );
        $this->versoes = $this->createMock(VersaoDoPdfRepository::class);
        $this->versoes->method('ultima')->willReturnCallback(function (DocumentoParaAssinar $documento): ?VersaoDoPdf {
            $doDocumento = array_filter($this->versoesNaBase, static fn (VersaoDoPdf $v): bool => $v->getDocumento() === $documento);

            return array_pop($doDocumento);
        });
        $this->versoes->method('salvar')->willReturnCallback(function (VersaoDoPdf $versao): void {
            $this->salvas[] = $versao;
            $this->versoesNaBase[] = $versao;
        });
        $this->versoes->expects(self::never())->method('remover');
    }

    #[TestDox('cadeia confirmada, em ordem: a versão devolvida com a análise e a cadeia, o arquivo no disco do escritório, "assinado" na trilha e a vez avisada ao próximo')]
    public function testConfirmadaGravaEPassaAVez(): void
    {
        [$documento, $base] = $this->enviado([self::BIA, self::ANA], emOrdem: true);
        [$bia, $ana] = $documento->getSignatarios();
        $devolvido = OficinaDePdfAssinado::pdfAssinadoComVinculo('ec', null, $base);

        $publico = $this->useCase()->executar($this->chave->token($bia), $this->entrada($this->upload('Contrato assinado.pdf', $devolvido)), self::evidencia());

        self::assertSame('ja_assinado', $publico['estado']);
        self::assertSame(SituacaoDoSignatario::Assinado, $bia->getSituacao());
        self::assertSame(['Bia Souza Lima', OficinaDePdfAssinado::CPF, 'govbr', null], [$bia->getNomeDigitado(), $bia->getCpfDigitado(), $bia->getMetodo(), $bia->getRubricaChave()]);
        self::assertCount(1, $this->salvas);
        $versao = $this->salvas[0];
        self::assertSame([OrigemDaVersao::Devolvida, 2, $bia, 'Contrato assinado.pdf', hash('sha256', $devolvido), \strlen($devolvido)], [$versao->getOrigem(), $versao->getOrdem(), $versao->getSignatario(), $versao->getNomeOriginal(), $versao->getSha256(), $versao->getTamanho()]);
        self::assertSame([1, 1, 2], [$versao->getAnalise()['v'], $versao->getAnalise()['assinaturas_novas'], \count($versao->getAnalise()['assinaturas'])], 'a análise é a do motor sobre o arquivo devolvido');
        self::assertSame(SituacaoDaCadeia::Confirmada, $versao->getCadeiaSituacao());
        self::assertSame(1, $versao->getCadeia()['v'] ?? null);
        self::assertSame('2026-11-09 10:00:00', $versao->getCadeiaConferidaEm()?->format('Y-m-d H:i:s'));
        self::assertSame($devolvido, $this->disco->ler(new ChaveDeArquivo(EscopoDeArquivo::deTenant(9), CategoriaDeArquivo::ASSINATURA_DOCUMENTO, $versao->getArquivoChave())), 'o arquivo é do escritório do documento');

        self::assertSame(['assinado', 'convite_enviado'], $this->tiposGravados());
        $dados = $this->ultimoGravado(TipoDeEvento::Assinado)->getDados();
        self::assertSame('govbr', $dados['metodo']);
        self::assertSame(['Contrato assinado.pdf', 2, hash('sha256', $devolvido), true], [$dados['arquivo'], $dados['n'], $dados['sha256'], $dados['cpf_confere']]);
        self::assertSame(['versao' => 1, 'sha256' => hash('sha256', 'assinatura_eletronica.aceite.v1')], $dados['aceite']);
        self::assertSame($bia, $this->ultimoGravado(TipoDeEvento::Assinado)->getSignatario());
        self::assertSame(['qualquer'], $this->validador->ancoras, 'gov.br aceita a âncora gov.br ou a ICP-Brasil');
        self::assertSame('ana@exemplo.com', $this->unicoEmail()->getTo()[0]->getAddress());
        self::assertStringContainsString('link=https://app.bluejus.test/assinar/' . $this->chave->token($ana), (string) $this->unicoEmail()->getHtmlBody());
        self::assertSame([], $this->despachadas, 'sem conclusão, o PDF final não é pedido');
    }

    #[TestDox('o último a assinar conclui: "documento_concluido", o remetente recebe a conclusão e o PDF final é pedido ao worker')]
    public function testUltimoConclui(): void
    {
        [$documento, $base] = $this->enviado([self::ANA, self::BIA]);
        [$ana, $bia] = $documento->getSignatarios();
        $ana->registrarAssinatura('Ana Lima Souza', '11144477735', 'r.png', 'simples', null, null, new \DateTimeImmutable('2026-11-09 09:30:00'));

        $publico = $this->useCase()->executar($this->chave->token($bia), $this->entrada($this->upload('a.pdf', OficinaDePdfAssinado::pdfAssinadoComVinculo('ec', null, $base))), self::evidencia());

        self::assertSame('concluido', $publico['estado']);
        self::assertSame(SituacaoDoDocumento::Concluido, $documento->getSituacao());
        self::assertSame(['assinado', 'documento_concluido'], $this->tiposGravados());
        self::assertSame('criadora@escritorio.test', $this->unicoEmail()->getTo()[0]->getAddress());
        self::assertEquals([new GerarPdfFinalDoDocumento(1, 9)], $this->despachadas, 'a conclusão pede o PDF final ao worker');
    }

    /** @return iterable<string, array{\Closure(string): string, string, string}> */
    public static function recusasAntesDoValidador(): iterable
    {
        yield 'não começa pela base' => [static fn (string $base): string => OficinaDePdfAssinado::pdfAssinadoComVinculo('ec'), ResultadoDoPdfAssinado::PREFIXO, RegraDaCadeia::PREFIXO_NAO_CONFERE];
        yield 'nenhuma assinatura nova (a própria base)' => [static fn (string $base): string => $base, ResultadoDoPdfAssinado::SEM_ASSINATURA_NOVA, RegraDaCadeia::SEM_ASSINATURA_NOVA];
        yield 'acréscimo que muda a página' => [
            static fn (string $base): string => OficinaDePdfAssinado::assinar(
                OficinaDePdfAssinado::acrescimoParaAssinar($base, trocas: [3 => '<</Type/Page/Parent 2 0 R/MediaBox[0 0 600 842]/Annots[4 0 R 6 0 R]>>']),
                OficinaDePdfAssinado::cmsDoOpenssl(OficinaDePdfAssinado::chave('ec')),
            ),
            ResultadoDoPdfAssinado::ALTERADA,
            RegraDaCadeia::ACRESCIMO_MUDA_CONTEUDO,
        ];
    }

    /** @param \Closure(string): string $devolvido */
    #[DataProvider('recusasAntesDoValidador')]
    #[TestDox('recusa do motor ($erro): o validador não é chamado; "pdf_assinado_recusado" com o motivo, a regra ($regra) e o sha256; nada guardado')]
    public function testRecusaAntesDoValidador(\Closure $devolvido, string $erro, string $regra): void
    {
        [$documento, $base] = $this->enviado([self::BIA]);
        $pdf = $devolvido($base);

        $this->assertRecusaGravada($erro, $regra, $documento->getSignatarios()[0], $pdf);
        self::assertSame(0, $this->validador->conferencias, 'o validador de cadeia não é chamado');
    }

    #[TestDox('sem versão guardada do documento: "prefixo" com a regra "prefixo_nao_pedido", sem chamar o validador')]
    public function testSemBase(): void
    {
        [$documento] = $this->enviado([self::BIA]);
        $this->versoesNaBase = [];

        $this->assertRecusaGravada(ResultadoDoPdfAssinado::PREFIXO, RegraDaCadeia::PREFIXO_NAO_PEDIDO, $documento->getSignatarios()[0], OficinaDePdfAssinado::pdfAssinadoComVinculo('ec'));
        self::assertSame(0, $this->validador->conferencias);
    }

    #[TestDox('recusa do validador: "cadeia_recusada" com a regra que recusou; o PDF que ele não lê é "ilegivel"; nada de versão')]
    public function testRecusaDoValidador(): void
    {
        [$documento, $base] = $this->enviado([self::BIA]);
        $bia = $documento->getSignatarios()[0];
        $pdf = OficinaDePdfAssinado::pdfAssinadoComVinculo('ec', null, $base);

        $this->validador->troca = ['confiavel' => false];
        $this->assertRecusaGravada(ResultadoDoPdfAssinado::CADEIA_RECUSADA, RegraDaCadeia::NAO_CONFIAVEL, $bia, $pdf);

        $this->gravados = [];
        $this->validador->troca = [];
        $this->validador->erro = 'pdf_ilegivel';
        $this->assertRecusaGravada(ResultadoDoPdfAssinado::ILEGIVEL, 'pdf_ilegivel', $bia, $pdf);
        self::assertSame(2, $this->validador->conferencias);
    }

    #[TestDox('validador fora do ar ou no limite de conferências do signatário: "cadeia_indisponivel" e nada gravado (nem evento, nem versão, nem arquivo)')]
    public function testValidadorIndisponivel(): void
    {
        [$documento, $base] = $this->enviado([self::BIA]);
        $bia = $documento->getSignatarios()[0];
        $pdf = OficinaDePdfAssinado::pdfAssinadoComVinculo('ec', null, $base);

        $this->validador->indisponivel = true;
        $this->assertRecusaSemGravar(ResultadoDoPdfAssinado::chave(ResultadoDoPdfAssinado::CADEIA_INDISPONIVEL), $bia, $this->entrada($this->upload('a.pdf', $pdf)));
        self::assertSame(1, $this->validador->conferencias);

        $this->validador->indisponivel = false;
        $doSignatario = $this->limitador->create(ConferenciaDaCadeia::quemSignatario((int) $bia->getId()));
        while ($doSignatario->consume()->isAccepted()) {
            // gasta as vagas que sobraram ao signatário
        }
        $this->assertRecusaSemGravar(ResultadoDoPdfAssinado::chave(ResultadoDoPdfAssinado::CADEIA_INDISPONIVEL), $bia, $this->entrada($this->upload('a.pdf', $pdf)));
        self::assertSame(1, $this->validador->conferencias, 'no limite, o validador não é chamado');
    }

    #[TestDox('validador não configurado: "cadeia_indisponivel" antes do formulário e do arquivo')]
    public function testValidadorNaoConfigurado(): void
    {
        [$documento] = $this->enviado([self::BIA]);
        $this->validador = new ValidadorDeCadeiaQueResponde(configurado: false);

        $this->assertRecusaSemGravar(ResultadoDoPdfAssinado::chave(ResultadoDoPdfAssinado::CADEIA_INDISPONIVEL), $documento->getSignatarios()[0], $this->entrada(null, ['nome' => 'Bia']));
        self::assertSame([], $this->disco->lidas, 'a versão base não foi lida');
        self::assertSame(0, $this->validador->conferencias);
    }

    #[TestDox('o CPF do certificado é conferido com o CPF DIGITADO: sem CPF no certificado "sem_cpf"; CPF de outro "cpf_diferente", mesmo sem CPF cadastrado')]
    public function testCpfDoCertificado(): void
    {
        [$documento, $base] = $this->enviado([self::BIA, self::CARLA]);
        [$bia, $carla] = $documento->getSignatarios();
        $semCpf = OficinaDePdfAssinado::assinar(
            OficinaDePdfAssinado::acrescimoParaAssinar($base),
            OficinaDePdfAssinado::cmsDoOpenssl(OficinaDePdfAssinado::chave('ec'), OficinaDePdfAssinado::certificado(OficinaDePdfAssinado::chave('ec'), 'SEM DOCUMENTO')),
        );

        $this->assertRecusaGravada(ResultadoDoPdfAssinado::SEM_CPF, null, $bia, $semCpf);

        $this->gravados = [];
        $comOutroCpf = $this->entrada($this->upload('a.pdf', OficinaDePdfAssinado::pdfAssinadoComVinculo('ec', null, $base)), ['nome' => 'Carla Dias Lima', 'cpf' => OficinaDePdfAssinado::CPF_2]);
        $this->assertRecusaGravada(ResultadoDoPdfAssinado::CPF_DIFERENTE, null, $carla, $comOutroCpf);
        self::assertSame(0, $this->validador->conferencias);
    }

    #[TestDox('o formulário na ordem do desenho (dc:386-388): nome, CPF, CPF cadastrado, PDF, aceite; depois o arquivo (PDF de até 20 MB)')]
    public function testOrdemDoFormulario(): void
    {
        [$documento] = $this->enviado([self::BIA]);
        $bia = $documento->getSignatarios()[0];
        $tudoErrado = ['nome' => 'Bia', 'cpf' => '123', 'aceite' => '0'];
        $passos = [
            ['assinatura_eletronica.erro.assinar_nome', $tudoErrado, false],
            ['assinatura_eletronica.erro.assinar_cpf', ['nome' => 'Bia Souza'] + $tudoErrado, false],
            ['assinatura_eletronica.erro.assinar_cpf_cadastro', ['nome' => 'Bia Souza', 'cpf' => OficinaDePdfAssinado::CPF_2] + $tudoErrado, false],
            [ResultadoDoPdfAssinado::chave(ResultadoDoPdfAssinado::SEM_PDF), ['nome' => 'Bia Souza', 'cpf' => OficinaDePdfAssinado::CPF] + $tudoErrado, false],
            ['assinatura_eletronica.erro.assinar_aceite', ['nome' => 'Bia Souza', 'cpf' => OficinaDePdfAssinado::CPF] + $tudoErrado, true],
        ];
        foreach ($passos as [$chave, $campos, $comArquivo]) {
            $this->assertRecusaSemGravar($chave, $bia, EntradaDoCertificado::doFormulario($campos, $comArquivo ? $this->upload('a.pdf', 'só texto') : null));
        }

        $ilegivel = ResultadoDoPdfAssinado::chave(ResultadoDoPdfAssinado::ILEGIVEL);
        $this->assertRecusaSemGravar($ilegivel, $bia, $this->entrada($this->upload('falso.pdf', 'só texto')));
        $this->assertRecusaSemGravar($ilegivel, $bia, $this->entrada($this->upload('grande.pdf', "%PDF-1.7\n", LimitesDaConferencia::PDF_MAXIMO_EM_BYTES + 1)));
        self::assertSame([], $this->disco->lidas, 'o motor não rodou');
        self::assertSame(0, $this->validador->conferencias);
    }

    #[TestDox('dois signatários sem ordem: a versão de outro entrou depois da base conferida; com a linha travada, "prefixo" gravado e nada guardado')]
    public function testCorrida(): void
    {
        [$documento, $base] = $this->enviado([self::BIA, self::CARLA]);
        [$bia, $carla] = $documento->getSignatarios();
        $this->aoTravar = function () use ($carla): void {
            if (\count($this->versoesNaBase) === 1) {
                $this->versoesNaBase[] = VersaoDoPdf::devolvida($carla, 2, 'da-carla.pdf', 'da carla.pdf', self::SHA, 10, ['v' => 1], new \DateTimeImmutable('2026-11-09 09:59:00'));
            }
        };

        $this->assertRecusaGravada(ResultadoDoPdfAssinado::PREFIXO, RegraDaCadeia::PREFIXO_NAO_CONFERE, $bia, OficinaDePdfAssinado::pdfAssinadoComVinculo('ec', null, $base));
        self::assertSame(1, $this->validador->conferencias, 'a cadeia já tinha sido confirmada');
    }

    #[TestDox('signatário Simples: link inválido, sem ler o formulário, o arquivo nem chamar o validador')]
    public function testSimplesRecusado(): void
    {
        [$documento, $base] = $this->enviado([self::ANA, self::BIA]);

        $this->expectException(LinkInvalido::class);
        try {
            $this->useCase()->executar($this->chave->token($documento->getSignatarios()[0]), $this->entrada($this->upload('a.pdf', OficinaDePdfAssinado::pdfAssinadoComVinculo('ec', null, $base))), self::evidencia());
        } finally {
            self::assertSame([], $this->disco->lidas);
            self::assertSame(0, $this->validador->conferencias);
            self::assertSame([], $this->gravados);
        }
    }

    #[TestDox('fora da vez (em ordem, aguardando o anterior): a mesma recusa da Simples, antes de tudo')]
    public function testForaDaVez(): void
    {
        [$documento, $base] = $this->enviado([self::ANA, self::BIA], emOrdem: true);

        $this->assertRecusaSemGravar('assinatura_eletronica.publica.estado.aguardando_anterior', $documento->getSignatarios()[1], $this->entrada($this->upload('a.pdf', OficinaDePdfAssinado::pdfAssinadoComVinculo('ec', null, $base))));
        self::assertSame(0, $this->validador->conferencias);
    }

    private function useCase(): AssinarComCertificadoUseCase
    {
        $regra = new RegraDaCadeia();

        return new AssinarComCertificadoUseCase(
            $this->link(),
            $this->documentos,
            $this->versoes,
            $this->eventosDaTrilha,
            $this->validador,
            new ConferenciaDoPdfAssinado($this->versoes, $this->disco, new ConferenciaDePdf(), $regra),
            $regra,
            new ConferenciaDaCadeia($this->validador, $regra, $this->limitador, $this->logger),
            $this->trilha(),
            $this->disco,
            $this->correio(),
            $this->apresentacao(),
            $this->pagina(),
            new IdentityTranslator(),
            $this->relogio,
            $this->barramento(),
            $this->logger,
        );
    }

    /**
     * Documento enviado às 9h com os signatários dados (ids 11, 12...) e o original já assinado guardado no rascunho
     * como a versão base (ordem 1). Devolve o documento e os bytes da base.
     *
     * @param list<array<string, string>> $signatarios
     *
     * @return array{DocumentoParaAssinar, string}
     */
    private function enviado(array $signatarios, bool $emOrdem = false): array
    {
        $momento = new \DateTimeImmutable('2026-11-09 08:00:00');
        $documento = new DocumentoParaAssinar($this->tenant, self::usuario('criadora@escritorio.test', 'Carla Criadora'), $momento);
        $documento->aplicarRascunho(DadosDoRascunho::deJson(['nome' => 'Contrato de honorários', 'emOrdem' => $emOrdem, 'signatarios' => $signatarios]), [], $momento);
        (new \ReflectionProperty(DocumentoParaAssinar::class, 'id'))->setValue($documento, 1);
        foreach ($documento->getSignatarios() as $i => $signatario) {
            (new \ReflectionProperty(Signatario::class, 'id'))->setValue($signatario, 11 + $i);
        }
        $base = OficinaDePdfAssinado::pdfAssinado();
        $original = VersaoDoPdf::original($documento, 1, 'original.pdf', 'Contrato assinado.pdf', self::SHA, \strlen($base), ['v' => 1], $momento);
        $this->versoesNaBase[] = $original;
        $this->disco->semear(ChavesDeAssinaturaEletronica::versao($original), $base);
        $documento->enviar(str_repeat('f', 64), new \DateTimeImmutable('2026-11-09 09:00:00'));
        foreach ($documento->getSignatarios() as $signatario) {
            $signatario->definirTokenHash(ChaveDaAssinatura::hashDoToken($this->chave->token($signatario)));
        }
        $this->naBase[] = $documento;

        return [$documento, $base];
    }

    /** @param array<string, string> $troca */
    private function entrada(?UploadedFile $pdf, array $troca = []): EntradaDoCertificado
    {
        return EntradaDoCertificado::doFormulario($troca + ['nome' => 'Bia Souza Lima', 'cpf' => '529.982.247-25', 'aceite' => '1'], $pdf);
    }

    /** O arquivo enviado; com `$tamanho`, completado com zeros até ele (arquivo esparso, sem os bytes em memória). */
    private function upload(string $nome, string $conteudo, ?int $tamanho = null): UploadedFile
    {
        $caminho = (string) tempnam(sys_get_temp_dir(), 'assinado-');
        file_put_contents($caminho, $conteudo);
        if ($tamanho !== null) {
            $arquivo = fopen($caminho, 'r+b') ?: self::fail('não abriu o temporário');
            ftruncate($arquivo, $tamanho);
            fclose($arquivo);
            clearstatcache(true, $caminho);
        }

        return new UploadedFile($caminho, $nome, null, null, true);
    }

    /** Recusa definitiva: só "pdf_assinado_recusado" na trilha, com o motivo, a regra e o sha256; nada guardado. */
    private function assertRecusaGravada(string $erro, ?string $regra, Signatario $signatario, string|EntradaDoCertificado $pdf): void
    {
        $entrada = $pdf instanceof EntradaDoCertificado ? $pdf : $this->entrada($this->upload('a.pdf', $pdf));
        $sha256 = hash('sha256', (string) $entrada->pdf?->getContent());

        $this->assertRecusa(ResultadoDoPdfAssinado::chave($erro), $signatario, $entrada);

        self::assertSame(['pdf_assinado_recusado'], $this->tiposGravados());
        $evento = $this->gravados[0];
        self::assertSame(['motivo' => $erro, 'regra' => $regra, 'sha256' => $sha256, 'chave' => 1, 'porta' => 51234], $evento->getDados());
        self::assertSame($signatario, $evento->getSignatario());
        self::assertSame('203.0.113.7', $evento->getIp());
    }

    /** Recusa sem gravar nada: nem evento na trilha. */
    private function assertRecusaSemGravar(string $chave, Signatario $signatario, EntradaDoCertificado $entrada): void
    {
        $this->assertRecusa($chave, $signatario, $entrada);
        self::assertSame([], $this->gravados, 'nenhum evento na trilha');
    }

    private function assertRecusa(string $chave, Signatario $signatario, EntradaDoCertificado $entrada): void
    {
        try {
            $this->useCase()->executar($this->chave->token($signatario), $entrada, self::evidencia());
            self::fail('esperava a recusa ' . $chave);
        } catch (AcaoRecusada $e) {
            self::assertSame($chave, $e->getMessage());
        }
        self::assertSame([], $this->salvas, 'nenhuma versão guardada');
        self::assertSame([], $this->disco->gravadas, 'nenhum arquivo gravado');
        self::assertTrue($signatario->estaPendente());
        self::assertSame([], $this->mailer->enviados);
    }
}
