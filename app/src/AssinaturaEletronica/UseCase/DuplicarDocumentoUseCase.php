<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\UseCase;

use App\AssinaturaEletronica\Armazenamento\ChavesDeAssinaturaEletronica;
use App\AssinaturaEletronica\Entity\DocumentoParaAssinar;
use App\AssinaturaEletronica\Enum\TipoDeEvento;
use App\AssinaturaEletronica\Exception\DocumentoNaoEncontrado;
use App\AssinaturaEletronica\Repository\DocumentoParaAssinarRepository;
use App\AssinaturaEletronica\Service\CopiaDeArquivo;
use App\AssinaturaEletronica\Service\TrilhaDoDocumento;
use App\AssinaturaEletronica\Service\TrocaDeArquivos;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Shared\Doctrine\Transacao\TransacaoComArquivoNovo;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "Duplicar" (desenho): rascunho novo "<nome> (cópia)" com a pasta, a cor, o conteúdo, a logo, o PDF e os
 * signatários (pendentes) do original, sem vínculo, e um único evento "Duplicado de <nome>". Os arquivos são copiados
 * para chaves novas: apagar um documento nunca apaga o arquivo do outro.
 */
final readonly class DuplicarDocumentoUseCase
{
    public function __construct(
        private DocumentoParaAssinarRepository $documentos,
        private CopiaDeArquivo $copia,
        private TrilhaDoDocumento $trilha,
        private TransacaoComArquivoNovo $transacao,
        private TranslatorInterface $tradutor,
        private ClockInterface $relogio,
    ) {
    }

    /**
     * @return int o id da cópia
     *
     * @throws DocumentoNaoEncontrado
     */
    public function executar(User $ator, Tenant $tenant, int $id): int
    {
        $origem = $this->documentos->doEscritorioMesmoNaLixeira($id, $tenant) ?? throw new DocumentoNaoEncontrado();
        $agora = \DateTimeImmutable::createFromInterface($this->relogio->now());
        $copia = DocumentoParaAssinar::copiaDe($origem, $this->nomeDaCopia($origem->getNome()), $ator, $agora);
        $troca = new TrocaDeArquivos();

        return $this->transacao->executar(
            function () use ($ator, $origem, $copia, $troca): int {
                $this->copiarArquivos($origem, $copia, $troca);
                $this->documentos->salvar($copia);
                $this->trilha->acrescentar($copia, TipoDeEvento::Duplicado, ['nome' => $origem->getNome()], $ator);

                return (int) $copia->getId();
            },
            static fn (): array => $troca->novas(),
            'assinatura_eletronica_duplicar',
        );
    }

    private function copiarArquivos(DocumentoParaAssinar $origem, DocumentoParaAssinar $copia, TrocaDeArquivos $troca): void
    {
        $pdf = ChavesDeAssinaturaEletronica::arquivo($origem);
        if ($pdf !== null) {
            $chave = $this->copia->copiar($pdf, static fn (string $extensao) => ChavesDeAssinaturaEletronica::novoArquivo($copia, $extensao));
            $troca->gravada($chave);
            $copia->definirArquivo($chave->nome, (string) $origem->getArquivoEnviadoNome(), $origem->getArquivoEnviadoPaginas());
        }

        $logo = ChavesDeAssinaturaEletronica::logo($origem);
        if ($logo !== null) {
            $chave = $this->copia->copiar($logo, static fn (string $extensao) => ChavesDeAssinaturaEletronica::novaLogo($copia, $extensao));
            $troca->gravada($chave);
            $copia->definirLogo($origem->getLogoTipo(), $chave->nome);
        }
    }

    /** Cabe nos 200 caracteres do nome: o original encolhe, o sufixo fica. */
    private function nomeDaCopia(string $nome): string
    {
        $copia = $this->tradutor->trans('assinatura_eletronica.nome_da_copia', ['nome' => $nome]);
        $excesso = mb_strlen($copia) - DocumentoParaAssinar::NOME_MAXIMO;
        if ($excesso <= 0) {
            return $copia;
        }

        return $this->tradutor->trans('assinatura_eletronica.nome_da_copia', ['nome' => mb_substr($nome, 0, mb_strlen($nome) - $excesso)]);
    }
}
