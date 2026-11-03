<?php

declare(strict_types=1);

namespace App\Blog\UseCase;

use App\Blog\Exception\ArtigoDoBlogNaoEncontrado;
use App\Blog\Repository\ArtigoDoBlogRepository;
use App\Blog\Service\ValidadorDoArtigo;

/**
 * "Verificar": roda as regras do zod (as que valem para ir ao ar) no artigo salvo, sem mudar nada. A data de publicação
 * ainda não tem campo na tela (Agendar e Publicar chegam na P4 e na P5), então esse aviso não entra aqui.
 */
final class VerificarArtigoDoBlogUseCase
{
    public function __construct(
        private readonly ArtigoDoBlogRepository $artigos,
        private readonly ValidadorDoArtigo $validador,
    ) {
    }

    /** @return array<string, list<string>> campo => mensagens; vazio = pronto para ir ao ar */
    public function executar(int $id): array
    {
        $artigo = $this->artigos->porId($id) ?? throw ArtigoDoBlogNaoEncontrado::comId($id);
        $erros = $this->validador->validar($artigo, $this->artigos->slugsExistentes());
        unset($erros['publicarEm']);

        return $erros;
    }
}
