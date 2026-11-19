<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Auth\User;
use App\Pasta\DTO\PastasRecebidasOutput;
use App\Pasta\Repository\PastaCompartilhamentoRepository;
use App\Pasta\Service\CodigoPessoal;
use App\Pasta\Service\LeitorDaPastaRecebida;
use App\Pasta\Service\PastaRecebidaDoUsuario;
use Psr\Clock\ClockInterface;

/**
 * A caixa "Recebidas" da pessoa (spec acesso-restrito-e-compartilhar §4.5, fatia 8; desenho L9 e L10): o código pessoal
 * (sorteado e gravado na primeira vez), quantos convites a aguardam e os convites de outras contas para ela, de
 * qualquer escritório. É da pessoa, não do escritório da sessão: só olha o `destino`.
 *
 * Fatia 9a (§4.5.1): cada item diz se abre (`abre`), pelo mesmo critério do `PastaRecebidaDoUsuario`, conferido em
 * lote. A Lista só mostra os que abrem, e "Ver pasta" da caixa só aparece neles; só eles levam os dados da linha da
 * Lista, com as metas abertas (`LeitorDaPastaRecebida::metasAbertasDaLista`, em lote).
 */
final class ConsultarPastasRecebidasUseCase
{
    public function __construct(
        private readonly PastaCompartilhamentoRepository $compartilhamentos,
        private readonly CodigoPessoal $codigoPessoal,
        private readonly ClockInterface $relogio,
        private readonly PastaRecebidaDoUsuario $pastaRecebida,
        private readonly LeitorDaPastaRecebida $leitor,
    ) {
    }

    public function executar(User $pessoa): PastasRecebidasOutput
    {
        $hoje = $this->relogio->now();
        $convites = $this->compartilhamentos->recebidasPor($pessoa);
        $abrem = $this->pastaRecebida->quaisAbrem($convites, $pessoa);
        $metas = $this->leitor->metasAbertasDaLista(array_values(array_filter($convites, static fn ($c): bool => isset($abrem[(int) $c->getId()]))));
        $itens = array_map(
            static fn ($c): array => PastasRecebidasOutput::item($c, $hoje, isset($abrem[(int) $c->getId()]), $metas[(int) $c->getId()] ?? null),
            $convites,
        );

        return new PastasRecebidasOutput(
            $this->codigoPessoal->garantir($pessoa),
            $this->compartilhamentos->pendentesPara($pessoa, $hoje),
            $itens,
        );
    }
}
