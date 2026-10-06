<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Armazenamento\ChavesDePasta;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Entity\PastaSecao;
use App\Pasta\Exception\SelecaoAcimaDoTetoException;
use App\Pasta\Repository\PastaDocumentoRepository;
use App\Pasta\Service\NomesDeEntradaDoZip;
use App\Pasta\Service\SelecaoDeItensDaPasta;
use App\Shared\Armazenamento\ArmazenamentoDeArquivos;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\FonteDeConteudo;
use App\Shared\Doctrine\Transacao\TransacaoComArquivoNovo;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Copia documentos da pasta para uma subpasta dela (ou para a raiz, `$destino = null`) — o
 * Ctrl+C/Ctrl+V e o "Colar" do explorador (D6, DOC-56). Só arquivos: o desenho não copia pastas.
 *
 * Quem dispara é um usuário com permissão de EDITAR a pasta; a rota provou a posse de todos os
 * ids (404 sem efeito parcial) e aqui cada um é reconferido ({@see SelecaoDeItensDaPasta}).
 *
 * Cada cópia é uma LINHA NOVA com um ARQUIVO NOVO:
 *  - nome `nome (cópia).ext`, depois `nome (cópia 2).ext`… único (sem distinguir caixa) entre os
 *    documentos vivos do destino e as cópias desta mesma ação; copiar uma cópia não empilha
 *    sufixo (`x (cópia).pdf` → `x (cópia 2).pdf`);
 *  - mesmo `sha256`, `mime`, `paginas`, `categoria`, `descricao` e `numero` do original;
 *    `tamanho_bytes` é o que o storage MEDIU ao gravar (D30 — igual ao original quando o
 *    armazenamento está íntegro);
 *  - `driveFileId = null`: para o reconciliador a cópia é documento novo, e sobe como tal;
 *  - `enviadoPor` = quem copiou; `carregadoEm` = agora; `modificadoEm` = NULL;
 *  - os bytes saem de `abrir(origem)` e entram por `gravar(ChavesDePasta::novoDocumento)` em
 *    streaming — nada do arquivo passa pela memória inteiro (INV-4).
 *
 * Tudo roda DENTRO de {@see TransacaoComArquivoNovo}: gravação e `persist` no trabalho, `flush` e
 * COMMIT pela transação. Qualquer falha antes do COMMIT (o storage no terceiro arquivo, o banco
 * recusando a linha) apaga os arquivos novos que chegaram a ser gravados — a lista é lida por
 * referência, então só tem o que existe; COMMIT de destino incerto preserva (INV-6).
 *
 * Tetos: {@see TETO_DE_DOCUMENTOS} (o mesmo do lote) e {@see TETO_DE_BYTES} (o do .zip), pela
 * soma de `tamanho_bytes`, antes de abrir qualquer arquivo. Auditoria: `PastaDocumento` é
 * `Auditavel` — cada cópia vira `create` pelo `AuditLogSubscriber`, nada a fazer aqui.
 */
final class CopiarDocumentosDaPastaUseCase
{
    /** Documentos por ação (o teto de itens do lote). */
    public const TETO_DE_DOCUMENTOS = 2000;

    /** Soma de `tamanho_bytes` por ação: o mesmo do .zip (S-11). */
    public const TETO_DE_BYTES = MontarZipDeDocumentosUseCase::TETO_DE_BYTES;

    /** O sufixo que esta classe põe — e que não empilha ao copiar uma cópia. */
    private const SUFIXO_DE_COPIA = '/ \(cópia(?: \d+)?\)$/u';

