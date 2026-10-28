<?php

declare(strict_types=1);

namespace App\Shared\EspelhoRemoto;

use App\Shared\Armazenamento\ArmazenamentoComPrefixo;
use App\Shared\Armazenamento\ArmazenamentoDeArquivos;
use App\Shared\Armazenamento\ArquivoArmazenado;
use App\Shared\Armazenamento\ArquivoEmprestado;
use App\Shared\Armazenamento\ArquivoTemporarioPossuido;
use App\Shared\Armazenamento\CategoriaComIsolamentoFisico;
use App\Shared\Armazenamento\CategoriaDeArquivo;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\DiretorioTemporarioPrivado;
use App\Shared\Armazenamento\EscopoDeArquivo;
use App\Shared\Armazenamento\Exception\ArquivoIndisponivelNoMomento;
use App\Shared\Armazenamento\Exception\ArquivoNaoEncontrado;
use App\Shared\Armazenamento\Exception\ChaveDeArquivoInvalida;
use App\Shared\Armazenamento\Exception\FalhaDeArmazenamento;
use App\Shared\Armazenamento\FonteDeConteudo;
use App\Shared\Armazenamento\MaterializadorDeArquivo;
use App\Shared\Armazenamento\MetadadosDeArquivo;
use App\Shared\Armazenamento\NovoArquivo;
use App\Shared\Armazenamento\ResultadoDaRemocao;
use App\Shared\EspelhoRemoto\Outbox\EnvioVerificado;
use App\Shared\EspelhoRemoto\Outbox\OutboxDeReplicacao;
use Psr\Log\LoggerInterface;

/**
 * O decorador da gravação dupla (spec P2 §3.1, §14) — disco local como fonte de verdade.
 *
 *  - **tudo** é delegado ao armazenamento interno (o local), e o resultado dele é o que volta;
 *  - **leitura** (`abrir`, `ler`, `existe`, `metadados`, `paraLeitura`, `copiaGravavel`) vai ao
 *    disco primeiro; o R2 só entra quando o disco NÃO tem o arquivo (leitura de recuperação, abaixo).
 *    `listar` é repasse puro: lista só o disco;
 *  - **gravar**, em categoria ligada pela {@see PoliticaDeEspelhamento}, registra um envio na outbox
 *    com o SHA medido no disco;
 *  - **excluir** e **excluirPrefixo**, em categoria ligada, registram a exclusão como EVENTO — nada
 *    sai para o R2 (regra 0).
 *
 * ## Falha da réplica nunca é falha do usuário (regra 4)
 *
 * Qualquer erro ao medir ou registrar vira `logger->error` e a operação local segue com o resultado
 * dela. O que se perde fica sem linha na outbox: a reconciliação o DETECTA (`ausente_no_espelho`), e
 * o reenvio é do backfill (P2.6) — a reconciliação desta etapa só relata. O log leva só categoria, escritório e a
 * classe do erro — nunca o nome do arquivo nem a mensagem crua (a do DBAL inclui os parâmetros SQL).
 *
 * ## Leitura de recuperação (spec `leitura-pelo-r2-e-liberacao-de-disco.md`, D1 a D3)
 *
 * Enquanto o arquivo está no disco, nada muda e o espelho nem é consultado. Quando o disco não tem o
 * arquivo, o R2 só é usado se as três condições valem:
 *  1. a categoria está ligada na {@see PoliticaDeEspelhamento} e não está em
 *     {@see self::CATEGORIAS_SEM_LEITURA_DE_RECUPERACAO};
 *  2. o espelho está configurado (`ESPELHO_R2_*`); sem ele, comportamento de hoje;
 *  3. a linha mais nova da chave na outbox é um envio `verificado` ({@see OutboxDeReplicacao::envioVerificadoDe()}).
 *
 * Aí `existe()` e `metadados()` respondem pela outbox (tamanho, SHA e MIME, sem ir ao R2), e as leituras
 * de conteúdo hidratam o {@see CacheDeLeituraDoEspelho}: o download é conferido pelo SHA da outbox antes
 * de ganhar nome no cache, e a segunda leitura sai do cache sem tocar no R2. Faltando qualquer condição,
 * o resultado é o de hoje (`ArquivoNaoEncontrado`, false, null).
 *
 * Falha do R2 (fora do ar, objeto ausente, conteúdo que não confere, cache que não grava) vira
 * {@see ArquivoIndisponivelNoMomento}, que a entrega HTTP responde com 503 e nunca com 404: a outbox
 * prova que o arquivo existe. Sem nova tentativa em linha. O espelho é montado só na primeira
 * hidratação, nunca no construtor.
 *
 * Com a política desligada (o padrão), este decorador é um repasse sem efeito colateral nenhum.
 */
