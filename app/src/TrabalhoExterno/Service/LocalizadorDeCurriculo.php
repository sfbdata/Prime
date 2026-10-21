<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Service;

use App\TrabalhoExterno\Entity\Curriculo;
use App\TrabalhoExterno\Exception\DemandaNaoEncontrada;
use App\TrabalhoExterno\Repository\CurriculoRepository;

/**
 * A porta única de "currículo por código", como o `LocalizadorDeVaga`: a Central alcança o banco
 * inteiro; o externo, só o dele. Qualquer outro caso é a mesma resposta de "não encontrado" (404),
 * para não confirmar que o currículo de outra pessoa existe.
 */
final class LocalizadorDeCurriculo
{
    public function __construct(
        private readonly CurriculoRepository $repositorio,
        private readonly PoliticaDoCurriculo $politica,
    ) {
    }

    public function localizar(Contexto $ctx, string $codigo): Curriculo
    {
        $id = Curriculo::idDoCodigo($codigo);
        $cv = match (true) {
            $id === null || !$this->politica->veAba($ctx) => null,
            $this->politica->ehInterno($ctx) => $this->repositorio->porId($id),
            default => $this->meuComId($ctx, $id),
        };
        if ($cv === null || !$this->politica->podeVer($ctx, $cv)) {
            throw new DemandaNaoEncontrada('Currículo não encontrado.');
        }

        return $cv;
    }

    /** O currículo do próprio usuário externo; null para o interno e para quem ainda não tem. */
    public function meu(Contexto $ctx): ?Curriculo
    {
        return $this->politica->temMeuCurriculo($ctx) ? $this->repositorio->doUsuario($ctx->usuario) : null;
    }

    /** @return list<Curriculo> o banco de talentos, só para a Central */
    public function visiveis(Contexto $ctx): array
    {
        return $this->politica->veAba($ctx) && $this->politica->ehInterno($ctx) ? $this->repositorio->recebidos() : [];
    }

    private function meuComId(Contexto $ctx, int $id): ?Curriculo
    {
        $meu = $this->meu($ctx);

        return $meu !== null && $meu->getId() === $id ? $meu : null;
    }
}
