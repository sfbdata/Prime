<?php

declare(strict_types=1);

namespace App\Comercial\UseCase;

use App\Comercial\Repository\ModeloDeCampanhaRepository;
use App\Comercial\Service\ModelosEfetivos;

/**
 * Os modelos que o seletor "Modelo" de cada data comemorativa oferece (M5.12): os padrões do sistema (com o nome da
 * versão editada, se houver) e os próprios do Master (`cu<id>`). O "Padrão da data" é o valor vazio, que a tela
 * acrescenta. As 17 mensagens `data-<id>` ficam de fora: cada data já tem a dela.
 */
final class ListarModelosDasDatasUseCase
{
    public function __construct(
        private readonly ModelosEfetivos $efetivos,
        private readonly ModeloDeCampanhaRepository $proprios,
    ) {
    }

    /** @return list<array{chave: string, nome: string, proprio: bool}> */
    public function executar(): array
    {
        $lista = [];
        foreach ($this->efetivos->fixos() as $m) {
            $lista[] = ['chave' => (string) $m['chave'], 'nome' => (string) $m['nome'], 'proprio' => false];
        }
        foreach ($this->proprios->todos() as $m) {
            $lista[] = ['chave' => 'cu' . $m->getId(), 'nome' => $m->getNome(), 'proprio' => true];
        }

        return $lista;
    }
}
