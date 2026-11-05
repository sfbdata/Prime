<?php

declare(strict_types=1);

namespace App\Pasta\Exception;

use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * O processo pedido só está em pastas ocultas para a pessoa pelo cadeado (regra K3): ele não abre para ela e
 * não pode ser vinculado por ela a outra pasta. A tela da pasta responde o mesmo 422 "Processo não encontrado."
 * do id inexistente (não confirma que o processo existe). Estende a recusa de acesso do Symfony para que quem
 * chama o vínculo sem traduzir a exceção responda 403, nunca 500.
 */
final class ProcessoOcultoPeloCadeadoException extends AccessDeniedException
{
}
