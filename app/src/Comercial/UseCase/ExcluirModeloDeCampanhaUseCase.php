<?php

declare(strict_types=1);

namespace App\Comercial\UseCase;

use App\Comercial\Campanha\ModelosDeCampanha;
use App\Comercial\Exception\CampanhaRecusada;
use App\Comercial\Repository\ModeloDeCampanhaRepository;
use App\Entity\Auth\User;

/**
 * "Excluir" (dc L.552; a tela pergunta antes, `confirm('Excluir o modelo "NOME"?')`): só o próprio. Auditado
 * (`modelo_del`). Não mexe em campanha confirmada (a campanha da 2b guarda a cópia do conteúdo, §8.4).
 */
final class ExcluirModeloDeCampanhaUseCase
{
    public function __construct(
        private readonly ModeloDeCampanhaRepository $modelos,
        private readonly ModelosDeCampanha $fixos,
    ) {
    }

    public function executar(string $idOuChave, User $por): void
    {
        if ($this->fixos->ehFixo($idOuChave)) {
            throw new CampanhaRecusada('Modelos padrão não são excluídos.');
        }
        $modelo = ctype_digit($idOuChave) ? $this->modelos->porId((int) $idOuChave) : null;
        if ($modelo === null) {
            throw new CampanhaRecusada('Modelo não encontrado.');
        }
        $this->modelos->remover($modelo, $por);
    }
}