    /** `nome_original` e `titulo` são `VARCHAR(255)`. */
    private const TAMANHO_MAXIMO_DO_NOME = 255;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ArmazenamentoDeArquivos $armazenamento,
        private readonly TransacaoComArquivoNovo $transacao,
        private readonly PastaDocumentoRepository $documentoRepository,
    ) {
    }

    /**
     * @param list<PastaDocumento> $documentos
     *
     * @throws SelecaoAcimaDoTetoException acima de um dos tetos (nada foi aberto)
     * @throws \InvalidArgumentException   seleção vazia, ou item/destino de outra pasta
     * @throws AccessDeniedException       item/destino de outro escritório
     */
    public function executar(Pasta $pasta, array $documentos, ?PastaSecao $destino, User $autor, Tenant $tenant): ResultadoCopiarDocumentosDaPasta
    {
        if ($destino !== null) {
            if ($destino->getTenant() !== $tenant) {
                throw new AccessDeniedException('Pasta de destino não pertence ao tenant do usuário.');
            }
            if ($destino->getPasta() !== $pasta) {
                throw new \InvalidArgumentException('A pasta de destino não pertence a esta pasta.');
            }
        }

        $selecao = SelecaoDeItensDaPasta::de($documentos, [], $pasta, $tenant);

        if (count($selecao->documentos) > self::TETO_DE_DOCUMENTOS) {
            throw SelecaoAcimaDoTetoException::porArquivos(count($selecao->documentos), self::TETO_DE_DOCUMENTOS);
        }
        $bytes = array_sum(array_map(static fn (PastaDocumento $d): int => $d->getTamanhoBytes(), $selecao->documentos));
        if ($bytes > self::TETO_DE_BYTES) {
            throw SelecaoAcimaDoTetoException::porBytes($bytes, self::TETO_DE_BYTES);
        }

        $nomes = $this->nomesParaAsCopias($pasta, $tenant, $destino, $selecao->documentos);

        /** @var list<ChaveDeArquivo> $chavesNovas preenchida pelo trabalho; lida por referência na falha */
        $chavesNovas = [];

        $copias = $this->transacao->executar(
            function () use ($pasta, $destino, $autor, $tenant, $selecao, $nomes, &$chavesNovas): array {
                $copias = [];
                foreach ($selecao->documentos as $i => $origem) {
                    $copia = $this->copiar($origem, $nomes[$i], $pasta, $destino, $autor, $tenant, $chavesNovas);
                    $this->em->persist($copia);
                    $copias[] = $copia;
                }

                return $copias;
            },
            function () use (&$chavesNovas): array {
                return $chavesNovas;
            },
            'CopiarDocumentosDaPastaUseCase: cópias de documentos da pasta',
        );

        return new ResultadoCopiarDocumentosDaPasta($copias);
    }

    /** @param list<ChaveDeArquivo> $chavesNovas */
    private function copiar(PastaDocumento $origem, string $nome, Pasta $pasta, ?PastaSecao $destino, User $autor, Tenant $tenant, array &$chavesNovas): PastaDocumento
    {
        // O escopo sai da própria cópia (R1), com o escritório atribuído ANTES da gravação.
        $copia = new PastaDocumento();
        $copia->setTenant($tenant);

        $recurso = $this->armazenamento->abrir(ChavesDePasta::documento($origem));
        try {
            $armazenado = $this->armazenamento->gravar(
                ChavesDePasta::novoDocumento($copia, self::extensaoDe($origem)),
                FonteDeConteudo::deStream($recurso),
            );
        } finally {
            fclose($recurso);
        }
        $chavesNovas[] = $armazenado->chave;

        $copia->setPasta($pasta);
        $copia->setSecao($destino);
        $copia->setTitulo($nome);
        $copia->setNomeOriginal($nome);
        $copia->setCategoria($origem->getCategoria());
        $copia->setDescricao($origem->getDescricao());
        $copia->setNumero($origem->getNumero());
        $copia->setCaminhoArquivo($armazenado->chave->nome);
        $copia->setMimeType($origem->getMimeType());
        $copia->setTamanhoBytes($armazenado->tamanhoBytes);
        $copia->setSha256($origem->getSha256());
        $copia->setPaginas($origem->getPaginas());
        $copia->setEnviadoPor($autor);
        $copia->setDriveFileId(null);

        return $copia;
    }

    /**
     * Os nomes das cópias, na ordem da seleção, únicos entre os documentos VIVOS do destino (uma
     * consulta, filtrada em memória — o `LixeiraFilter` já deixou a lixeira de fora) e entre si.
     *
     * @param list<PastaDocumento> $origens
     *
     * @return list<string>
     */
    private function nomesParaAsCopias(Pasta $pasta, Tenant $tenant, ?PastaSecao $destino, array $origens): array
    {
        $destinoId = $destino?->getId();
        $tomados   = [];
        foreach ($this->documentoRepository->findByPastaComSecao($pasta, $tenant) as $existente) {
            if ($existente->getSecao()?->getId() === $destinoId) {
                $tomados[mb_strtolower($existente->getNomeOriginal())] = true;
            }
        }

        $nomes = [];
        foreach ($origens as $origem) {
            [$tronco, $extensao] = NomesDeEntradaDoZip::separarExtensao($origem->getNomeOriginal());
            $tronco              = (string) preg_replace(self::SUFIXO_DE_COPIA, '', $tronco);

            $n = 1;
            do {
                $candidato = self::nomeDaCopia($tronco, $extensao, $n++);
            } while (isset($tomados[mb_strtolower($candidato)]));

            $tomados[mb_strtolower($candidato)] = true;
            $nomes[]                            = $candidato;
        }

        return $nomes;
    }

    /** `tronco (cópia).ext`, `tronco (cópia 2).ext`… cabendo na coluna: o tronco encurta, o sufixo não. */
    private static function nomeDaCopia(string $tronco, string $extensao, int $n): string
    {
        $sufixo = $n === 1 ? ' (cópia)' : sprintf(' (cópia %d)', $n);
        $nome   = $tronco . $sufixo . $extensao;

        while (strlen($nome) > self::TAMANHO_MAXIMO_DO_NOME && mb_strlen($tronco) > 1) {
            $tronco = mb_substr($tronco, 0, mb_strlen($tronco) - 1);
            $nome   = $tronco . $sufixo . $extensao;
        }

        return $nome;
    }

    /** A extensão da chave nova: a do arquivo armazenado; sem ela, a do nome; `NovoArquivo` saneia o resto. */
    private static function extensaoDe(PastaDocumento $origem): string
    {
        $extensao = pathinfo($origem->getCaminhoArquivo(), PATHINFO_EXTENSION);
        if ($extensao === '') {
            $extensao = pathinfo($origem->getNomeOriginal(), PATHINFO_EXTENSION);
        }

        return $extensao;
    }
}
