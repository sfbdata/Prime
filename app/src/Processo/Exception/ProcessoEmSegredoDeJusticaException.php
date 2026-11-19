<?php

declare(strict_types=1);

namespace App\Processo\Exception;

/**
 * O processo está em segredo de justiça ({@see \App\Processo\Entity\Processo::emSegredoDeJustica()}) e o conteúdo dele
 * não pode sair para quem lê sem sessão (conector de I.A.). Só vem depois de a pessoa já poder abrir o processo: quem
 * não pode vê "não encontrado".
 */
final class ProcessoEmSegredoDeJusticaException extends \DomainException
{
}
