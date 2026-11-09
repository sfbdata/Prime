<?php

declare(strict_types=1);

namespace App\Ponto\Service;

use App\Ponto\DTO\DadosDoComprovante;
use App\Ponto\Entity\ComprovanteDoPonto;
use App\Ponto\Entity\RegistroPonto;
use App\Ponto\Repository\ComprovanteDoPontoRepository;

/**
 * Prepara o comprovante da batida que está sendo gravada (E15, P48 e D9): código sorteado no servidor, dados
 * canônicos, HMAC e versão da chave. Só persiste; quem grava a batida faz o flush único, na mesma transação.
 *
 * Com a função desligada ou o selo indisponível, devolve null e não grava nada: a batida segue só com o NSR.
 */
final class EmissaoDoComprovante
{
    public function __construct(
        private readonly FuncaoDoComprovante $funcao,
        private readonly SeloDoComprovante $selo,
        private readonly CodigoDoComprovante $codigos,
        private readonly ComprovanteDoPontoRepository $comprovantes,
    ) {
    }

    public function emitir(RegistroPonto $batida): ?ComprovanteDoPonto
    {
        if (!$this->funcao->emite()) {
            return null;
        }

        $dados = DadosDoComprovante::daBatida($batida, $this->codigos->novo());
        $canonico = $dados->canonico();
        $selo = $this->selo->selar($canonico);
        $comprovante = new ComprovanteDoPonto(
            $batida->getTenant() ?? throw new \LogicException('Batida sem escritório.'),
            $batida,
            $dados->nsr,
            $dados->codigo,
            $selo['hmac'],
            $selo['versao'],
            new \DateTimeImmutable(),
            $canonico,
        );
        $this->comprovantes->adicionar($comprovante);

        return $comprovante;
    }
}
