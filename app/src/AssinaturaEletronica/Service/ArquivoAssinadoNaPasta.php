<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Service;

use App\AssinaturaEletronica\Entity\DocumentoParaAssinar;
use App\AssinaturaEletronica\Repository\DocumentoParaAssinarRepository;
use App\Entity\Auth\User;
use App\Pasta\Armazenamento\ChavesDePasta;
use App\Pasta\Armazenamento\GeradorDeCodigoDeDocumento;
use App\Pasta\Entity\ArquivoArmazenado;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Service\AvisoDePastaAcompanhada;
use App\Pasta\Service\ContadorDePaginasDePdf;
use App\Pasta\Service\RegistroDoSistemaNaPasta;
use App\Pasta\UseCase\MarcarContratoAssinadoPeloArquivoUseCase;
use App\Shared\Armazenamento\AreaTemporariaPrivada;
use App\Shared\Armazenamento\ArmazenamentoDeArquivos;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\FonteDeConteudo;
use App\Shared\Armazenamento\RemocaoAposTransacao;
use App\Shared\Armazenamento\Sha256DeArquivo;
use App\Sync\Service\SincronizacaoPastaDispatcher;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * O PDF final com selo virando documento da pasta (spec assinatura-documentos-etapa-3, E3-12), nos passos do upload
 * comum da pasta (`UploadPecaUseCase`): cópia por chave para uma chave nova da pasta, código DOC da Central, número de
 * páginas, registro no histórico da pasta, contrato marcado quando couber, aviso de pasta acompanhada e sincronização com
 * o Drive.
 *
 * Quem decide se o vínculo pode ser aplicado e abre a transação é o {@see VinculoComAPasta}; aqui só o arquivo e o que
 * a pasta registra dele. A cópia é feita FORA da transação (uma falha do storage não fecha o EntityManager).
 */
