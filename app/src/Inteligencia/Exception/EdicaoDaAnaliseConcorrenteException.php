<?php

declare(strict_types=1);

namespace App\Inteligencia\Exception;

/**
 * A análise foi editada por outra pessoa depois que esta tela abriu (P4h). Os pontos vão por posição
 * na lista exibida, então salvar por cima trocaria o texto de um ponto pelo de outro: recusa (409).
 */
final class EdicaoDaAnaliseConcorrenteException extends \DomainException
{
}