final class ArmazenamentoEspelhado implements ArmazenamentoDeArquivos, MaterializadorDeArquivo, ArmazenamentoComPrefixo
{
    /**
     * Categorias que ficam FORA da leitura de recuperação mesmo ligadas na política: sem o arquivo no
     * disco, o comportamento é o de hoje.
     *
     * `tarefa_anexo`: a spec `leitura-pelo-r2-e-liberacao-de-disco.md` §3.3 exige, antes de ligar a
     * leitura, provar que a chave de leitura do anexo usa o tenant da TAREFA e não o da mensagem
     * (pendência R1 da p2 §12). Sai daqui só com essa prova.
     */
    public const CATEGORIAS_SEM_LEITURA_DE_RECUPERACAO = [CategoriaDeArquivo::TAREFA_ANEXO];

    /** Montado só na primeira hidratação: a requisição que acha o arquivo no disco nunca o monta. */
    private ?EspelhoRemoto $espelho = null;

    public function __construct(
        private readonly ArmazenamentoDeArquivos&MaterializadorDeArquivo&ArmazenamentoComPrefixo $interno,
        private readonly OutboxDeReplicacao $outbox,
        private readonly PoliticaDeEspelhamento $politica,
        private readonly LoggerInterface $logger,
        private readonly ProvedorDoEspelhoRemoto $provedor,
        private readonly CacheDeLeituraDoEspelho $cache,
    ) {
    }

    public function gravar(ChaveDeArquivo|NovoArquivo $destino, FonteDeConteudo $fonte): ArquivoArmazenado
    {
        $armazenado = $this->interno->gravar($destino, $fonte);

        if ($this->politica->espelha($armazenado->chave->categoria)) {
            try {
                $medida = MedidaDeConteudo::de($this->interno, $armazenado->chave);
                $this->outbox->registrarEnvio($armazenado->chave, $medida->sha256, $medida->tamanhoBytes, $armazenado->mimeType);
            } catch (\Throwable $e) {
                $this->registrarFalha('envio', $armazenado->chave, $e);
            }
        }

        return $armazenado;
    }

    public function excluir(ChaveDeArquivo $chave): void
    {
        $this->interno->excluir($chave);

        if ($this->politica->espelha($chave->categoria)) {
            $this->registrarExclusao($chave);
        }
    }

    public function excluirPrefixo(EscopoDeArquivo $escopo, CategoriaComIsolamentoFisico $categoria): ResultadoDaRemocao
    {
        if (!$this->politica->espelha($categoria->paraCategoria())) {
            return $this->interno->excluirPrefixo($escopo, $categoria);
        }

        // A remoção por prefixo devolve só a contagem; para registrar CADA exclusão, as chaves são
        // listadas antes e conferidas depois — só o que de fato saiu do disco vira evento.
        $antes = [];
        try {
            foreach ($this->interno->listar($escopo, $categoria) as $chave) {
                $antes[] = $chave;
            }
        } catch (\Throwable $e) {
            $this->logger->error(sprintf(
                'Espelho: não foi possível listar o prefixo antes da purga (categoria=%s, escritório=%s, erro=%s); as exclusões não serão registradas.',
                $categoria->value,
                $escopo->comoTexto(),
                $e::class,
            ));
        }

        $resultado = $this->interno->excluirPrefixo($escopo, $categoria);

        foreach ($antes as $chave) {
            try {
                if (!$this->interno->existe($chave)) {
                    $this->registrarExclusao($chave);
                }
            } catch (\Throwable $e) {
                $this->registrarFalha('exclusão', $chave, $e);
            }
        }

        return $resultado;
    }

    public function abrir(ChaveDeArquivo $chave): mixed
    {
        try {
            return $this->interno->abrir($chave);
        } catch (ArquivoNaoEncontrado $e) {
            $caminho = $this->hidratarOuRelancar($chave, $e);
        }

        try {
            return $this->cache->abrir($caminho);
        } catch (FalhaDeArmazenamento $e) {
            throw ArquivoIndisponivelNoMomento::para($chave, $e);
        }
    }

    public function ler(ChaveDeArquivo $chave): string
    {
        try {
            return $this->interno->ler($chave);
        } catch (ArquivoNaoEncontrado $e) {
            $caminho = $this->hidratarOuRelancar($chave, $e);
        }

        try {
            return $this->cache->ler($caminho);
        } catch (FalhaDeArmazenamento $e) {
            throw ArquivoIndisponivelNoMomento::para($chave, $e);
        }
    }

    public function existe(ChaveDeArquivo $chave): bool
    {
        if ($this->interno->existe($chave)) {
            return true;
        }

        return $this->provaNoEspelho($chave) !== null;
    }

    public function metadados(ChaveDeArquivo $chave): ?MetadadosDeArquivo
    {
        $local = $this->interno->metadados($chave);
        if ($local !== null) {
            return $local;
        }

        $prova = $this->provaNoEspelho($chave);

        return $prova === null ? null : new MetadadosDeArquivo(
            tamanhoBytes: $prova->tamanhoBytes,
            mimeType: $prova->mimeType,
            atualizadoEm: $prova->gravadoEm,
            checksum: $prova->sha256,
        );
    }

