<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\UseCase;

use App\TrabalhoExterno\DTO\DadosDoEscritorioSolicitante;
use App\TrabalhoExterno\Entity\EscritorioSolicitante;
use App\TrabalhoExterno\Enum\Perfil;
use App\TrabalhoExterno\Exception\AcaoNaoPermitida;
use App\TrabalhoExterno\Exception\DadosInvalidos;
use App\TrabalhoExterno\Exception\TransicaoInvalida;
use App\TrabalhoExterno\Repository\EscritorioSolicitanteRepository;
use App\TrabalhoExterno\Service\Contexto;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * "Novo escritório" da Nova solicitação (desenho 06 de 25/10): guarda, para o usuário, os dados de
 * escritório digitados. Quem: o Solicitante (ou o Master), nunca na prévia. Não toca em Tenant.
 * Alternativos: nome vazio ou dado fora do formato é 422; passou de 20 guardados é 409.
 * Pós-condição: o escritório pertence ao usuário logado.
 */
final class CriarEscritorioSolicitanteUseCase
{
    public function __construct(
        private readonly EscritorioSolicitanteRepository $escritorios,
        private readonly ValidatorInterface $validator,
    ) {
    }

    public function executar(Contexto $ctx, DadosDoEscritorioSolicitante $dados): EscritorioSolicitante
    {
        if ($ctx->previa || !in_array($ctx->perfil, [Perfil::Solicitante, Perfil::Master], true)) {
            throw new AcaoNaoPermitida('Só quem abre solicitações guarda escritórios.');
        }
        $violacoes = $this->validator->validate($dados);
        if (count($violacoes) > 0) {
            throw new DadosInvalidos((string) $violacoes[0]->getMessage());
        }
        if ($this->escritorios->contarDoUsuario($ctx->usuario) >= EscritorioSolicitante::LIMITE_POR_USUARIO) {
            throw new TransicaoInvalida(sprintf('Você já guardou %d escritórios, que é o limite. Use um dos salvos.', EscritorioSolicitante::LIMITE_POR_USUARIO));
        }

        $escritorio = new EscritorioSolicitante($ctx->usuario, $dados);
        $this->escritorios->salvar($escritorio, flush: true);

        return $escritorio;
    }
}
