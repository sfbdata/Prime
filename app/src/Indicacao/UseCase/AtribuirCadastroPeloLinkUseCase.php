<?php

declare(strict_types=1);

namespace App\Indicacao\UseCase;

use App\Indicacao\Entity\Indicacao;
use App\Indicacao\Repository\IndicacaoCodigoRepository;
use App\Indicacao\Repository\IndicacaoRepository;
use App\Repository\UserRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\Clock\ClockInterface;

/**
 * A conta criada por quem chegou pelo link curto `/i/{codigo}` (spec `indicar-bluejus.md`, comportamento 7): liga a
 * conta nova a quem indicou, como uma indicação de canal link com `indicado_user_id`. Quem chama é o ouvinte do
 * cadastro, depois do commit; o código vem da sessão.
 *
 * Ignora em silêncio (devolve falso): código ausente, malformado ou inexistente, dono inativo, a própria conta nova
 * como dona do código e conta já atribuída. A pausa (global ou da pessoa) NÃO impede: ela trava o envio, e atribuir
 * não envia nada. Não conta nos limites nem no alerta, e não avisa quem indicou.
 */
final class AtribuirCadastroPeloLinkUseCase
{
    public function __construct(
        private readonly IndicacaoCodigoRepository $codigos,
        private readonly IndicacaoRepository $indicacoes,
        private readonly UserRepository $usuarios,
        private readonly ClockInterface $relogio,
    ) {
    }

    /** Devolve se ligou a conta nova a quem indicou. */
    public function executar(?string $codigo, int $contaNovaId): bool
    {
        $codigo = strtoupper(trim((string) $codigo));
        if (preg_match('/^[A-Z0-9]{1,12}$/', $codigo) !== 1) {
            return false;
        }
        $indicador = $this->codigos->donoDoCodigo($codigo);
        $contaNova = $this->usuarios->find($contaNovaId);
        if ($indicador === null || $contaNova === null || !$indicador->isActive() || $indicador->getId() === $contaNova->getId()) {
            return false;
        }
        if ($this->indicacoes->contaJaAtribuida($contaNovaId)) {
            return false;
        }

        try {
            return $this->indicacoes->gravarAtribuicao(Indicacao::porCadastroDoLink($indicador, null, $contaNova, $this->relogio->now()));
        } catch (UniqueConstraintViolationException) {
            // Corrida que escapou do ON CONFLICT: a conta já foi atribuída.
            return false;
        }
    }
}
