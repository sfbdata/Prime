<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Support;

use App\AssinaturaEletronica\Armazenamento\ChavesDeAssinaturaEletronica;
use App\AssinaturaEletronica\DTO\DadosDoRascunho;
use App\AssinaturaEletronica\Entity\DocumentoParaAssinar;
use App\AssinaturaEletronica\Entity\Signatario;
use App\AssinaturaEletronica\Enum\SituacaoDoDocumento;
use App\AssinaturaEletronica\Service\ArquivoAssinadoNaPasta;
use App\AssinaturaEletronica\Service\PdfFinalDoDocumento;
use App\AssinaturaEletronica\Service\VinculoComAPasta;
use App\AssinaturaEletronica\UseCase\VincularAPastaUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Armazenamento\GeradorDeCodigoDeDocumento;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Entity\PastaMensagem;
use App\Pasta\Repository\ArquivoArmazenadoRepository;
use App\Pasta\Repository\PastaRepository;
use App\Pasta\Service\ContadorDePaginasDePdf;
use App\Pasta\Service\RegistroDoSistemaNaPasta;
use App\Pasta\UseCase\AlterarSituacaoContratoUseCase;
use App\Pasta\UseCase\MarcarContratoAssinadoPeloArquivoUseCase;
use App\Service\PermissionChecker;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\FonteDeConteudo;
use App\Shared\Armazenamento\RemocaoAposTransacao;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\Translation\IdentityTranslator;

/**
 * O vínculo à pasta nos testes de unidade (spec assinatura-documentos-etapa-3, E3-10 a E3-12), por cima da
 * {@see OficinaDosUseCasesDaAssinatura}: pastas do escritório, a permissão de cada pasta (ver e editar, o cadeado
 * incluído, como o `canAccessResource`), o PDF final dublado no disco em memória, o contador de páginas dublado e o que
 * foi para a pasta. O pedido do PDF final ao worker vai para o barramento da oficina (`$despachadas`).
 *
 * O EntityManager só entra pelos serviços finais da Pasta (`RegistroDoSistemaNaPasta` e o do contrato), como nos testes
 * deles; o domínio da assinatura grava pelo repositório dublado (`guardarNaPasta`).
 */
trait OficinaDoVinculo
{
    use OficinaDosUseCasesDaAssinatura;

    private const PDF_FINAL = "%PDF-1.4\n% PDF final com selo\n%%EOF\n";

    private PermissionChecker&MockObject $permissoes;
    private PastaRepository&MockObject $repositorioDePastas;

    /** @var array<int, Pasta> */
    private array $pastasNaBase = [];

    /** @var array<int, array{ver: bool, editar: bool}> por id da pasta */
    private array $acesso = [];

    /** @var list<PastaDocumento> o que o vínculo pôs na pasta */
    private array $naPasta = [];

    /** @var list<PastaMensagem> o histórico escrito nas pastas */
    private array $historico = [];

    private int $pdfsGarantidos = 0;

    private bool $pdfFalha = false;

    /** O que o contador de páginas dublado devolve (nulo: não conseguiu contar). */
    private ?int $paginasDoPdf = 2;

    /** @var list<string> o conteúdo de cada PDF que o contador de páginas leu do caminho local */
    private array $pdfsContados = [];

    /** Chamado quando o PDF final dublado é gerado e guardado (o evento `PdfFinalGuardado` do gerador real). */
    private ?\Closure $aoGarantir = null;
    private ?VinculoComAPasta $vinculoAtual = null;

