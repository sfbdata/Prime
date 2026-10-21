<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\UseCase;

use App\TrabalhoExterno\DTO\DadosDaSolicitacao;
use App\TrabalhoExterno\DTO\DadosDaVaga;
use App\TrabalhoExterno\Entity\InteressadoEmVagas;
use App\TrabalhoExterno\Entity\LinkDeVagas;
use App\TrabalhoExterno\Exception\DadosInvalidos;
use App\TrabalhoExterno\Repository\InteressadoEmVagasRepository;
use App\TrabalhoExterno\Repository\LinkDeVagasRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

/**
 * "Quero receber as vagas" da página pública (spec VS 18.10.26 §4.2). Quem: qualquer visitante,
 * sem login. Exige e-mail válido e o consentimento LGPD marcado (as mensagens são as do desenho).
 * Repetir o e-mail atualiza as preferências e responde igual, para a página não dizer quem já está
 * inscrito. O limite de tentativas é do controller; enviar e-mail é de outra frente.
 */
final class InscreverInteressadoUseCase
{
    public function __construct(
        private readonly InteressadoEmVagasRepository $interessados,
        private readonly LinkDeVagasRepository $links,
    ) {
    }

    /** @param list<mixed> $areas */
    public function executar(string $email, string $uf, array $areas, bool $consentiu, ?string $token): void
    {
        $email = mb_strtolower(trim($email));
        if (mb_strlen($email) > 180 || filter_var($email, \FILTER_VALIDATE_EMAIL) === false) {
            throw new DadosInvalidos('Informe um e-mail válido.');
        }
        if (!$consentiu) {
            throw new DadosInvalidos('Marque a autorização para receber os e-mails.');
        }

        $interessado = $this->interessados->doEmail($email) ?? new InteressadoEmVagas($email);
        $interessado->definirPreferencias(
            in_array($uf, DadosDaSolicitacao::UFS, true) ? $uf : '',
            array_values(array_intersect(DadosDaVaga::AREAS, $areas)),
            $token !== null && $this->links->doToken($token) !== null ? LinkDeVagas::hash($token) : null,
            new \DateTimeImmutable(),
        );
        try {
            $this->interessados->salvar($interessado, flush: true);
        } catch (UniqueConstraintViolationException) {
            // Duas inscrições do mesmo e-mail ao mesmo tempo: a outra gravou. Para o visitante, é igual.
        }
    }
}