final readonly class ArquivoAssinadoNaPasta
{
    public const CONTEXTO = 'vínculo da assinatura de documentos à pasta';

    public function __construct(
        private ArmazenamentoDeArquivos $armazenamento,
        private GeradorDeCodigoDeDocumento $codigos,
        private RemocaoAposTransacao $remocao,
        private RegistroDoSistemaNaPasta $registro,
        private MarcarContratoAssinadoPeloArquivoUseCase $contrato,
        private DocumentoParaAssinarRepository $documentos,
        private TranslatorInterface $tradutor,
        private ClockInterface $relogio,
        private LoggerInterface $logger,
        private ContadorDePaginasDePdf $contadorDePaginas,
        private ?AvisoDePastaAcompanhada $aviso = null,
        private ?SincronizacaoPastaDispatcher $drive = null,
    ) {
    }

    /**
     * Copia o PDF final para uma chave nova da pasta e monta o documento da pasta, ainda não persistido. O código DOC
     * sai antes da gravação: se ele falhar, não sobra arquivo.
     */
    public function copiar(DocumentoParaAssinar $documento, Pasta $pasta, User $autor, ChaveDeArquivo $pdfFinal): PastaDocumento
    {
        $tenant = $documento->getTenant();
        $arquivo = (new PastaDocumento())->setTenant($tenant);
        $codigo = $this->codigos->gerar((int) $tenant->getId());

        $origem = $this->armazenamento->abrir($pdfFinal);
        try {
            $gravado = $this->armazenamento->gravar(ChavesDePasta::novoDocumento($arquivo, 'pdf'), FonteDeConteudo::deStream($origem));
        } finally {
            if (\is_resource($origem)) {
                fclose($origem);
            }
        }

        $nome = self::nomeDoArquivo($documento->getNome());
        $sha256 = $documento->getHashFinal() ?? Sha256DeArquivo::deChaveOuNulo($this->armazenamento, $gravado->chave, $this->logger);
        $arquivo->setPasta($pasta)
            ->setTitulo($nome)
            ->setNomeOriginal($nome)
            ->setCategoria(self::categoria($documento->getModeloChave()))
            ->setCaminhoArquivo($gravado->chave->nome)
            ->setMimeType('application/pdf')
            ->setTamanhoBytes($gravado->tamanhoBytes)
            ->setSha256($sha256)
            ->setPaginas($this->paginas($gravado->chave))
            ->setEnviadoPor($autor)
            ->setArquivo(new ArquivoArmazenado($tenant, $codigo, $gravado->chave->nome, $gravado->tamanhoBytes, $sha256));
        $arquivo->marcarAssinadoEletronicamente(\DateTimeImmutable::createFromInterface($this->relogio->now()), $autor);

        return $arquivo;
    }

    /** Dentro da transação: persiste o documento da pasta e escreve no histórico dela. */
    public function guardar(PastaDocumento $arquivo, DocumentoParaAssinar $documento, User $autor): void
    {
        $this->documentos->guardarNaPasta($arquivo);
        $this->registro->registrar($arquivo->getPasta() ?? throw new \LogicException('Documento da pasta sem pasta.'), $autor, 'assinatura', $this->tradutor->trans(
            'assinatura_eletronica_tela.js.vinculo.historico',
            ['nome' => $documento->getNome(), 'codigo' => (string) $documento->getCodigo()],
        ));
    }

    /**
     * Depois do COMMIT, como o upload do Financeiro (`PastaController::financeiroUpload`): contrato marcado quando
     * couber, aviso de quem acompanha e Drive. Nenhum deles desfaz o arquivo já salvo: falha vira registro no log.
     */
    public function depoisDeGuardar(PastaDocumento $arquivo, DocumentoParaAssinar $documento, User $autor): void
    {
        $pasta = $arquivo->getPasta();
        if ($pasta === null) {
            return;
        }
        $tenant = $documento->getTenant();
        try {
            if ($arquivo->getCategoria() === PastaDocumento::CATEGORIA_CONTRATO) {
                $this->contrato->executar($pasta, $arquivo, $autor, $tenant);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Vínculo da assinatura: contrato da pasta {pasta} não marcado: {erro}', ['pasta' => $pasta->getId(), 'erro' => $e->getMessage()]);
        }
        $this->aviso?->aoIncluirDocumento($arquivo, $pasta, $tenant, $autor);
        $this->drive?->despachar($pasta, $autor, $tenant);
    }

    /** O arquivo copiado que não chegou a ser usado (o vínculo foi aplicado por outra chamada no meio). */
    public function descartar(PastaDocumento $arquivo): void
    {
        $chave = $this->chaveDe($arquivo);
        if ($chave !== null) {
            $this->remocao->remover([$chave], self::CONTEXTO . ': cópia sem uso');
        }
    }

    /** @return list<ChaveDeArquivo> a chave nova, para a transação apagar se nada for confirmado */
    public function chavesNovas(PastaDocumento $arquivo): array
    {
        $chave = $this->chaveDe($arquivo);

        return $chave === null ? [] : [$chave];
    }

    /**
     * `assinado_{nome}.pdf` do desenho (vincExec): sem acento, só letras, números, sublinhado e espaço, espaços viram
     * sublinhado, até 60 caracteres.
     */
    public static function nomeDoArquivo(string $nome): string
    {
        $semAcento = \Normalizer::normalize($nome, \Normalizer::FORM_D);
        $limpo = trim((string) preg_replace('/[^A-Za-z0-9_ ]/', '', $semAcento === false ? $nome : $semAcento));

        return 'assinado_' . substr((string) preg_replace('/\s+/', '_', $limpo), 0, 60) . '.pdf';
    }

    /** E3-12: a categoria do documento da pasta pelo modelo do documento assinado. */
    public static function categoria(?string $modelo): string
    {
        $modelo ??= '';

        return match (true) {
            str_starts_with($modelo, 'proc-'), $modelo === 'subst' => PastaDocumento::CATEGORIA_PROCURACAO,
            $modelo === 'contrato' => PastaDocumento::CATEGORIA_CONTRATO,
            $modelo === 'hipo' => PastaDocumento::CATEGORIA_GRATUIDADE_JUSTICA,
            $modelo === 'resid' => PastaDocumento::CATEGORIA_COMPROVANTE_RESIDENCIA,
            default => PastaDocumento::CATEGORIA_DEMAIS,
        };
    }

    /**
     * As páginas da cópia, pelo mesmo contador do upload comum (`UploadPecaUseCase`). O contador lê de um caminho local:
     * a cópia é materializada pela chave numa área temporária privada, apagada no fim. Como no upload, a contagem é
     * opcional: falha vira nulo com aviso no log, e o `app:documentos:calcular-hash --paginas` preenche depois.
     */
    private function paginas(ChaveDeArquivo $chave): ?int
    {
        $area = null;
        try {
            $area = AreaTemporariaPrivada::criar('vinculoassinatura');

            return $this->contadorDePaginas->contar($area->gravar($this->armazenamento->ler($chave), 'pdf'));
        } catch (\Exception $e) {
            $this->logger->warning('Vínculo da assinatura: páginas da cópia não contadas; o documento fica sem contagem até o app:documentos:calcular-hash --paginas.', [
                'chave' => $chave->comoTexto(),
                'erro' => $e->getMessage(),
            ]);

            return null;
        } finally {
            $area?->liberar();
        }
    }

    private function chaveDe(PastaDocumento $arquivo): ?ChaveDeArquivo
    {
        return $arquivo->getTenant() === null || $arquivo->getCaminhoArquivo() === '' ? null : ChavesDePasta::documento($arquivo);
    }
}