    public function paraLeitura(ChaveDeArquivo $chave): ArquivoEmprestado
    {
        try {
            return $this->interno->paraLeitura($chave);
        } catch (ArquivoNaoEncontrado $e) {
            // O arquivo do cache é emprestado como o do disco: ninguém que recebe isto o apaga (INV-9).
            return new ArquivoEmprestado($this->hidratarOuRelancar($chave, $e));
        }
    }

    public function copiaGravavel(ChaveDeArquivo $chave): ArquivoTemporarioPossuido
    {
        try {
            return $this->interno->copiaGravavel($chave);
        } catch (ArquivoNaoEncontrado $e) {
            $origem = $this->hidratarOuRelancar($chave, $e);
        }

        // A mesma área temporária privada que o disco usa para as cópias graváveis (D29).
        $copia = DiretorioTemporarioPrivado::doProcesso('copia')->novoArquivo('copia-');
        try {
            $this->cache->copiarPara($origem, $copia->caminho());
        } catch (\Throwable $e) {
            $copia->liberar();

            throw $e;
        }

        return $copia;
    }

    /**
     * O disco não tem o arquivo: hidrata do R2 quando há prova, senão relança a ausência de hoje.
     *
     * @throws ArquivoNaoEncontrado         sem prova no espelho (o comportamento de hoje)
     * @throws ArquivoIndisponivelNoMomento com prova, mas o R2 ou o cache falharam
     */
    private function hidratarOuRelancar(ChaveDeArquivo $chave, ArquivoNaoEncontrado $ausente): string
    {
        $prova = $this->provaNoEspelho($chave);
        if ($prova === null) {
            throw $ausente;
        }

        try {
            return $this->cache->hidratar(
                $prova->sha256,
                $prova->tamanhoBytes,
                fn (mixed $destino) => $this->espelho()->baixar($chave, $destino, $prova->tamanhoBytes, $prova->sha256),
            );
        } catch (\Throwable $e) {
            $this->logger->error(sprintf(
                'Espelho: leitura de recuperação falhou (categoria=%s, escritório=%s, erro=%s). O arquivo existe no espelho e a entrega responde 503.',
                $chave->categoria->value,
                $chave->escopo->comoTexto(),
                $e::class,
            ));

            throw ArquivoIndisponivelNoMomento::para($chave, $e);
        }
    }

    /**
     * A prova de que a chave está no espelho, ou null quando não há como usar o espelho para ela:
     * categoria desligada, espelho sem configuração, ou a linha mais nova não é um envio verificado.
     *
     * Erro ao consultar a outbox não vira "não existe": é pane, e sobe como indisponível (D10).
     */
    private function provaNoEspelho(ChaveDeArquivo $chave): ?EnvioVerificado
    {
        if (!$this->politica->espelha($chave->categoria)
            || \in_array($chave->categoria, self::CATEGORIAS_SEM_LEITURA_DE_RECUPERACAO, true)
            || !$this->provedor->estaConfigurado()) {
            return null;
        }

        try {
            $chaveRemota = LayoutDeChaveRemota::chaveDe($chave);
        } catch (ChaveDeArquivoInvalida) {
            // Chave que o layout recusa nunca foi replicada (a outbox recusaria do mesmo jeito).
            return null;
        }

        try {
            return $this->outbox->envioVerificadoDe($chaveRemota);
        } catch (\Throwable $e) {
            $this->logger->error(sprintf(
                'Espelho: não foi possível consultar a outbox para a leitura de recuperação (categoria=%s, escritório=%s, erro=%s).',
                $chave->categoria->value,
                $chave->escopo->comoTexto(),
                $e::class,
            ));

            throw ArquivoIndisponivelNoMomento::para($chave, $e);
        }
    }

    private function espelho(): EspelhoRemoto
    {
        return $this->espelho ??= $this->provedor->criar();
    }

    public function listar(EscopoDeArquivo $escopo, CategoriaComIsolamentoFisico $categoria): iterable
    {
        return $this->interno->listar($escopo, $categoria);
    }

    private function registrarExclusao(ChaveDeArquivo $chave): void
    {
        try {
            $this->outbox->registrarExclusao($chave);
        } catch (\Throwable $e) {
            $this->registrarFalha('exclusão', $chave, $e);
        }
    }

    private function registrarFalha(string $oQue, ChaveDeArquivo $chave, \Throwable $e): void
    {
        $this->logger->error(sprintf(
            'Espelho: não foi possível registrar o evento de %s na outbox (categoria=%s, escritório=%s, erro=%s). A operação local seguiu; a reconciliação vai acusar a falta.',
            $oQue,
            $chave->categoria->value,
            $chave->escopo->comoTexto(),
            $e::class,
        ));
    }
}
