<?php

declare(strict_types=1);

namespace App\Ponto\Service;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\DTO\ConteudoDaFolha;
use App\Ponto\DTO\DadosDaFolhaEmitida;
use App\Ponto\Entity\AceiteDaFolhaDoPonto;
use App\Ponto\Entity\FolhaEmitidaDoPonto;
use App\Ponto\Repository\FolhaEmitidaDoPontoRepository;

/**
 * Prepara uma folha emitida com código BJF (spec ponto-desenho-0811, E16, decisões 2 e 18), como a
 * {@see EmissaoDoComprovante} prepara o comprovante: código sorteado no servidor, dados canônicos (o resumo dos
 * registros e a impressão deles, decisão 3), HMAC e versão da chave. Cada chamada é uma emissão nova: nada é
 * reaproveitado.
 *
 * Só persiste. Quem chama faz o flush único e grava "Folha emitida" na trilha depois dele, na mesma transação (a
 * trilha guarda o id da folha). O instante vem de quem chama, o mesmo objeto para o texto selado e a coluna
 * ({@see RelogioDaFolha}).
 */
final class EmissaoDaFolha
{
    public function __construct(
        private readonly SeloDaFolha $selo,
        private readonly CodigoDaFolha $codigos,
        private readonly FolhaEmitidaDoPontoRepository $folhas,
    ) {
    }

    /**
     * @param ?User                 $emissor        quem gerou: a própria pessoa ou o gestor (P89)
     * @param ?AceiteDaFolhaDoPonto $aceiteImpresso o aceite carimbado nesta folha, ou null
     *
     * @throws \LogicException quando o selo não está disponível (quem chama confere antes)
     */
    public function emitir(ConteudoDaFolha $conteudo, Tenant $escritorio, User $pessoa, ?User $emissor, \DateTimeImmutable $emitidaEm, ?AceiteDaFolhaDoPonto $aceiteImpresso): FolhaEmitidaDoPonto
    {
        $dados = DadosDaFolhaEmitida::daEmissao(
            $this->codigos->novo(),
            $escritorio,
            $pessoa,
            $conteudo->ano,
            $conteudo->mes,
            $emitidaEm,
            $emissor,
            $conteudo->impressaoDosRegistros(),
            $conteudo->resumoDosRegistros(),
            $aceiteImpresso,
        );
        $canonico = $dados->canonico();
        $selado = $this->selo->selar($canonico);
        $folha = new FolhaEmitidaDoPonto($escritorio, $pessoa, $emissor, $dados->ano, $dados->mes, $dados->codigo, $selado['hmac'], $selado['versao'], $emitidaEm, $dados->impressaoRegistros, $canonico);
        $this->folhas->adicionar($folha);

        return $folha;
    }
}
