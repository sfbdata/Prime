<?php

declare(strict_types=1);

namespace App\Cobranca\DTO;

/**
 * Leitura da página unificada do Objeto (ajuste 2): abrir o objeto = ver a cobrança inteira. Embrulha
 * o `CasoDetalheOutput` do caso âncora (invisível ao usuário) e agrega a identidade do objeto + os
 * vínculos de pessoas. O corpo operacional (saldo, abas, alertas) vem todo do `caso`; aqui só somamos
 * a camada do objeto (identificação/descrição/carteira) e a lista de envolvidos. Montado por
 * `MontarDetalheObjetoUseCase` — o controller não calcula nada.
 *
 * @param list<VinculoPessoaOutput> $vinculos
 */
final class ObjetoDetalheOutput
{
    public function __construct(
        public readonly int $objetoId,
        public readonly string $identificacao,
        public readonly ?string $descricao,
        public readonly ?string $referenciaExterna,
        public readonly int $carteiraId,
        public readonly string $carteiraNome,
        public readonly CasoDetalheOutput $caso,
        public readonly bool $temCobradaAtual,
        public readonly array $vinculos,
        /**
         * Ficha completa da pessoa cobrada ATUAL (spec §4) — telefones, e-mails e endereços, que a aba
         * Responsáveis passa a listar de verdade em vez do telefone único derivado. Só a cobrada: a
         * ficha dos demais vinculados continua a um clique de distância, no accordion.
         *
         * `null` quando o caso não tem cobrada atual (`temCobradaAtual === false`).
         */
        public readonly ?PessoaFichaOutput $fichaCobrada = null,
        /**
         * Vizinhos da unidade na carteira (spec §1.5), para as setas `‹ ›` do cabeçalho e o
         * `Próxima unidade →` do rodapé. `null` = a unidade está naquela ponta e a seta fica
         * desabilitada. Ordem `identificacao ASC, id ASC`, resolvida em
         * `ObjetoCobrancaRepository::vizinhosNaCarteira`.
         */
        public readonly ?int $objetoAnteriorId = null,
        public readonly ?int $objetoProximoId = null,
    ) {
    }
}
