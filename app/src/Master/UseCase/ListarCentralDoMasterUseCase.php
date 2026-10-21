<?php

declare(strict_types=1);

namespace App\Master\UseCase;

use App\Entity\Auth\User;
use App\Master\DTO\AbaDaCentral;
use App\Master\DTO\CaixaDaCentralOutput;
use App\Master\DTO\ItemDaCentral;
use App\Master\Notificacoes\OrigensDaCentral;
use App\Master\Repository\CentralMasterEstadoRepository;
use App\Master\Repository\CentralMasterRespostaRepository;

/**
 * A caixa da Central de Notificações do Master (spec master-central-de-notificacoes.md; desenho 05 VS 21.10.26,
 * nfVals). Quem dispara: o Master, ao abrir a seção, trocar de aba, buscar ou atualizar.
 *
 * Junta as mensagens de todas as origens (D4), aplica o estado DESTE Master (lida, estrela, arquivada) e o
 * "Respondida" de todos (D1), filtra pela aba e pela busca (D9) e devolve uma página de 50. Os contadores das abas
 * não levam a busca, como no desenho. Mensagem que sumiu da origem simplesmente não vem.
 */
final class ListarCentralDoMasterUseCase
{
    public const POR_PAGINA = 50;

    public function __construct(
        private readonly OrigensDaCentral $origens,
        private readonly CentralMasterEstadoRepository $estados,
        private readonly CentralMasterRespostaRepository $respostas,
    ) {
    }

    public function executar(User $master, string $aba, string $busca, int $pagina): CaixaDaCentralOutput
    {
        $aba = AbaDaCentral::valida($aba);
        $itens = $this->itensDoMaster($master);
        $daAba = array_values(array_filter($itens, fn (ItemDaCentral $i): bool => $i->estaNaAba($aba) && $this->casaComBusca($i, $busca)));
        $pagina = max(1, $pagina);

        return new CaixaDaCentralOutput(
            aba: $aba,
            contagens: $this->contagens($itens),
            total: count($daAba),
            pagina: $pagina,
            porPagina: self::POR_PAGINA,
            itens: array_slice($daAba, ($pagina - 1) * self::POR_PAGINA, self::POR_PAGINA),
        );
    }

    /** @return list<ItemDaCentral> */
    private function itensDoMaster(User $master): array
    {
        $estados = $this->estados->doMaster($master);
        $respondidas = $this->respostas->chavesRespondidas();
        $itens = [];
        foreach ($this->origens->todas() as $mensagem) {
            $itens[] = ItemDaCentral::de($mensagem, $estados[$mensagem->chave()] ?? null, isset($respondidas[$mensagem->chave()]));
        }

        return $itens;
    }

    private function casaComBusca(ItemDaCentral $item, string $busca): bool
    {
        $busca = trim($busca);

        return $busca === '' || $item->mensagem->contem($busca);
    }

    /**
     * @param list<ItemDaCentral> $itens
     *
     * @return array<string, int>
     */
    private function contagens(array $itens): array
    {
        $contagens = [];
        foreach (AbaDaCentral::TODAS as $aba) {
            $contagens[$aba] = count(array_filter($itens, static fn (ItemDaCentral $i): bool => $i->estaNaAba($aba)));
        }

        return $contagens;
    }
}
