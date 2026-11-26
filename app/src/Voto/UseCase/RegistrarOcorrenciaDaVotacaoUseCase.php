<?php

declare(strict_types=1);

namespace App\Voto\UseCase;

use App\Voto\Entity\OcorrenciaDaVotacao;
use App\Voto\Entity\PautaDaAssembleia;
use App\Voto\Entity\UnidadeDaAssembleia;
use App\Voto\Exception\CedulaNaoEncontradaException;
use App\Voto\Exception\OperacaoInvalidaNaAssembleiaException;
use App\Voto\Repository\OcorrenciaDaVotacaoRepository;
use App\Voto\Repository\PautaDaAssembleiaRepository;
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
 * A qualificação (nome e unidade) é congelada na gravação. Token que não leva a nenhuma unidade (ou escritório sem o
 * Voto liberado): {@see CedulaNaoEncontradaException}. Recusa de negócio: {@see OperacaoInvalidaNaAssembleiaException}.
 */
final class RegistrarOcorrenciaDaVotacaoUseCase
{
    public function __construct(
        private readonly UnidadeDoLink $link,
        private readonly PautaDaAssembleiaRepository $pautas,
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

        return $this->transacao->executar(function () use ($unidade, $pautaId, $texto): OcorrenciaDaVotacao {
            if ($this->ocorrencias->contarDaUnidade($unidade) >= OcorrenciaDaVotacao::MAXIMO_POR_UNIDADE) {
                throw new OperacaoInvalidaNaAssembleiaException($this->tradutor->trans('voto.assembleia.cedula.ocorrencia.limite', ['n' => OcorrenciaDaVotacao::MAXIMO_POR_UNIDADE]));
            }
            $pauta = $this->pautaDaUnidade($unidade, $pautaId);
            $ocorrencia = new OcorrenciaDaVotacao(
                $unidade->getAssembleia(),
                $unidade,
                $pauta,
                $texto,
                QualificacaoDoEleitor::nome($unidade),
                QualificacaoDoEleitor::rotulo($unidade),
                $this->relogio->now(),
            );
            $this->ocorrencias->salvar($ocorrencia);

            return $ocorrencia;
        });
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
