<?php

declare(strict_types=1);

namespace App\Conector\Ferramenta;

use App\Conector\DTO\ChamadaDoConector;
use App\Conector\DTO\RespostaDaFerramenta;
use App\Conector\DTO\ResultadoDaConfirmacao;
use App\Conector\Exception\ErroDaFerramenta;
use App\Pasta\Entity\Pasta;
use App\Pasta\Repository\PastaAcompanhamentoDesligadoRepository;
use App\Pasta\Repository\PastaDestaqueRepository;
use App\Pasta\Repository\PastaFavoritaRepository;
use App\Pasta\UseCase\AlternarAcompanhamentoDaPastaUseCase;
use App\Pasta\UseCase\AlternarFavoritoDaPastaUseCase;
use App\Pasta\UseCase\DestacarPastaUseCase;

/**
 * `favoritar`, `acompanhar` e `destacar` da `atualizar_pasta` (`docs/specs/mcp-remoto-oauth.md`, 6.6): preferência de
 * quem olha, só para a pessoa da chamada. Como a tela (`pasta_favorito_alternar`, `pasta_acompanhamento_alternar`,
 * `expediente_pasta_destaque`), basta VER a pasta. Marcar e desmarcar são diretos (abertura da 6.6).
 *
 * A tela alterna; o conector recebe o estado pedido (`ligado`, `destaque`) e só alterna quando ele é diferente do atual,
 * para o assistente nunca desfazer sem querer o que a pessoa já tinha. Pedir o estado que já está recusa sem gravar.
 */
final class AtualizarPastaPreferencias implements AtualizarPastaGrupo
{
    public const RECUSA = 'O BlueJus recusou mudar esta preferência. Tente de novo pela tela.';
    public const SO_PARA_VOCE = 'Vale só para você: os colegas não veem esta preferência.';

    public function __construct(
        private readonly AtualizarPastaAcesso $acesso,
        private readonly PastaFavoritaRepository $favoritas,
        private readonly PastaAcompanhamentoDesligadoRepository $desligados,
        private readonly PastaDestaqueRepository $destaques,
        private readonly AlternarFavoritoDaPastaUseCase $alternarFavorito,
        private readonly AlternarAcompanhamentoDaPastaUseCase $alternarAcompanhamento,
        private readonly DestacarPastaUseCase $destacar,
    ) {
    }

    public function acoes(): array
    {
        return ['favoritar', 'acompanhar', 'destacar'];
    }

    public function executar(AtualizarPastaEntrada $entrada, ChamadaDoConector $chamada): RespostaDaFerramenta
    {
        $pasta = $this->acesso->viva($chamada, $entrada->acao, $entrada->pastaId(), false);
        $dados = match ($entrada->acao) {
            'favoritar' => ['favorita' => $this->favoritar($pasta, $entrada->booleano('ligado'), $chamada)],
            'acompanhar' => ['acompanha' => $this->acompanhar($pasta, $entrada->booleano('ligado'), $chamada)],
            default => ['destaque' => $this->destacarLinha($pasta, $entrada->textoObrigatorio('destaque'), $chamada)],
        };
        $id = (int) $pasta->getId();

        return new RespostaDaFerramenta(['estado' => 'concluida', 'acao' => $entrada->acao, 'pasta_id' => $id] + $dados + ['aviso' => self::SO_PARA_VOCE], ids: ['pasta' => [$id]]);
    }

    public function reconferir(string $acao, array $dados, ChamadaDoConector $chamada): void
    {
        throw ErroDaFerramenta::entradaInvalida();
    }

    public function executarConfirmada(string $acao, array $dados, ChamadaDoConector $chamada): ResultadoDaConfirmacao
    {
        throw ErroDaFerramenta::entradaInvalida();
    }

    /** @throws ErroDaFerramenta */
    private function favoritar(Pasta $pasta, bool $ligado, ChamadaDoConector $chamada): bool
    {
        $contexto = $chamada->contexto;
        $atual = $this->favoritas->buscarDoUsuario($pasta, $contexto->pessoa, $contexto->escritorio) !== null;
        if ($atual === $ligado) {
            throw ErroDaFerramenta::recusada(AtualizarPastaFerramenta::NADA_A_MUDAR);
        }

        return AtualizarPastaRecusa::gravando(fn (): bool => $this->alternarFavorito->executar($pasta, $contexto->pessoa, $contexto->escritorio), self::RECUSA);
    }

    /** @throws ErroDaFerramenta */
    private function acompanhar(Pasta $pasta, bool $ligado, ChamadaDoConector $chamada): bool
    {
        $contexto = $chamada->contexto;
        $atual = !$this->desligados->estaDesligado($pasta, $contexto->pessoa, $contexto->escritorio);
        if ($atual === $ligado) {
            throw ErroDaFerramenta::recusada(AtualizarPastaFerramenta::NADA_A_MUDAR);
        }

        return AtualizarPastaRecusa::gravando(fn (): bool => $this->alternarAcompanhamento->executar($pasta, $contexto->pessoa, $contexto->escritorio), self::RECUSA);
    }

    /** @throws ErroDaFerramenta */
    private function destacarLinha(Pasta $pasta, string $destaque, ChamadaDoConector $chamada): string
    {
        $contexto = $chamada->contexto;
        $pedido = $destaque === AtualizarPastaEntrada::SEM_DESTAQUE ? null : $destaque;
        if ($this->destaques->buscarDoUsuario($pasta, $contexto->pessoa, $contexto->escritorio)?->getCor() === $pedido) {
            throw ErroDaFerramenta::recusada(AtualizarPastaFerramenta::NADA_A_MUDAR);
        }
        $final = AtualizarPastaRecusa::gravando(fn (): ?string => $this->destacar->executar($pasta, $pedido, $contexto->pessoa, $contexto->escritorio), self::RECUSA);

        return $final ?? AtualizarPastaEntrada::SEM_DESTAQUE;
    }
}
