<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\UseCase;

use App\TrabalhoExterno\DTO\DadosDoCurriculo;
use App\TrabalhoExterno\DTO\ResultadoDoSalvarCurriculo;
use App\TrabalhoExterno\Exception\CandidaturaRecusada;
use App\TrabalhoExterno\Service\Contexto;

/**
 * "Salvar currículo" e "Salvar e candidatar-se" (`salvarCv`, desenho 06 l.1006): salva o próprio
 * currículo e, com `$candidatarA` (código da vaga), segue o mesmo caso de uso da candidatura. São dois
 * casos de uso, cada um com o seu COMMIT: a recusa da candidatura sai com `curriculoSalvo`, porque o
 * currículo já ficou gravado. O toast é o do desenho: "Currículo salvo" sem vaga; "Currículo salvo e
 * candidatura enviada" só quando criou a candidatura comum; senão, o da própria candidatura.
 */
final class SalvarCurriculoECandidatarUseCase
{
    public function __construct(
        private readonly SalvarMeuCurriculoUseCase $salvar,
        private readonly CandidatarOuAceitarUseCase $candidatar,
    ) {
    }

    public function executar(Contexto $ctx, DadosDoCurriculo $dados, ?string $candidatarA): ResultadoDoSalvarCurriculo
    {
        $cv = $this->salvar->executar($ctx, $dados);
        if ($candidatarA === null || $candidatarA === '') {
            return new ResultadoDoSalvarCurriculo($cv, ['titulo' => 'Currículo salvo', 'texto' => 'Use-o para se candidatar às vagas.']);
        }

        try {
            return new ResultadoDoSalvarCurriculo($cv, $this->candidatar->executar($ctx, $candidatarA)->toastAposSalvarOCurriculo());
        } catch (CandidaturaRecusada $e) {
            throw $e->comCurriculoSalvo();
        }
    }
}
