<?php

declare(strict_types=1);

namespace App\Contribua\Armazenamento;

use App\Contribua\DTO\RetratoDoEspaco;
use App\Contribua\Event\EspacoAtingiuFaixa;
use App\Contribua\Exception\EspacoEsgotado;
use App\Contribua\Repository\ConsumoDeArmazenamentoRepository;
use App\Contribua\Service\SituacaoDoEspaco;
use App\Shared\Armazenamento\ArmazenamentoDeArquivos;
use App\Shared\Armazenamento\ArquivoArmazenado;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\FonteDeConteudo;
use App\Shared\Armazenamento\MetadadosDeArquivo;
use App\Shared\Armazenamento\NovoArquivo;
use App\Shared\Armazenamento\RemocaoAposTransacao;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Contracts\Service\ResetInterface;

/**
 * O ponto ÚNICO de bloqueio por espaço: todo arquivo novo de escritório passa por aqui.
 *
 * Só olha `NovoArquivo` com escopo de tenant. Passam livres:
 *  - regravação numa chave existente (`ChaveDeArquivo`): a recompressão regrava menor e a edição
 *    de peça regrava o mesmo documento; bloquear ali impediria até liberar espaço;
 *  - escopo global (foto de perfil, que é do User);
 *  - leitura, download, exclusão e metadados: "consultar e baixar arquivos continua liberado".
 *
 * O tamanho de uma `FonteDeConteudo` só é conhecido depois da escrita (stream). Por isso: recusa
 * antes quando o escritório já está no limite; senão grava, mede e, se passou, remove o arquivo
 * recém-cunhado (ainda sem registro que aponte para ele) e recusa. Com o bloqueio desligado nada é
 * recusado (`RetratoDoEspaco::cabe()`), mas a faixa continua medida: subir de faixa dispara
 * `EspacoAtingiuFaixa`, que é de onde saem os avisos por e-mail.
 *
 * O uso medido fica guardado por escritório durante a requisição e cresce a cada gravação: um
 * envio de dez arquivos faz uma soma no banco, não dez. `reset()` esvazia entre requisições e
 * mensagens do worker.
 */
#[AsDecorator(decorates: ArmazenamentoDeArquivos::class)]
final class ArmazenamentoComCota implements ArmazenamentoDeArquivos, ResetInterface
{
    /** @var array<int, int> bytes usados por tenant, medidos nesta requisição */
    private array $usados = [];

    public function __construct(
        #[AutowireDecorated]
        private readonly ArmazenamentoDeArquivos $interno,
        private readonly ConsumoDeArmazenamentoRepository $consumo,
        private readonly SituacaoDoEspaco $situacao,
        private readonly EventDispatcherInterface $eventos,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function gravar(ChaveDeArquivo|NovoArquivo $destino, FonteDeConteudo $fonte): ArquivoArmazenado
    {
        $tenantId = $destino instanceof NovoArquivo ? $destino->escopo->tenantIdOuNull() : null;
        if ($tenantId === null) {
            return $this->interno->gravar($destino, $fonte);
        }

        $antes = $this->retrato($tenantId);
        if (!$antes->cabe(0)) {
            throw new EspacoEsgotado($antes);
        }

        $armazenado = $this->interno->gravar($destino, $fonte);
        $this->desfazerSeNaoCoube($antes, $armazenado);
        $this->usados[$tenantId] += $armazenado->tamanhoBytes;
        $this->avisarSeSubiuDeFaixa($antes, $this->retrato($tenantId));

        return $armazenado;
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

    public function excluir(ChaveDeArquivo $chave): void
    {
        $this->interno->excluir($chave);
    }

    public function metadados(ChaveDeArquivo $chave): ?MetadadosDeArquivo
    {
        return $this->interno->metadados($chave);
    }

    public function reset(): void
    {
        $this->usados = [];
    }

    private function retrato(int $tenantId): RetratoDoEspaco
    {
        $this->usados[$tenantId] ??= $this->consumo->bytesDoEscritorio($tenantId);

        return $this->situacao->retratar($tenantId, $this->usados[$tenantId]);
    }

    /** Passou do limite com este arquivo: ele sai já (nenhum registro aponta para ele ainda) e o envio é recusado. */
    private function desfazerSeNaoCoube(RetratoDoEspaco $antes, ArquivoArmazenado $armazenado): void
    {
        if ($antes->cabe($armazenado->tamanhoBytes)) {
            return;
        }

        (new RemocaoAposTransacao($this->interno, $this->logger))
            ->remover([$armazenado->chave], 'arquivo recusado por falta de espaço');

        throw new EspacoEsgotado($antes);
    }

    private function avisarSeSubiuDeFaixa(RetratoDoEspaco $antes, RetratoDoEspaco $depois): void
    {
        if ($depois->faixa->ordem() > $antes->faixa->ordem()) {
            $this->eventos->dispatch(new EspacoAtingiuFaixa($depois->tenantId, $depois->faixa, $antes->faixa, $depois));
        }
    }
}