    private function montarVinculo(): void
    {
        $this->montarOficina();
        $this->permissoes = $this->createMock(PermissionChecker::class);
        $this->permissoes->method('canAccessResource')->willReturnCallback(
            fn (User $u, ?Tenant $t, string $tipo, int $id, string $acao): bool => $tipo === 'pasta' && ($this->acesso[$id][$acao === 'view' ? 'ver' : 'editar'] ?? false),
        );
        $this->repositorioDePastas = $this->createMock(PastaRepository::class);
        $this->repositorioDePastas->method('findOneBy')->willReturnCallback(function (array $criterio): ?Pasta {
            $pasta = $this->pastasNaBase[$criterio['id']] ?? null;

            return $pasta !== null && $pasta->getTenant() === $criterio['tenant'] ? $pasta : null;
        });
        $this->documentos->method('guardarNaPasta')->willReturnCallback(function (PastaDocumento $arquivo): void {
            $this->naPasta[] = $arquivo;
        });
    }

    private function useCase(): VincularAPastaUseCase
    {
        return new VincularAPastaUseCase(
            $this->documentos,
            $this->repositorioDePastas,
            $this->permissoes,
            $this->eventosDaTrilha,
            $this->trilha(),
            $this->vinculo(),
            $this->apresentacao(),
            $this->relogio,
            $this->logger,
        );
    }

    private function vinculo(): VinculoComAPasta
    {
        return $this->vinculoAtual ??= new VinculoComAPasta(
            $this->documentos,
            $this->eventosDaTrilha,
            $this->trilha(),
            $this->permissoes,
            $this->pdfFinal(),
            $this->barramento(),
            $this->arquivosNaPasta(),
            new IdentityTranslator(),
            $this->logger,
        );
    }

