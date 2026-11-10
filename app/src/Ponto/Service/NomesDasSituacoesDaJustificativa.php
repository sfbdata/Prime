<?php

declare(strict_types=1);

namespace App\Ponto\Service;

/**
 * P40 do desenho do Ponto (docs/specs/ponto-desenho-0811.md, E3): um nome só para cada situação da justificativa
 * ("Em análise", "Abonado", "Recusado") também FORA da tela nova do Ponto: na ficha do gestor (a aba Justificativas,
 * o botão e o motivo da recusa e as mensagens da análise) e no aviso do sininho (NotificacaoService).
 *
 * Ponto único da troca: a constante VIGENTES escolhe o conjunto. Para voltar aos nomes de antes (o Ponto antigo
 * de volta), a linha passa a ser `VIGENTES = self::DE_ANTES`. A tela nova do Ponto não passa por aqui: ela usa os
 * nomes do desenho de cada lugar (D22: a folha no feminino, a aba no masculino).
 */
final class NomesDasSituacoesDaJustificativa
{
    /** Os nomes do desenho (P40 ligado). */
    public const DO_DESENHO = [
        'situacao.pendente'      => 'Em análise',
        'situacao.rejeitado'     => 'Recusado',
        'aviso.recusada'         => 'Sua justificativa de ponto foi recusada.',
        'analise.recusada'       => 'Justificativa de %s recusada.',
        'analise.sem_motivo'     => 'Informe o motivo da recusa.',
        'analise.so_para_abonar' => 'Apenas justificativas em análise podem ser abonadas.',
        'analise.so_para_recusar' => 'Apenas justificativas em análise podem ser recusadas.',
        'reverter.botao'         => 'Voltar para análise',
        'reverter.confirmar'     => 'Voltar esta justificativa para análise?',
        'reverter.feito'         => 'Justificativa de %s voltou para análise.',
        'reverter.ja_esta'       => 'Esta justificativa já está em análise.',
        'ficha.recusar'          => 'Recusar',
        'ficha.motivo'           => 'Motivo da recusa (obrigatório)',
        'ficha.confirmar_recusa' => 'Recusar justificativa de %s?',
        'anexo.travado'          => 'O atestado não pode ser trocado: este lançamento já está abonado ou recusado em pelo '
            . 'menos um dos dias. Para trocá-lo, todos os dias precisam voltar para análise. Fale com o administrador.',
        'edicao.ja_analisada'    => 'Esta justificativa não está mais em análise (já foi analisada pelo gestor ou '
            . 'registrada como falta) e não pode ser alterada. Para corrigi-la, peça ao administrador para voltá-la '
            . 'para análise.',
        'lote.sem_pendente'      => 'Nenhuma justificativa em análise encontrada neste lote.',
        'reverter.feito_com_batida' => 'Justificativa de %s voltou para análise. A batida criada pelo abono antes desta '
            . 'versão continua na folha: para tirá-la da conta, use Desconsiderar na aba Batidas de Ponto.',
        'reverter.feito_desconsiderada' => 'Justificativa de %s voltou para análise e a batida do abono foi desconsiderada.',
        // E12: a recusa do trabalho externo tira a batida da conta; voltar para análise a devolve.
        'analise.recusada_externo' => 'Justificativa de %s recusada. A batida do trabalho externo saiu da conta.',
        'reverter.feito_restaurada' => 'Justificativa de %s voltou para análise e a batida do trabalho externo voltou para a conta.',
        // Folha exportada (PDF e XLSX), entre parênteses depois do tipo: no feminino, como a Folha do desenho (D22).
        'folha.recusada'         => 'recusada',
    ];

    /** Os nomes de antes do desenho, guardados para a volta do Ponto antigo. */
    public const DE_ANTES = [
        'situacao.pendente'      => 'Pendente',
        'situacao.rejeitado'     => 'Rejeitado',
        'aviso.recusada'         => 'Sua justificativa de ponto foi rejeitada.',
        'analise.recusada'       => 'Justificativa de %s rejeitada.',
        'analise.sem_motivo'     => 'Informe o motivo da rejeição.',
        'analise.so_para_abonar' => 'Apenas justificativas pendentes podem ser abonadas.',
        'analise.so_para_recusar' => 'Apenas justificativas pendentes podem ser rejeitadas.',
        'reverter.botao'         => 'Reverter para Pendente',
        'reverter.confirmar'     => 'Reverter esta justificativa para pendente?',
        'reverter.feito'         => 'Justificativa de %s revertida para pendente.',
        'reverter.ja_esta'       => 'Esta justificativa já está pendente.',
        'ficha.recusar'          => 'Rejeitar',
        'ficha.motivo'           => 'Motivo da rejeição (obrigatório)',
        'ficha.confirmar_recusa' => 'Rejeitar justificativa de %s?',
        'anexo.travado'          => 'O atestado não pode ser trocado: este lançamento já está abonado ou rejeitado em '
            . 'pelo menos um dos dias. Para trocá-lo, todos os dias precisam voltar a pendente. Fale com o administrador.',
        'edicao.ja_analisada'    => 'Esta justificativa não está mais pendente (já foi analisada pelo gestor ou '
            . 'registrada como falta) e não pode ser alterada. Para corrigi-la, peça ao administrador para '
            . 'revertê-la para pendente.',
        'lote.sem_pendente'      => 'Nenhuma justificativa pendente encontrada neste lote.',
        'reverter.feito_com_batida' => 'Justificativa de %s revertida para pendente. A batida criada pelo abono antes '
            . 'desta versão continua na folha: para tirá-la da conta, use Desconsiderar na aba Batidas de Ponto.',
        'reverter.feito_desconsiderada' => 'Justificativa de %s revertida para pendente e a batida do abono foi '
            . 'desconsiderada.',
        'analise.recusada_externo' => 'Justificativa de %s rejeitada. A batida do trabalho externo saiu da conta.',
        'reverter.feito_restaurada' => 'Justificativa de %s revertida para pendente e a batida do trabalho externo voltou '
            . 'para a conta.',
        'folha.recusada'         => 'rejeitada',
    ];

    /** A linha da troca do P40. */
    // Pública para quem precisa do texto numa constante (JustificativaJaAnalisadaException::MENSAGEM).
    public const VIGENTES = self::DO_DESENHO;

    private function __construct()
    {
    }

    /** O texto da chave no conjunto vigente; `%s` recebe os valores (a data, nas mensagens da análise). */
    public static function texto(string $chave, string ...$valores): string
    {
        $texto = self::VIGENTES[$chave] ?? throw new \LogicException(sprintf('Nome de situação sem texto: "%s".', $chave));

        return $valores === [] ? $texto : sprintf($texto, ...$valores);
    }
}
