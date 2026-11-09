<?php

declare(strict_types=1);

namespace App\Ponto\Service;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\DTO\JustificativaAAnalisarOutput;
use App\Ponto\Repository\JustificativaPontoRepository;

/**
 * "Justificativas a analisar" da aba Equipe da /ponto (spec `docs/specs/ponto-desenho-0811.md`, E5, P57; desenho 16
 * L217 e L218): as em análise do escritório da sessão, de quem tem vínculo ativo nele. As de quem vê entram só quando
 * ele pode analisar a própria (P43 a, `AnaliseDaPropriaJustificativa`: o primário e o Master), e vêm primeiro, como no
 * desenho; depois as da equipe, da data mais nova para a mais antiga e por nome.
 *
 * `contarAAnalisar` é o número da aba (e do indicador "A analisar"), numa contagem só, sem carregar a lista.
 */
final class JustificativasAAnalisarDaEquipe
{
    public function __construct(
        private readonly JustificativaPontoRepository $justificativas,
        private readonly AnaliseDaPropriaJustificativa $analiseDaPropria,
    ) {
    }

    /** @return list<JustificativaAAnalisarOutput> */
    public function listar(User $eu, Tenant $escritorio): array
    {
        $comAsProprias = $this->analiseDaPropria->permite($eu, $eu, $escritorio);
        $proprias = [];
        $daEquipe = [];
        foreach ($this->justificativas->aAnalisarNoEscritorio($escritorio) as $justificativa) {
            if ($justificativa->getUser()?->getId() !== $eu->getId()) {
                $daEquipe[] = JustificativaAAnalisarOutput::daJustificativa($justificativa);
            } elseif ($comAsProprias) {
                $proprias[] = JustificativaAAnalisarOutput::daJustificativa($justificativa);
            }
        }

        return [...$proprias, ...$daEquipe];
    }

    public function contarAAnalisar(User $eu, Tenant $escritorio): int
    {
        $semAsMinhas = $this->analiseDaPropria->permite($eu, $eu, $escritorio) ? null : $eu;

        return $this->justificativas->contarAAnalisarNoEscritorio($escritorio, $semAsMinhas);
    }
}
