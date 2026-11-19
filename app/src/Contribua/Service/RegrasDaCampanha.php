<?php

declare(strict_types=1);

namespace App\Contribua\Service;

use App\Contribua\Entity\Campanha;

/**
 * As regras do Desafio, geradas da configuração: as mesmas frases aparecem na janela da campanha e no painel do
 * Master (uma fonte só, para nunca divergirem). Os textos são os do `regras()` do desenho.
 */
final class RegrasDaCampanha
{
    /** @return list<string> */
    public function gerar(Campanha $campanha): array
    {
        $preco = FormatoDaCampanha::reais($campanha->getPrecoCentavos());
        $devolve = FormatoDaCampanha::reais($campanha->valorDaDevolucaoCentavos());
        $vagas = $campanha->getVagas();

        return [
            'Valor único de ' . $preco . ', pago por Pix. Sem cartão, sem renovação automática e sem cobrança posterior.',
            'O BlueJus continua gratuito para todos. O ' . $preco . ' é só a inscrição no Desafio e dá acesso aos benefícios da campanha listados nesta página.',
            'Espaço de armazenamento não faz parte da campanha: continua na modalidade atual, gratuito até o limite e ampliado por liberação.',
            'Garantia do Desafio: se não gostar, peça a devolução em até ' . $campanha->getPrazoPedidoDias() . ' dias da inscrição e receba ' . $devolve
                . ' (' . FormatoDaCampanha::multiplicador($campanha->getMultiplicador()) . ' do valor pago) por Pix em até ' . $campanha->getPrazoPagamentoDiasUteis() . ' dias úteis. Não precisa justificar.',
            'O direito de arrependimento de 7 dias do Código de Defesa do Consumidor (art. 49) continua valendo, independentemente do Desafio.',
            'Uma inscrição por CPF e por inscrição na OAB.' . ($vagas > 0 ? ' Limite de ' . $vagas . ' vagas.' : ''),
            'Período: de ' . FormatoDaCampanha::data($campanha->getInicio()) . ' a ' . FormatoDaCampanha::data($campanha->getFim())
                . '. Pedidos de devolução feitos dentro do prazo são honrados mesmo depois do fim da campanha.',
            'Não é sorteio nem concurso: todo participante que pedir a devolução no prazo recebe.',
            'Ao pedir a devolução, os benefícios da campanha se encerram. Suas pastas e seus dados continuam no BlueJus gratuito; nada é apagado.',
            'Os dados informados (nome, CPF, OAB e chave Pix) são usados só para a inscrição e a devolução, conforme a Política de Privacidade.',
        ];
    }
}
