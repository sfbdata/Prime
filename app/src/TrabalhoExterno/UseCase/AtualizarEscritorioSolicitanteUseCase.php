<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\UseCase;

use App\TrabalhoExterno\DTO\DadosDoEscritorioSolicitante;
use App\TrabalhoExterno\Entity\EscritorioSolicitante;
use App\TrabalhoExterno\Enum\Perfil;
use App\TrabalhoExterno\Exception\AcaoNaoPermitida;
use App\TrabalhoExterno\Exception\DadosInvalidos;
use App\TrabalhoExterno\Exception\DemandaNaoEncontrada;
use App\TrabalhoExterno\Repository\EscritorioSolicitanteRepository;
use App\TrabalhoExterno\Service\Contexto;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * "Salvar edição" da Nova solicitação (desenho 06 de 25/10): grava as alterações num escritório que o
 * usuário guardou. Só o dono edita: id de outro usuário (ou inexistente) é 404. Não toca em Tenant.
 */
final class AtualizarEscritorioSolicitanteUseCase
{
    public function __construct(
        private readonly EscritorioSolicitanteRepository $escritorios,
        private readonly ValidatorInterface $validator,
    ) {
    }

    public function executar(Contexto $ctx, int $id, DadosDoEscritorioSolicitante $dados): EscritorioSolicitante
    {
        if ($ctx->previa || !in_array($ctx->perfil, [Perfil::Solicitante, Perfil::Master], true)) {
            throw new AcaoNaoPermitida('Só quem abre solicitações edita escritórios guardados.');
        }
        $escritorio = $this->escritorios->doUsuarioPorId($ctx->usuario, $id)
            ?? throw new DemandaNaoEncontrada('Escritório não encontrado.');
        $violacoes = $this->validator->validate($dados);
        if (count($violacoes) > 0) {
            throw new DadosInvalidos((string) $violacoes[0]->getMessage());
        }

        $escritorio->preencher($dados);
        $this->escritorios->salvar($escritorio, flush: true);

        return $escritorio;
    }
}
