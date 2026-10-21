<?php

declare(strict_types=1);

namespace App\Agenda\Exception;

/** Só o criador ou quem administra usuários do escritório altera ou exclui: responde 403. */
final class SemPermissaoNoCompromissoException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Só quem criou o compromisso ou um administrador pode alterá-lo.');
    }
}
