<?php

declare(strict_types=1);

namespace App\Tests\Inteligencia\Support;

use App\Entity\Auth\User;
use App\Inteligencia\Entity\ConfiguracaoDaPlataformaDeIa;
use App\Inteligencia\Repository\ConfiguracaoDaPlataformaDeIaRepository;

/**
 * O repositório da plataforma da BlueJus IA para quem monta a {@see \App\Inteligencia\Service\DisponibilidadeDeInteligencia}
 * à mão: devolve a linha com a chave geral do Master ligada (o padrão dos testes que provam o resto da cadeia) ou
 * desligada.
 */
trait PlataformaDeIaNoTesteTrait
{
    private function plataformaDeIa(bool $ligada = true): ConfiguracaoDaPlataformaDeIaRepository
    {
        $configuracao = new ConfiguracaoDaPlataformaDeIa();
        if ($ligada) {
            $configuracao->ligarParaTodos(new User(), new \DateTimeImmutable());
        }
        $repositorio = $this->createStub(ConfiguracaoDaPlataformaDeIaRepository::class);
        $repositorio->method('obter')->willReturn($configuracao);

        return $repositorio;
    }
}
