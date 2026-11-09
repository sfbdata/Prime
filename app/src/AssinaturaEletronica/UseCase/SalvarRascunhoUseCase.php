<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\UseCase;

use App\AssinaturaEletronica\DTO\EntradaDoRascunho;
use App\AssinaturaEletronica\Entity\ConfiguracaoDaAssinatura;
use App\AssinaturaEletronica\Entity\DocumentoParaAssinar;
use App\AssinaturaEletronica\Enum\TipoDeEvento;
use App\AssinaturaEletronica\Exception\AcaoRecusada;
use App\AssinaturaEletronica\Exception\DocumentoNaoEncontrado;
use App\AssinaturaEletronica\Repository\ConfiguracaoDaAssinaturaRepository;
use App\AssinaturaEletronica\Repository\DocumentoParaAssinarRepository;
use App\AssinaturaEletronica\Service\ArquivosDoRascunho;
use App\AssinaturaEletronica\Service\ConferenciaDeUpload;
use App\AssinaturaEletronica\Service\TrilhaDoDocumento;
use App\AssinaturaEletronica\Service\TrocaDeArquivos;
use App\AssinaturaEletronica\Service\ValidadorDeBlocos;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Shared\Armazenamento\RemocaoAposTransacao;
use App\Shared\Doctrine\Transacao\TransacaoComArquivoNovo;
use Psr\Clock\ClockInterface;

/**
 * "Salvar rascunho" (criar e salvar, contrato da etapa 1, seção 4): o JSON, o PDF e a logo vão juntos, e nada fica
 * gravado antes de salvar. Tudo é conferido antes de gravar o primeiro arquivo; o arquivo novo só sai se a transação
 * comprovadamente não confirmou, e o que perdeu o uso só sai depois do COMMIT. Todo salvamento acrescenta "Rascunho
 * salvo" à trilha.
 */
final readonly class SalvarRascunhoUseCase
{
    public function __construct(
        private DocumentoParaAssinarRepository $documentos,
        private ConfiguracaoDaAssinaturaRepository $configuracoes,
        private ArquivosDoRascunho $arquivos,
        private TrilhaDoDocumento $trilha,
        private TransacaoComArquivoNovo $transacao,
        private RemocaoAposTransacao $remocao,
        private ClockInterface $relogio,
    ) {
    }

    /**
     * @param int|null $id nulo cria o documento
     *
     * @return int o id do documento
     *
     * @throws DocumentoNaoEncontrado
     * @throws AcaoRecusada
     */
    public function executar(User $ator, Tenant $tenant, ?int $id, EntradaDoRascunho $entrada): int
    {
        $agora = \DateTimeImmutable::createFromInterface($this->relogio->now());
        $documento = $id === null ? new DocumentoParaAssinar($tenant, $ator, $agora) : $this->editavel($id, $tenant);
        $configuracao = $this->configuracoes->doEscritorio($tenant);
        $blocos = $this->conferir($documento, $configuracao, $entrada);
        $troca = new TrocaDeArquivos();

        $id = $this->transacao->executar(
            function () use ($ator, $tenant, $documento, $configuracao, $entrada, $blocos, $agora, $troca): int {
                $documento->aplicarRascunho($entrada->dados, $blocos['blocos'], $agora);
                if ($entrada->arquivo !== null) {
                    $this->arquivos->gravarPdf($documento, $entrada->arquivo, $blocos['paginas'], $troca);
                }
                $this->arquivos->aplicarLogo($documento, $entrada->dados->logo, $entrada->logo, $configuracao, $troca);
                if ($entrada->dados->usarLogoComoPadrao) {
                    $this->guardarPadrao($documento, $configuracao ?? new ConfiguracaoDaAssinatura($tenant), $troca);
                }
                $this->documentos->salvar($documento);
                $this->trilha->acrescentar($documento, TipoDeEvento::RascunhoSalvo, [], $ator);

                return (int) $documento->getId();
            },
            static fn (): array => $troca->novas(),
            'assinatura_eletronica_salvar_rascunho',
        );

        if ($troca->antigas() !== []) {
            $this->remocao->remover($troca->antigas(), 'assinatura_eletronica_rascunho_trocou_arquivo');
        }

        return $id;
    }

    /**
     * @throws DocumentoNaoEncontrado
     * @throws AcaoRecusada
     */
    private function editavel(int $id, Tenant $tenant): DocumentoParaAssinar
    {
        $documento = $this->documentos->doEscritorio($id, $tenant) ?? throw new DocumentoNaoEncontrado();
        if (!$documento->ehRascunho()) {
            throw new AcaoRecusada('assinatura_eletronica.erro.so_rascunho');
        }

        return $documento;
    }

    /**
     * Tudo que pode recusar, antes de gravar qualquer arquivo.
     *
     * @return array{blocos: list<array<string, int|string>>, paginas: ?int}
     *
     * @throws AcaoRecusada
     */
    private function conferir(DocumentoParaAssinar $documento, ?ConfiguracaoDaAssinatura $configuracao, EntradaDoRascunho $entrada): array
    {
        if ($entrada->arquivo !== null) {
            ConferenciaDeUpload::pdf($entrada->arquivo);
        }
        if ($entrada->logo !== null) {
            ConferenciaDeUpload::logo($entrada->logo);
        }
        if (!$this->arquivos->temOrigemDaLogo($documento, $entrada->dados->logo, $entrada->logo, $configuracao)) {
            throw new AcaoRecusada('assinatura_eletronica.erro.logo_propria_sem_arquivo');
        }

        $paginas = $entrada->arquivo !== null ? $this->arquivos->paginas($entrada->arquivo) : $documento->getArquivoEnviadoPaginas();
        $temArquivo = $entrada->arquivo !== null || $documento->temArquivo();

        return [
            'blocos' => ValidadorDeBlocos::normalizar($entrada->dados->blocos, $temArquivo, $paginas),
            'paginas' => $paginas,
        ];
    }

    private function guardarPadrao(DocumentoParaAssinar $documento, ConfiguracaoDaAssinatura $configuracao, TrocaDeArquivos $troca): void
    {
        $this->arquivos->tornarPadrao($documento, $configuracao, $troca);
        $this->configuracoes->salvar($configuracao);
    }
}
