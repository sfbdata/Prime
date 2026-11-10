<?php

declare(strict_types=1);

namespace App\Ponto\Exception;

/**
 * Competência da folha fora do calendário: mês fora de 1 a 12 ou ano sem quatro dígitos (spec ponto-desenho-0811, E16,
 * decisão 25). Lançada por {@see \App\Ponto\Entity\FolhaEmitidaDoPonto::exigirCompetencia()} antes de montar a folha.
 *
 * Estende `\InvalidArgumentException` para quem já esperava esse tipo continuar funcionando; existe para a rota do PDF
 * responder 400 só a ela, e não a outro `\InvalidArgumentException` do desenho ou do embrulho do PDF (a folha já
 * gravada, o erro é do servidor).
 */
final class CompetenciaDaFolhaInvalidaException extends \InvalidArgumentException
{
}