    private function arquivosNaPasta(): ArquivoAssinadoNaPasta
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function (object $o): void {
            if ($o instanceof PastaMensagem) {
                $this->historico[] = $o;
            }
        });
        $codigos = $this->createStub(ArquivoArmazenadoRepository::class);
        $codigos->method('existeCodigo')->willReturn(false);

        return new ArquivoAssinadoNaPasta(
            $this->disco,
            new GeradorDeCodigoDeDocumento($codigos),
            new RemocaoAposTransacao($this->disco, $this->logger),
            new RegistroDoSistemaNaPasta($em),
            new MarcarContratoAssinadoPeloArquivoUseCase(new AlterarSituacaoContratoUseCase($em)),
            $this->documentos,
            new IdentityTranslator(),
            $this->relogio,
            $this->logger,
            $this->contadorDePaginas(),
        );
    }

    /** O contador de páginas dublado: lê o caminho local que recebe (como o Ghostscript) e devolve `$paginasDoPdf`. */
    private function contadorDePaginas(): ContadorDePaginasDePdf
    {
        return new class (function (string $caminho): ?int {
            $this->pdfsContados[] = (string) file_get_contents($caminho);

            return $this->paginasDoPdf;
        }) implements ContadorDePaginasDePdf {
            public function __construct(private readonly \Closure $contar)
            {
            }

            public function contar(string $caminhoLocal): ?int
            {
                return ($this->contar)($caminhoLocal);
            }
        };
    }

    /** O PDF final dublado, com o contrato do {@see \App\AssinaturaEletronica\Service\PdfFinalComSelo}. */
    private function pdfFinal(): PdfFinalDoDocumento
    {
        return new class (fn (DocumentoParaAssinar $d): ChaveDeArquivo => $this->garantirPdf($d)) implements PdfFinalDoDocumento {
            public function __construct(private readonly \Closure $garantir)
            {
            }

            public function garantir(DocumentoParaAssinar $doc): ChaveDeArquivo
            {
                return ($this->garantir)($doc);
            }
        };
    }

    /**
     * Como o gerador real: o guardado volta como está; senão grava no disco em memória, guarda a chave no documento e só
     * então avisa (`$aoGarantir`, o evento depois do COMMIT).
     */
    private function garantirPdf(DocumentoParaAssinar $doc): ChaveDeArquivo
    {
        ++$this->pdfsGarantidos;
        if ($this->pdfFalha) {
            throw new \LogicException('O PDF final com selo ainda não é gerado.');
        }
        $guardada = ChavesDeAssinaturaEletronica::pdfFinal($doc);
        if ($guardada !== null) {
            return $guardada;
        }
        $nova = $this->guardarPdfFinal($doc);
        if ($this->aoGarantir !== null) {
            ($this->aoGarantir)($doc);
        }

        return $nova;
    }

    /** O PDF final já guardado (pelo worker ou pelo primeiro download): no disco em memória e com a chave no documento. */
    private function guardarPdfFinal(DocumentoParaAssinar $doc): ChaveDeArquivo
    {
        $chave = $this->disco->gravar(ChavesDeAssinaturaEletronica::novoPdfFinal($doc), FonteDeConteudo::deTexto(self::PDF_FINAL))->chave;
        $doc->guardarArquivoFinal($chave->nome, hash('sha256', self::PDF_FINAL));

        return $chave;
    }

    private function pasta(int $id = 42, string $nup = 'P-0042', string $cliente = 'MARIA DA SILVA', bool $ver = true, bool $editar = true): Pasta
    {
        $pasta = new Pasta();
        $pasta->setTenant($this->tenant);
        $pasta->setNup($nup);
        $pasta->setNomeCliente($cliente);
        (new \ReflectionProperty(Pasta::class, 'id'))->setValue($pasta, $id);
        $this->pastasNaBase[$id] = $pasta;
        $this->acesso[$id] = ['ver' => $ver, 'editar' => $editar];

        return $pasta;
    }

    private static function ator(): User
    {
        $ator = self::usuario('advogada@escritorio.test', 'Ana Advogada');
        (new \ReflectionProperty(User::class, 'id'))->setValue($ator, 7);

        return $ator;
    }

    /**
     * Documento concluído (os dois signatários assinaram), com o modelo dado e o PDF final já guardado pelo worker (sem
     * ele quando `$comPdf` é falso: o worker ainda não guardou).
     */
    private function concluido(?string $modelo = null, bool $comPdf = true): DocumentoParaAssinar
    {
        $documento = $modelo === null ? $this->documentoEnviado() : $this->enviadoDoModelo($modelo);
        foreach ($documento->getSignatarios() as $s) {
            $documento->assinar($s, $s->getNome(), self::CPF_ANA, 'rubrica.png', 'simples', null, null, new \DateTimeImmutable('2026-11-09 09:30:00'));
        }
        self::assertSame(SituacaoDoDocumento::Concluido, $documento->getSituacao());
        if ($comPdf) {
            $this->guardarPdfFinal($documento);
        }

        return $documento;
    }

    private function enviadoDoModelo(string $modelo): DocumentoParaAssinar
    {
        $momento = new \DateTimeImmutable('2026-11-09 08:00:00');
        $documento = new DocumentoParaAssinar($this->tenant, self::usuario('criadora@escritorio.test', 'Carla Criadora'), $momento);
        $documento->aplicarRascunho(DadosDoRascunho::deJson([
            'nome' => 'Contrato de honorários',
            'modelo' => $modelo,
            'signatarios' => [['nome' => 'Ana Lima', 'email' => 'ana@exemplo.com', 'cpf' => self::CPF_ANA]],
        ]), [], $momento);
        (new \ReflectionProperty(DocumentoParaAssinar::class, 'id'))->setValue($documento, 1);
        foreach ($documento->getSignatarios() as $signatario) {
            (new \ReflectionProperty(Signatario::class, 'id'))->setValue($signatario, 11);
        }
        $this->naBase[] = $documento;
        $documento->enviar(str_repeat('f', 64), new \DateTimeImmutable('2026-11-09 09:00:00'));

        return $documento;
    }

    /** @return list<string> os textos do histórico das pastas */
    private function textosDoHistorico(): array
    {
        return array_map(static fn (PastaMensagem $m): string => $m->getSistema() . ': ' . strip_tags($m->getConteudo()), $this->historico);
    }
}
