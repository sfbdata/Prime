<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\UseCase;

use App\AssinaturaEletronica\DTO\EntradaDoRascunho;
use App\AssinaturaEletronica\DTO\OriginalJaAssinado;
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
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\RemocaoAposTransacao;
use App\Shared\Doctrine\Transacao\TransacaoComArquivoNovo;
use Psr\Clock\ClockInterface;

/**
 * "Salvar rascunho" (criar e salvar, contrato da etapa 1, seção 4): o JSON, o PDF e a logo vão juntos, e nada fica
 * gravado antes de salvar. Tudo é conferido antes de gravar o primeiro arquivo; o arquivo novo só sai se a transação
 * comprovadamente não confirmou, e o que perdeu o uso só sai depois do COMMIT (e só se nenhum documento do escritório
 * ainda aponta para ele). Todo salvamento acrescenta "Rascunho salvo" à trilha, menos a gravação do "Gerar links e
 * enviar" (`envio=1`): nela o EnviarDocumento registra "Documento criado", ou "Rascunho salvo" se a conferência recusar.
 *
 * O PDF só fica guardado enquanto algum bloco é página dele (`pg`): rascunho sem página perde o PDF. A logo padrão do
 * escritório segue a caixa "Usar esta logo nos próximos documentos": marcada grava, desmarcada limpa, ausente não mexe.
 *
 * Etapa 5, bloco 5A: o PDF enviado que já chega com assinatura digital vira a versão `original` (arquivo próprio, com a
 * conferência do motor) e o conteúdo ganha o parágrafo de dc:374. O original muda quando chega PDF novo ou quando o
 * rascunho perde o PDF: aí o anterior sai. Todo salvamento de rascunho existente trava e relê o documento, e o
 * conteúdo fica só com o parágrafo do original guardado depois de salvar (duas abas não deixam evidência trocada).
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
                $this->travarERecarregar($documento, $tenant);
                $conteudo = $this->arquivos->comParagrafoDoOriginal($blocos['blocos'], $documento, $blocos['mudaOriginal'], $blocos['original']);
                $documento->aplicarRascunho($entrada->dados, $conteudo, $agora);
                $this->aplicarPdf($documento, $entrada, $blocos['paginas'], $troca);
                $this->arquivos->aplicarLogo($documento, $entrada->dados->logo, $entrada->logo, $configuracao, $troca);
                $this->aplicarPadrao($tenant, $documento, $configuracao, $entrada->dados->usarLogoComoPadrao, $troca);
                $this->documentos->salvar($documento);
                if ($blocos['mudaOriginal']) {
                    $this->arquivos->trocarOriginal($documento, $blocos['original'], $troca, $agora);
                }
                // Na gravação do envio a trilha fica para o EnviarDocumento: "Documento criado" se ele passar, "Rascunho
                // salvo" se a conferência do envio recusar (desenho L374; decisão de 10/11/2026).
                if (!$entrada->doEnvio) {
                    $this->trilha->acrescentar($documento, TipoDeEvento::RascunhoSalvo, [], $ator);
                }

                return (int) $documento->getId();
            },
            static fn (): array => $troca->novas(),
            'assinatura_eletronica_salvar_rascunho',
        );

        $this->removerSemUso($tenant, $troca->antigas());

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
     * Tudo que pode recusar, antes de gravar qualquer arquivo. O original muda quando chega PDF novo com página dele ou
     * quando o rascunho fica sem página do PDF; o PDF novo é conferido pelo motor (só ele vira original, se assinado).
     *
     * @return array{blocos: list<array<string, int|string>>, paginas: ?int, mudaOriginal: bool, original: ?OriginalJaAssinado}
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
        $blocos = ValidadorDeBlocos::normalizar($entrada->dados->blocos, $temArquivo, $paginas);
        $temPagina = \in_array('pg', array_column($blocos, 'k'), true);
        $mudaOriginal = !$temPagina || $entrada->arquivo !== null;
        $original = $temPagina && $entrada->arquivo !== null ? $this->arquivos->conferirOriginal($entrada->arquivo) : null;
        $this->arquivos->exigirLugarParaOParagrafo($blocos, $documento, $mudaOriginal, $original);

        return ['blocos' => $blocos, 'paginas' => $paginas, 'mudaOriginal' => $mudaOriginal, 'original' => $original];
    }

    /**
     * Rascunho existente sempre travado e relido (o travamento descarta o que estava só na memória, por isso vem antes de
     * aplicar o rascunho): o original guardado e o parágrafo dele só mudam com a linha travada, e decidir "tem versão"
     * antes de travar seria a mesma corrida entre duas abas. Documento novo ainda não tem linha para travar.
     *
     * @throws DocumentoNaoEncontrado
     */
    private function travarERecarregar(DocumentoParaAssinar $documento, Tenant $tenant): void
    {
        if ($documento->getId() !== null && !$this->documentos->travarERecarregar($documento, $tenant)) {
            throw new DocumentoNaoEncontrado();
        }
    }

    /** Com página do PDF, o enviado agora troca o guardado (sem envio, fica o atual); sem nenhuma, o PDF sai. */
    private function aplicarPdf(DocumentoParaAssinar $documento, EntradaDoRascunho $entrada, ?int $paginas, TrocaDeArquivos $troca): void
    {
        if (!$documento->temPaginaDoArquivo()) {
            $this->arquivos->descartarPdf($documento, $troca);
        } elseif ($entrada->arquivo !== null) {
            $this->arquivos->gravarPdf($documento, $entrada->arquivo, $paginas, $troca);
        }
    }

    /** @param bool|null $usar a caixa "Usar esta logo nos próximos documentos"; nulo quando a tela não a mostrou */
    private function aplicarPadrao(Tenant $tenant, DocumentoParaAssinar $documento, ?ConfiguracaoDaAssinatura $configuracao, ?bool $usar, TrocaDeArquivos $troca): void
    {
        if ($usar === null || (!$usar && $configuracao === null)) {
            return;
        }

        $configuracao ??= new ConfiguracaoDaAssinatura($tenant);
        if ($usar) {
            $this->arquivos->tornarPadrao($documento, $configuracao, $troca);
        } else {
            $this->arquivos->limparPadrao($configuracao, $troca);
        }
        $this->configuracoes->salvar($configuracao);
    }

    /**
     * Depois do COMMIT: apaga o que perdeu o uso, menos o que algum documento do escritório ainda usa.
     *
     * @param list<ChaveDeArquivo> $antigas
     */
    private function removerSemUso(Tenant $tenant, array $antigas): void
    {
        if ($antigas === []) {
            return;
        }

        $emUso = $this->documentos->arquivosEmUso($tenant, array_map(static fn (ChaveDeArquivo $c): string => $c->nome, $antigas));
        $semUso = array_values(array_filter($antigas, static fn (ChaveDeArquivo $c): bool => !\in_array($c->nome, $emUso, true)));
        if ($semUso !== []) {
            $this->remocao->remover($semUso, 'assinatura_eletronica_rascunho_trocou_arquivo');
        }
    }
}
