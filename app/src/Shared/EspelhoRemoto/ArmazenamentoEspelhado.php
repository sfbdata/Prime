<?php

declare(strict_types=1);

namespace App\Shared\EspelhoRemoto;

use App\Shared\Armazenamento\ArmazenamentoComPrefixo;
use App\Shared\Armazenamento\ArmazenamentoDeArquivos;
use App\Shared\Armazenamento\ArquivoArmazenado;
use App\Shared\Armazenamento\ArquivoEmprestado;
use App\Shared\Armazenamento\ArquivoTemporarioPossuido;
use App\Shared\Armazenamento\CategoriaComIsolamentoFisico;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\EscopoDeArquivo;
use App\Shared\Armazenamento\FonteDeConteudo;
use App\Shared\Armazenamento\MaterializadorDeArquivo;
use App\Shared\Armazenamento\MetadadosDeArquivo;
use App\Shared\Armazenamento\NovoArquivo;
use App\Shared\Armazenamento\ResultadoDaRemocao;
use App\Shared\Armazenamento\Sha256DeArquivo;
use App\Shared\EspelhoRemoto\Outbox\OutboxDeReplicacao;
use Psr\Log\LoggerInterface;

/**
 * O decorador da gravação dupla (spec P2 §3.1, §14) — disco local como fonte de verdade.
 *
 *  - **tudo** é delegado ao armazenamento interno (o local), e o resultado dele é o que volta;
 *  - **leitura** (`abrir`, `ler`, `existe`, `metadados`, `paraLeitura`, `copiaGravavel`, `listar`)
 *    é repasse puro — este decorador nem conhece o {@see EspelhoRemoto} (DP-4);
 *  - **gravar**, em categoria ligada pela {@see PoliticaDeEspelhamento}, registra um envio na outbox
 *    com o SHA medido no disco;
 *  - **excluir** e **excluirPrefixo**, em categoria ligada, registram a exclusão como EVENTO — nada
 *    sai para o R2 (regra 0).
 *
 * ## Falha da réplica nunca é falha do usuário (regra 4)
 *
 * Qualquer erro ao medir ou registrar vira `logger->error` e a operação local segue com o resultado
 * dela. O que se perde é recuperado pela reconciliação. O log leva só categoria, escritório e a
 * classe do erro — nunca o nome do arquivo nem a mensagem crua (a do DBAL inclui os parâmetros SQL).
 *
 * Com a política desligada (o padrão), este decorador é um repasse sem efeito colateral nenhum.
 */
final class ArmazenamentoEspelhado implements ArmazenamentoDeArquivos, MaterializadorDeArquivo, ArmazenamentoComPrefixo
{
    public function __construct(
        private readonly ArmazenamentoDeArquivos&MaterializadorDeArquivo&ArmazenamentoComPrefixo $interno,
        private readonly OutboxDeReplicacao $outbox,
        private readonly PoliticaDeEspelhamento $politica,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function gravar(ChaveDeArquivo|NovoArquivo $destino, FonteDeConteudo $fonte): ArquivoArmazenado
    {
        $armazenado = $this->interno->gravar($destino, $fonte);

        if ($this->politica->espelha($armazenado->chave->categoria)) {
            try {
                $sha256 = Sha256DeArquivo::deChave($this->interno, $armazenado->chave);
                $this->outbox->registrarEnvio($armazenado->chave, $sha256, $armazenado->tamanhoBytes, $armazenado->mimeType);
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
        return $this->interno->abrir($chave);
    }

    public function ler(ChaveDeArquivo $chave): string
    {
        return $this->interno->ler($chave);
    }

    public function existe(ChaveDeArquivo $chave): bool
    {
        return $this->interno->existe($chave);
    }

    public function metadados(ChaveDeArquivo $chave): ?MetadadosDeArquivo
    {
        return $this->interno->metadados($chave);
    }

    public function paraLeitura(ChaveDeArquivo $chave): ArquivoEmprestado
    {
        return $this->interno->paraLeitura($chave);
    }

    public function copiaGravavel(ChaveDeArquivo $chave): ArquivoTemporarioPossuido
    {
        return $this->interno->copiaGravavel($chave);
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
            'Espelho: não foi possível registrar o evento de %s na outbox (categoria=%s, escritório=%s, erro=%s). A operação local seguiu; a reconciliação recupera.',
            $oQue,
            $chave->categoria->value,
            $chave->escopo->comoTexto(),
            $e::class,
        ));
    }
}
