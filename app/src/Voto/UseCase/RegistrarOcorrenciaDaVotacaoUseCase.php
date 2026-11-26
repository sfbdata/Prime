<?php

declare(strict_types=1);

namespace App\Voto\UseCase;

use App\Voto\Entity\OcorrenciaDaVotacao;
use App\Voto\Entity\PautaDaAssembleia;
use App\Voto\Entity\UnidadeDaAssembleia;
use App\Voto\Enum\EstadoDaAssembleia;
use App\Voto\Exception\CedulaNaoEncontradaException;
use App\Voto\Exception\OperacaoInvalidaNaAssembleiaException;
use App\Voto\Repository\OcorrenciaDaVotacaoRepository;
use App\Voto\Repository\PautaDaAssembleiaRepository;
use App\Voto\Repository\UnidadeDaAssembleiaRepository;
use App\Voto\Service\QualificacaoDoEleitor;
use App\Voto\Service\TransacaoDoVoto;
use App\Voto\Service\UnidadeDoLink;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * O eleitor registra uma ocorrência na votação pelo link pessoal `/votar/{token}` (pedido de 26/11/2026). Vale com a
 * votação aberta ou encerrada. Texto de 1 a 500 caracteres; no máximo 5 por unidade em cada assembleia. A pauta é
 * opcional e, se vier, precisa ser da MESMA assembleia da unidade (guarda de posse) e visível a ela.
 *
 * Só em assembleia publicada e não arquivada (rascunho e arquivada recusam). O limite é atômico: a linha da unidade é
 * travada (SELECT ... FOR UPDATE) antes da contagem, dentro da transação.
 *
 * A qualificação (nome e unidade) é congelada na gravação. Token que não leva a nenhuma unidade (ou escritório sem o
 * Voto liberado): {@see CedulaNaoEncontradaException}. Recusa de negócio: {@see OperacaoInvalidaNaAssembleiaException}.
 */
final class RegistrarOcorrenciaDaVotacaoUseCase
{
    public function __construct(
        private readonly UnidadeDoLink $link,
        private readonly PautaDaAssembleiaRepository $pautas,
        private readonly UnidadeDaAssembleiaRepository $unidades,
        private readonly OcorrenciaDaVotacaoRepository $ocorrencias,
        private readonly TransacaoDoVoto $transacao,
        private readonly ClockInterface $relogio,
        private readonly TranslatorInterface $tradutor,
    ) {
    }

    /**
     * @throws CedulaNaoEncontradaException
     * @throws OperacaoInvalidaNaAssembleiaException texto vazio ou longo, pauta inválida ou limite atingido
     */
    public function executar(string $token, ?int $pautaId, string $texto): OcorrenciaDaVotacao
    {
        $unidade = $this->link->localizar($token) ?? throw new CedulaNaoEncontradaException();
        $texto = $this->textoValido($texto);

        return $this->transacao->executar(function () use ($unidade, $pautaId, $texto): OcorrenciaDaVotacao {
            // A trava serializa dois envios do mesmo eleitor: a contagem abaixo já enxerga o que o outro gravou.
            $unidade = $this->unidades->buscarDaAssembleiaTravada((int) $unidade->getId(), $unidade->getAssembleia()) ?? throw new CedulaNaoEncontradaException();
            $this->exigirAssembleiaAtiva($unidade);
            if ($this->ocorrencias->contarDaUnidade($unidade) >= OcorrenciaDaVotacao::MAXIMO_POR_UNIDADE) {
                throw new OperacaoInvalidaNaAssembleiaException($this->tradutor->trans('voto.assembleia.cedula.ocorrencia.limite', ['n' => OcorrenciaDaVotacao::MAXIMO_POR_UNIDADE]));
            }
            $ocorrencia = new OcorrenciaDaVotacao(
                $unidade->getAssembleia(),
                $unidade,
                $this->pautaDaUnidade($unidade, $pautaId),
                $texto,
                QualificacaoDoEleitor::nome($unidade),
                QualificacaoDoEleitor::rotulo($unidade),
                $this->relogio->now(),
            );
            $this->ocorrencias->salvar($ocorrencia);

            return $ocorrencia;
        });
    }

    /** Votação aberta ou encerrada vale; rascunho (ainda não publicada) e arquivada não. */
    private function exigirAssembleiaAtiva(UnidadeDaAssembleia $unidade): void
    {
        $assembleia = $unidade->getAssembleia();
        if (EstadoDaAssembleia::Publicada !== $assembleia->getEstado()) {
            throw new OperacaoInvalidaNaAssembleiaException($this->tradutor->trans('voto.assembleia.cedula.ocorrencia.nao_publicada'));
        }
        if ($assembleia->arquivada()) {
            throw new OperacaoInvalidaNaAssembleiaException($this->tradutor->trans('voto.assembleia.cedula.ocorrencia.arquivada'));
        }
    }

    /** Texto limpo de 1 a 500 caracteres, com as mensagens do catálogo de tradução. */
    private function textoValido(string $texto): string
    {
        $texto = trim($texto);
        if ('' === $texto) {
            throw new OperacaoInvalidaNaAssembleiaException($this->tradutor->trans('voto.assembleia.cedula.ocorrencia.texto_vazio'));
        }
        if (mb_strlen($texto) > OcorrenciaDaVotacao::MAXIMO_DO_TEXTO) {
            throw new OperacaoInvalidaNaAssembleiaException($this->tradutor->trans('voto.assembleia.cedula.ocorrencia.texto_longo', ['n' => OcorrenciaDaVotacao::MAXIMO_DO_TEXTO]));
        }

        return $texto;
    }

    private function pautaDaUnidade(UnidadeDaAssembleia $unidade, ?int $pautaId): ?PautaDaAssembleia
    {
        if (null === $pautaId) {
            return null;
        }
        $pauta = $this->pautas->buscarDaAssembleia($pautaId, $unidade->getAssembleia());
        if (null === $pauta || $pauta->retirada() || !$pauta->aceitaBloco($unidade->getBloco())) {
            throw new OperacaoInvalidaNaAssembleiaException($this->tradutor->trans('voto.assembleia.cedula.ocorrencia.pauta_invalida'));
        }

        return $pauta;
    }
}
