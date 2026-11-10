<?php

declare(strict_types=1);

namespace App\Ponto\Service;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\DTO\ComprovanteNaListaOutput;
use App\Ponto\DTO\DadosDoComprovante;
use App\Ponto\Repository\ComprovanteDoPontoRepository;

/**
 * A aba "Comprovantes" da /ponto (E15, P48): se aparece e o que lista.
 *
 * Aparece com a função `ponto-comprovante` visível no Master, ligada no escritório e o selo pronto. Com o escritório
 * desligado, continua só para quem já tem comprovante (lista, Baixar e Validar código, sem emissão nova); para quem
 * não tem nenhum, some, como no desenho (decisão do Coordenador de 09/11/2026, pela Portaria MTP 671/2021). Com o
 * Global desligado pelo Master, some para todos. Lista os comprovantes da própria pessoa, só deste
 * escritório, a batida mais nova primeiro, até 120 (o mesmo teto que o desenho guarda, L542), sem recorte por mês:
 * o desenho mostra a lista inteira, independente da competência escolhida na Folha.
 */
final class AbaDosComprovantes
{
    public const LIMITE = 120;

    public function __construct(
        private readonly FuncaoDoComprovante $funcao,
        private readonly ComprovanteDoPontoRepository $comprovantes,
    ) {
    }

    public function visivel(Tenant $escritorio, User $pessoa): bool
    {
        if ($this->funcao->abaVisivel($escritorio)) {
            return true;
        }

        return $this->funcao->baixaOsEmitidos() && $this->comprovantes->existeDaPessoaNoEscritorio($pessoa, $escritorio);
    }

    /** @return list<ComprovanteNaListaOutput> vazia quando a pessoa não pode ver os comprovantes */
    public function linhas(User $pessoa, Tenant $escritorio): array
    {
        if (!$this->funcao->baixaOsEmitidos()) {
            return [];
        }

        $linhas = [];
        foreach ($this->comprovantes->daPessoaNoEscritorio($pessoa, $escritorio, self::LIMITE) as $comprovante) {
            try {
                $linhas[] = ComprovanteNaListaOutput::dosDados((int) $comprovante->getId(), DadosDoComprovante::deCanonico($comprovante->getDadosAssinados()));
            } catch (\UnexpectedValueException) {
                continue;   // linha fora do formato não vira comprovante na tela
            }
        }

        return $linhas;
    }
}
