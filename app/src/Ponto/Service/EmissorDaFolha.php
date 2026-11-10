<?php

declare(strict_types=1);

namespace App\Ponto\Service;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\DTO\ConteudoDaFolha;
use App\Ponto\Exception\CompetenciaDaFolhaInvalidaException;

/**
 * Emitir a folha do mês (spec ponto-desenho-0811, E16, decisões 2, 16 e 18): montar a folha, carimbar o aceite que vale
 * e, com o segredo do HMAC, gravar a emissão com código próprio e o evento "Folha emitida". A implementação é o
 * {@see \App\Ponto\UseCase\EmitirFolhaDoPontoUseCase} (a transação e o flush); a interface existe para quem desenha o
 * PDF por ela ({@see \App\Ponto\UseCase\GerarPdfDaFolhaUseCase}) ser testado sem banco e sem mockar o EntityManager.
 */
interface EmissorDaFolha
{
    /**
     * @param ?User $emissor quem gera o PDF (a pessoa ou o gestor); autor do evento na trilha
     *
     * @return ConteudoDaFolha a folha do mês, com a validação (código BJF) quando o selo está pronto
     *
     * @throws CompetenciaDaFolhaInvalidaException competência fora do calendário, antes de montar ou gravar
     */
    public function executar(Tenant $escritorio, User $pessoa, int $ano, int $mes, ?User $emissor): ConteudoDaFolha;
}
